<?php

namespace App\Models;

use App\Events\ContractEventCreated;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class ContractEvent extends Model
{
    protected $fillable = [
        'contract_id',
        'sequence_number',
        'event_type',
        'field',
        'from_value',
        'to_value',
        'triggered_by',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    protected $dispatchesEvents = [
        'created' => ContractEventCreated::class,
    ];

    protected static function booted(): void
    {
        static::creating(function (ContractEvent $event) {
            // Geração atômica via INSERT ON CONFLICT — sem race condition
            $row = DB::selectOne(
                "INSERT INTO contract_sequences (contract_id, last_sequence)
                 VALUES (?, 1)
                 ON CONFLICT (contract_id)
                 DO UPDATE SET last_sequence = contract_sequences.last_sequence + 1
                 RETURNING last_sequence",
                [$event->contract_id]
            );
            $event->sequence_number = (int) $row->last_sequence;
        });
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}
