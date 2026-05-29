<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Facades\Hash;
use App\Notifications\ResetPasswordNotification;
use App\Services\PermissionService;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;
    use \App\Attachments\Concerns\HasGlobalAttachments;

    // FASE 11 — chave do registry global de anexos.
    public static function attachmentEntityType(): string { return 'USER'; }

    /**
     * Campos que controlam autorização e identidade — fora de $fillable para evitar
     * escalada de privilégio via mass assignment. Setar apenas via forceFill() ou
     * setAttribute() em fluxos administrativos com policy/permission validados.
     */
    public const PROTECTED_FIELDS = [
        'is_executive',
        'type',
        'coordinator_type',
        'can_timesheet_sustentacao',
        'extra_permissions',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'enabled',
        'hourly_rate',
        'rate_type',
        'daily_hours',
        'consultant_type',
        'contract_type',
        // Folha de pagamento (planilha de importação)
        'full_name',
        'cpf',
        'matricula',
        'payroll_status',
        'bank_hours_start_date',
        'guaranteed_hours',
        'theme_preference',
        'has_temporary_password',
        'temporary_password_expires_at',
        'customer_id',
        'partner_id',
        // Type/permission flags
        'type',
        'coordinator_type',
        'is_executive',
        'can_timesheet_sustentacao',
        'extra_permissions',
        // Capacity (módulo skills)
        'capacity_hours',
        'allocated_hours',
        // Profile fields (form perfil-skills + candidato)
        'availability_status',
        'availability_start_date',
        'relevant_projects',
        'segments',
        // Candidato form
        'phone',
        'linkedin_url',
        'work_model',
        'city',
        'state',
        'protheus_years_experience',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = [
        'profile_photo_url'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        // App Password de SMTP do usuário — NUNCA serializar em JSON/API.
        'smtp_app_password',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'enabled' => 'boolean',
            'hourly_rate' => 'decimal:2',
            'daily_hours' => 'decimal:2',
            'has_temporary_password' => 'boolean',
            'temporary_password_expires_at' => 'datetime',
            'is_executive' => 'boolean',
            'can_timesheet_sustentacao' => 'boolean',
            'bank_hours_start_date' => 'date:Y-m-d',
            'guaranteed_hours'      => 'decimal:2',
            'extra_permissions'     => 'array',
            'segments' => 'array',
            'extra_permissions' => 'array',
            // Criptografa em repouso com APP_KEY; descriptografa na leitura.
            'smtp_app_password' => 'encrypted',
        ];
    }

    /**
     * Pode enviar e-mail COMO ele mesmo (From = próprio e-mail) via App Password O365?
     * Exige App Password configurado E e-mail cadastrado. Caso contrário, usa o remetente padrão.
     */
    public function canSendAsSelf(): bool
    {
        return filled($this->smtp_app_password) && filled($this->email);
    }

    /**
     * Método seguro para verificar senha
     */
    public function verifyPassword(string $password): bool
    {
        // Lê o hash direto do banco, sem passar pelo cast 'hashed'
        $stored = \DB::table('users')->where('id', $this->id)->value('password');
        return Hash::check($password, $stored);
    }

    /**
     * Método seguro para atualizar senha
     */
    public function updatePassword(string $newPassword): void
    {
        \DB::table('users')->where('id', $this->id)->update([
            'password'   => Hash::make($newPassword),
            'updated_at' => now(),
        ]);
        $this->refresh();
    }

    /**
     * Relacionamento com cliente (se o usuário for de um cliente específico)
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Relacionamento com parceiro (se o usuário for do tipo Parceiro / Parceiro ADM)
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /**
     * Valor hora efetivo: resolve dinamicamente conforme pricing_type do parceiro.
     * fixed   → usa partner.hourly_rate (ignora user.hourly_rate)
     * variable → usa user.hourly_rate
     */
    public function getEffectiveHourlyRateAttribute(): ?string
    {
        if ($this->partner_id && $this->relationLoaded('partner') && $this->partner) {
            if ($this->partner->pricing_type === Partner::PRICING_FIXED) {
                return $this->partner->hourly_rate;
            }
        }
        return $this->hourly_rate;
    }

    // ── Métodos semânticos de tipo ────────────────────────────────────────────
    // Fonte de verdade: users.type

    public function isAdmin(): bool            { return $this->type === 'admin'; }
    public function isAdministrativo(): bool   { return $this->type === 'administrativo'; }
    public function isCoordenador(): bool      { return $this->type === 'coordenador'; }
    public function isConsultor(): bool     { return $this->type === 'consultor'; }
    public function isCliente(): bool       { return $this->type === 'cliente'; }
    public function isParceiroAdmin(): bool { return $this->type === 'parceiro_admin'; }

    /**
     * Verifica se o usuário tem acesso a uma permissão específica via PermissionService.
     */
    public function hasAccess(string $permission): bool
    {
        $permissions = PermissionService::for($this);
        if (in_array('*', $permissions, true)) return true;
        return in_array($permission, $permissions, true);
    }

    // ── Legado ────────────────────────────────────────────────────────────────

    /**
     * Verifica se o usuário é um usuário de cliente
     */
    public function isCustomerUser(): bool
    {
        return $this->customer_id !== null;
    }

    /**
     * Verifica se o usuário é um usuário interno
     */
    public function isInternalUser(): bool
    {
        return $this->customer_id === null;
    }

    /**
     * Relacionamento com projetos onde o usuário é consultor
     */
    public function consultantProjects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_consultants')
                    ->withTimestamps();
    }

    /**
     * Relacionamento com projetos onde o usuário é coordenador
     */
    public function coordinatorProjects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_coordinators')
                    ->withTimestamps();
    }

    /**
     * @deprecated Use coordinatorProjects()
     */
    public function approverProjects(): BelongsToMany
    {
        return $this->coordinatorProjects();
    }

    /**
     * Relacionamento com grupos de consultores
     */
    public function consultantGroups(): BelongsToMany
    {
        return $this->belongsToMany(ConsultantGroup::class, 'consultant_group_user')
                    ->withTimestamps();
    }

    public function permissionGroups(): BelongsToMany
    {
        return $this->belongsToMany(PermissionGroup::class, 'permission_group_user');
    }

    /**
     * Todos os projetos do usuário (consultor ou aprovador)
     */
    public function allProjects(): BelongsToMany
    {
        return $this->consultantProjects()->union($this->approverProjects()->getQuery());
    }

    /**
     * Relacionamento com logs de alteração de valor hora
     */
    public function hourlyRateLogs(): HasMany
    {
        return $this->hasMany(UserHourlyRateLog::class, 'user_id');
    }

    /**
     * Relacionamento com logs de alterações feitas por este usuário
     */
    public function hourlyRateChangesMade(): HasMany
    {
        return $this->hasMany(UserHourlyRateLog::class, 'changed_by');
    }

    /**
     * Send the password reset notification.
     *
     * @param string $token
     * @return void
     */
    public function sendPasswordResetNotification($token)
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    /**
     * Verifica se o usuário está usando uma senha temporária
     */
    public function hasTemporaryPassword(): bool
    {
        return $this->has_temporary_password &&
               $this->temporary_password_expires_at &&
               $this->temporary_password_expires_at->isFuture();
    }

    /**
     * Verifica se a senha temporária expirou
     */
    public function temporaryPasswordExpired(): bool
    {
        return $this->has_temporary_password &&
               $this->temporary_password_expires_at &&
               $this->temporary_password_expires_at->isPast();
    }

    /**
     * Define uma senha temporária com expiração
     */
    public function setTemporaryPassword(string $password, int $hoursToExpire = 24): void
    {
        \DB::table('users')->where('id', $this->id)->update([
            'password'                      => Hash::make($password),
            'has_temporary_password'        => true,
            'temporary_password_expires_at' => now()->addHours($hoursToExpire),
            'updated_at'                    => now(),
        ]);
        $this->refresh();
    }

    /**
     * Remove a marcação de senha temporária
     */
    public function clearTemporaryPassword(): void
    {
        $this->update([
            'has_temporary_password' => false,
            'temporary_password_expires_at' => null,
        ]);
    }

    /**
     * Obtém a URL da foto de perfil — 100% via nova camada (FASE 11.7).
     */
    public function getProfilePhotoUrlAttribute(): ?string
    {
        return $this->attachmentUrl('avatar');
    }


    /**
     * Remove a foto de perfil — soft-delete na camada Attachment.
     * O arquivo físico não é removido; só a referência é invalidada
     * (deleted_at na tabela attachments). Restore possível.
     */
    public function removeProfilePhoto(): void
    {
        try {
            \App\Models\Attachment::query()
                ->forEntity('USER', $this->id)
                ->ofCategory('avatar')
                ->whereNull('deleted_at')
                ->get()
                ->each(fn ($att) => $att->delete());
        } catch (\Throwable $e) {
            \Log::warning('USER.avatar soft-delete falhou', [
                'user_id' => $this->id, 'error' => $e->getMessage(),
            ]);
        }
    }


    /**
     * Obtém os tipos de dashboard permitidos para o usuário
     */
    public function getAllowedDashboardTypes(): array
    {
        return \DB::table('user_dashboard_types')
            ->where('user_id', $this->id)
            ->pluck('dashboard_type')
            ->toArray();
    }

    /**
     * Verifica se o usuário tem acesso a um tipo específico de dashboard
     */
    public function hasDashboardAccess(string $dashboardType): bool
    {
        return \DB::table('user_dashboard_types')
            ->where('user_id', $this->id)
            ->where('dashboard_type', $dashboardType)
            ->exists();
    }

    /**
     * Adiciona acesso a um tipo de dashboard
     */
    public function addDashboardAccess(string $dashboardType): void
    {
        \DB::table('user_dashboard_types')->updateOrInsert(
            [
                'user_id' => $this->id,
                'dashboard_type' => $dashboardType
            ],
            [
                'created_at' => now(),
                'updated_at' => now()
            ]
        );
    }

    /**
     * Remove acesso a um tipo de dashboard
     */
    public function removeDashboardAccess(string $dashboardType): void
    {
        \DB::table('user_dashboard_types')
            ->where('user_id', $this->id)
            ->where('dashboard_type', $dashboardType)
            ->delete();
    }

    /**
     * Sincroniza os tipos de dashboard permitidos
     */
    public function syncDashboardTypes(array $dashboardTypes): void
    {
        // Remove todos os tipos atuais
        \DB::table('user_dashboard_types')
            ->where('user_id', $this->id)
            ->delete();

        // Adiciona os novos tipos
        foreach ($dashboardTypes as $dashboardType) {
            $this->addDashboardAccess($dashboardType);
        }
    }
}
