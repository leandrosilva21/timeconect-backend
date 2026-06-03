<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerHourlyRateLog extends Model
{
    protected $fillable = [
        'partner_id',
        'changed_by',
        'old_hourly_rate',
        'new_hourly_rate',
        'effective_from',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'old_hourly_rate' => 'decimal:2',
            'new_hourly_rate' => 'decimal:2',
            'effective_from'  => 'date:Y-m-d',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
