<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ServiceType;

/**
 * Filtro de "projetos de sustentação" usado pelo Portal de Sustentação.
 * Centraliza a regra (incluindo override de coordenador) que antes vivia só
 * no SustentacaoController e agora também é aplicada nos endpoints originais
 * (timesheets/expenses/approvals/timesheet-logs) via `?scope=sustentacao`.
 */
class SustentacaoScopeService
{
    /**
     * IDs dos service_types cujo code/nome representa sustentação.
     */
    public function serviceTypeIds(): array
    {
        return ServiceType::where('code', 'sustentacao')
            ->orWhereRaw('LOWER(TRIM(name)) IN (?, ?)', ['sustentação', 'sustentacao'])
            ->pluck('id')
            ->all();
    }

    /**
     * IDs dos projetos elegíveis ao Portal de Sustentação.
     * - service_type pertence à lista de sustentação
     * - projeto não está soft-deleted
     * - projeto não tem override de coordenador (kanban_coordinator_override_id IS NULL).
     *   Projetos com override são gerenciados pelo coord override e não aparecem
     *   nas abas Apontamentos/Despesas/Aprovações/Auditoria do portal.
     */
    public function projectIds(?int $customerId = null): array
    {
        $stIds = $this->serviceTypeIds();
        if (empty($stIds)) return [];

        $q = Project::whereIn('service_type_id', $stIds)
            ->whereNull('deleted_at')
            ->whereNull('kanban_coordinator_override_id');

        if ($customerId) $q->where('customer_id', $customerId);

        return $q->pluck('id')->all();
    }

    /**
     * Conveniência: aplica o filtro num query builder de Eloquent já existente,
     * filtrando pela coluna `project_id` (default) ou outra coluna informada.
     * Quando não há projetos elegíveis, força resultado vazio.
     */
    public function applyToQuery($query, ?int $customerId = null, string $column = 'project_id')
    {
        $ids = $this->projectIds($customerId);
        if (empty($ids)) {
            return $query->whereRaw('1 = 0');
        }
        return $query->whereIn($column, $ids);
    }
}
