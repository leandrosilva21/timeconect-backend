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
     * FASE 11.7 — Compat FE: os accessors legados (abaixo) PRECISAM ir no JSON.
     *
     * Após o PR 7b o FE de Contract/Kanban/Chat/Mensagens continua lendo os nomes
     * legados (`type`, `path`, `size`, `file_path`, `file_size`, `message_id`) — que
     * deixaram de ser colunas e viraram accessors. Sem `$appends`, accessor não
     * serializa: a relação devolvia o anexo SEM esses campos → o FE renderizava
     * label/tamanho vazios e sumia com o item nas telas que agrupam/condicionam por
     * eles ("aparece no upload, some no refresh"). `human_size` entra por robustez
     * (já vinha via AttachmentController::present(); aqui cobre qualquer serialização).
     */
    protected $appends = [
        'type',
        'path',
        'size',
        'file_path',
        'file_size',
        'message_id',
        'human_size',
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
     *
     * Sem argumento: retorna só rows não-soft-deleted (filtro semântico de "vivo"
     * usado pelos accessors de read). SoftDeletes já filtra deleted_at por default;
     * deixar o scope chamável sem arg simplifica os callers (`->visible()->latest()`).
     *
     * Com argumento: filtra também por nível ('admin' | 'internal' | 'client').
     */
    public function scopeVisible(Builder $query, ?string $visibility = null): Builder
    {
        if ($visibility !== null && $visibility !== '') {
            $query->where('visibility', $visibility);
        }
        return $query;
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

    // ── Compatibilidade FASE 11.7 (PR 7b) com payloads legados ────────────────
    //
    // Após o drop das 5 tabelas dedicadas, o FE de chat (ProjectMessages,
    // ContractMessages, ContractRequestMessages) continua lendo `att.file_path`,
    // `att.file_size`, `att.message_id`. Listagens de Project/Contract leem
    // `att.path`, `att.size`, `att.type`. Os accessors abaixo mantêm os nomes
    // legados publicáveis sem refator de FE (que vem em PR 8/futuro).

    /** Alias legado: messages chats usam file_path. */
    public function getFilePathAttribute(): ?string
    {
        return $this->storage_path;
    }

    /** Alias legado: messages chats usam file_size. */
    public function getFileSizeAttribute(): ?int
    {
        return $this->size_bytes;
    }

    /** Alias legado: messages chats usam message_id (== entity_id pra entity_type=*_MESSAGE). */
    public function getMessageIdAttribute(): int
    {
        return (int) $this->entity_id;
    }

    /** Alias legado: project_attachments/contract_attachments usam path. */
    public function getPathAttribute(): ?string
    {
        return $this->storage_path;
    }

    /** Alias legado: project_attachments/contract_attachments usam size (int bytes). */
    public function getSizeAttribute(): ?int
    {
        return $this->size_bytes;
    }

    /**
     * Alias legado: project_attachments/contract_attachments usam type (pt).
     * Inversa do map en→pt usado no register.
     */
    public function getTypeAttribute(): ?string
    {
        return match (strtolower((string) $this->category)) {
            'proposal'        => 'proposta',
            'contract'        => 'contrato',
            'logo'            => 'logo',
            'client_approval' => 'aprovacao_cliente',
            'evidence'        => 'evidencia',
            'image'           => 'imagem',
            'attachment'      => 'outro',
            default           => $this->category,
        };
    }
}
