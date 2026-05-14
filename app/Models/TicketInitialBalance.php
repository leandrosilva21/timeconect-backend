<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TicketInitialBalance extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'ticket',
        'customer_id',
        'project_id',
        'initial_minutes',
        'description',
        'created_by',
    ];

    protected $casts = [
        'initial_minutes' => 'integer',
        'customer_id'     => 'integer',
        'project_id'      => 'integer',
        'created_by'      => 'integer',
    ];

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function project(): BelongsTo  { return $this->belongsTo(Project::class); }
    public function creator(): BelongsTo  { return $this->belongsTo(User::class, 'created_by'); }
}
