<?php

namespace App\Services\Inbox;

use App\Enums\ConversationType;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ConversationService
{
    /**
     * Cria (ou retorna) a DM entre dois usuários. Idempotente.
     */
    public function createDirect(User $a, User $b): Conversation
    {
        if ($a->id === $b->id) {
            throw new \InvalidArgumentException('Direct conversation requires two distinct users.');
        }

        [$x, $y] = $a->id < $b->id ? [$a->id, $b->id] : [$b->id, $a->id];

        $candidates = Conversation::query()
            ->where('type', ConversationType::Direct->value)
            ->whereHas('participants', fn ($q) => $q->where('user_id', $x))
            ->whereHas('participants', fn ($q) => $q->where('user_id', $y))
            ->withCount('participants')
            ->get();

        $existing = $candidates->first(fn (Conversation $c) => (int) $c->participants_count === 2);
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($a, $b) {
            $conv = Conversation::create([
                'type'            => ConversationType::Direct,
                'title'           => null,
                'created_by'      => $a->id,
                'last_message_at' => now(),
            ]);

            foreach ([$a->id, $b->id] as $uid) {
                ConversationParticipant::create([
                    'conversation_id' => $conv->id,
                    'user_id'         => $uid,
                    'role'            => 'member',
                    'joined_at'       => now(),
                ]);
            }

            return $conv;
        });
    }

    /**
     * Cria um grupo. Owner vira admin; demais entram como member.
     *
     * @param  array<int>  $participantIds  IDs além do owner
     */
    public function createGroup(User $owner, string $name, array $participantIds): Conversation
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Group name is required.');
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $participantIds), fn ($i) => $i > 0 && $i !== $owner->id)));

        return DB::transaction(function () use ($owner, $name, $ids) {
            $conv = Conversation::create([
                'type'            => ConversationType::Group,
                'title'           => $name,
                'created_by'      => $owner->id,
                'last_message_at' => now(),
            ]);

            ConversationParticipant::create([
                'conversation_id' => $conv->id,
                'user_id'         => $owner->id,
                'role'            => 'admin',
                'joined_at'       => now(),
            ]);

            foreach ($ids as $uid) {
                ConversationParticipant::create([
                    'conversation_id' => $conv->id,
                    'user_id'         => $uid,
                    'role'            => 'member',
                    'joined_at'       => now(),
                ]);
            }

            return $conv;
        });
    }

    /**
     * Adiciona participante ao grupo. Só admin do grupo (ou criador) pode adicionar.
     */
    public function addParticipant(Conversation $conv, User $newMember, User $by): ConversationParticipant
    {
        $this->assertGroupAdmin($conv, $by);

        return ConversationParticipant::firstOrCreate(
            ['conversation_id' => $conv->id, 'user_id' => $newMember->id],
            ['role' => 'member', 'joined_at' => now()],
        );
    }

    /**
     * Remove participante. Admin pode remover qualquer um; member pode remover apenas a si mesmo.
     */
    public function removeParticipant(Conversation $conv, int $userId, User $by): void
    {
        if ($conv->type !== ConversationType::Group) {
            throw new \DomainException('Only groups support participant changes.');
        }

        $isSelf = $by->id === $userId;
        if (! $isSelf) {
            $this->assertGroupAdmin($conv, $by);
        }

        if ($userId === (int) $conv->created_by) {
            throw new \DomainException('Cannot remove the group creator.');
        }

        ConversationParticipant::where('conversation_id', $conv->id)
            ->where('user_id', $userId)
            ->delete();
    }

    private function assertGroupAdmin(Conversation $conv, User $by): void
    {
        if ($conv->type !== ConversationType::Group) {
            throw new \DomainException('Only groups support admin operations.');
        }
        if ((int) $conv->created_by === $by->id) {
            return;
        }
        $p = ConversationParticipant::where('conversation_id', $conv->id)
            ->where('user_id', $by->id)
            ->first();
        if (! $p || $p->role !== 'admin') {
            throw new \DomainException('Only group admins can change participants.');
        }
    }
}
