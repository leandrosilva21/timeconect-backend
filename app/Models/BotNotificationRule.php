<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BotNotificationRule extends Model
{
    protected $fillable = [
        'name', 'trigger_event', 'severity_min',
        'target_type', 'target_value', 'channel', 'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('active', true);
    }

    public function scopeForEvent(Builder $q, string $eventClass): Builder
    {
        return $q->where('trigger_event', $eventClass);
    }
}
