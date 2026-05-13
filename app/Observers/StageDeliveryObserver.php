<?php

namespace App\Observers;

use App\Models\DeliveryEvent;
use App\Models\StageDelivery;
use Illuminate\Support\Facades\Auth;

class StageDeliveryObserver
{
    public function created(StageDelivery $delivery): void
    {
        $this->log($delivery, DeliveryEvent::TYPE_CREATED, [
            'title'                => $delivery->title,
            'status'               => $delivery->status,
            'responsible_user_id'  => $delivery->responsible_user_id,
        ]);
    }

    public function updated(StageDelivery $delivery): void
    {
        $original = $delivery->getOriginal();

        if (array_key_exists('status', $delivery->getChanges())) {
            $this->log($delivery, DeliveryEvent::TYPE_STATUS_CHANGED, [
                'from' => $original['status'] ?? null,
                'to'   => $delivery->status,
            ]);

            if ($delivery->status === StageDelivery::STATUS_DONE && empty($original['completed_at'])) {
                $delivery->forceFill(['completed_at' => now()])->saveQuietly();
                $this->log($delivery, DeliveryEvent::TYPE_COMPLETED, [
                    'completed_at' => $delivery->completed_at?->toIso8601String(),
                ]);
            }
        }

        if (array_key_exists('responsible_user_id', $delivery->getChanges())) {
            $this->log($delivery, DeliveryEvent::TYPE_REASSIGNED, [
                'from' => $original['responsible_user_id'] ?? null,
                'to'   => $delivery->responsible_user_id,
            ]);
        }
    }

    private function log(StageDelivery $delivery, string $type, array $payload): void
    {
        DeliveryEvent::create([
            'delivery_id'   => $delivery->id,
            'actor_user_id' => Auth::id(),
            'type'          => $type,
            'payload'       => $payload,
        ]);
    }
}
