<?php

namespace App\Attachments\Storage;

use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * FASE 11 — Abstração de storage de anexos.
 *
 * AttachmentService NUNCA toca Storage:: do Laravel diretamente. Tudo passa por
 * uma impl desta interface. Isso permite evolução pra S3/R2/Supabase sem refator
 * estrutural — adiciona-se um novo provider, registra no container, atualiza
 * o CHECK constraint da tabela (e o LocalStorageProvider continua funcionando
 * pros arquivos legados).
 *
 * Convenção de keys (paths opacos):
 *  - Novos uploads (FASE 11): "attachments/{entity_type_lower}/{entity_id}/{uuid}_{checksum8}.{ext}"
 *  - Arquivos legados (pós-backfill): mantêm o path original (ex: "public/receipts/2026/05/foo.pdf")
 */
interface StorageProvider
{
    /**
     * Nome curto do provider — bate com attachments.storage_provider.
     * Ex: 'local', 's3', 'r2', 'supabase'.
     */
    public function name(): string;

    /**
     * Guarda o conteúdo binário e devolve a key opaca (storage_path).
     * NÃO retorna URL — chame `url()` separado quando precisar.
     */
    public function put(string $key, string $content, ?string $mimeType = null): void;

    /**
     * Idem `put()` mas a partir de um UploadedFile (evita carregar todo o blob em memória).
     */
    public function putUploaded(string $key, UploadedFile $file): void;

    /**
     * Retorna o conteúdo binário. Lança \RuntimeException se ausente.
     */
    public function get(string $key): string;

    /**
     * Apaga o arquivo físico. AttachmentService raramente chama isso — preferir soft delete.
     * Usado por integrity check em cleanup explícito.
     */
    public function delete(string $key): void;

    /**
     * Existe no storage?
     */
    public function exists(string $key): bool;

    /**
     * URL assinada com TTL. Pra `local`, devolve a rota interna de download
     * (assinatura via Laravel signed URL); pra cloud, devolve a presigned URL real.
     */
    public function url(string $key, int $ttlSeconds = 300): string;

    /**
     * SHA-256 hex do arquivo armazenado. Usado pra integrity check e dedup.
     */
    public function checksum(string $key): string;

    /**
     * Tamanho em bytes do arquivo armazenado (consulta o storage, não confia no DB).
     */
    public function size(string $key): int;

    /**
     * Stream pra download (sem carregar tudo em memória). Caller seta headers próprios
     * de Content-Disposition (anexar nome amigável vem de attachments.original_name).
     */
    public function downloadStream(string $key): StreamedResponse;
}
