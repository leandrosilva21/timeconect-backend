<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evento append-only da timeline operacional de uma etapa.
 * Ver ADR 0005.
 */
class StageActivityEvent extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    public const TYPE_DELIVERY_MOVED     = 'delivery_moved';
    public const TYPE_DELIVERY_CREATED   = 'delivery_created';
    public const TYPE_DELIVERY_COMPLETED = 'delivery_completed';
    public const TYPE_HOURS_LOGGED       = 'hours_logged';
    public const TYPE_APORTE_CREATED     = 'aporte_created';
    public const TYPE_BLOCK_SET          = 'block_set';
    public const TYPE_BLOCK_CLEARED      = 'block_cleared';
    public const TYPE_COMMENT            = 'comment';

    protected $fillable = [
        'stage_id',
        'actor_user_id',
        'type',
        'payload',
        'attachment_path',
        'attachment_original_name',
        'attachment_mime',
        'attachment_size',
    ];

    protected $casts = [
        'payload'    => 'array',
        'created_at' => 'datetime',
    ];

    public function stage(): BelongsTo
    {
        return $this->belongsTo(ProjectStage::class, 'stage_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
