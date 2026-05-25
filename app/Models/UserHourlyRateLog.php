<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserHourlyRateLog extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'user_id',
        'changed_by',
        'old_hourly_rate',
        'new_hourly_rate',
        'old_rate_type',
        'new_rate_type',
        'reason',
        'old_consultant_type',
        'new_consultant_type',
        'effective_from',
    ];

    /**
     * Retorna os valores (hourly_rate, rate_type, consultant_type) em vigor na competência.
     * Vigência por log: a data ESCOLHIDA (effective_from) tem prioridade; logs legados sem
     * vigência caem no comportamento antigo (mudança vale a partir do MÊS SEGUINTE à troca).
     * Garante que alterar o valor NÃO mude fechamentos de meses anteriores ("legado intacto").
     */
    public static function effectiveValuesAt(int $userId, \App\Models\User $user, string $firstDayOfMonth): array
    {
        $comp = \Carbon\Carbon::parse($firstDayOfMonth)->startOfMonth();

        $applicable = static::where('user_id', $userId)
            ->orderBy('created_at')
            ->get()
            ->map(fn ($l) => [
                'log' => $l,
                'eff' => $l->effective_from
                    ? \Carbon\Carbon::parse($l->effective_from)->startOfMonth()
                    : \Carbon\Carbon::parse($l->created_at)->startOfMonth()->addMonthNoOverflow(),
            ])
            ->filter(fn ($x) => $x['eff']->lessThanOrEqualTo($comp))
            ->sortBy(fn ($x) => $x['eff']->timestamp)
            ->last();

        $log = $applicable ? $applicable['log'] : null;

        return [
            'hourly_rate'     => $log?->new_hourly_rate    ?? $user->hourly_rate,
            'rate_type'       => $log?->new_rate_type       ?? $user->rate_type,
            'consultant_type' => $log?->new_consultant_type ?? $user->consultant_type,
        ];
    }

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_hourly_rate' => 'decimal:2',
            'new_hourly_rate' => 'decimal:2',
            'effective_from'  => 'date:Y-m-d',
        ];
    }

    /**
     * Relacionamento com o usuário que teve o valor alterado
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Relacionamento com o usuário que fez a alteração
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /**
     * Scope para filtrar por usuário
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope para ordenar por data mais recente
     */
    public function scopeLatest($query)
    {
        return $query->orderBy('created_at', 'desc');
    }
}
