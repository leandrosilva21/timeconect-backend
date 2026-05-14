<?php

namespace App\Models;

use App\Observers\StageDeliveryObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ObservedBy([StageDeliveryObserver::class])]
class StageDelivery extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_BACKLOG         = 'backlog';
    public const STATUS_IN_PROGRESS     = 'in_progress';
    public const STATUS_WAITING_CLIENT  = 'waiting_client';
    public const STATUS_REVIEW          = 'review';
    public const STATUS_DONE            = 'done';

    public const STATUSES = [
        self::STATUS_BACKLOG,
        self::STATUS_IN_PROGRESS,
        self::STATUS_WAITING_CLIENT,
        self::STATUS_REVIEW,
        self::STATUS_DONE,
    ];

    public const PRIORITY_LOW    = 'low';
    public const PRIORITY_MEDIUM = 'medium';
    public const PRIORITY_HIGH   = 'high';

    public const PRIORITIES = [
        self::PRIORITY_LOW,
        self::PRIORITY_MEDIUM,
        self::PRIORITY_HIGH,
    ];

    protected $fillable = [
        'stage_id',
        'title',
        'description',
        'responsible_user_id',
        'hours_planned',
        'priority',
        'status',
        'due_date',
        'order_index',
        'completed_at',
        'planned_start_at',
        'actual_start_at',
        'depends_on_delivery_id',
    ];

    protected $casts = [
        'hours_planned'    => 'decimal:2',
        'order_index'      => 'integer',
        'due_date'         => 'date:Y-m-d',
        'completed_at'     => 'datetime',
        'planned_start_at' => 'date:Y-m-d',
        'actual_start_at'  => 'datetime',
    ];

    public function stage(): BelongsTo
    {
        return $this->belongsTo(ProjectStage::class, 'stage_id');
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function timesheets(): HasMany
    {
        return $this->hasMany(Timesheet::class, 'stage_delivery_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(DeliveryEvent::class, 'delivery_id')->orderByDesc('created_at');
    }

    /** Dependência leve (1 atividade depende de no máximo 1 outra). ADR 0009. */
    public function dependsOn(): BelongsTo
    {
        return $this->belongsTo(StageDelivery::class, 'depends_on_delivery_id');
    }

    public function dependents(): HasMany
    {
        return $this->hasMany(StageDelivery::class, 'depends_on_delivery_id');
    }
}
