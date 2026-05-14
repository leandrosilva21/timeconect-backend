<?php

namespace App\Services\Inbox;

use App\Enums\ConversationType;
use App\Enums\MessageType;
use App\Enums\NotificationStatus;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class InboxService
{
    /**
     * Garante que existe a conversation tipo `bot` (Minutor) do usuário e retorna.
     * Cria sob demanda na primeira interação.
     */
    public function ensureBotConversation(User $user): Conversation
    {
        $conv = Conversation::query()
            ->where('type', ConversationType::Bot->value)
            ->whereHas('participants', fn ($p) => $p->where('user_id', $user->id))
            ->first();

        if ($conv) {
            return $conv;
        }

        return DB::transaction(function () use ($user) {
            $conv = Conversation::create([
                'type'             => ConversationType::Bot,
                'title'            => 'BOT Minutor',
                'created_by'       => null,
                'last_message_at'  => now(),
            ]);

            ConversationParticipant::create([
                'conversation_id' => $conv->id,
                'user_id'         => $user->id,
                'role'            => 'member',
                'joined_at'       => now(),
            ]);

            return $conv;
        });
    }

    public function listConversations(User $user): array
    {
        return Conversation::query()
            ->forUser($user->id)
            ->recent()
            ->with([
                'participants.user:id,name,profile_photo',
                'customer:id,name',
            ])
            ->limit(100)
            ->get()
            ->map(fn (Conversation $c) => $this->summarize($c, $user))
            ->all();
    }

    public function listMessages(Conversation $conversation, int $perPage = 50): LengthAwarePaginator
    {
        return $conversation->messages()
            ->with('sender:id,name,profile_photo')
            ->orderByDesc('created_at')
            ->paginate(min($perPage, 100));
    }

    public function sendUserMessage(Conversation $conversation, User $sender, string $body, ?array $metadata = null): Message
    {
        return $this->persist($conversation, [
            'sender_user_id' => $sender->id,
            'type'           => MessageType::User,
            'body'           => $body,
            'metadata'       => $metadata,
        ]);
    }

    public function persist(Conversation $conversation, array $attrs): Message
    {
        return DB::transaction(function () use ($conversation, $attrs) {
            $msg = $conversation->messages()->create($attrs);
            $conversation->update(['last_message_at' => $msg->created_at]);
            return $msg;
        });
    }

    public function markRead(Conversation $conversation, User $user): void
    {
        ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->update(['last_read_at' => now()]);
    }

    public function unreadCount(Conversation $conversation, User $user): int
    {
        return $conversation->messages()
            ->where('status', NotificationStatus::Unread->value)
            ->count();
    }

    /**
     * Conta unread agrupado por severity (em metadata->severity).
     *
     * @return array{critical:int,high:int,medium:int,low:int,info:int,total:int}
     */
    public function unreadBySeverity(Conversation $conversation): array
    {
        $rows = $conversation->messages()
            ->where('status', NotificationStatus::Unread->value)
            ->select(DB::raw("COALESCE(metadata->>'severity', 'info') as sev"), DB::raw('count(*) as total'))
            ->groupBy('sev')
            ->pluck('total', 'sev');

        $sum = [
            'critical' => (int) ($rows['critical'] ?? 0),
            'high'     => (int) ($rows['high']     ?? 0),
            'medium'   => (int) ($rows['medium']   ?? 0),
            'low'      => (int) ($rows['low']      ?? 0),
            'info'     => (int) ($rows['info']     ?? 0),
        ];
        $sum['total'] = array_sum($sum);
        return $sum;
    }

    public function updateMessageStatus(
        Message $message,
        NotificationStatus $status,
        ?User $actor = null,
        ?\DateTimeInterface $snoozedUntil = null,
    ): Message {
        $attrs = ['status' => $status];

        if ($status === NotificationStatus::Resolved) {
            $attrs['resolved_by'] = $actor?->id;
            $attrs['resolved_at'] = now();
        }
        if ($status === NotificationStatus::Snoozed) {
            $attrs['snoozed_until'] = $snoozedUntil ?? now()->addHours(4);
        }
        if ($status === NotificationStatus::Unread) {
            $attrs['snoozed_until'] = null;
            $attrs['resolved_by']   = null;
            $attrs['resolved_at']   = null;
        }

        $message->update($attrs);
        return $message->refresh();
    }

    private function summarize(Conversation $c, User $user): array
    {
        $last = $c->messages()->orderByDesc('created_at')->first(['id', 'type', 'body', 'metadata', 'created_at']);
        $unreadBy = $this->unreadBySeverity($c);

        return [
            'id'              => $c->id,
            'type'            => $c->type->value,
            'title'           => $c->title ?? ($c->type === ConversationType::Bot ? 'BOT Minutor' : 'Conversa'),
            'customer'        => $c->customer ? ['id' => $c->customer->id, 'name' => $c->customer->name] : null,
            'last_message'    => $last ? [
                'id'         => $last->id,
                'type'       => $last->type->value,
                'severity'   => $last->metadata['severity'] ?? null,
                'preview'    => mb_substr(strip_tags($last->body), 0, 120),
                'created_at' => $last->created_at?->toIso8601String(),
            ] : null,
            'unread_count'        => $unreadBy['total'],
            'unread_by_severity'  => $unreadBy,
            'last_message_at'     => $c->last_message_at?->toIso8601String(),
        ];
    }
}
