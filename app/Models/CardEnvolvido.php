<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CardEnvolvido extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'card_envolvidos';

    public const TYPE_REQUEST = 'contract_request';
    public const TYPE_PROJECT = 'project';
    public const SIDE_INTERNAL = 'internal';
    public const SIDE_CLIENT   = 'client';

    protected $fillable = [
        'card_type', 'card_id',
        'user_id', 'external_email',
        'side', 'added_by',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    /**
     * Display name: do user OR do email externo.
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->user?->name ?? $this->external_email ?? '—';
    }

    /**
     * Email destino: prioriza user.email, fallback external_email.
     */
    public function getNotificationEmailAttribute(): ?string
    {
        return $this->user?->email ?? $this->external_email;
    }

    public function scopeForCard($q, string $type, int $id)
    {
        return $q->where('card_type', $type)->where('card_id', $id);
    }

    public function scopeInternal($q)  { return $q->where('side', self::SIDE_INTERNAL); }
    public function scopeClient($q)    { return $q->where('side', self::SIDE_CLIENT); }
}
