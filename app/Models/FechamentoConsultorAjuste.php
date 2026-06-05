<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FechamentoConsultorAjuste extends Model
{
    protected $table = 'fechamento_consultor_ajustes';

    protected $fillable = [
        'user_id',
        'year_month',
        'desconto',
        'desconto_desc',
        'adiantamento',
        'adicional',
        'adicional_desc',
    ];

    protected $casts = [
        'desconto'     => 'decimal:2',
        'adiantamento' => 'decimal:2',
        'adicional'    => 'decimal:2',
    ];
}
