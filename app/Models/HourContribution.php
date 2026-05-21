<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HourContribution extends Model
{
    use HasFactory, SoftDeletes;
    
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'project_id',
        'contributed_hours',
        'hourly_rate',
        'description',
        'motivo',
        'contributed_by',
        'contributed_at',
    ];

    // Motivos do aporte: aporte | excedentes | absorvidas
    public const MOTIVOS = ['aporte', 'excedentes', 'absorvidas'];
    
    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'contributed_hours' => 'decimal:2',
        'hourly_rate' => 'decimal:2',
        'contributed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
    
    /**
     * Relacionamento com projeto
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
    
    /**
     * Relacionamento com usuário que registrou o aporte
     */
    public function contributedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contributed_by');
    }
    
    /**
     * Calcular o valor total deste aporte
     *
     * @return float
     */
    public function getTotalValue(): float
    {
        return round($this->contributed_hours * $this->hourly_rate, 2);
    }
    
    /**
     * Accessor para valor total
     */
    public function getTotalValueAttribute(): float
    {
        return $this->getTotalValue();
    }
}
