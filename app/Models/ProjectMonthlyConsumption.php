<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectMonthlyConsumption extends Model
{
    protected $fillable = [
        'project_id',
        'year_month',
        'consumed_minutes',
    ];

    protected $casts = [
        'consumed_minutes' => 'integer',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
