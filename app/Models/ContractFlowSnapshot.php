<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractFlowSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'contract_id',
        'status',
        'kanban_status',
        'sustentacao_column',
        'project_status',
        'project_id',
        'category',
        'updated_by',
        'version',
        'inconsistency_count',
        'last_event_id',
        'last_sequence',
        'replay_in_progress',
    ];

    protected $casts = [
        'updated_at' => 'datetime',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
