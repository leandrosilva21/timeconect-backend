<?php

namespace App\Models;

use App\Enums\PresenceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPresence extends Model
{
    protected $table = 'user_presence';
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    public const CREATED_AT = null;

    protected $fillable = ['user_id', 'status', 'last_seen_at'];

    protected $casts = [
        'status'       => PresenceStatus::class,
        'last_seen_at' => 'datetime',
        'updated_at'   => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
