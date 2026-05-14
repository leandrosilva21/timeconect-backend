<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProjectStage extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_DONE   = 'done';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_PAUSED,
        self::STATUS_DONE,
    ];

    protected $fillable = [
        'project_id',
        'name',
        'responsible_user_id',
        'hours_planned',
        'status',
        'order_index',
        'expected_end_date',
    ];

    protected $casts = [
        'hours_planned'      => 'decimal:2',
        'order_index'        => 'integer',
        'expected_end_date'  => 'date:Y-m-d',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(StageDelivery::class, 'stage_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(StageAllocation::class, 'stage_id');
    }

    public function aportes(): HasMany
    {
        return $this->hasMany(StageHourAporte::class, 'stage_id')->orderByDesc('created_at');
    }

    public function timesheets(): HasMany
    {
        return $this->hasMany(Timesheet::class, 'stage_id');
    }
}
