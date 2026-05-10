<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimesheetLog extends Model
{
    public $timestamps = false;

    public const UPDATED_AT = null;

    protected $fillable = [
        'timesheet_id',
        'changed_by',
        'source',
        'action',
        'changes',
    ];

    protected $casts = [
        'changes'    => 'array',
        'created_at' => 'datetime',
    ];

    public function timesheet(): BelongsTo
    {
        return $this->belongsTo(Timesheet::class)->withTrashed();
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
