<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectRequiredSkill extends Model
{
    protected $fillable = ['project_id', 'skill_id', 'min_level_id'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    public function minLevel(): BelongsTo
    {
        return $this->belongsTo(SkillLevel::class, 'min_level_id');
    }
}
