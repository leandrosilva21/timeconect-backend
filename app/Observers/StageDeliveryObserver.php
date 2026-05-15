<?php

namespace App\Observers;

use App\Models\DeliveryEvent;
use App\Models\ProjectStage;
use App\Models\StageActivityEvent;
use App\Models\StageDelivery;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StageDeliveryObserver
{
    public function created(StageDelivery $delivery): void
    {
        $this->log($delivery, DeliveryEvent::TYPE_CREATED, [
            'title'                => $delivery->title,
            'status'               => $delivery->status,
            'responsible_user_id'  => $delivery->responsible_user_id,
        ]);

        $this->logStageActivity($delivery, StageActivityEvent::TYPE_DELIVERY_CREATED, [
            'delivery_id' => $delivery->id,
            'title'       => $delivery->title,
            'status'      => $delivery->status,
        ]);

        $this->recalcStageDates($delivery->stage_id);
    }

    public function updated(StageDelivery $delivery): void
    {
        $original = $delivery->getOriginal();

        if (array_key_exists('status', $delivery->getChanges())) {
            $this->log($delivery, DeliveryEvent::TYPE_STATUS_CHANGED, [
                'from' => $original['status'] ?? null,
                'to'   => $delivery->status,
            ]);

            $this->logStageActivity($delivery, StageActivityEvent::TYPE_DELIVERY_MOVED, [
                'delivery_id' => $delivery->id,
                'title'       => $delivery->title,
                'from'        => $original['status'] ?? null,
                'to'          => $delivery->status,
            ]);

            // Auto-set actual_start_at: primeiro move out of backlog (ADR 0009)
            $movedOutOfBacklog = ($original['status'] ?? null) === StageDelivery::STATUS_BACKLOG
                && $delivery->status !== StageDelivery::STATUS_BACKLOG;
            if ($movedOutOfBacklog && empty($original['actual_start_at'])) {
                $delivery->forceFill(['actual_start_at' => now()])->saveQuietly();
            }

            if ($delivery->status === StageDelivery::STATUS_DONE && empty($original['completed_at'])) {
                $delivery->forceFill(['completed_at' => now()])->saveQuietly();
                $this->log($delivery, DeliveryEvent::TYPE_COMPLETED, [
                    'completed_at' => $delivery->completed_at?->toIso8601String(),
                ]);

                $this->logStageActivity($delivery, StageActivityEvent::TYPE_DELIVERY_COMPLETED, [
                    'delivery_id'  => $delivery->id,
                    'title'        => $delivery->title,
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

        // Envolvimento opcional do cliente (toggle client_involved)
        $changes = $delivery->getChanges();
        if (array_key_exists('client_involved', $changes)) {
            $wasInvolved = (bool) ($original['client_involved'] ?? false);
            $nowInvolved = (bool) $delivery->client_involved;
            if (!$wasInvolved && $nowInvolved) {
                $this->logStageActivity($delivery, StageActivityEvent::TYPE_CLIENT_INVOLVED, [
                    'delivery_id'    => $delivery->id,
                    'title'          => $delivery->title,
                    'client_user_id' => $delivery->client_user_id,
                    'client_email'   => $delivery->client_email,
                ]);
            } elseif ($wasInvolved && !$nowInvolved) {
                $this->logStageActivity($delivery, StageActivityEvent::TYPE_CLIENT_REMOVED, [
                    'delivery_id'  => $delivery->id,
                    'title'        => $delivery->title,
                ]);
            }
        }

        $touchesStageDates = array_intersect(
            array_keys($delivery->getChanges()),
            ['status', 'actual_start_at', 'completed_at', 'stage_id']
        );
        if (!empty($touchesStageDates)) {
            $this->recalcStageDates($delivery->stage_id);
            if (!empty($original['stage_id']) && $original['stage_id'] !== $delivery->stage_id) {
                $this->recalcStageDates((int) $original['stage_id']);
            }
        }
    }

    public function deleted(StageDelivery $delivery): void
    {
        $this->recalcStageDates($delivery->stage_id);
    }

    /**
     * Rollup das datas reais da etapa a partir das atividades.
     *
     * - `actual_start_at` = min(deliveries.actual_start_at) — ignora null.
     * - `actual_end_at`   = max(deliveries.completed_at) APENAS se todas as
     *   atividades da etapa estão `done`. Se alguma não está done, é null.
     *
     * Usa `saveQuietly()` para evitar disparar observers em loop.
     */
    private function recalcStageDates(?int $stageId): void
    {
        if (!$stageId) return;

        $stage = ProjectStage::find($stageId);
        if (!$stage) return;

        $row = DB::table('stage_deliveries')
            ->where('stage_id', $stageId)
            ->whereNull('deleted_at')
            ->selectRaw('
                MIN(actual_start_at) AS min_start,
                MAX(completed_at) AS max_end,
                COUNT(*) AS total,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS done_count
            ', [StageDelivery::STATUS_DONE])
            ->first();

        $actualStart = $row?->min_start ?: null;
        $allDone = $row && (int) $row->total > 0 && (int) $row->total === (int) $row->done_count;
        $actualEnd = $allDone ? ($row->max_end ?: null) : null;

        $current = [
            'actual_start_at' => $stage->actual_start_at?->toDateTimeString(),
            'actual_end_at'   => $stage->actual_end_at?->toDateTimeString(),
        ];
        $next = [
            'actual_start_at' => $actualStart,
            'actual_end_at'   => $actualEnd,
        ];

        if ($current !== $next) {
            $stage->forceFill($next)->saveQuietly();
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

    private function logStageActivity(StageDelivery $delivery, string $type, array $payload): void
    {
        StageActivityEvent::create([
            'stage_id'      => $delivery->stage_id,
            'delivery_id'   => $delivery->id, // ADR 0008 — eventos da atividade carregam delivery_id
            'actor_user_id' => Auth::id(),
            'type'          => $type,
            'payload'       => $payload,
        ]);
    }
}
