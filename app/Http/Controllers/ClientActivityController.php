<?php

namespace App\Http\Controllers;

use App\Models\StageActivityEvent;
use App\Models\StageDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Acesso contextual do cliente a atividades onde está pontualmente envolvido.
 *
 * Rotas separadas das internas (`/activities/{id}/*`) para manter o
 * `BlockCliente` nas operacionais. Aqui validamos manualmente que o
 * `auth()->id() === delivery.client_user_id` e `client_involved=true`.
 *
 * Cliente NÃO acessa: outras atividades, board, etapas, projetos completos,
 * horas internas, riscos, capacidade.
 */
class ClientActivityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->isCliente()) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }

        $items = StageDelivery::query()
            ->where('client_user_id', $user->id)
            ->where('client_involved', true)
            ->with([
                'stage:id,project_id,name',
                'stage.project:id,name,customer_id',
                'responsible:id,name,email',
            ])
            ->orderByDesc('due_date')
            ->get()
            ->map(fn ($d) => $this->summarize($d));

        return response()->json(['items' => $items]);
    }

    public function show(StageDelivery $delivery, Request $request): JsonResponse
    {
        if (($err = $this->ensureAccess($delivery, $request)) !== null) return $err;

        $delivery->load(['responsible:id,name,email', 'stage:id,project_id,name', 'stage.project:id,name']);

        return response()->json($this->summarize($delivery, full: true));
    }

    public function timeline(StageDelivery $delivery, Request $request): JsonResponse
    {
        if (($err = $this->ensureAccess($delivery, $request)) !== null) return $err;

        $events = StageActivityEvent::query()
            ->where('delivery_id', $delivery->id)
            ->whereIn('type', [
                // Eventos que o cliente PODE ver na sua atividade
                StageActivityEvent::TYPE_DELIVERY_CREATED,
                StageActivityEvent::TYPE_DELIVERY_MOVED,
                StageActivityEvent::TYPE_DELIVERY_COMPLETED,
                StageActivityEvent::TYPE_COMMENT,
                StageActivityEvent::TYPE_CLIENT_INVOLVED,
                StageActivityEvent::TYPE_CLIENT_REMOVED,
            ])
            ->with('actor:id,name,email')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return response()->json(['items' => $events]);
    }

    public function storeComment(StageDelivery $delivery, Request $request): JsonResponse
    {
        if (($err = $this->ensureAccess($delivery, $request)) !== null) return $err;

        $data = $request->validate([
            'text'       => 'nullable|string|max:5000',
            'attachment' => 'nullable|file|max:20480|mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip',
        ]);

        $text = trim((string) ($data['text'] ?? ''));
        $hasAttach = $request->hasFile('attachment');

        if ($text === '' && !$hasAttach) {
            return response()->json(['message' => 'Comentário precisa de texto ou anexo.'], 422);
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

        $event = StageActivityEvent::create(array_merge([
            'stage_id'      => $delivery->stage_id,
            'delivery_id'   => $delivery->id,
            'actor_user_id' => $request->user()->id,
            'type'          => StageActivityEvent::TYPE_COMMENT,
            'payload'       => array_filter([
                'text'         => $text !== '' ? $text : null,
                'from_client'  => true,
            ]),
        ], $attachmentData));

        return response()->json($event->load('actor:id,name,email'), 201);
    }

    /**
     * View limitada da atividade para o cliente.
     * NÃO inclui: hours_planned interno, alocações, predecessor, riscos, health.
     */
    private function summarize(StageDelivery $d, bool $full = false): array
    {
        $base = [
            'id'                => $d->id,
            'title'             => $d->title,
            'description'       => $d->description,
            'status'            => $d->status,
            'planned_start_at'  => $d->planned_start_at?->toDateString(),
            'due_date'          => $d->due_date?->toDateString(),
            'completed_at'      => $d->completed_at?->toIso8601String(),
            'responsible_name'  => $d->responsible?->name,
            'stage_name'        => $d->stage?->name,
            'project_name'      => $d->stage?->project?->name,
        ];

        if ($full) {
            $base['client_involved'] = (bool) $d->client_involved;
        }

        return $base;
    }

    private function ensureAccess(StageDelivery $delivery, Request $request): ?JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Não autenticado.'], 401);
        }
        if (!$user->isCliente()) {
            return response()->json(['message' => 'Endpoint exclusivo do perfil cliente.'], 403);
        }
        if ((int) $delivery->client_user_id !== (int) $user->id || !$delivery->client_involved) {
            return response()->json(['message' => 'Você não está envolvido nesta atividade.'], 403);
        }
        return null;
    }
}
