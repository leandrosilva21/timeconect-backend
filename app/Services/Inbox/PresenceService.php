<?php

namespace App\Services\Inbox;

use App\Enums\PresenceStatus;
use App\Models\User;
use App\Models\UserPresence;
use Carbon\Carbon;

class PresenceService
{
    public const AWAY_AFTER_MINUTES    = 5;
    public const OFFLINE_AFTER_MINUTES = 15;

    public function heartbeat(User $user, PresenceStatus $status = PresenceStatus::Online): UserPresence
    {
        return UserPresence::updateOrCreate(
            ['user_id' => $user->id],
            [
                'status'       => $status,
                'last_seen_at' => now(),
            ],
        );
    }

    /**
     * Status efetivo considera last_seen_at: stale heartbeat vira away/offline.
     */
    public function effectiveStatus(?UserPresence $presence): PresenceStatus
    {
        if (! $presence || ! $presence->last_seen_at) {
            return PresenceStatus::Offline;
        }

        $minutesAgo = $presence->last_seen_at->diffInMinutes(now());

        if ($minutesAgo >= self::OFFLINE_AFTER_MINUTES) {
            return PresenceStatus::Offline;
        }
        if ($minutesAgo >= self::AWAY_AFTER_MINUTES) {
            return PresenceStatus::Away;
        }
        return PresenceStatus::Online;
    }

    /**
     * @return array<int,array{user_id:int,status:string,last_seen_at:?string}>
     */
    public function snapshot(array $userIds = []): array
    {
        $q = UserPresence::query();
        if ($userIds) {
            $q->whereIn('user_id', $userIds);
        }

        return $q->get()->map(function (UserPresence $p) {
            return [
                'user_id'      => $p->user_id,
                'status'       => $this->effectiveStatus($p)->value,
                'last_seen_at' => $p->last_seen_at?->toIso8601String(),
            ];
        })->all();
    }
}
