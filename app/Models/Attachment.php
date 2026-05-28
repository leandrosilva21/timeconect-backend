<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * FASE 11 — Anexo global polimórfico.
 *
 * IMPORTANTE — anti-N+1:
 *  - NÃO existe relation morphTo entity ativa de propósito. Lazy load morph é
 *    fonte clássica de N+1 em listagens.
 *  - Para carregar a entidade-dona, use:
 *      \App\Attachments\EntityRegistry::resolve($att->entity_type)->find($att->entity_id)
 *    ou (recomendado pra listas):
 *      AttachmentService::aggregateLoader('PROJECT', $ids)
 *
 * IMPORTANTE — abstração oficial:
 *  - NUNCA: Attachment::where('project_id', $id)
 *  - SEMPRE: Attachment::forEntity('PROJECT', $id)
 *
 * Documento NUNCA é deletado físico — SoftDeletes garante restore futuro.
 */
class Attachment extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'attachments';

    protected $fillable = [
        'company_id',
        'entity_type',
        'entity_id',
        'category',
        'original_name',
        'file_name',
        'mime_type',
        'extension',
        'size_bytes',
        'checksum',
        'storage_provider',
        'storage_path',
        'visibility',
        'uploaded_by',
        'metadata',
    ];

    protected $casts = [
        'entity_id'   => 'integer',
        'size_bytes'  => 'integer',
        'company_id'  => 'integer',
        'uploaded_by' => 'integer',
        'metadata'    => 'array',
    ];

    /**
     * Uploader (único FK real).
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Timeline de auditoria do anexo (uploaded, downloaded, soft_deleted, ...).
     */
    public function events(): HasMany
    {
        return $this->hasMany(AttachmentEvent::class)->orderByDesc('created_at');
    }

    // ── Scopes oficiais ───────────────────────────────────────────────────────

    /**
     * Filtro polimórfico — abstração oficial. Nunca consultar por coluna de
     * negócio (project_id, expense_id) em código novo.
     */
    public function scopeForEntity(Builder $query, string $entityType, int $entityId): Builder
    {
        return $query->where('entity_type', $entityType)->where('entity_id', $entityId);
    }

    /**
     * Filtro polimórfico em lote — base do aggregateLoader (anti-N+1).
     */
    public function scopeForEntities(Builder $query, string $entityType, array $entityIds): Builder
    {
        return $query->where('entity_type', $entityType)->whereIn('entity_id', $entityIds);
    }

    /**
     * Filtro por categoria semântica (proposal, evidence, expense_receipt, ...).
     */
    public function scopeOfCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    /**
     * Visibilidade — pareado com permission_check do EntityRegistry pra autorização.
     */
    public function scopeVisible(Builder $query, string $visibility): Builder
    {
        return $query->where('visibility', $visibility);
    }

    // ── Helpers de exibição (puro display; lógica fica no service) ────────────

    /**
     * Tamanho legível humano (1.2 MB).
     */
    public function getHumanSizeAttribute(): string
    {
        $bytes = (int) $this->size_bytes;
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1048576) return number_format($bytes / 1024, 1, ',', '.') . ' KB';
        if ($bytes < 1073741824) return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
        return number_format($bytes / 1073741824, 1, ',', '.') . ' GB';
    }

    /**
     * Está soft-deletado?
     */
    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }
}
