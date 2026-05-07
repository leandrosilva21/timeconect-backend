<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectOpenPeriod extends Model
{
    protected $fillable = ['project_id', 'year_month', 'opened_by', 'closed_by', 'closed_at'];

    protected $casts = ['closed_at' => 'datetime'];

    public function isActive(): bool
    {
        return $this->closed_at === null;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
