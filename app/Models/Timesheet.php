<?php

namespace App\Models;

use App\Observers\TimesheetObserver;
use App\Notifications\TimesheetStatusNotification;
use App\Services\TimesheetN8nNotifier;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

#[ObservedBy([TimesheetObserver::class])]
class Timesheet extends Model
{
    use HasFactory, SoftDeletes;
    use \App\Attachments\Concerns\HasGlobalAttachments;

    // FASE 11 — chave do registry global de anexos.
    public static function attachmentEntityType(): string { return 'TIMESHEET'; }

    /**
     * Source explícito pra o TimesheetObserver registrar no log de auditoria.
     * Propriedade pública (não atributo Eloquent) — não vai pro UPDATE SQL.
     * Setar antes do save() quando origem != contexto padrão (ex: sync Movidesk).
     */
    public ?string $_logSource = null;

    /**
     * Status constants
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CONFLICTED = 'conflicted';
    public const STATUS_ADJUSTMENT_REQUESTED = 'adjustment_requested';
    public const STATUS_INTERNAL = 'internal';
    public const STATUS_RELEASED = 'released';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'customer_id',
        'project_id',
        'real_project_id',
        'date',
        'start_time',
        'end_time',
        'effort_minutes',
        'observation',
        'ticket',
        'origin',
        'is_billable_only',
        'is_internal_action',
        'client_extra_pct',
        'consultant_extra_pct',
        'status',
        'rejection_reason',
        'reviewed_by',
        'reviewed_at',
        'manual_project_edit',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'date' => 'date:Y-m-d',
        'is_billable_only'    => 'boolean',
        'is_internal_action'  => 'boolean',
        'manual_project_edit' => 'boolean',
        'client_extra_pct'    => 'decimal:2',
        'consultant_extra_pct'=> 'decimal:2',
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
            self::STATUS_INTERNAL             => 'Ação Interna',
            self::STATUS_RELEASED             => 'Liberado',
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
     * Converte uma string `total_hours` em minutos (int) ou null se inválido.
     * Aceita 3 formatos:
     *   - HH:MM     ("4:30"   → 270)
     *   - Decimal   ("4.5", "4,5" → 270; "4.25" → 255; "4.75" → 285)
     *   - Inteiro   ("4"      → 240)
     *
     * Centralizado aqui pra que TimesheetController (store + update) e setTotalHours
     * usem a mesma regra. Fração com mais de 2 casas é arredondada.
     */
    public static function parseTotalHoursToMinutes(?string $totalHours): ?int
    {
        if ($totalHours === null || $totalHours === '') {
            return null;
        }
        // HH:MM (minutos 00-59)
        if (preg_match('/^(\d+):([0-5][0-9])$/', $totalHours, $m)) {
            return ((int) $m[1]) * 60 + ((int) $m[2]);
        }
        // Decimal (ponto ou vírgula) ou inteiro puro
        if (preg_match('/^(\d+)(?:[.,](\d{1,2}))?$/', $totalHours, $m)) {
            $hours    = (int) $m[1];
            $fracStr  = $m[2] ?? '';
            $fraction = $fracStr === '' ? 0.0 : (float) ('0.' . $fracStr);
            return $hours * 60 + (int) round($fraction * 60);
        }
        return null;
    }

