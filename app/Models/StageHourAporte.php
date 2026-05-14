<?php

namespace App\Models;

use App\Observers\StageHourAporteObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro imutável de aporte de horas em uma etapa.
 * Ver ADR 0004.
 */
#[ObservedBy([StageHourAporteObserver::class])]
class StageHourAporte extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'stage_hour_aportes';

    protected $fillable = [
        'stage_id',
        'user_id',
        'hours',
        'reason',
    ];

    protected $casts = [
        'hours'      => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function stage(): BelongsTo
    {
        return $this->belongsTo(ProjectStage::class, 'stage_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
