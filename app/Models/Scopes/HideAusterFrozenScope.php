<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Esconde subprojetos da Auster (customer_id 220) com start_date anterior a 2025-05-01.
 * Esses projetos são histórico somente-leitura — invisíveis em listagens, eager loads
 * e queries em geral. Pra acessá-los (ex: indicadores específicos), use:
 *   Project::withoutGlobalScope(HideAusterFrozenScope::class)
 */
class HideAusterFrozenScope implements Scope
{
    public const AUSTER_CUSTOMER_ID = 220;
    public const FREEZE_DATE        = '2025-05-01';

    public function apply(Builder $builder, Model $model): void
    {
        $builder->where(function (Builder $q) {
            $q->where(function (Builder $inner) {
                $inner->where('customer_id', '!=', self::AUSTER_CUSTOMER_ID)
                      ->orWhereNull('parent_project_id')
                      ->orWhereNull('start_date')
                      ->orWhere('start_date', '>=', self::FREEZE_DATE);
            });
        });
    }
}
