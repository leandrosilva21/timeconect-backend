<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Partner extends Model
{
    use HasFactory, SoftDeletes;

    const PRICING_FIXED    = 'fixed';
    const PRICING_VARIABLE = 'variable';

    protected $fillable = [
        'name',
        'document',
        'email',
        'fechamento_email',
        'phone',
        'active',
        'pricing_type',
        'hourly_rate',
    ];

    protected $casts = [
        'active'       => 'boolean',
        'hourly_rate'  => 'string',
        'created_at'   => 'datetime',
        'updated_at'   => 'datetime',
        'deleted_at'   => 'datetime',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
