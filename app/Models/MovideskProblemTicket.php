<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MovideskProblemTicket extends Model
{
    protected $fillable = [
        'ticket_id',
        'attempts',
        'last_error',
        'last_attempt_at',
        'blacklisted_at',
    ];

    protected $casts = [
        'attempts'        => 'integer',
        'last_attempt_at' => 'datetime',
        'blacklisted_at'  => 'datetime',
    ];

    public const MAX_ATTEMPTS = 3;

    public function scopeRetryable(Builder $q): Builder
    {
        return $q->whereNull('blacklisted_at')->where('attempts', '<', self::MAX_ATTEMPTS);
    }

    public function scopeBlacklisted(Builder $q): Builder
    {
        return $q->whereNotNull('blacklisted_at');
    }
}
