<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use App\Models\PaymentMethod;

class Expense extends Model
{
    use HasFactory;

    // Status constants
    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_ADJUSTMENT_REQUESTED = 'adjustment_requested';

    // Expense types
    const TYPE_CORPORATE_CARD = 'corporate_card';
    const TYPE_REIMBURSEMENT = 'reimbursement';

    // Payment methods
    const PAYMENT_CORPORATE_CARD = 'corporate_card';
    const PAYMENT_CASH = 'cash';
    const PAYMENT_BANK_TRANSFER = 'bank_transfer';
    const PAYMENT_PIX = 'pix';
    const PAYMENT_CHECK = 'check';
    const PAYMENT_OTHER = 'other';

    protected $fillable = [
        'user_id',
        'project_id',
        'expense_category_id',
        'expense_date',
        'description',
        'amount',
        'expense_type',
        'payment_method',
        'receipt_path',
        'receipt_original_name',
        'status',
        'rejection_reason',
        'charge_client',
        'is_paid',
        'pagar_no_fechamento',
        'paid_by',
        'paid_at',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'expense_date' => 'date:Y-m-d', // Retorna apenas a data sem horário
        'amount' => 'decimal:2',
        'charge_client' => 'boolean',
        'is_paid' => 'boolean',
        'pagar_no_fechamento' => 'boolean',
        'paid_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $appends = [
        'status_display',
        'expense_type_display',
        'payment_method_display',
        'formatted_amount',
        'receipt_url'
    ];

    /**
     * Relacionamento com usuário solicitante
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Quem marcou como paga
     */
    public function paidByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    /**
     * Relacionamento com projeto
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Relacionamento com categoria da despesa
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
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
        return $this->hasMany(ExpenseReversal::class);
    }

    /**
     * Accessor para exibir status em português
     */
    public function getStatusDisplayAttribute(): string
    {
        return match($this->status) {
            self::STATUS_PENDING => 'Pendente',
            self::STATUS_APPROVED => 'Aprovado',
            self::STATUS_REJECTED => 'Rejeitado',
            self::STATUS_ADJUSTMENT_REQUESTED => 'Ajuste Solicitado',
            default => 'Desconhecido'
        };
    }

    /**
     * Accessor para exibir tipo em português
     */
    public function getExpenseTypeDisplayAttribute(): string
    {
        if (!$this->expense_type) {
            return 'Desconhecido';
        }

        // Buscar o tipo de despesa no banco de dados
        $expenseType = ExpenseType::where('code', $this->expense_type)
            ->where('is_active', true)
            ->first();

        return $expenseType ? $expenseType->name : 'Desconhecido';
    }

    /**
     * Accessor para exibir forma de pagamento em português
     */
    public function getPaymentMethodDisplayAttribute(): string
    {
        if (!$this->payment_method) {
            return 'Desconhecido';
        }

        // Buscar o método de pagamento no banco de dados
        $paymentMethod = PaymentMethod::where('code', $this->payment_method)
            ->where('is_active', true)
            ->first();

        return $paymentMethod ? $paymentMethod->name : 'Desconhecido';
    }

    /**
     * Accessor para URL completa do comprovante
     */
    public function getReceiptUrlAttribute(): ?string
    {
        if (!$this->receipt_path) {
            return null;
        }

        // Serve via endpoint da API (não depende de symlink do storage)
        $backendUrl = rtrim(config('app.url'), '/');
        return $backendUrl . '/api/v1/expenses/' . $this->id . '/receipt';
    }

    /**
     * Accessor para valor formatado
     */
    public function getFormattedAmountAttribute(): string
    {
        return 'R$ ' . number_format($this->amount, 2, ',', '.');
    }

    /**
     * Verifica se a despesa pode ser editada
     */
    public function canBeEdited(): bool
    {
        return $this->status === self::STATUS_PENDING ||
               $this->status === self::STATUS_ADJUSTMENT_REQUESTED ||
               $this->status === self::STATUS_REJECTED;
    }

    /**
     * Verifica se a despesa pode ser aprovada
     */
    public function canBeApproved(): bool
    {
        return $this->status === self::STATUS_PENDING ||
               $this->status === self::STATUS_ADJUSTMENT_REQUESTED;
    }

    /**
     * Verifica se um usuário pode aprovar esta despesa
     */
    public function canBeApprovedBy(User $user): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if (!$user->isCoordenador() || !$this->project) {
            return false;
        }

        // Coordenador de sustentação aprova qualquer despesa de projeto de sustentação
        if ($user->coordinator_type === 'sustentacao') {
            return $this->project->service_type_id &&
                \App\Models\ServiceType::where('id', $this->project->service_type_id)
                    ->where('code', 'sustentacao')
                    ->exists();
        }

