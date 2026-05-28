<?php

namespace App\Attachments\Concerns;

use App\Attachments\AttachmentService;
use App\Models\Attachment;
use App\Models\StageActivityEvent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * FASE 11.2 — Trait para dual-write do attachment em stage_activity_events.
 *
 * Reutilizada por:
 *  - ProjectStageController     (comentário em etapa de cronograma)
 *  - StageDeliveryController    (comentário em entrega/atividade)
 *  - ClientActivityController   (portal cliente posta comentário com anexo)
 *
 * Os 3 controllers seguem o mesmo padrão: cria StageActivityEvent com 4 colunas
 * de attachment + arquivo físico em `public/stage-attachments/{stage_id}/`. A
 * trait extrai isso. Falha não-fatal — coluna legada continua sendo fonte de
 * verdade até FASE 11.4.
 */
trait DualWritesStageAttachment
{
    /**
     * @param StageActivityEvent $event   Já persistido (precisa do id).
     * @param string             $path    Mesmo storage path do legado (zero duplicação física).
     * @param string             $originalName
     * @param string|null        $mime
     */
    protected function dualWriteStageAttachment(StageActivityEvent $event, string $path, string $originalName, ?string $mime): void
    {
        try {
            $actor = Auth::user() ?? ($event->actor_user_id ? \App\Models\User::find($event->actor_user_id) : null);
            if (!$actor) return;

            app(AttachmentService::class)->registerExisting($actor, [
                'entity_type'   => 'STAGE_ACTIVITY_EVENT',
                'entity_id'     => $event->id,
                'category'      => 'attachment',
                'storage_path'  => $path,
                'original_name' => $originalName,
                'mime_type'     => $mime ?: 'application/octet-stream',
            ]);
        } catch (\Throwable $e) {
            Log::warning('FASE11 dual-write STAGE_ACTIVITY_EVENT.attachment falhou (não-fatal)', [
                'event_id' => $event->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    protected function dualSoftDeleteStageAttachments(StageActivityEvent $event): void
    {
        try {
            Attachment::query()
                ->forEntity('STAGE_ACTIVITY_EVENT', $event->id)
                ->ofCategory('attachment')
                ->whereNull('deleted_at')
                ->get()
                ->each(fn ($att) => $att->delete()); // SoftDeletes
        } catch (\Throwable $e) {
            Log::warning('FASE11 dual-delete STAGE_ACTIVITY_EVENT.attachment falhou (não-fatal)', [
                'event_id' => $event->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}
