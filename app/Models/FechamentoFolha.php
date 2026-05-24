<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Valores editáveis da folha de pagamento por consultor/mês (planilha de importação).
 */
class FechamentoFolha extends Model
{
    protected $table = 'fechamento_folha';

    protected $fillable = [
        'user_id',
        'socio_key',
        'year_month',
        // overrides de linha-sócio (manual)
        'cpf',
        'matricula',
        'nome',
        'status',
        'valor_hora',
        'producao',
        // editáveis comuns
        'dias_trabalhados',
        'horas_trabalhadas',
        'variavel',
        'reemb',
        'descontos_diversos',
        'adiantamento',
        'horista_mensalista',
        'cancelado',
    ];

    protected $casts = [
        'cancelado'          => 'boolean',
        'dias_trabalhados'   => 'decimal:2',
        'horas_trabalhadas'  => 'decimal:2',
        'valor_hora'         => 'decimal:4',
        'producao'           => 'decimal:2',
        'variavel'           => 'decimal:2',
        'reemb'              => 'decimal:2',
        'descontos_diversos' => 'decimal:2',
        'adiantamento'       => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
