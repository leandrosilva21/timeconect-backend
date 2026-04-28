<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class Timesheet extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Status constants
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CONFLICTED = 'conflicted';
    public const STATUS_ADJUSTMENT_REQUESTED = 'adjustment_requested';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'customer_id',
        'project_id',
        'date',
        'start_time',
        'end_time',
        'effort_minutes',
        'observation',
        'ticket',
        'origin',
        'is_billable_only',
        'status',
        'rejection_reason',
        'reviewed_by',
        'reviewed_at',
        'attachment_path',
        'attachment_original_name',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'date' => 'date:Y-m-d',
        'is_billable_only' => 'boolean',
        'start_time' => 'datetime:H:i',
        'end_time' => 'datetime:H:i',
        'effort_minutes' => 'integer',
        'reviewed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array<int, string>
     */
    protected $hidden = [];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = ['effort_hours', 'status_display', 'attachment_url'];

    /**
     * Boot method - define model events
     */
    protected static function boot()
    {
        parent::boot();

        // Calcular automaticamente o esforço antes de salvar
        static::saving(function ($timesheet) {
            $timesheet->calculateEffort();
        });
    }

    /**
     * Get all available statuses
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_PENDING              => 'Pendente',
            self::STATUS_APPROVED             => 'Aprovado',
            self::STATUS_REJECTED             => 'Rejeitado',
            self::STATUS_CONFLICTED           => 'Conflitante',
            self::STATUS_ADJUSTMENT_REQUESTED => 'Ajuste Solicitado',
        ];
    }

    /**
     * Calcular esforço em minutos baseado no horário de início e fim
     * ou usar valor customizado se fornecido
     */
    public function calculateEffort(): void
    {
        // Se já existe um valor de effort_minutes definido manualmente, não sobrescrever
        if ($this->effort_minutes !== null && $this->effort_minutes > 0) {
            return;
        }

        if ($this->start_time && $this->end_time) {
            $startTime = Carbon::parse($this->start_time);
            $endTime = Carbon::parse($this->end_time);

            // Se o horário final for menor que o inicial, assumir que passou da meia-noite
            if ($endTime->lt($startTime)) {
                $endTime->addDay();
            }

            $this->effort_minutes = $startTime->diffInMinutes($endTime);
        }
    }

    /**
     * Converte total_hours (formato HH:MM) para effort_minutes
     */
    public function setTotalHours(string $totalHours): void
    {
        if (preg_match('/^(\d+):([0-5][0-9])$/', $totalHours, $matches)) {
            $hours = intval($matches[1]);
            $minutes = intval($matches[2]);
            $this->effort_minutes = ($hours * 60) + $minutes;
        }
    }

    /**
     * Get effort in hours (computed attribute)
     */
    public function getEffortHoursAttribute(): string
    {
        if (!$this->effort_minutes) {
            return '0:00';
        }

        $hours = intval($this->effort_minutes / 60);
        $minutes = $this->effort_minutes % 60;

        return sprintf('%d:%02d', $hours, $minutes);
    }

    /**
     * Accessor para URL do anexo
     */
    public function getAttachmentUrlAttribute(): ?string
    {
        if (!$this->attachment_path) {
            return null;
        }
        $backendUrl = rtrim(config('app.url'), '/');
        return $backendUrl . '/api/v1/timesheets/' . $this->id . '/attachment';
    }

    /**
     * Get human readable status
     */
    public function getStatusDisplayAttribute(): string
    {
        return self::getStatuses()[$this->status] ?? $this->status;
    }

    /**
     * Relacionamento com usuário
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relacionamento com customer
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Relacionamento com projeto
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Relacionamento com quem revisou
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Relacionamento com estornos de aprovação
     */
    public function reversals(): HasMany
    {
        return $this->hasMany(TimesheetReversal::class);
    }

    /**
     * Scope para filtrar por usuário
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('timesheets.user_id', $userId);
    }

    /**
     * Scope para filtrar por projeto
     */
    public function scopeForProject($query, int $projectId)
    {
        return $query->where('timesheets.project_id', $projectId);
    }

    /**
     * Scope para filtrar por status
     */
    public function scopeWithStatus($query, string $status)
    {
        return $query->where('timesheets.status', $status);
    }

    /**
     * Scope para filtrar por período
     */
    public function scopeInPeriod($query, $startDate, $endDate)
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }

    /**
     * Scope para timesheets pendentes
     */
    public function scopePending($query)
    {
        return $query->where('timesheets.status', self::STATUS_PENDING);
    }

    /**
     * Scope para timesheets aprovados
     */
    public function scopeApproved($query)
    {
        return $query->where('timesheets.status', self::STATUS_APPROVED);
    }

    /**
     * Scope para timesheets rejeitados
     */
    public function scopeRejected($query)
    {
        return $query->where('timesheets.status', self::STATUS_REJECTED);
    }

    /**
     * Scope para timesheets conflitantes
     */
    public function scopeConflicted($query)
    {
        return $query->where('timesheets.status', self::STATUS_CONFLICTED);
    }

    /**
     * Verifica se o timesheet pode ser editado
     */
    public function canBeEdited(): bool
    {
        return $this->status === self::STATUS_PENDING ||
               $this->status === self::STATUS_REJECTED ||
               $this->status === self::STATUS_CONFLICTED ||
               $this->status === self::STATUS_ADJUSTMENT_REQUESTED;
    }

    /**
     * Verifica se o timesheet pode ser aprovado
     */
    public function canBeApproved(): bool
    {
        return $this->status === self::STATUS_PENDING ||
               $this->status === self::STATUS_CONFLICTED ||
               $this->status === self::STATUS_ADJUSTMENT_REQUESTED;
    }

    /**
     * Verifica se o usuário pode aprovar este timesheet
     */
    public function canBeApprovedBy(User $user): bool
    {
        if (!$this->canBeApproved()) {
            return false;
        }

        // Admin pode aprovar qualquer timesheet
        if ($user->isAdmin()) {
            return true;
        }

        // Coordenadores do projeto podem aprovar
        if (!$this->project) {
            return false;
        }
        return $this->project->coordinators()->where('users.id', $user->id)->exists();
    }

    /**
     * Aprovar timesheet
     */
    public function approve(User $approver): bool
    {
        if (!$this->canBeApprovedBy($approver)) {
            return false;
        }

        $this->status = self::STATUS_APPROVED;
        $this->reviewed_by = $approver->id;
        $this->reviewed_at = now();
        $this->rejection_reason = null;

        return $this->save();
    }

    /**
     * Rejeitar timesheet
     */
    public function reject(User $approver, string $reason): bool
    {
        if (!$this->canBeApprovedBy($approver)) {
            return false;
        }

        $this->status = self::STATUS_REJECTED;
        $this->reviewed_by = $approver->id;
        $this->reviewed_at = now();
        $this->rejection_reason = $reason;

        return $this->save();
    }

    /**
     * Solicitar ajuste no timesheet
     */
    public function requestAdjustment(User $approver, string $reason): bool
    {
        if (!$this->canBeApprovedBy($approver)) {
            return false;
        }

        $this->status = self::STATUS_ADJUSTMENT_REQUESTED;
        $this->reviewed_by = $approver->id;
        $this->reviewed_at = now();
        $this->rejection_reason = $reason;

        return $this->save();
    }

    /**
     * Verifica se o timesheet pode ter sua aprovação estornada
     */
    public function canBeReversedBy(User $user): bool
    {
        // Apenas timesheets aprovados podem ser estornados
        if ($this->status !== self::STATUS_APPROVED) {
            return false;
        }

        // Administradores podem estornar qualquer aprovação
        if ($user->isAdmin()) {
            return true;
        }

        // Quem aprovou pode estornar dentro do período permitido
        if ($this->reviewed_by === $user->id) {
            $reversalPeriod = config('timesheets.reversal_period_hours', 24);
            $reversalDeadline = $this->reviewed_at->addHours($reversalPeriod);

            return now()->lt($reversalDeadline);
        }

        return false;
    }

    /**
     * Estornar aprovação do timesheet
     */
    public function reverseApproval(User $reverser, string $reason): bool
    {
        if (!$this->canBeReversedBy($reverser)) {
            return false;
        }

        // Criar registro de estorno
        $this->reversals()->create([
            'reversed_by' => $reverser->id,
            'reversal_reason' => $reason,
            'original_approver_id' => $this->reviewed_by,
            'original_approval_date' => $this->reviewed_at,
        ]);

        // Reverter status para pendente
        $this->status = self::STATUS_PENDING;
        $this->reviewed_by = null;
        $this->reviewed_at = null;
        $this->rejection_reason = null;

        return $this->save();
    }

    /**
     * Verifica se o timesheet pode ter sua rejeição estornada
     */
    public function canBeRejectionReversedBy(User $user): bool
    {
        // Apenas timesheets rejeitados podem ser estornados
        if ($this->status !== self::STATUS_REJECTED) {
            return false;
        }

        // Administradores podem estornar qualquer rejeição
        if ($user->isAdmin()) {
            return true;
        }

        // Quem rejeitou pode estornar dentro do período permitido
        if ($this->reviewed_by === $user->id) {
            $reversalPeriod = config('timesheets.reversal_period_hours', 24);
            $reversalDeadline = $this->reviewed_at->addHours($reversalPeriod);

            return now()->lt($reversalDeadline);
        }

        return false;
    }

    /**
     * Estornar rejeição do timesheet
     */
    public function reverseRejection(User $reverser, string $reason): bool
    {
        if (!$this->canBeRejectionReversedBy($reverser)) {
            return false;
        }

        // Criar registro de estorno
        $this->reversals()->create([
            'reversed_by' => $reverser->id,
            'reversal_reason' => $reason,
            'original_approver_id' => $this->reviewed_by,
            'original_approval_date' => $this->reviewed_at,
        ]);

        // Reverter status para pendente
        $this->status = self::STATUS_PENDING;
        $this->reviewed_by = null;
        $this->reviewed_at = null;
        $this->rejection_reason = null;

        return $this->save();
    }

    /**
     * Reavalia todos os timesheets conflitados do usuário na data informada.
     * Se um conflitado não tem mais sobreposição com nenhum outro, volta para pending.
     */
    public static function resolveStaleConflicts(int $userId, string $date): void
    {
        static::where('user_id', $userId)
            ->where('date', $date)
            ->where('status', self::STATUS_CONFLICTED)
            ->whereNotNull('start_time')
            ->whereNotNull('end_time')
            ->get()
            ->each(function ($ts) {
                $stillConflicts = static::where('user_id', $ts->user_id)
                    ->where('date', $ts->date)
                    ->where('id', '!=', $ts->id)
                    ->whereNotIn('status', [self::STATUS_REJECTED])
                    ->whereNotNull('start_time')
                    ->whereNotNull('end_time')
                    ->where('start_time', '<', $ts->end_time)
                    ->where('end_time', '>', $ts->start_time)
                    ->exists();
                if (!$stillConflicts) {
                    $ts->status = self::STATUS_PENDING;
                    $ts->save();
                }
            });
    }
}
