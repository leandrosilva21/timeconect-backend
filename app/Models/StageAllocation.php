<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alocação operacional de um consultor numa etapa.
 *
 * **Persiste apenas `planned_hours`.** `actual_hours` e `remaining_hours` são
 * sempre derivados em query — ver ADR 0003. Não criar coluna pra estes;
 * não criar Observer pra atualizar — risco de drift e race condition.
 */
class StageAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'stage_id',
        'delivery_id',
        'user_id',
        'planned_hours',
        'allocation_start_at',
        'allocation_end_at',
        'is_primary',
    ];

    protected $casts = [
        'planned_hours'       => 'decimal:2',
        'allocation_start_at' => 'date:Y-m-d',
        'allocation_end_at'   => 'date:Y-m-d',
        'is_primary'          => 'boolean',
    ];

    public function stage(): BelongsTo
    {
        return $this->belongsTo(ProjectStage::class, 'stage_id');
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(StageDelivery::class, 'delivery_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
