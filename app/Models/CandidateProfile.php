<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CandidateProfile extends Model
{
    public const STATUSES = ['new','screening','interview','approved','rejected','hired','allocated'];

    protected $fillable = [
        'user_id', 'status', 'score_initial', 'interest_level', 'expected_rate', 'notes',
    ];

    protected $casts = [
        'score_initial' => 'float',
        'expected_rate' => 'float',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
