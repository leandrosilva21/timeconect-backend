<?php

namespace App\Http\Controllers;

use App\Models\ProjectStage;
use App\Models\StageDelivery;
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
        ]);

        $data['stage_id'] = $stage->id;
        $data['order_index'] = (int) $stage->deliveries()
            ->where('status', $data['status'] ?? StageDelivery::STATUS_BACKLOG)
            ->max('order_index') + 1;

        $delivery = StageDelivery::create($data);

        return response()->json($delivery->load('responsible:id,name,email'), 201);
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
        ]);

        // Guard contra ciclo: atividade não pode depender de si mesma
        if (array_key_exists('depends_on_delivery_id', $data)
            && $data['depends_on_delivery_id'] !== null
            && (int) $data['depends_on_delivery_id'] === (int) $delivery->id) {
            return response()->json([
                'message' => 'Atividade não pode depender de si mesma.',
            ], 422);
        }

        $delivery->update($data);

        return response()->json($delivery->fresh()->load('responsible:id,name,email'));
    }

    public function destroy(StageDelivery $delivery): JsonResponse
    {
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
}
