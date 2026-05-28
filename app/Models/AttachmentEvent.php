<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FASE 11 — Evento de auditoria do AttachmentService.
 *
 * Não tem timestamps Eloquent (só created_at, set pelo DB com useCurrent).
 * Eventos são imutáveis: nunca update, nunca delete em código.
 */
class AttachmentEvent extends Model
{
    protected $table = 'attachment_events';

    public $timestamps = false;

    protected $fillable = [
        'attachment_id',
        'event_type',
        'actor_user_id',
        'ip',
        'user_agent',
        'metadata',
    ];

    protected $casts = [
        'attachment_id' => 'integer',
        'actor_user_id' => 'integer',
        'metadata'      => 'array',
        'created_at'    => 'datetime',
    ];

    // Lista oficial — espelha o CHECK constraint do DB.
    public const TYPE_UPLOADED          = 'uploaded';
    public const TYPE_DOWNLOADED        = 'downloaded';
    public const TYPE_URL_SIGNED        = 'url_signed';
    public const TYPE_SOFT_DELETED      = 'soft_deleted';
    public const TYPE_RESTORED          = 'restored';
    public const TYPE_INTEGRITY_FAIL    = 'integrity_fail';
    public const TYPE_MIME_VIOLATION    = 'mime_violation';
    public const TYPE_SIZE_EXCEEDED     = 'size_exceeded';
    public const TYPE_PERMISSION_DENIED = 'permission_denied';

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
