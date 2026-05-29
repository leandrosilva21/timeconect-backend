<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HourContribution extends Model
{
    use HasFactory, SoftDeletes;
    use \App\Attachments\Concerns\HasGlobalAttachments;

    // FASE 11 — chave do registry global de anexos.
    public static function attachmentEntityType(): string { return 'HOUR_CONTRIBUTION'; }


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
        'kanban_status',
        'contributed_by',
        'contributed_at',
    ];

    // Motivos do aporte: aporte | excedentes | absorvidas
    public const MOTIVOS = ['aporte', 'excedentes', 'absorvidas'];

    // Colunas do kanban onde o aporte pode estar.
    // 'novo_contrato' = inicial (revisão); 'aporte' = final (coluna Aporte do kanban).
    public const KANBAN_NOVO    = 'novo_contrato';
    public const KANBAN_FINAL   = 'aporte';
    public const KANBAN_STATUSES = [self::KANBAN_NOVO, self::KANBAN_FINAL];
    
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
