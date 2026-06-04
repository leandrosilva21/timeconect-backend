<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FechamentoEmailTemplate extends Model
{
    protected $fillable = [
        'categoria',
        'contract_type',
        'nome',
        'subject',
        'body',
        'pay_day',
        'active',
    ];

    protected $casts = [
        'active'  => 'boolean',
        'pay_day' => 'integer',
    ];

    public const CATEGORIAS = ['consultor', 'parceiro', 'cliente'];
    // 'bizify' é eixo do consultor (User.is_bizify) e tem precedência sobre o contract_type real.
    public const CONTRACT_TYPES = ['cooperado', 'clt', 'pj', 'bizify'];
}