    /**
     * Converte total_hours (HH:MM ou decimal) para effort_minutes do model.
     */
    public function setTotalHours(string $totalHours): void
    {
        $minutes = self::parseTotalHoursToMinutes($totalHours);
        if ($minutes !== null) {
            $this->effort_minutes = $minutes;
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
     * Accessor para URL do anexo — 100% via nova camada (FASE 11.7).
     */
    public function getAttachmentUrlAttribute(): ?string
    {
        $newUrl = $this->attachmentUrl('attachment');
        if ($newUrl === null) return null;
        return rtrim(config('app.url'), '/') . $newUrl;
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
     * Projeto REAL do apontamento de investimento (referência). O consumo é contabilizado
     * no project_id (investimento); o real define o coordenador que aprova.
     */
    public function realProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'real_project_id');
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

        if ($user->isAdmin()) {
            return true;
        }

        if (!$this->project) {
            return false;
        }

        // Investimento COMERCIAL: o EXECUTIVO do cliente aprova (mesmo sem ser coordenador).
        if ($this->project->is_investimento_comercial && $this->project->categoria_interna === 'Comercial') {
            return $this->customer_id && \App\Models\Customer::where('id', $this->customer_id)
                ->where('executive_id', $user->id)->exists();
        }

        if (!$user->isCoordenador()) {
            return false;
        }

        // Coordenador de sustentação aprova qualquer timesheet de projeto de sustentação
        if ($user->coordinator_type === 'sustentacao') {
            return $this->project->service_type_id &&
                \App\Models\ServiceType::where('id', $this->project->service_type_id)
                    ->where('code', 'sustentacao')
                    ->exists();
        }

        // Apontamento de INVESTIMENTO (Suporte/Projeto): aprova o coordenador do PROJETO REAL.
        if ($this->project->is_investimento_comercial && $this->real_project_id) {
            return \App\Models\Project::where('id', $this->real_project_id)
                ->whereHas('coordinators', fn($q) => $q->where('users.id', $user->id))
                ->exists();
        }

        // Coordenador de projetos aprova projetos onde está vinculado como coordenador
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

        $saved = $this->save();

        if ($saved) {
            $timesheet = $this;
            DB::afterCommit(function () use ($timesheet, $reason) {
                app(TimesheetN8nNotifier::class)->notify($timesheet, 'REJEITADO', $reason);
                $timesheet->notifyOwnerOfStatus('REJEITADO', $reason);
            });
        }

        return $saved;
    }

    /**
     * Liberar ação interna
     */
    public function release(User $reviewer): bool
    {
        if ($this->status !== self::STATUS_INTERNAL) {
            return false;
        }

        $this->status      = self::STATUS_RELEASED;
        $this->reviewed_by = $reviewer->id;
        $this->reviewed_at = now();

        return $this->save();
    }

    /**
     * Estornar liberação de ação interna
     */
    public function reverseRelease(): bool
    {
        if ($this->status !== self::STATUS_RELEASED) {
            return false;
        }

        $this->status      = self::STATUS_INTERNAL;
        $this->reviewed_by = null;
        $this->reviewed_at = null;

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

        $saved = $this->save();

        if ($saved) {
            $timesheet = $this;
            DB::afterCommit(function () use ($timesheet, $reason) {
                app(TimesheetN8nNotifier::class)->notify($timesheet, 'AJUSTE', $reason);
                $timesheet->notifyOwnerOfStatus('AJUSTE', $reason);
            });
        }

        return $saved;
    }

    /**
     * Envia e-mail para o dono do apontamento avisando da mudança de status.
     * Funciona em paralelo ao webhook n8n (caso de redundância); o destino é
     * sempre o user.email do timesheet — fix da reclamação onde o n8n estava
     * caindo no admin em vez do dono.
     */
    public function notifyOwnerOfStatus(string $statusKey, ?string $reason = null): void
    {
        $owner = $this->user;
        if (!$owner || !$owner->email) {
            return;
        }
        try {
            $owner->notify(new TimesheetStatusNotification($this, $statusKey, $reason));
        } catch (\Throwable $e) {
            \Log::warning('notifyOwnerOfStatus: falha ao enviar', [
                'timesheet_id' => $this->id,
                'status'       => $statusKey,
                'error'        => $e->getMessage(),
            ]);
        }
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
        // Apenas timesheets rejeitados ou com ajuste solicitado podem ser estornados
        if (!in_array($this->status, [self::STATUS_REJECTED, self::STATUS_ADJUSTMENT_REQUESTED])) {
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
     * Reavalia timesheets conflitados do usuário na data informada.
     *
     * Regra: um apontamento só permanece em `conflicted` enquanto houver
     * OUTRO apontamento do MESMO cliente/data sobreposto que AINDA NÃO
     * TENHA SIDO TRATADO MANUALMENTE pelo admin/coord.
     *
     * "Tratado manualmente" = qualquer status diferente de pending/conflicted
     * (approved, rejected, adjustment_requested, internal, released). Esses
     * já passaram por decisão humana — não fazem mais sentido travar o par
     * como conflicted indefinidamente.
     *
     * Se o overlap restante já foi tratado (ou sumiu), volta pra `pending`
     * pra que o ciclo de aprovação continue.
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
                    ->where('customer_id', $ts->customer_id)
                    ->where('date', $ts->date)
                    ->where('id', '!=', $ts->id)
                    // Considera "em disputa" SOMENTE outro pending ou conflicted.
                    // Approved/rejected/adjustment_requested/internal/released =
                    // decisão humana tomada, libera o par.
                    ->whereIn('status', [self::STATUS_PENDING, self::STATUS_CONFLICTED])
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
