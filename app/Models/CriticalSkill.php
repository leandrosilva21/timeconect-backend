<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CriticalSkill extends Model
{
    protected $fillable = ['skill_id', 'min_level_id', 'context'];

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    public function minLevel(): BelongsTo
    {
        return $this->belongsTo(SkillLevel::class, 'min_level_id');
    }
}
