<?php

namespace App\Http\Controllers;

use App\Models\ProjectStage;
use App\Models\StageDelivery;
use App\Services\BusinessCalendarService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StageDeliveryController extends Controller
{
    public function index(ProjectStage $stage, Request $request): JsonResponse
    {
        $query = $stage->deliveries()
            ->with('responsible:id,name,email')
            ->withSum('timesheets as effort_minutes_sum', 'effort_minutes');

        // Visão consultor: vê só entregas atribuídas a ele. ADR 0004.
        $user = $request->user();
        if ($user && method_exists($user, 'isConsultor') && $user->isConsultor()) {
            $query->where('responsible_user_id', $user->id);
        }

        $deliveries = $query
            ->orderBy('status')
            ->orderBy('order_index')
            ->get();

        return response()->json(['items' => $deliveries]);
    }

    public function show(StageDelivery $delivery): JsonResponse
    {
        $delivery->load(['responsible:id,name,email', 'stage:id,project_id,name']);

        return response()->json($delivery);
    }

    public function store(Request $request, ProjectStage $stage): JsonResponse
    {
        $data = $request->validate([
            'title'                  => 'required|string|max:200',
            'description'            => 'nullable|string',
            'responsible_user_id'    => 'nullable|exists:users,id',
            'hours_planned'          => 'nullable|numeric|min:0',
            'priority'               => ['nullable', Rule::in(StageDelivery::PRIORITIES)],
            'status'                 => ['nullable', Rule::in(StageDelivery::STATUSES)],
            'due_date'               => 'nullable|date',
            'planned_start_at'       => 'nullable|date',
            'depends_on_delivery_id' => 'nullable|integer|exists:stage_deliveries,id',
            'dependency_type'        => ['nullable', Rule::in(StageDelivery::DEPENDENCY_TYPES)],
            'client_user_id'         => 'nullable|integer|exists:users,id',
            'client_email'           => 'nullable|email|max:180',
            'client_involved'        => 'nullable|boolean',
        ]);

        $data['stage_id'] = $stage->id;
        $data['order_index'] = (int) $stage->deliveries()
            ->where('status', $data['status'] ?? StageDelivery::STATUS_BACKLOG)
            ->max('order_index') + 1;

        // Fix Fase 9: auto-persiste due_date sugerida se start+horas presentes e fim ausente.
        // Antes o backend só RETORNAVA suggested_due_date — agora aplica direto pra que
        // duration_business_days não fique null e a UI já mostre o intervalo completo.
        if (empty($data['due_date']) && !empty($data['planned_start_at']) && !empty($data['hours_planned'])) {
            $tmp = new StageDelivery($data);
            if ($suggested = $this->suggestedDueDate($tmp)) {
                $data['due_date'] = $suggested;
            }
        }

        $delivery = StageDelivery::create($data);

        $payload = $delivery->load('responsible:id,name,email')->toArray();
        if ($suggested = $this->suggestedDueDate($delivery)) {
            $payload['suggested_due_date'] = $suggested;
        }

        return response()->json($payload, 201);
    }

    public function update(Request $request, StageDelivery $delivery): JsonResponse
    {
        $data = $request->validate([
            'title'                  => 'sometimes|string|max:200',
            'description'            => 'nullable|string',
            'responsible_user_id'    => 'nullable|exists:users,id',
            'hours_planned'          => 'sometimes|numeric|min:0',
            'priority'               => ['sometimes', Rule::in(StageDelivery::PRIORITIES)],
            'status'                 => ['sometimes', Rule::in(StageDelivery::STATUSES)],
            'due_date'               => 'nullable|date',
            'planned_start_at'       => 'nullable|date',
            'depends_on_delivery_id' => 'nullable|integer|exists:stage_deliveries,id',
            'dependency_type'        => ['nullable', Rule::in(StageDelivery::DEPENDENCY_TYPES)],
            'client_user_id'         => 'nullable|integer|exists:users,id',
            'client_email'           => 'nullable|email|max:180',
            'client_involved'        => 'nullable|boolean',
        ]);

        // Guard contra ciclo: atividade não pode depender de si mesma
        if (array_key_exists('depends_on_delivery_id', $data)
            && $data['depends_on_delivery_id'] !== null
            && (int) $data['depends_on_delivery_id'] === (int) $delivery->id) {
            return response()->json([
                'message' => 'Atividade não pode depender de si mesma.',
            ], 422);
        }

        // Ciclo transitivo: o novo predecessor não pode ter $delivery na sua cadeia
        if (array_key_exists('depends_on_delivery_id', $data)
            && $data['depends_on_delivery_id'] !== null
            && $this->hasCycle((int) $data['depends_on_delivery_id'], (int) $delivery->id)) {
            return response()->json([
                'message' => 'Dependência cria ciclo (A depende de B que depende de A).',
            ], 422);
        }

        $delivery->update($data);

        $payload = $delivery->fresh()->load('responsible:id,name,email')->toArray();
        if ($suggested = $this->suggestedDueDate($delivery->fresh())) {
            $payload['suggested_due_date'] = $suggested;
        }

        return response()->json($payload);
    }

    public function destroy(StageDelivery $delivery): JsonResponse
    {
        // Fix Fase 9: limpa FK de dependentes antes do soft-delete pra evitar
        // referências fantasma (FK ON DELETE SET NULL não dispara em soft-delete).
        StageDelivery::where('depends_on_delivery_id', $delivery->id)
            ->update(['depends_on_delivery_id' => null, 'dependency_type' => null]);

        $delivery->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Move uma entrega: muda status (coluna) e/ou reposiciona dentro da coluna.
     * Payload: { status: 'in_progress', order_index: 2, sibling_ids?: [4,5,7] }
     *
     * Se sibling_ids vier, reordena todas as entregas da nova coluna na ordem informada.
     */
    public function move(Request $request, StageDelivery $delivery): JsonResponse
    {

        $data = $request->validate([
            'status'        => ['required', Rule::in(StageDelivery::STATUSES)],
            'order_index'   => 'sometimes|integer|min:0',
            'sibling_ids'   => 'sometimes|array',
            'sibling_ids.*' => 'integer|exists:stage_deliveries,id',
        ]);

        // Bloqueio operacional: predecessor FS pending impede sair de backlog (ADR 0009 appendix)
        $movingOutOfBacklog = $delivery->status === StageDelivery::STATUS_BACKLOG
            && $data['status'] !== StageDelivery::STATUS_BACKLOG;
        if ($movingOutOfBacklog && $delivery->depends_on_delivery_id && $delivery->dependency_type === 'FS') {
            $predecessor = StageDelivery::find($delivery->depends_on_delivery_id);
            if ($predecessor && $predecessor->status !== StageDelivery::STATUS_DONE) {
                return response()->json([
                    'message' => "Conclua a atividade '{$predecessor->title}' antes de iniciar esta.",
                    'predecessor_id' => $predecessor->id,
                ], 422);
            }
        }

        DB::transaction(function () use ($data, $delivery) {
            $delivery->update([
                'status'      => $data['status'],
                'order_index' => $data['order_index'] ?? $delivery->order_index,
            ]);

            if (!empty($data['sibling_ids'])) {
                foreach ($data['sibling_ids'] as $index => $id) {
                    StageDelivery::where('id', $id)
                        ->where('stage_id', $delivery->stage_id)
                        ->update(['order_index' => $index]);
                }
            }
        });

        return response()->json($delivery->fresh());
    }

    /**
     * Timeline da atividade — eventos com delivery_id=X. Mais recentes primeiro.
     * Reaproveita a tabela stage_activity_events (ADR 0005 + 0010).
     */
    public function activity(StageDelivery $delivery, Request $request): JsonResponse
    {
        $limit = min((int) $request->get('limit', 50), 200);

        $events = \App\Models\StageActivityEvent::query()
            ->where('delivery_id', $delivery->id)
            ->with('actor:id,name,email')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return response()->json(['items' => $events]);
    }

    /**
     * Comentário operacional na atividade (Pilar A do refactor).
     *
     * Cria evento append-only `type=comment` em `stage_activity_events`
     * com `delivery_id` setado. Texto livre, anexo opcional, mentions
     * via `mentioned_user_ids`.
     *
     * Filtro de escrita: consultor só comenta em atividade onde é
     * `responsible_user_id` OU está alocado (stage_allocations.delivery_id).
     */
    public function storeComment(Request $request, StageDelivery $delivery): JsonResponse
    {
        $user = $request->user();

        if ($user && method_exists($user, 'isConsultor') && $user->isConsultor()) {
            $isResponsible = (int) $delivery->responsible_user_id === (int) $user->id;
            $isAllocated = \App\Models\StageAllocation::query()
                ->where('user_id', $user->id)
                ->where(function ($q) use ($delivery) {
                    $q->where('delivery_id', $delivery->id)
                      ->orWhere(function ($s) use ($delivery) {
                          $s->whereNull('delivery_id')
                            ->where('stage_id', $delivery->stage_id);
                      });
                })
                ->exists();
            if (!$isResponsible && !$isAllocated) {
                return response()->json([
                    'message' => 'Você não está alocado nesta atividade.',
                ], 403);
            }
        }

        $data = $request->validate([
            'text'                 => 'nullable|string|max:5000',
            'attachment'           => 'nullable|file|max:20480|mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip',
            'mentioned_user_ids'   => 'nullable|array',
            'mentioned_user_ids.*' => 'integer|exists:users,id',
        ]);

        $text       = trim((string) ($data['text'] ?? ''));
        $hasAttach  = $request->hasFile('attachment');
        $mentioned  = array_map('intval', $data['mentioned_user_ids'] ?? []);

        if ($text === '' && !$hasAttach) {
            return response()->json([
                'message' => 'Comentário precisa de texto ou anexo.',
            ], 422);
        }

        $attachmentData = [];
        if ($hasAttach) {
            $file = $request->file('attachment');
            $path = $file->store("stage-attachments/{$delivery->stage_id}", 'public');
            $attachmentData = [
                'attachment_path'          => $path,
                'attachment_original_name' => $file->getClientOriginalName(),
                'attachment_mime'          => $file->getMimeType(),
                'attachment_size'          => $file->getSize(),
            ];
        }

        $event = \App\Models\StageActivityEvent::create(array_merge([
            'stage_id'      => $delivery->stage_id,
            'delivery_id'   => $delivery->id,
            'actor_user_id' => $user?->id,
            'type'          => \App\Models\StageActivityEvent::TYPE_COMMENT,
            'payload'       => array_filter([
                'text'               => $text !== '' ? $text : null,
                'mentioned_user_ids' => !empty($mentioned) ? $mentioned : null,
            ]),
        ], $attachmentData));

        return response()->json($event->load('actor:id,name,email'), 201);
    }

    /**
     * Cascade FS: para cada dependente direto/transitivo,
     * sugere novo start = nextBusinessDay(predecessor.due_date) e
     * novo end = addBusinessHours(novo_start, hours, 8).
     *
     * Body: { apply: bool }. apply=false retorna preview; apply=true persiste.
     */
    public function recalcDependents(Request $request, StageDelivery $delivery): JsonResponse
    {
        $apply = (bool) $request->boolean('apply', false);
        $calendar = app(BusinessCalendarService::class);

        // Preview com valor *que o coord está digitando* sem persistir.
        // Aceito apenas quando apply=false.
        $simulate = $request->input('simulate', []);
        if (!$apply && is_array($simulate) && !empty($simulate)) {
            if (array_key_exists('planned_start_at', $simulate)) {
                $delivery->planned_start_at = $simulate['planned_start_at'] ? Carbon::parse($simulate['planned_start_at']) : null;
            }
            if (array_key_exists('due_date', $simulate)) {
                $delivery->due_date = $simulate['due_date'] ? Carbon::parse($simulate['due_date']) : null;
            }
            if (array_key_exists('hours_planned', $simulate)) {
                $delivery->hours_planned = $simulate['hours_planned'];
            }
        }

        $chain = [];
        $visited = [];

        $walk = function (StageDelivery $node, ?Carbon $newEnd) use (&$walk, &$chain, &$visited, $calendar) {
            $deps = StageDelivery::where('depends_on_delivery_id', $node->id)
                ->where('dependency_type', 'FS')
                ->get();

            foreach ($deps as $dep) {
                if (isset($visited[$dep->id])) continue;
                $visited[$dep->id] = true;

                $hours = (float) ($dep->hours_planned ?? 0);
                $predEnd = $newEnd ?: ($node->due_date ? Carbon::parse($node->due_date) : null);
                if (!$predEnd) continue;

                $suggestedStart = $calendar->nextBusinessDay($predEnd->copy()->addDay());
                $suggestedEnd   = $hours > 0
                    ? $calendar->addBusinessHours($suggestedStart, $hours, 8.0)
                    : $suggestedStart;

                $chain[] = [
                    'id'              => $dep->id,
                    'title'           => $dep->title,
                    'current_start'   => $dep->planned_start_at?->toDateString(),
                    'current_end'     => $dep->due_date?->toDateString(),
                    'suggested_start' => $suggestedStart->toDateString(),
                    'suggested_end'   => $suggestedEnd->toDateString(),
                    'hours_planned'   => $hours,
                ];

                $walk($dep, $suggestedEnd);
            }
        };

        $startEnd = $delivery->due_date ? Carbon::parse($delivery->due_date) : null;
        $walk($delivery, $startEnd);

        if (!$apply) {
            return response()->json(['chain' => $chain]);
        }

        $updatedIds = [];
        DB::transaction(function () use ($chain, &$updatedIds) {
            foreach ($chain as $row) {
                $dep = StageDelivery::find($row['id']);
                if (!$dep) continue;
                $dep->update([
                    'planned_start_at' => $row['suggested_start'],
                    'due_date'         => $row['suggested_end'],
                ]);
                $updatedIds[] = $dep->id;
            }
        });

        return response()->json([
            'chain'   => $chain,
            'updated' => $updatedIds,
        ]);
    }

    private function suggestedDueDate(?StageDelivery $delivery): ?string
    {
        if (!$delivery) return null;
        if (!$delivery->planned_start_at) return null;
        $hours = (float) ($delivery->hours_planned ?? 0);
        if ($hours <= 0) return null;

        return app(BusinessCalendarService::class)
            ->addBusinessHours(Carbon::parse($delivery->planned_start_at), $hours, 8.0)
            ->toDateString();
    }

    /**
     * BFS no grafo de dependências FS a partir de $startId. Retorna true se
     * encontrar $targetId na cadeia (profundidade máxima 10).
     */
    private function hasCycle(int $startId, int $targetId): bool
    {
        $queue = [$startId];
        $seen = [];
        $depth = 0;

        while (!empty($queue) && $depth < 10) {
            $next = [];
            foreach ($queue as $id) {
                if (isset($seen[$id])) continue;
                $seen[$id] = true;
                if ($id === $targetId) return true;

                $parent = StageDelivery::where('id', $id)->value('depends_on_delivery_id');
                if ($parent) $next[] = (int) $parent;
            }
            $queue = $next;
            $depth++;
        }

        return false;
    }
}
