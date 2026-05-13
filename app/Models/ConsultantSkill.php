<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsultantSkill extends Model
{
    protected $fillable = [
        'consultant_id',
        'skill_id',
        'level_id',
        'years_experience',
        'last_used_at',
        'source',
        'confidence',
        'notes',
    ];

    protected $casts = [
        'last_used_at'     => 'date',
        'years_experience' => 'integer',
    ];

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consultant_id');
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(SkillLevel::class, 'level_id');
    }
}
