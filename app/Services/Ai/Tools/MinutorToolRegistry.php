<?php

namespace App\Services\Ai\Tools;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Timesheet;
use Illuminate\Support\Facades\DB;

/**
 * Registro de tools disponíveis pro BOT consultar dados do Minutor.
 * Format compatível com Anthropic tool_use API.
 */
class MinutorToolRegistry
{
    /**
     * Definições das tools no formato Anthropic.
     * @return array<int,array{name:string,description:string,input_schema:array}>
     */
    public function definitions(): array
    {
        return [
            [
                'name' => 'search_customer',
                'description' => 'Busca cliente por nome ou parte do nome. Retorna até 5 resultados com id, nome, cnpj e status ativo. Use isto antes de qualquer outra consulta para resolver o customer_id.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Nome ou parte do nome do cliente (case-insensitive)'],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'get_customer_overview',
                'description' => 'Retorna visão geral do cliente: total de projetos por status, total de horas vendidas/consumidas/saldo agregado, total de contratos. Use para perguntas de "como está o cliente X".',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'customer_id' => ['type' => 'integer', 'description' => 'ID do cliente (obter via search_customer primeiro)'],
                    ],
                    'required' => ['customer_id'],
                ],
            ],
            [
                'name' => 'list_customer_projects',
                'description' => 'Lista todos os projetos de um cliente com nome, código, status, horas vendidas, horas consumidas e saldo. Use para perguntas como "quais os projetos do cliente X".',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'customer_id' => ['type' => 'integer'],
                        'only_active'  => ['type' => 'boolean', 'description' => 'Se true, filtra apenas status=started ou awaiting_start. Default: false (todos).'],
                    ],
                    'required' => ['customer_id'],
                ],
            ],
            [
                'name' => 'get_project_details',
                'description' => 'Detalhes de um projeto específico: nome, status, datas, horas, contrato vinculado, executivo, vendedor, arquiteto. Use após listar projetos para aprofundar em um.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer'],
                    ],
                    'required' => ['project_id'],
                ],
            ],
            [
                'name' => 'list_customer_contracts',
                'description' => 'Lista contratos de um cliente com tipo, status, valor, horas contratadas. Use para perguntas sobre contratos comerciais.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'customer_id' => ['type' => 'integer'],
                    ],
                    'required' => ['customer_id'],
                ],
            ],
        ];
    }

    /**
     * Executa uma tool pelo nome e retorna o resultado como array serializável.
     */
    public function execute(string $name, array $input): array
    {
        return match ($name) {
            'search_customer'         => $this->searchCustomer((string) ($input['query'] ?? '')),
            'get_customer_overview'   => $this->customerOverview((int) ($input['customer_id'] ?? 0)),
            'list_customer_projects'  => $this->listProjects((int) ($input['customer_id'] ?? 0), (bool) ($input['only_active'] ?? false)),
            'get_project_details'     => $this->projectDetails((int) ($input['project_id'] ?? 0)),
            'list_customer_contracts' => $this->listContracts((int) ($input['customer_id'] ?? 0)),
            default                   => ['error' => "Tool desconhecida: $name"],
        };
    }

    private function searchCustomer(string $q): array
    {
        if (trim($q) === '') return ['error' => 'query vazia'];
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], trim($q)) . '%';

        $rows = Customer::query()
            ->where(function ($w) use ($like) {
                $w->where('name', 'ilike', $like)
                  ->orWhere('company_name', 'ilike', $like)
                  ->orWhere('cgc', 'ilike', $like);
            })
            ->limit(5)
            ->get(['id', 'name', 'company_name', 'cgc', 'active']);

        return [
            'results' => $rows->map(fn ($c) => [
                'id'           => $c->id,
                'name'         => $c->name,
                'company_name' => $c->company_name,
                'cnpj'         => $c->cgc,
                'active'       => (bool) $c->active,
            ])->all(),
        ];
    }

    private function customerOverview(int $customerId): array
    {
        $customer = Customer::find($customerId);
        if (! $customer) return ['error' => 'Cliente não encontrado'];

        $projects = Project::where('customer_id', $customerId)->get(['id', 'status', 'sold_hours']);

        // Horas consumidas via timesheet (minutos → horas) por project_id
        $projectIds = $projects->pluck('id');
        $consumedByProject = Timesheet::query()
            ->whereIn('project_id', $projectIds)
            ->select('project_id', DB::raw('SUM(effort_minutes) as min_total'))
            ->groupBy('project_id')
            ->pluck('min_total', 'project_id');

        $soldHours     = (float) $projects->sum('sold_hours');
        $consumedHours = round($consumedByProject->sum() / 60, 2);

        $byStatus = $projects->groupBy('status')->map->count();
        $contracts = Contract::where('customer_id', $customerId)->count();

        return [
            'customer' => [
                'id'   => $customer->id,
                'name' => $customer->name,
                'cnpj' => $customer->cgc,
                'active' => (bool) $customer->active,
            ],
            'projects_total'    => $projects->count(),
            'projects_by_status'=> $byStatus,
            'hours_sold'        => round($soldHours, 2),
            'hours_consumed'    => $consumedHours,
            'hours_balance'     => round($soldHours - $consumedHours, 2),
            'contracts_total'   => $contracts,
        ];
    }

    private function listProjects(int $customerId, bool $onlyActive): array
    {
        if (! Customer::where('id', $customerId)->exists()) return ['error' => 'Cliente não encontrado'];

        $q = Project::where('customer_id', $customerId);
        if ($onlyActive) $q->whereIn('status', ['started', 'awaiting_start']);

        $projects = $q->get(['id', 'name', 'code', 'status', 'sold_hours', 'start_date', 'expected_end_date']);
        $projectIds = $projects->pluck('id');

        $consumedByProject = Timesheet::query()
            ->whereIn('project_id', $projectIds)
            ->select('project_id', DB::raw('SUM(effort_minutes) as min_total'))
            ->groupBy('project_id')
            ->pluck('min_total', 'project_id');

        return [
            'count' => $projects->count(),
            'projects' => $projects->map(function (Project $p) use ($consumedByProject) {
                $consumed = round(($consumedByProject[$p->id] ?? 0) / 60, 2);
                $sold     = (float) $p->sold_hours;
                return [
                    'id'              => $p->id,
                    'code'            => $p->code,
                    'name'            => $p->name,
                    'status'          => $p->status,
                    'hours_sold'      => round($sold, 2),
                    'hours_consumed'  => $consumed,
                    'hours_balance'   => round($sold - $consumed, 2),
                    'consumed_pct'    => $sold > 0 ? round(($consumed / $sold) * 100, 1) : null,
                    'start_date'      => $p->start_date?->toDateString(),
                    'expected_end'    => $p->expected_end_date?->toDateString(),
                ];
            })->all(),
        ];
    }

    private function projectDetails(int $projectId): array
    {
        $p = Project::with(['customer:id,name', 'contract:id,tipo_contrato,status'])
            ->find($projectId);
        if (! $p) return ['error' => 'Projeto não encontrado'];

        $consumedMin = (int) Timesheet::where('project_id', $projectId)->sum('effort_minutes');
        $consumed = round($consumedMin / 60, 2);
        $sold = (float) $p->sold_hours;

        return [
            'id'             => $p->id,
            'code'           => $p->code,
            'name'           => $p->name,
            'status'         => $p->status,
            'customer'       => $p->customer ? ['id' => $p->customer->id, 'name' => $p->customer->name] : null,
            'contract'       => $p->contract ? ['id' => $p->contract->id, 'type' => $p->contract->tipo_contrato, 'status' => $p->contract->status] : null,
            'hours_sold'     => round($sold, 2),
            'hours_consumed' => $consumed,
            'hours_balance'  => round($sold - $consumed, 2),
            'consumed_pct'   => $sold > 0 ? round(($consumed / $sold) * 100, 1) : null,
            'start_date'     => $p->start_date?->toDateString(),
            'expected_end'   => $p->expected_end_date?->toDateString(),
            'project_value'  => (float) $p->project_value,
            'hourly_rate'    => (float) $p->hourly_rate,
            'tipo_faturamento' => $p->tipo_faturamento,
            'tipo_alocacao'  => $p->tipo_alocacao,
        ];
    }

    private function listContracts(int $customerId): array
    {
        if (! Customer::where('id', $customerId)->exists()) return ['error' => 'Cliente não encontrado'];

        $contracts = Contract::where('customer_id', $customerId)
            ->get(['id', 'tipo_contrato', 'tipo_faturamento', 'status', 'valor_projeto', 'valor_hora', 'horas_contratadas', 'expectativa_inicio']);

        return [
            'count' => $contracts->count(),
            'contracts' => $contracts->map(fn ($c) => [
                'id'                 => $c->id,
                'tipo'               => $c->tipo_contrato,
                'tipo_faturamento'   => $c->tipo_faturamento,
                'status'             => $c->status,
                'valor_projeto'      => (float) $c->valor_projeto,
                'valor_hora'         => (float) $c->valor_hora,
                'horas_contratadas'  => (float) $c->horas_contratadas,
                'expectativa_inicio' => $c->expectativa_inicio?->toDateString(),
            ])->all(),
        ];
    }
}
