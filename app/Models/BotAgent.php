<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BotAgent extends Model
{
    protected $fillable = [
        'slug', 'name', 'role_description', 'system_prompt',
        'model_override', 'temperature_override',
        'active', 'priority', 'min_severity',
        'cooldown_minutes', 'max_per_day',
        'trigger_conditions',
    ];

    protected $casts = [
        'active'               => 'boolean',
        'temperature_override' => 'decimal:2',
        'trigger_conditions'   => 'array',
    ];

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('active', true);
    }

    public function scopeByPriority(Builder $q): Builder
    {
        return $q->orderBy('priority');
    }

    public function shouldRunFor(array $context): bool
    {
        if (! $this->active) {
            return false;
        }

        $cond = $this->trigger_conditions ?? [];

        if (isset($cond['always']) && $cond['always']) {
            return true;
        }
        if (isset($cond['classification']) && in_array($context['classification'] ?? '', (array) $cond['classification'], true)) {
            return true;
        }
        if (isset($cond['tickets_gt']) && (int) ($context['tickets'] ?? 0) > (int) $cond['tickets_gt']) {
            return true;
        }
        if (isset($cond['score_gt']) && (int) ($context['score'] ?? 0) > (int) $cond['score_gt']) {
            return true;
        }

        return false;
    }
}
