<?php

namespace App\Http\Controllers;

use App\Enums\NotificationStatus;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Inbox\InboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InboxController extends Controller
{
    public function __construct(protected InboxService $inbox)
    {
    }

    public function conversations(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->inbox->ensureBotConversation($user);

        $items = $this->inbox->listConversations($user);
        return response()->json(['data' => $items]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $conv = Conversation::query()
            ->forUser($user->id)
            ->with(['customer:id,name'])
            ->findOrFail($id);

        return response()->json(['data' => (new ConversationResource($conv))->toArray($request)]);
    }

    public function messages(Request $request, int $id)
    {
        $user = $request->user();
        $conv = Conversation::query()->forUser($user->id)->findOrFail($id);

        $perPage = (int) $request->query('per_page', 50);

        // Filtro opcional por status
        $statusFilter = $request->query('status'); // unread|read|resolved|archived|snoozed ou CSV
        $q = $conv->messages()->with('sender:id,name,profile_photo')->orderByDesc('created_at');

        if ($statusFilter) {
            $statuses = is_array($statusFilter) ? $statusFilter : explode(',', $statusFilter);
            $q->whereIn('status', $statuses);
        }

        return MessageResource::collection($q->paginate(min($perPage, 100)));
    }

    public function send(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $conv = Conversation::query()->forUser($user->id)->findOrFail($id);

        $data = $request->validate([
            'body'     => 'required|string|max:8000',
            'metadata' => 'nullable|array',
        ]);

        $msg = $this->inbox->sendUserMessage($conv, $user, $data['body'], $data['metadata'] ?? null);
        $msg->load('sender:id,name,profile_photo');

        return (new MessageResource($msg))
            ->response()
            ->setStatusCode(201);
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $conv = Conversation::query()->forUser($user->id)->findOrFail($id);

        $this->inbox->markRead($conv, $user);

        // Marca todas as messages do conv como `read` (em vez de só atualizar last_read_at)
        $conv->messages()
            ->where('status', NotificationStatus::Unread->value)
            ->update(['status' => NotificationStatus::Read->value]);

        return response()->json(['marked_read' => true]);
    }

    /**
     * PATCH /api/v1/inbox/messages/{id}/status
     * body: { "status": "resolved|archived|snoozed|read|unread", "snoozed_until"?: "iso8601" }
     */
    public function updateMessageStatus(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'status'        => 'required|in:unread,read,resolved,archived,snoozed',
            'snoozed_until' => 'nullable|date|after:now',
        ]);

        $message = Message::with('conversation.participants')->findOrFail($id);

        // Garante que o user participa da conversation da message
        $isParticipant = $message->conversation->participants->contains('user_id', $user->id);
        if (! $isParticipant) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $status = NotificationStatus::from($data['status']);
        $snoozeUntil = ! empty($data['snoozed_until']) ? new \DateTime($data['snoozed_until']) : null;

        $message = $this->inbox->updateMessageStatus($message, $status, $user, $snoozeUntil);

        return response()->json(['data' => [
            'id'            => $message->id,
            'status'        => $message->status->value,
            'snoozed_until' => $message->snoozed_until?->toIso8601String(),
            'resolved_at'   => $message->resolved_at?->toIso8601String(),
            'resolved_by'   => $message->resolved_by,
        ]]);
    }

    /**
     * GET /api/v1/inbox/unread-summary
     * Retorna agora também breakdown por severity.
     */
    public function unreadSummary(Request $request): JsonResponse
    {
        $user = $request->user();
        $conversations = $this->inbox->listConversations($user);

        $total = array_sum(array_map(fn ($c) => $c['unread_count'], $conversations));

        $bySeverity = [
            'critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'info' => 0,
        ];
        foreach ($conversations as $c) {
            foreach ($bySeverity as $k => $_) {
                $bySeverity[$k] += $c['unread_by_severity'][$k] ?? 0;
            }
        }

        return response()->json([
            'total_unread'        => $total,
            'by_severity'         => $bySeverity,
            'conversations_count' => count($conversations),
        ]);
    }
}
