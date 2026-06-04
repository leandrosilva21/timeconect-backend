<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Marca de "faturado / NFS-e enviada" de um mês de um projeto On Demand (pai).
 * A existência da linha (project_id, year_month) = mês faturado.
 */
class OnDemandInvoicedMonth extends Model
{
    protected $fillable = [
        'project_id',
        'year_month',
        'invoiced_at',
        'invoiced_by',
    ];

    protected $casts = [
        'invoiced_at' => 'datetime',
    ];
}
