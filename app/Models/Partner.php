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
        'contract_type',
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

    /** Todas as alterações de valor hora (histórico, mais recente primeiro). */
    public function hourlyRateLogs(): HasMany
    {
        return $this->hasMany(PartnerHourlyRateLog::class)->orderBy('created_at', 'desc');
    }

    /** Alterações COM vigência (effective_from), ordenadas — base do histórico temporal. */
    public function hourlyRateChanges(): HasMany
    {
        return $this->hasMany(PartnerHourlyRateLog::class)
            ->whereNotNull('effective_from')
            ->orderBy('effective_from');
    }

    /**
     * Valor hora vigente numa competência (YYYY-MM): a vigência mais recente com
     * effective_from <= competência; antes da 1ª usa o valor anterior a ela; sem
     * vigências, cai no hourly_rate atual. Garante legado intacto ao mudar o valor.
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
            ? (float) $aplicavel->new_hourly_rate
            : (float) ($changes->first()->old_hourly_rate ?? $this->hourly_rate ?? 0);
    }
}
