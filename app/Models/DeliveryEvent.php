<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryEvent extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    public const TYPE_CREATED        = 'created';
    public const TYPE_STATUS_CHANGED = 'status_changed';
    public const TYPE_REASSIGNED     = 'reassigned';
    public const TYPE_HOURS_LOGGED   = 'hours_logged';
    public const TYPE_HOURS_EDITED   = 'hours_edited';
    public const TYPE_COMPLETED      = 'completed';

    protected $fillable = [
        'delivery_id',
        'actor_user_id',
        'type',
        'payload',
    ];

    protected $casts = [
        'payload'    => 'array',
        'created_at' => 'datetime',
    ];

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(StageDelivery::class, 'delivery_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
