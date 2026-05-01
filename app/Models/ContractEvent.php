<?php

namespace App\Models;

use App\Events\ContractEventCreated;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
            $event->sequence_number = (int) static::where('contract_id', $event->contract_id)
                ->max('sequence_number') + 1;
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
