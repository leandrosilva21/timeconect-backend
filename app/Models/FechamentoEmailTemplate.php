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
    public const CONTRACT_TYPES = ['cooperado', 'clt', 'pj'];
}
