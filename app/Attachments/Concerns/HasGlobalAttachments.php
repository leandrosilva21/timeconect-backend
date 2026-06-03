<?php

namespace App\Attachments\Concerns;

use App\Models\Attachment;
use Illuminate\Database\Eloquent\Collection;

/**
 * FASE 11.4-prep — Reader-shim pra models que ainda têm coluna legada de anexo.
 *
 * Cada model que use a trait ganha 2 helpers:
 *   - $expense->latestAttachment('receipt')           — último não-deletado da categoria
 *   - $expense->allAttachments('receipt')             — coleção completa
 *
 * Cada model define entityType() (estática) que mapeia pro registry.
 * O accessor legado (receipt_url, attachment_url, profile_photo_url) pode
 * verificar latestAttachment() PRIMEIRO e cair na coluna velha se vazio.
 *
 * Quando a FASE 11.4 dropar a coluna legada, o fallback desaparece — o
 * accessor passa a depender só de latestAttachment(). Compatibilidade
 * preservada porque o cliente do API continua chamando o mesmo accessor.
 */
trait HasGlobalAttachments
{
    /**
     * Cada model que usar a trait deve retornar a chave do registry.
     * Ex: User → 'USER', Expense → 'EXPENSE', Timesheet → 'TIMESHEET'.
     */
    abstract public static function attachmentEntityType(): string;

    /**
     * Último attachment vivo (não deletado) da entidade pra uma category.
     * Retorna null se não há.
     *
     * Usa scope forEntity + ofCategory (índices compostos do schema).
     * Performance: 1 query por chamada — caller que precisa de listas deve
     * usar AttachmentService::aggregateLoader pra evitar N+1.
     */
    public function latestAttachment(string $category): ?Attachment
    {
        return Attachment::query()
            ->forEntity(static::attachmentEntityType(), (int) $this->getKey())
            ->ofCategory($category)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * Todos os attachments vivos da entidade (opcionalmente filtrados por category).
     * Ordem cronológica crescente (primeiro adicionado primeiro).
     */
    public function allAttachments(?string $category = null): Collection
    {
        $q = Attachment::query()
            ->forEntity(static::attachmentEntityType(), (int) $this->getKey())
            ->whereNull('deleted_at')
            ->orderBy('created_at');
        if ($category !== null) $q->ofCategory($category);
        return $q->get();
    }

    /**
     * URL canônica de download de um attachment dessa entidade — sempre via
     * /api/v1/attachments/{id}/download (autenticado). Usado pelos accessors
     * legados (receipt_url, attachment_url, profile_photo_url) pra preferir
     * a nova camada quando há attachment.
     *
     * Retorna null se não há attachment vivo dessa categoria (caller cai no
     * fallback legado).
     */
    public function attachmentUrl(string $category): ?string
    {
        $att = $this->latestAttachment($category);
        if (!$att) return null;
        return '/api/v1/attachments/' . $att->id . '/download';
    }
}
