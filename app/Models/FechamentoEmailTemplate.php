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
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public const CATEGORIAS = ['consultor', 'parceiro', 'cliente'];
    public const CONTRACT_TYPES = ['cooperado', 'clt', 'pj'];
}
