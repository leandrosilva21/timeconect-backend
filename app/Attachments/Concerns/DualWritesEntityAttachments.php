<?php

namespace App\Attachments\Concerns;

use App\Attachments\AttachmentService;
use App\Models\Attachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * FASE 11.2 — Trait pra dual-write de anexos diretos de entidade.
 *
 * Reutilizada por:
 *  - ProjectController::uploadAttachment / deleteAttachment
 *  - ContractController::uploadAttachment / deleteAttachment
 *
 * O legado guarda `type` em pt-BR (proposta/contrato/logo/...) — o registry usa
 * en-US (proposal/contract/logo/...). Mapeamento centralizado aqui.
 */
trait DualWritesEntityAttachments
{
    /**
     * Mapa type-legado-pt → category-registry-en.
     * Mantém valores não-mapeados como 'attachment' (fallback seguro).
     * Protected pra subclasses (ex.: backfill modules) reutilizarem.
     */
    protected static function mapAttachmentTypeToCategory(string $legacyType): string
    {
        return match (strtolower($legacyType)) {
            'proposta'           => 'proposal',
            'contrato'           => 'contract',
            'logo'               => 'logo',
            'aprovacao_cliente'  => 'client_approval',
            'evidencia'          => 'evidence',
            'imagem'             => 'image',
            'outro'              => 'attachment',
            default              => 'attachment',
        };
    }

    /**
     * Dual-write de UM anexo direto da entidade (Project ou Contract).
     *
     * @param string       $entityType 'PROJECT' | 'CONTRACT'
     * @param int          $entityId
     * @param string       $legacyType type do legado (proposta/contrato/logo/...)
     * @param UploadedFile $file
     * @param string       $storagePath
     */
    protected function dualWriteEntityAttachment(string $entityType, int $entityId, string $legacyType, UploadedFile $file, string $storagePath): void
    {
        try {
            $actor = Auth::user();
            if (!$actor) return;
            app(AttachmentService::class)->registerExisting($actor, [
                'entity_type'   => $entityType,
                'entity_id'     => $entityId,
                'category'      => self::mapAttachmentTypeToCategory($legacyType),
                'storage_path'  => $storagePath,
                'original_name' => $file->getClientOriginalName(),
                'mime_type'     => $file->getMimeType() ?: 'application/octet-stream',
                'metadata'      => ['legacy_type' => $legacyType],
            ]);
        } catch (\Throwable $e) {
            Log::warning("FASE11 dual-write {$entityType}.{$legacyType} falhou (não-fatal)", [
                'entity_id' => $entityId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Dual-soft-delete: encontra a row em attachments correspondente ao path do
     * legado e soft-deleta. Usado quando o usuário deleta o anexo via endpoint
     * legado (que apaga arquivo físico + row legada).
     */
    protected function dualSoftDeleteEntityAttachmentByPath(string $entityType, int $entityId, string $storagePath): void
    {
        try {
            Attachment::query()
                ->forEntity($entityType, $entityId)
                ->where('storage_path', $storagePath)
                ->whereNull('deleted_at')
                ->get()
                ->each(fn ($att) => $att->delete()); // SoftDeletes
        } catch (\Throwable $e) {
            Log::warning("FASE11 dual-delete {$entityType}.attachment falhou (não-fatal)", [
                'entity_id'    => $entityId,
                'storage_path' => $storagePath,
                'error'        => $e->getMessage(),
            ]);
        }
    }
}