        // Coordenador de projetos aprova projetos onde está vinculado como coordenador
        return $this->project->coordinators()->where('users.id', $user->id)->exists();
    }

    /**
     * Aprovar despesa
     */
    public function approve(User $approver, bool $chargeClient = false): bool
    {
        if (!$this->canBeApproved() || !$this->canBeApprovedBy($approver)) {
            return false;
        }

        $this->status = self::STATUS_APPROVED;
        $this->reviewed_by = $approver->id;
        $this->reviewed_at = now();
        $this->charge_client = $chargeClient;
        $this->rejection_reason = null;

        return $this->save();
    }

    /**
     * Rejeitar despesa
     */
    public function reject(User $approver, string $reason): bool
    {
        if (!$this->canBeApproved() || !$this->canBeApprovedBy($approver)) {
            return false;
        }

        $this->status = self::STATUS_REJECTED;
        $this->reviewed_by = $approver->id;
        $this->reviewed_at = now();
        $this->rejection_reason = $reason;
        $this->charge_client = false;

        return $this->save();
    }

    /**
     * Solicitar ajuste na despesa
     */
    public function requestAdjustment(User $approver, string $reason): bool
    {
        if (!$this->canBeApproved() || !$this->canBeApprovedBy($approver)) {
            return false;
        }

        $this->status = self::STATUS_ADJUSTMENT_REQUESTED;
        $this->reviewed_by = $approver->id;
        $this->reviewed_at = now();
        $this->rejection_reason = $reason;

        return $this->save();
    }

    /**
     * Verifica se a despesa pode ter sua aprovação estornada
     */
    public function canBeReversedBy(User $user): bool
    {
        // Apenas despesas aprovadas podem ser estornadas
        if ($this->status !== self::STATUS_APPROVED) {
            return false;
        }

        // Administradores podem estornar qualquer aprovação
        if ($user->isAdmin()) {
            return true;
        }

        // Quem aprovou pode estornar dentro do período permitido
        if ($this->reviewed_by === $user->id) {
            $reversalPeriod = config('expenses.reversal_period_hours', 24);
            $reversalDeadline = $this->reviewed_at->addHours($reversalPeriod);

            return now()->lt($reversalDeadline);
        }

        return false;
    }

    /**
     * Estornar aprovação da despesa
     */
    public function reverseApproval(User $reverser, string $reason): bool
    {
        if (!$this->canBeReversedBy($reverser)) {
            return false;
        }

        try {
            $this->reversals()->create([
                'reversed_by'            => $reverser->id,
                'reversal_reason'        => $reason,
                'original_approver_id'   => $this->reviewed_by ?? $reverser->id,
                'original_approval_date' => $this->reviewed_at ?? now(),
            ]);
        } catch (\Exception $e) {
            // Tabela expense_reversals não migrada ainda — continua sem o log
        }

        $this->status          = self::STATUS_PENDING;
        $this->reviewed_by     = null;
        $this->reviewed_at     = null;
        $this->rejection_reason = null;
        $this->charge_client   = false;

        return $this->save();
    }

    /**
     * Verifica se a despesa pode ter sua rejeição estornada
     */
    public function canBeRejectionReversedBy(User $user): bool
    {
        // Apenas despesas rejeitadas podem ser estornadas
        if ($this->status !== self::STATUS_REJECTED) {
            return false;
        }

        // Administradores podem estornar qualquer rejeição
        if ($user->isAdmin()) {
            return true;
        }

        // Quem rejeitou pode estornar dentro do período permitido
        if ($this->reviewed_by === $user->id) {
            $reversalPeriod = config('expenses.reversal_period_hours', 24);
            $reversalDeadline = $this->reviewed_at->addHours($reversalPeriod);

            return now()->lt($reversalDeadline);
        }

        return false;
    }

    /**
     * Estornar rejeição da despesa
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
        $this->charge_client = false;

        return $this->save();
    }

    /**
     * Scope para filtrar por usuário
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope para filtrar por projeto
     */
    public function scopeForProject(Builder $query, int $projectId): Builder
    {
        return $query->where('project_id', $projectId);
    }

    /**
     * Scope para filtrar por período
     */
    public function scopeInPeriod(Builder $query, string $startDate, string $endDate): Builder
    {
        return $query->whereBetween('expense_date', [$startDate, $endDate]);
    }

    /**
     * Scope para filtrar por status
     */
    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Retorna todos os status possíveis
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_PENDING => 'Pendente',
            self::STATUS_APPROVED => 'Aprovado',
            self::STATUS_REJECTED => 'Rejeitado',
            self::STATUS_ADJUSTMENT_REQUESTED => 'Ajuste Solicitado',
        ];
    }

    /**
     * Retorna todos os tipos de despesa ativos do banco de dados
     */
    public static function getExpenseTypes(): array
    {
        return ExpenseType::where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->pluck('name', 'code')
            ->toArray();
    }

    /**
     * Retorna todas as formas de pagamento ativas do banco de dados
     */
    public static function getPaymentMethods(): array
    {
        return PaymentMethod::where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->pluck('name', 'code')
            ->toArray();
    }
}
