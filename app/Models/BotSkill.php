<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BotSkill extends Model
{
    protected $fillable = [
        'slug', 'name', 'description', 'rule_type', 'config', 'severity', 'active',
    ];

    protected $casts = [
        'config' => 'array',
        'active' => 'boolean',
    ];

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('active', true);
    }
}
