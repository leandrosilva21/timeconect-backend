<?php

namespace App\Models;

use App\Models\Scopes\HideAusterFrozenScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory, SoftDeletes;

    // Nota: o global scope HideAusterFrozenScope foi removido por decisão de produto
    // (12/05/2026). Esses subprojetos voltam a aparecer em listagens, mas continuam
    // sendo ignorados nos cálculos de consumed_hours do pai via isAusterFrozen() +
    // `continue` nos loops (ver getGeneralHoursBalance, index gestao mode, etc).
    // O frontend marca esses projetos com badge "Histórico" usando o accessor abaixo.

    // Contract type constants removidos - agora vem da tabela contract_types

    /**
     * Status constants — lifecycle real (single source of truth).
     * Derivações de coluna/visão usam App\Services\ProjectWorkflowService. Ver ADR 0002.
     */
    public const STATUS_AWAITING_START       = 'awaiting_start';
    public const STATUS_BACKLOG              = 'backlog';
    public const STATUS_PLANNING             = 'planning';
    public const STATUS_STARTED              = 'started';
    public const STATUS_LIBERADO_PARA_TESTES = 'liberado_para_testes';
    public const STATUS_PAUSED               = 'paused';
    public const STATUS_CANCELLED            = 'cancelled';
    public const STATUS_FINISHED             = 'finished';

    /**
     * Expense responsible party constants
     */
    public const EXPENSE_RESPONSIBLE_CONSULTANCY = 'consultancy';
    public const EXPENSE_RESPONSIBLE_CLIENT = 'client';

    /**
     * Kanban executivo — desacoplado do status técnico.
     * Status técnico (awaiting_start/started/...) ≠ stage executivo (backlog/planning/...).
     */
    public const KANBAN_STAGE_BACKLOG      = 'backlog';
    public const KANBAN_STAGE_PLANNING     = 'planning';
    public const KANBAN_STAGE_EXECUTION    = 'execution';
    public const KANBAN_STAGE_HOMOLOGATION = 'homologation';
    public const KANBAN_STAGE_CLOSED       = 'closed';

    public const KANBAN_STAGES = [
        self::KANBAN_STAGE_BACKLOG,
        self::KANBAN_STAGE_PLANNING,
        self::KANBAN_STAGE_EXECUTION,
        self::KANBAN_STAGE_HOMOLOGATION,
        self::KANBAN_STAGE_CLOSED,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'code',
        'description',
        'customer_id',
        'parent_project_id',
        'contract_id',
        'tipo_faturamento',
        'tipo_alocacao',
        'architect_id',
        'condicao_pagamento',
        'observacoes_contrato',
        'cobra_despesa_cliente',
        'permissoes_despesa',
        'executivo_conta_id',
        'vendedor_id',
        'project_value',
        'hourly_rate',
        'sold_hours',
        'accumulated_sold_hours',
        'hour_contribution',
        'exceeded_hour_contribution',
        'initial_hours_balance',
        'initial_hours_consumed',
        'initial_cost',
        'consultant_hours',
        'coordinator_hours',
        'coordinator_percentage',
        'additional_hourly_rate',
        'start_date',
        'expected_end_date',
        'encerramento_date',
        'save_erpserv',
        'max_expense_per_consultant',
        'unlimited_expense',
        'expense_responsible_party',
        'timesheet_retroactive_limit_days',
        'allow_manual_timesheets',
        'service_type_id',
        'contract_type_id',
        'status',
        'kanban_stage',
        'allow_negative_balance',
        'allow_weekend_work',
        'allow_holiday_work',
        'proj_sequence',
        'proj_year',
        'child_sequence',
        'is_manual_code',
        'contract_request_id',
        'is_investimento_comercial',
        'categoria_interna',
        'kanban_coordinator_override_id',
        'movidesk_integration_enabled',
    ];

    /**
     * Atributos calculados incluídos automaticamente no JSON.
     */
    protected $appends = ['status_display', 'contract_type_display', 'is_operational', 'is_auster_frozen'];

    public function getIsAusterFrozenAttribute(): bool
    {
        return $this->isAusterFrozen();
    }

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'project_value' => 'decimal:2',
        'hourly_rate' => 'decimal:2',
        'additional_hourly_rate' => 'decimal:2',
        'max_expense_per_consultant' => 'decimal:2',
        'unlimited_expense' => 'boolean',
        'sold_hours' => 'integer',
        'accumulated_sold_hours' => 'integer',
        'hour_contribution' => 'decimal:2',
        'exceeded_hour_contribution' => 'decimal:2',
        'initial_hours_balance' => 'decimal:2',
        'initial_hours_consumed' => 'decimal:2',
        'consultant_hours' => 'integer',
        'coordinator_hours' => 'integer',
        'coordinator_percentage' => 'decimal:2',
        'timesheet_retroactive_limit_days' => 'integer',
        'allow_manual_timesheets' => 'boolean',
        'allow_negative_balance' => 'boolean',
        'allow_weekend_work' => 'boolean',
        'allow_holiday_work' => 'boolean',
        'is_investimento_comercial' => 'boolean',
        'movidesk_integration_enabled' => 'boolean',
        'save_erpserv' => 'decimal:2',
        'start_date' => 'date:Y-m-d',
        'expected_end_date' => 'date:Y-m-d',
        'encerramento_date' => 'date:Y-m-d',
        'cobra_despesa_cliente' => 'boolean',
        'permissoes_despesa' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get all available contract types
     */
    public static function getContractTypes(): array
    {
        return ContractType::getActiveOptionsWithCode();
    }

    /**
     * Get all available statuses (from database)
     */
    public static function getStatuses(): array
    {
        return ProjectStatus::getActiveOptionsWithCode();
    }

    /**
     * Get all available expense responsible party options
     */
    public static function getExpenseResponsiblePartyOptions(): array
    {
        return [
            self::EXPENSE_RESPONSIBLE_CONSULTANCY => 'Consultoria',
            self::EXPENSE_RESPONSIBLE_CLIENT => 'Cliente',
        ];
    }

    /**
     * Get human readable contract type
     */
    public function getContractTypeDisplayAttribute(): string
    {
        return $this->contractType?->name ?? 'N/A';
    }

    /**
     * Get human readable status
     */
    public function getStatusDisplayAttribute(): string
    {
        if (!$this->status) {
            return 'Não definido';
        }

        return self::getStatuses()[$this->status] ?? $this->status;
    }

    /**
     * Espelha isOperational() pro JSON — frontend usa pra decidir UI dual.
     */
    public function getIsOperationalAttribute(): bool
    {
        return $this->isOperational();
    }

    /**
     * Relacionamento com customer
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Relacionamento com tipo de serviço
     */
    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    /**
     * Relacionamento com tipo de contrato
     */
    public function contractType(): BelongsTo
    {
        return $this->belongsTo(ContractType::class);
    }

    /**
     * Relacionamento com consultores (many-to-many)
     */
    public function consultants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_consultants')
                    ->withPivot('allow_manual_timesheet')
                    ->withTimestamps();
    }

    /**
     * Relacionamento com grupos de consultores (many-to-many)
     */
    public function consultantGroups(): BelongsToMany
    {
        return $this->belongsToMany(ConsultantGroup::class, 'project_consultant_groups')
                    ->withTimestamps();
    }

    /**
     * Relacionamento com coordenadores (many-to-many)
     */
    public function coordinators(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_coordinators')
                    ->withTimestamps();
    }

    /**
     * @deprecated Use coordinators()
     */
    public function approvers(): BelongsToMany
    {
        return $this->coordinators();
    }

    /**
     * Histórico de alterações de horas vendidas (Banco de Horas Mensal)
     */
    public function soldHoursHistory(): HasMany
    {
        return $this->hasMany(ProjectSoldHoursHistory::class)->orderBy('effective_from');
    }

    /**
     * Alterações de valor hora COM vigência (effective_from), ordenadas — base do
     * histórico temporal. Vêm de project_change_logs (field_name='hourly_rate').
     */
    public function hourlyRateChanges(): HasMany
    {
        return $this->hasMany(\App\Models\ProjectChangeLog::class)
            ->where('field_name', 'hourly_rate')
            ->whereNotNull('effective_from')
            ->orderBy('effective_from');
    }

    /**
     * Valor hora vigente numa competência (YYYY-MM): a vigência mais recente com
     * effective_from <= competência; antes da 1ª vigência usa o valor anterior a ela;
     * sem vigências registradas, cai no hourly_rate atual. Garante que mudar o valor
     * NÃO altere fechamentos de meses anteriores ("legado intacto").
     */
    public function hourlyRateForCompetencia(string $yearMonth): float
    {
        $changes = $this->relationLoaded('hourlyRateChanges')
            ? $this->hourlyRateChanges
            : $this->hourlyRateChanges()->get();

        if ($changes->isEmpty()) {
            return (float) ($this->hourly_rate ?? 0);
        }

        $comp = \Carbon\Carbon::parse($yearMonth . '-01')->startOfMonth();
        $aplicavel = $changes->last(
            fn ($c) => \Carbon\Carbon::parse($c->effective_from)->startOfMonth()->lessThanOrEqualTo($comp)
        );

        return $aplicavel
            ? (float) $aplicavel->new_value
            : (float) ($changes->first()->old_value ?? $this->hourly_rate ?? 0);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function contractRequest(): BelongsTo
    {
        return $this->belongsTo(ContractRequest::class);
    }

    public function architect(): BelongsTo
    {
        return $this->belongsTo(User::class, 'architect_id');
    }

    public function executivoConta(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executivo_conta_id');
    }

    /**
     * Coordenador "override" para projetos de sustentação que devem ser
     * gerenciados por outro coordenador (admin/coord de projetos).
     * Quando preenchido:
     *  - Card do contrato migra da fila de sustentação pra coluna desse coord no Kanban
     *  - Projeto some das abas Apontamentos/Despesas/Aprovações do Portal /sustentacao
     *  - Aprovações globais e dashboards permanecem inalterados
     */
    public function kanbanOverrideCoordinator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'kanban_coordinator_override_id');
    }

    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vendedor_id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(ProjectContact::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ProjectAttachment::class);
    }

    /**
     * Relacionamento com timesheets (one-to-many)
     */
    public function timesheets(): HasMany
    {
        return $this->hasMany(Timesheet::class);
    }

    public function stages(): HasMany
    {
        return $this->hasMany(ProjectStage::class)->orderBy('order_index');
    }

    /**
     * Relacionamento com despesas
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /**
     * Relacionamento com aportes de horas
     */
    public function hourContributions(): HasMany
    {
        return $this->hasMany(HourContribution::class)
                    ->orderBy('contributed_at', 'desc');
    }

    /**
     * Relacionamento com projeto pai (many-to-one)
     */
    public function parentProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'parent_project_id');
    }

    /**
     * Relacionamento com projetos filhos (one-to-many)
     */
    public function childProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'parent_project_id');
    }

    /**
     * Verifica se o projeto é um subprojeto
     */
    public function isSubProject(): bool
    {
        return $this->parent_project_id !== null;
    }

    /**
     * Verifica se o projeto tem subprojetos
     */
    public function hasChildProjects(): bool
    {
        return $this->childProjects()->exists();
    }

    /**
     * Scope para filtrar por status
     */
    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope para filtrar por tipo de contrato
     */
    public function scopeWithContractType($query, int $contractTypeId)
    {
        return $query->where('contract_type_id', $contractTypeId);
    }

    /**
     * Scope para projetos ativos (não cancelados, encerrados nem pausados)
     */
    public function scopeActive($query)
    {
        return $query->whereNotIn('status', [self::STATUS_CANCELLED, self::STATUS_FINISHED, self::STATUS_PAUSED]);
    }

    /**
     * Scope para projetos que aceitam novos lançamentos (despesas e apontamentos).
     * Projetos pausados permitem registro de lançamentos pendentes — apenas cancelados e encerrados bloqueiam.
     */
    public function scopeOpen($query)
    {
        return $query->whereNotIn('status', [self::STATUS_CANCELLED, self::STATUS_FINISHED]);
    }

    /**
     * Scope para projetos em execução REAL — produtividade, SLA, consumo operacional.
     * Exclui backlog (autorizado mas ainda não executando) e awaiting_start (sem coord).
     * Ver ProjectWorkflowService::IN_EXECUTION e ADR 0002.
     */
    public function scopeInExecution($query)
    {
        return $query->whereIn('status', \App\Services\ProjectWorkflowService::IN_EXECUTION);
    }

    /**
     * Verifica se o projeto está ativo (permite novos lançamentos)
     */
    public function isActive(): bool
    {
        return !in_array($this->status, [self::STATUS_CANCELLED, self::STATUS_FINISHED, self::STATUS_PAUSED]);
    }

    /**
     * Verifica se o projeto aceita novos lançamentos (despesas e apontamentos).
     * Projetos pausados ainda permitem lançamentos — apenas cancelados e encerrados bloqueiam.
     */
    public function isOpen(): bool
    {
        return !in_array($this->status, [self::STATUS_CANCELLED, self::STATUS_FINISHED]);
    }

    /**
     * Verifica se o projeto usa modelo OPERACIONAL (etapas + alocação por etapa + cards).
     * Modelo alternativo: sustentação (alocação direta no projeto, sem etapas).
     *
     * Regra (mesma do CLAUDE.md): categoria=sustentacao ⇔ serviceType.name contém
     * "cloud", "bizify" ou "sustentacao". Demais são projeto operacional.
     *
     * Ver ADR 0004.
     */
    public function isOperational(): bool
    {
        $name = strtolower((string) ($this->serviceType?->name ?? ''));
        if ($name === '') return true; // sem serviceType, default operacional
        if (str_contains($name, 'sustenta')) return false;
        if (str_contains($name, 'cloud'))    return false;
        if (str_contains($name, 'bizify'))   return false;
        return true;
    }

    /**
     * Verifica se o projeto pode ser editado
     */
    public function canBeEdited(): bool
    {
        return $this->status !== self::STATUS_FINISHED;
    }

    /**
     * Obter o prazo limite (em dias) para lançamento retroativo de horas
     * Usa configuração do projeto, ou fallback para configuração do sistema
     */
    public function getTimesheetRetroactiveLimitDays(): int
    {
        // Se o projeto tem configuração própria, usar ela
        if ($this->timesheet_retroactive_limit_days !== null) {
            return $this->timesheet_retroactive_limit_days;
        }

        // Senão, usar configuração global do sistema (null/ausente = sem limite)
        return (int) \App\Models\SystemSetting::get('timesheet_retroactive_limit_days', 0);
    }

    /**
     * Verificar se uma data de serviço ainda está dentro do prazo para lançamento
     */
    public function isWithinTimesheetDeadline(\Carbon\Carbon $serviceDate): bool
    {
        $limitDays = $this->getTimesheetRetroactiveLimitDays();

        // Se limite é 0, não há restrição
        if ($limitDays === 0) {
            return true;
        }

        // Calcular data limite
        $deadlineDate = $serviceDate->copy()->addDays($limitDays)->endOfDay();
        $now = \Carbon\Carbon::now();

        return $now->lessThanOrEqualTo($deadlineDate);
    }

    /**
     * Obter a data limite para lançamento de horas de um serviço
     */
    public function getTimesheetDeadline(\Carbon\Carbon $serviceDate): \Carbon\Carbon
    {
        $limitDays = $this->getTimesheetRetroactiveLimitDays();
        return $serviceDate->copy()->addDays($limitDays)->endOfDay();
    }

    /**
     * Calcular o total de horas apontadas no projeto (em minutos)
     * 
     * IMPORTANTE: Não inclui apontamentos com status 'rejected'
     *
     * @param bool $includeChildProjects Se deve incluir horas dos subprojetos
     * @param int|null $excludeTimesheetId ID do timesheet a excluir do cálculo (útil na edição)
     * @return int Total de minutos apontados
     */
    public function getTotalLoggedMinutes(bool $includeChildProjects = false, ?int $excludeTimesheetId = null): int
    {
        $query = $this->timesheets()->whereNotIn('status', [Timesheet::STATUS_REJECTED, Timesheet::STATUS_CONFLICTED, Timesheet::STATUS_ADJUSTMENT_REQUESTED, Timesheet::STATUS_INTERNAL]);

        if ($excludeTimesheetId) {
            $query->where('id', '!=', $excludeTimesheetId);
        }

        $totalMinutes = $query->sum('effort_minutes') ?? 0;

        if ($includeChildProjects && $this->hasChildProjects()) {
            foreach ($this->childProjects as $childProject) {
                $childQuery = $childProject->timesheets()->whereNotIn('status', [Timesheet::STATUS_REJECTED, Timesheet::STATUS_CONFLICTED, Timesheet::STATUS_ADJUSTMENT_REQUESTED, Timesheet::STATUS_INTERNAL]);
                if ($excludeTimesheetId) {
                    $childQuery->where('id', '!=', $excludeTimesheetId);
                }
                $totalMinutes += $childQuery->sum('effort_minutes') ?? 0;
            }
        }

        return $totalMinutes;
    }

    /**
     * Calcular o total de horas apontadas no projeto (em horas)
     *
     * @param bool $includeChildProjects Se deve incluir horas dos subprojetos
     * @param int|null $excludeTimesheetId ID do timesheet a excluir do cálculo (útil na edição)
     * @return float Total de horas apontadas
     */
    public function getTotalLoggedHours(bool $includeChildProjects = false, ?int $excludeTimesheetId = null): float
    {
        $totalMinutes = $this->getTotalLoggedMinutes($includeChildProjects, $excludeTimesheetId);
        return round($totalMinutes / 60, 2);
    }

    /**
     * Calcular o total de horas apontadas por consultores (em minutos)
     * 
     * IMPORTANTE: Não inclui apontamentos com status 'rejected'
     *
     * @param bool $includeChildProjects Se deve incluir horas dos subprojetos
     * @param int|null $excludeTimesheetId ID do timesheet a excluir do cálculo (útil na edição)
     * @return int Total de minutos apontados por consultores
     */
    public function getConsultantLoggedMinutes(bool $includeChildProjects = false, ?int $excludeTimesheetId = null): int
    {
        // Obter IDs dos consultores do projeto
        $consultantIds = $this->consultants()->pluck('users.id')->toArray();

        if (empty($consultantIds)) {
            return 0;
        }

        $query = $this->timesheets()
            ->whereIn('user_id', $consultantIds)
            ->whereNotIn('status', [Timesheet::STATUS_REJECTED, Timesheet::STATUS_CONFLICTED, Timesheet::STATUS_ADJUSTMENT_REQUESTED, Timesheet::STATUS_INTERNAL]);

        if ($excludeTimesheetId) {
            $query->where('id', '!=', $excludeTimesheetId);
        }

        $totalMinutes = $query->sum('effort_minutes') ?? 0;

        if ($includeChildProjects && $this->hasChildProjects()) {
            foreach ($this->childProjects as $childProject) {
                $childQuery = $childProject->timesheets()
                    ->whereIn('user_id', $consultantIds)
                    ->whereNotIn('status', [Timesheet::STATUS_REJECTED, Timesheet::STATUS_CONFLICTED, Timesheet::STATUS_ADJUSTMENT_REQUESTED, Timesheet::STATUS_INTERNAL]);
                if ($excludeTimesheetId) {
                    $childQuery->where('id', '!=', $excludeTimesheetId);
                }
                $totalMinutes += $childQuery->sum('effort_minutes') ?? 0;
            }
        }

        return $totalMinutes;
    }

    /**
     * Calcular o total de horas apontadas por consultores (em horas)
     *
     * @param bool $includeChildProjects Se deve incluir horas dos subprojetos
     * @param int|null $excludeTimesheetId ID do timesheet a excluir do cálculo (útil na edição)
     * @return float Total de horas apontadas por consultores
     */
    public function getConsultantLoggedHours(bool $includeChildProjects = false, ?int $excludeTimesheetId = null): float
    {
        $totalMinutes = $this->getConsultantLoggedMinutes($includeChildProjects, $excludeTimesheetId);
        return round($totalMinutes / 60, 2);
    }

    /**
     * Calcular o total de horas apontadas por coordenadores (em minutos)
     *
     * Coordenadores são identificados como aprovadores do projeto ou usuários com role 'Coordinator'
     * 
     * IMPORTANTE: Não inclui apontamentos com status 'rejected'
     *
     * @param bool $includeChildProjects Se deve incluir horas dos subprojetos
     * @param int|null $excludeTimesheetId ID do timesheet a excluir do cálculo (útil na edição)
     * @return int Total de minutos apontados por coordenadores
     */
    public function getCoordinatorLoggedMinutes(bool $includeChildProjects = false, ?int $excludeTimesheetId = null): int
    {
        // Obter IDs dos coordenadores do projeto
        $coordinatorIds = $this->coordinators()->pluck('users.id')->toArray();

        if (empty($coordinatorIds)) {
            return 0;
        }

        $query = $this->timesheets()
            ->whereIn('user_id', $coordinatorIds)
            ->whereNotIn('status', [Timesheet::STATUS_REJECTED, Timesheet::STATUS_CONFLICTED, Timesheet::STATUS_ADJUSTMENT_REQUESTED, Timesheet::STATUS_INTERNAL]);

        if ($excludeTimesheetId) {
            $query->where('id', '!=', $excludeTimesheetId);
        }

        $totalMinutes = $query->sum('effort_minutes') ?? 0;

        if ($includeChildProjects && $this->hasChildProjects()) {
            foreach ($this->childProjects as $childProject) {
                $childQuery = $childProject->timesheets()
                    ->whereIn('user_id', $coordinatorIds)
                    ->whereNotIn('status', [Timesheet::STATUS_REJECTED, Timesheet::STATUS_CONFLICTED, Timesheet::STATUS_ADJUSTMENT_REQUESTED, Timesheet::STATUS_INTERNAL]);
                if ($excludeTimesheetId) {
                    $childQuery->where('id', '!=', $excludeTimesheetId);
                }
                $totalMinutes += $childQuery->sum('effort_minutes') ?? 0;
            }
        }

        return $totalMinutes;
    }

    /**
     * Calcular o total de horas apontadas por coordenadores (em horas)
     *
     * @param bool $includeChildProjects Se deve incluir horas dos subprojetos
     * @param int|null $excludeTimesheetId ID do timesheet a excluir do cálculo (útil na edição)
     * @return float Total de horas apontadas por coordenadores
     */
    public function getCoordinatorLoggedHours(bool $includeChildProjects = false, ?int $excludeTimesheetId = null): float
    {
        $totalMinutes = $this->getCoordinatorLoggedMinutes($includeChildProjects, $excludeTimesheetId);
        return round($totalMinutes / 60, 2);
    }

    /**
     * Calcular horas de coordenação para um total de horas apontadas.
     *
     * Regras:
     * - On Demand: sempre 0
     * - Banco de Horas (qualquer status): horas_apontadas × percentual_coordenacao
     *
     * @param float $loggedHours Total de horas apontadas (excluindo rejeitados)
     * @return float Horas de coordenação
     */
    public function calculateCoordinationHours(float $loggedHours = 0.0): float
    {
        $this->loadMissing('contractType');

        // On Demand não tem coordenação
        if ($this->isOnDemand()) {
            return 0.0;
        }

        $percent = (float) ($this->coordinator_hours ?? 0);
        if ($percent <= 0) {
            return 0.0;
        }

        return round($loggedHours * $percent / 100, 2);
    }

    /**
     * Verificar se o projeto é do tipo On Demand
     */
    public function isOnDemand(): bool
    {
        if (!$this->contractType) {
            return false;
        }
        return strtolower(trim($this->contractType->name)) === 'on demand';
    }

    /**
     * Calcular o saldo geral de horas do projeto
     *
     * Fórmula (Banco de Horas):
     *   saldo = horas_disponíveis - (horas_apontadas + horas_coordenação) + saldo_inicial
     *
     * Fórmula (On Demand):
     *   sem saldo (retorna 0)
     *
     * IMPORTANTE: Sempre inclui projetos filhos no cálculo.
     * Para projetos com contract_type.name = "Banco de Horas Mensal", usa accumulated_sold_hours.
     * Para projetos filhos com contract_type.name = "Fechado", subtrai (horas vendidas + aporte).
     *
     * @param bool $includeChildProjects Não utilizado (sempre inclui filhos)
     * @param int|null $excludeTimesheetId ID do timesheet a excluir do cálculo (útil na edição)
     * @return float Saldo geral em horas
     */
    public function getGeneralHoursBalance(bool $includeChildProjects = false, ?int $excludeTimesheetId = null, ?int $excludeChildProjectId = null): float
    {
        $this->loadMissing('contractType');

        // On Demand não controla saldo
        if ($this->isOnDemand()) {
            return 0.0;
        }

        // Para Banco de Horas Mensal, usar accumulated_sold_hours; caso contrário, usar sold_hours
        if ($this->isBankHoursMonthly()) {
            $soldHours = $this->accumulated_sold_hours ?? $this->sold_hours ?? 0;
        } else {
            $soldHours = $this->sold_hours ?? 0;
        }

        $totalAvailableHours = $this->getTotalAvailableHours();
        $contributionHours = $totalAvailableHours - ($this->sold_hours ?? 0);

        // Horas apontadas (excluindo rejeitados)
        $query = $this->timesheets()->whereNotIn('status', [Timesheet::STATUS_REJECTED, Timesheet::STATUS_CONFLICTED, Timesheet::STATUS_ADJUSTMENT_REQUESTED, Timesheet::STATUS_INTERNAL]);
        if ($excludeTimesheetId) {
            $query->where('id', '!=', $excludeTimesheetId);
        }
        $totalLoggedMinutes = $query->sum('effort_minutes') ?? 0;
        $totalLoggedHours   = round($totalLoggedMinutes / 60, 2);

        // Horas de coordenação sobre as horas apontadas deste projeto
        $coordinationHours = $this->calculateCoordinationHours($totalLoggedHours);

        $initialBalance  = (float) ($this->initial_hours_balance ?? 0);
        $initialConsumed = (float) ($this->initial_hours_consumed ?? 0);
        // initial_hours_consumed: horas já consumidas antes do projeto entrar no Minutor (saldo
        // já queimado pelo cliente). Sem essa subtração o show() devolvia o saldo inflado e o
        // modal de subprojeto autorizava criação acima do realmente disponível.
        $balance = ($soldHours + $contributionHours) - ($totalLoggedHours + $coordinationHours) + $initialBalance - $initialConsumed;

        // Sempre incluir projetos filhos no cálculo
        if ($this->hasChildProjects()) {
            // Carregar relacionamento contractType para evitar N+1 queries
            $this->loadMissing('childProjects.contractType');

            foreach ($this->childProjects as $childProject) {
                if ($childProject->isAusterFrozen()) continue;
                // Excluir o filho em edição do cálculo (validação de horas do
                // subprojeto): assim o disponível do pai não carrega o consumo desse
                // filho — inclusive initial_hours_consumed, que o estorno antigo não
                // devolvia e derrubava o disponível indevidamente.
                if ($excludeChildProjectId !== null && (int) $childProject->id === $excludeChildProjectId) continue;

                // Verificar se o projeto filho é do tipo "Fechado"
                $isClosedContract = $childProject->contractType &&
                                    strtolower(trim($childProject->contractType->name)) === 'fechado';
                $childCode = (string) ($childProject->contractType->code ?? '');
                $childName = $childProject->contractType ? strtolower(trim($childProject->contractType->name)) : '';
                $isBhFixoChild = $childCode === 'fixed_hours' || $childName === 'banco de horas fixo';

                if ($isClosedContract || $isBhFixoChild) {
                    // Fechado E Banco de Horas Fixo (como subprojeto): comprometem
                    // horas vendidas + aportes do filho no saldo do pai, independente
                    // do consumo efetivo.
                    $childTotalHours = $childProject->getTotalAvailableHours();
                    $balance -= $childTotalHours;
                } elseif ($childProject->isBankHoursMonthly()) {
                    // Banco de Horas Mensal: usa accumulated_sold_hours
                    $childSoldHours = $childProject->accumulated_sold_hours ?? $childProject->sold_hours ?? 0;
                    $childTotalAvailable = $childProject->getTotalAvailableHours();
                    $childContributionHours = $childTotalAvailable - ($childProject->sold_hours ?? 0);

                    $childQuery = $childProject->timesheets()->whereNotIn('status', [Timesheet::STATUS_REJECTED, Timesheet::STATUS_CONFLICTED, Timesheet::STATUS_ADJUSTMENT_REQUESTED, Timesheet::STATUS_INTERNAL]);
                    if ($excludeTimesheetId) {
                        $childQuery->where('id', '!=', $excludeTimesheetId);
                    }
                    $childLoggedHours = round(($childQuery->sum('effort_minutes') ?? 0) / 60, 2);
                    $childCoordinationHours = $childProject->calculateCoordinationHours($childLoggedHours);

                    $childInitialBalance = (float) ($childProject->initial_hours_balance ?? 0);
                    $childBalance = ($childSoldHours + $childContributionHours)
                        - ($childLoggedHours + $childCoordinationHours)
                        + $childInitialBalance;
                    $balance -= $childBalance;
                } else {
                    // Demais tipos: subtrai apenas o efetivamente apontado + initial_consumed
                    $childQuery = $childProject->timesheets()->whereNotIn('status', [Timesheet::STATUS_REJECTED, Timesheet::STATUS_CONFLICTED, Timesheet::STATUS_ADJUSTMENT_REQUESTED, Timesheet::STATUS_INTERNAL]);
                    if ($excludeTimesheetId) {
                        $childQuery->where('id', '!=', $excludeTimesheetId);
                    }
                    $childLoggedHours = round(($childQuery->sum('effort_minutes') ?? 0) / 60, 2);
                    $childInitialConsumed = (float) ($childProject->initial_hours_consumed ?? 0);
                    $childCoordinationHours = $childProject->calculateCoordinationHours($childLoggedHours);
                    $balance -= ($childLoggedHours + $childInitialConsumed + $childCoordinationHours);
                }
            }
        }

        return round($balance, 2);
    }

    /**
     * Calcular o saldo geral de horas do projeto excluindo o mês atual
     * 
     * Este método calcula o saldo considerando apenas meses fechados (até o final do mês anterior).
     * Para projetos "Banco de Horas Mensal", usa accumulated_sold_hours calculado até o mês anterior.
     * Exclui horas apontadas do mês atual.
     *
     * @param bool $includeChildProjects Se deve incluir horas dos subprojetos (sempre true internamente)
     * @param int|null $excludeTimesheetId ID do timesheet a excluir do cálculo (útil na edição)
     * @return float Saldo geral em horas (excluindo mês atual)
     */
    public function getGeneralHoursBalanceExcludingCurrentMonth(bool $includeChildProjects = false, ?int $excludeTimesheetId = null): float
    {
        // Carregar contractType se necessário
        $this->loadMissing('contractType');

        // Data de referência: último dia do mês anterior
        $endOfLastMonth = \Carbon\Carbon::now()->subMonth()->endOfMonth();

        // Para Banco de Horas Mensal, calcular accumulated_sold_hours até o mês anterior
        if ($this->isBankHoursMonthly()) {
            $soldHours = $this->calculateAccumulatedSoldHours($endOfLastMonth) ?? $this->sold_hours ?? 0;
        } else {
            $soldHours = $this->sold_hours ?? 0;
        }
        
        // Usar método auxiliar para obter aportes (novos + fallback legado)
        $totalAvailableHours = $this->getTotalAvailableHours();
        $contributionHours = $totalAvailableHours - ($this->sold_hours ?? 0);

        // Calcular horas apontadas excluindo o mês atual (até o final do mês anterior)
        $query = $this->timesheets()
            ->whereNotIn('status', [Timesheet::STATUS_REJECTED, Timesheet::STATUS_CONFLICTED, Timesheet::STATUS_ADJUSTMENT_REQUESTED, Timesheet::STATUS_INTERNAL])
            ->where('date', '<=', $endOfLastMonth->format('Y-m-d'));

        if ($excludeTimesheetId) {
            $query->where('id', '!=', $excludeTimesheetId);
        }

        $totalLoggedMinutes = $query->sum('effort_minutes') ?? 0;
        $totalLoggedHours = round($totalLoggedMinutes / 60, 2);

        // Calcular saldo base do projeto atual
        // IMPORTANTE: Para Banco de Horas Mensal, soldHours já é accumulated até o mês anterior
        $initialBalance  = (float) ($this->initial_hours_balance ?? 0);
        $initialConsumed = (float) ($this->initial_hours_consumed ?? 0);
        $balance = ($soldHours + $contributionHours) - $totalLoggedHours + $initialBalance - $initialConsumed;

        // Sempre incluir projetos filhos no cálculo
        if ($this->hasChildProjects()) {
            // Carregar relacionamento contractType para evitar N+1 queries
            $this->loadMissing('childProjects.contractType');

            foreach ($this->childProjects as $childProject) {
                if ($childProject->isAusterFrozen()) continue;

                // Verificar se o projeto filho é do tipo "Fechado" ou "Banco de Horas Fixo"
                $isClosedContract = $childProject->contractType &&
                                    strtolower(trim($childProject->contractType->name)) === 'fechado';
                $childCode = (string) ($childProject->contractType->code ?? '');
                $childName = $childProject->contractType ? strtolower(trim($childProject->contractType->name)) : '';
                $isBhFixoChild = $childCode === 'fixed_hours' || $childName === 'banco de horas fixo';

                if ($isClosedContract || $isBhFixoChild) {
                    // Fechado E Banco de Horas Fixo (como subprojeto): comprometem
                    // horas vendidas + aportes do filho no saldo do pai, independente
                    // do consumo efetivo.
                    $childTotalHours = $childProject->getTotalAvailableHours();
                    $balance -= $childTotalHours;
                } elseif ($childProject->isBankHoursMonthly()) {
                    // Para Banco de Horas Mensal: calcular accumulated_sold_hours até o mês anterior
                    $childSoldHours = $childProject->calculateAccumulatedSoldHours($endOfLastMonth) ?? $childProject->sold_hours ?? 0;
                    
                    // Calcular aportes usando método auxiliar
                    $childTotalAvailable = $childProject->getTotalAvailableHours();
                    $childContributionHours = $childTotalAvailable - ($childProject->sold_hours ?? 0);
                    
                    // Calcular horas apontadas do filho excluindo o mês atual
                    $childQuery = $childProject->timesheets()
                        ->whereNotIn('status', [Timesheet::STATUS_REJECTED, Timesheet::STATUS_CONFLICTED, Timesheet::STATUS_ADJUSTMENT_REQUESTED, Timesheet::STATUS_INTERNAL])
                        ->where('date', '<=', $endOfLastMonth->format('Y-m-d'));

                    if ($excludeTimesheetId) {
                        $childQuery->where('id', '!=', $excludeTimesheetId);
                    }

                    $childLoggedMinutes = $childQuery->sum('effort_minutes') ?? 0;
                    $childLoggedHours = round($childLoggedMinutes / 60, 2);
                    
                    // Subtrair o saldo do filho: (accumulated_sold_hours até mês anterior + aportes + saldo inicial) - horas apontadas até mês anterior
                    $childInitialBalance = (float) ($childProject->initial_hours_balance ?? 0);
                    $childBalance = ($childSoldHours + $childContributionHours) - $childLoggedHours + $childInitialBalance;
                    $balance -= $childBalance;
                } else {
                    // Para outros tipos (inclui Banco de Horas Fixo): subtrair apontadas + initial_hours_consumed
                    $childQuery = $childProject->timesheets()
                        ->whereNotIn('status', [Timesheet::STATUS_REJECTED, Timesheet::STATUS_CONFLICTED, Timesheet::STATUS_ADJUSTMENT_REQUESTED, Timesheet::STATUS_INTERNAL])
                        ->where('date', '<=', $endOfLastMonth->format('Y-m-d'));

                    if ($excludeTimesheetId) {
                        $childQuery->where('id', '!=', $excludeTimesheetId);
                    }

                    $childLoggedMinutes = $childQuery->sum('effort_minutes') ?? 0;
                    $childLoggedHours = round($childLoggedMinutes / 60, 2);
                    $childInitialConsumed = (float) ($childProject->initial_hours_consumed ?? 0);
                    $balance -= ($childLoggedHours + $childInitialConsumed);
                }
            }
        }

        return round($balance, 2);
    }

    /**
     * Calcular o saldo de horas dos consultores
     *
     * Fórmula: Quantidade Horas Consultor - todos apontamentos de consultores vinculados
     *
     * @param bool $includeChildProjects Se deve incluir horas dos subprojetos
     * @param int|null $excludeTimesheetId ID do timesheet a excluir do cálculo (útil na edição)
     * @return float Saldo de horas dos consultores
     */
    public function getConsultantHoursBalance(bool $includeChildProjects = false, ?int $excludeTimesheetId = null): float
    {
        $consultantHours = $this->consultant_hours ?? 0;
        $consultantLoggedHours = $this->getConsultantLoggedHours($includeChildProjects, $excludeTimesheetId);

        $balance = $consultantHours - $consultantLoggedHours;

        return round($balance, 2);
    }

    /**
     * Calcular o saldo de horas dos coordenadores
     *
     * Fórmula: (horas_apontadas_consultores × percentual) - horas_apontadas_coordenadores
     *
     * @param bool $includeChildProjects Se deve incluir horas dos subprojetos
     * @param int|null $excludeTimesheetId ID do timesheet a excluir do cálculo (útil na edição)
     * @return float Saldo de horas dos coordenadores
     */
    public function getCoordinatorHoursBalance(bool $includeChildProjects = false, ?int $excludeTimesheetId = null): float
    {
        $coordinatorLoggedHours  = $this->getCoordinatorLoggedHours($includeChildProjects, $excludeTimesheetId);
        $consultantLoggedHours   = $this->getConsultantLoggedHours($includeChildProjects, $excludeTimesheetId);
        $percent                 = (float) ($this->coordinator_hours ?? 0);
        $coordinatorAvailableHours = round($consultantLoggedHours * $percent / 100, 2);

        return round($coordinatorAvailableHours - $coordinatorLoggedHours, 2);
    }

    /**
     * Subprojetos da Auster com início anterior a 01/05/2025 são somente-leitura histórica:
     * não devem contribuir para saldos, totais ou custos do projeto pai.
     *
     * Nota: o global scope HideAusterFrozenScope já filtra esses projetos das queries
     * por padrão. Este método é mantido como guarda defensiva (caso alguém carregue
     * via withoutGlobalScope) e como fonte da regra para indicadores futuros.
     */
    public function isAusterFrozen(): bool
    {
        return $this->customer_id === HideAusterFrozenScope::AUSTER_CUSTOMER_ID
            && $this->parent_project_id !== null
            && $this->start_date !== null
            && \Carbon\Carbon::parse($this->start_date)->lt(\Carbon\Carbon::parse(HideAusterFrozenScope::FREEZE_DATE));
    }

    /**
     * Verificar se o projeto é do tipo "Banco de Horas Mensal"
     *
     * @return bool
     */
    public function isBankHoursMonthly(): bool
    {
        if (!$this->contractType) {
            return false;
        }

        $contractTypeName = strtolower(trim($this->contractType->name));
        return $contractTypeName === 'banco de horas mensal';
    }

    /**
     * Calcular horas acumuladas respeitando o histórico de alterações de sold_hours.
     *
     * Para cada segmento do histórico calcula: sold_hours_do_segmento × meses_do_segmento.
     * Se não houver histórico registrado, usa o comportamento legado (sold_hours × totalMeses).
     *
     * Exemplo:
     *   Projeto inicia Jan/2025 com 100h/mês
     *   Abr/2025: altera para 120h/mês
     *   Referência: Jun/2025
     *   → 3×100 + 3×120 = 660h
     *
     * @param \Carbon\Carbon|null $referenceDate Data de referência (padrão: hoje)
     * @return int|null null se não for Banco de Horas Mensal ou faltar dados
     */
    public function calculateAccumulatedSoldHours(?\Carbon\Carbon $referenceDate = null): ?int
    {
        if (!$this->isBankHoursMonthly()) {
            return null;
        }

        if (!$this->sold_hours || !$this->start_date) {
            return null;
        }

        $endDate   = $referenceDate ?? \Carbon\Carbon::now();
        $startDate = \Carbon\Carbon::parse($this->start_date);

        if ($startDate->startOfMonth()->greaterThan($endDate->copy()->startOfMonth())) {
            return 0;
        }

        $startMonth = $startDate->copy()->startOfMonth();
        $endMonth   = $endDate->copy()->startOfMonth();

        // Carregar histórico ordenado por effective_from
        $history = $this->soldHoursHistory()->orderBy('effective_from')->get();

        // Sem histórico: comportamento legado
        if ($history->isEmpty()) {
            $months = $startMonth->diffInMonths($endMonth) + 1;
            return (int) ($this->sold_hours * $months);
        }

        $total = 0;
        $count = $history->count();

        for ($i = 0; $i < $count; $i++) {
            $record  = $history[$i];
            $segFrom = \Carbon\Carbon::parse($record->effective_from)->startOfMonth();

            // Fim do segmento: mês anterior ao próximo registro, ou endMonth
            if ($i + 1 < $count) {
                $segTo = \Carbon\Carbon::parse($history[$i + 1]->effective_from)
                    ->startOfMonth()
                    ->subMonthNoOverflow();
            } else {
                $segTo = $endMonth->copy();
            }

            // Limitar ao intervalo [startMonth, endMonth]
            $from = $segFrom->max($startMonth);
            $to   = $segTo->min($endMonth);

            if ($from->greaterThan($to)) {
                continue;
            }

            $months = $from->diffInMonths($to) + 1;
            $total += (int) $record->sold_hours * $months;
        }

        return $total;
    }

    /**
     * Atualizar o campo accumulated_sold_hours automaticamente
     * 
     * @param \Carbon\Carbon|null $referenceDate Data de referência para o cálculo (padrão: hoje)
     * @param bool $skipObserver Se true, atualiza sem disparar observers (útil quando chamado de dentro do observer)
     * @return bool True se atualizou, false caso contrário
     */
    public function updateAccumulatedSoldHours(?\Carbon\Carbon $referenceDate = null, bool $skipObserver = false): bool
    {
        $accumulatedHours = $this->calculateAccumulatedSoldHours($referenceDate);
        
        // Se retornou null, não é Banco de Horas Mensal ou faltam dados
        if ($accumulatedHours === null) {
            // Se não é Banco de Horas Mensal, limpar o campo
            if (!$this->isBankHoursMonthly()) {
                // Usar update direto no banco para evitar loop de observers
                if ($skipObserver) {
                    \Illuminate\Support\Facades\DB::table('projects')
                        ->where('id', $this->id)
                        ->update(['accumulated_sold_hours' => null]);
                    $this->accumulated_sold_hours = null;
                    return true;
                }
                $this->accumulated_sold_hours = null;
                return $this->save();
            }
            return false;
        }

        // Atualizar o campo apenas se o valor mudou
        if ($this->accumulated_sold_hours !== $accumulatedHours) {
            // Usar update direto no banco para evitar loop de observers
            if ($skipObserver) {
                \Illuminate\Support\Facades\DB::table('projects')
                    ->where('id', $this->id)
                    ->update(['accumulated_sold_hours' => $accumulatedHours]);
                $this->accumulated_sold_hours = $accumulatedHours;
                return true;
            }
            $this->accumulated_sold_hours = $accumulatedHours;
            return $this->save();
        }

        return true;
    }

    /**
     * Verificar se um usuário é consultor do projeto
     *
     * @param int $userId ID do usuário
     * @return bool
     */
    public function isUserConsultant(int $userId): bool
    {
        // Vinculado diretamente como consultor
        if ($this->consultants()->where('users.id', $userId)->exists()) {
            return true;
        }

        // Pertence a algum grupo de consultores vinculado ao projeto
        return $this->consultantGroups()
            ->whereHas('consultants', fn ($q) => $q->where('users.id', $userId))
            ->exists();
    }

    /**
     * Verificar se um usuário é coordenador do projeto
     *
     * @param int $userId ID do usuário
     * @return bool
     */
    public function isUserCoordinator(int $userId): bool
    {
        // Verificar se é coordenador do projeto
        if ($this->coordinators()->where('users.id', $userId)->exists()) {
            return true;
        }

        // Verificar se o usuário é do tipo coordenador
        $user = \App\Models\User::find($userId);
        if ($user && $user->isCoordenador()) {
            return true;
        }

        return false;
    }

    /**
     * Obter o total de horas disponíveis no projeto
     * Considera horas vendidas + aporte legado + novos aportes
     *
     * @return int Total de horas disponíveis
     */
    public function getTotalAvailableHours(): int
    {
        // Usa a relação já carregada em memória (evita N+1).
        // Se não estiver carregada (chamada isolada), faz a query normalmente.
        $contributions = $this->relationLoaded('hourContributions')
            ? $this->hourContributions
            : $this->hourContributions()->get();

        $newContributions = $contributions->sum('contributed_hours');

        if ($newContributions > 0) {
            return ($this->sold_hours ?? 0) + (int) $newContributions;
        }

        // Fallback: usar aporte legado (para projetos antigos)
        return ($this->sold_hours ?? 0) + ($this->hour_contribution ?? 0);
    }

    /**
     * Calcular o valor total do projeto
     * Considera valor base + valor de todos os aportes
     *
     * @return float Valor total do projeto
     */
    public function calculateTotalProjectValue(): float
    {
        // Valor das horas vendidas inicialmente
        $baseSoldHoursValue = ($this->sold_hours ?? 0) * ($this->hourly_rate ?? 0);

        // Usa relação já carregada em memória (evita N+1)
        $contributions = $this->relationLoaded('hourContributions')
            ? $this->hourContributions
            : $this->hourContributions()->get();

        $newContributions = $contributions->sum(fn($c) => $c->contributed_hours * $c->hourly_rate);

        if ($newContributions > 0) {
            return round($baseSoldHoursValue + $newContributions, 2);
        }

        // Fallback: usar aporte legado (para projetos antigos)
        $legacyContributionValue = ($this->hour_contribution ?? 0) * ($this->hourly_rate ?? 0);

        return round($baseSoldHoursValue + $legacyContributionValue, 2);
    }

    /**
     * Calcular o valor médio ponderado da hora
     * Considera todas as horas e valores
     *
     * @return float Valor médio ponderado por hora
     */
    public function getWeightedAverageHourlyRate(): float
    {
        // Usa relação já carregada em memória (evita N+1)
        $contributions = $this->relationLoaded('hourContributions')
            ? $this->hourContributions
            : $this->hourContributions()->get();

        if ($contributions->count() > 0) {
            $totalHours = $this->sold_hours ?? 0;
            $totalValue = ($this->sold_hours ?? 0) * ($this->hourly_rate ?? 0);

            foreach ($contributions as $contribution) {
                $totalHours += $contribution->contributed_hours;
                $totalValue += $contribution->getTotalValue();
            }

            return $totalHours > 0 ? round($totalValue / $totalHours, 2) : 0;
        }

        // Fallback: usar aporte legado (para projetos antigos)
        $totalHours = ($this->sold_hours ?? 0) + ($this->hour_contribution ?? 0);
        $totalValue = $totalHours * ($this->hourly_rate ?? 0);

        return $totalHours > 0 ? round($totalValue / $totalHours, 2) : ($this->hourly_rate ?? 0);
    }
}
