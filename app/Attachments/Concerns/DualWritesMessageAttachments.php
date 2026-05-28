<?php

namespace App\Attachments\Concerns;

use App\Attachments\AttachmentService;
use App\Models\Attachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * FASE 11.2 — Trait pra dual-write de anexos dos 3 chats do Minutor.
 *
 * Tabelas legadas idênticas em schema:
 *  - project_message_attachments
 *  - contract_message_attachments
 *  - contract_request_message_attachments
 *
 * O que MUDA entre os 3:
 *  - Storage prefix (`message-attachments/`, `contract-message-attachments/`,
 *    `req-message-attachments/`)
 *  - entity_type do registry (PROJECT_MESSAGE, CONTRACT_MESSAGE, REQUEST_MESSAGE)
 *
 * Cada mensagem pode ter N anexos — chamada por arquivo. Falha não-fatal — a
 * tabela legada continua sendo fonte de verdade até FASE 11.4.
 */
trait DualWritesMessageAttachments
{
    /**
     * Registra UM attachment paralelo apontando pro MESMO arquivo legado.
     *
     * @param string       $entityType  PROJECT_MESSAGE | CONTRACT_MESSAGE | REQUEST_MESSAGE
     * @param int          $messageId   ID da mensagem na tabela do controller
     * @param UploadedFile $file        Arquivo já gravado (precisa do mime/original)
     * @param string       $storagePath Path canonical onde o legado gravou
     */
    protected function dualWriteMessageAttachment(string $entityType, int $messageId, UploadedFile $file, string $storagePath): void
    {
        try {
            $actor = Auth::user();
            if (!$actor) return;

            app(AttachmentService::class)->registerExisting($actor, [
                'entity_type'   => $entityType,
                'entity_id'     => $messageId,
                'category'      => 'attachment',
                'storage_path'  => $storagePath,
                'original_name' => $file->getClientOriginalName(),
                'mime_type'     => $file->getMimeType() ?: 'application/octet-stream',
            ]);
        } catch (\Throwable $e) {
            Log::warning("FASE11 dual-write {$entityType}.attachment falhou (não-fatal)", [
                'message_id' => $messageId,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
