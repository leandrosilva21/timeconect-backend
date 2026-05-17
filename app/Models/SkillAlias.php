<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SkillAlias extends Model
{
    protected $table = 'skill_aliases';

    protected $fillable = ['skill_id', 'alias'];

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }
}
