<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractValueChange extends Model
{
    protected $fillable = [
        'contract_id',
        'valor_anterior',
        'valor_novo',
        'percentual',
        'indice',
        'periodo_inicio',
        'periodo_fim',
        'periodo_formatado',
        'user_id',
    ];

    protected $casts = [
        'valor_anterior' => 'decimal:2',
        'valor_novo'     => 'decimal:2',
        'percentual'     => 'decimal:4',
        'periodo_inicio' => 'date:Y-m-d',
        'periodo_fim'    => 'date:Y-m-d',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
