<?php

namespace App\Http\Controllers;

use App\Models\Timesheet;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FechamentoContratoController extends Controller
{
    private function period(string $yearMonth): array
    {
        $from = "{$yearMonth}-01";
        $to   = Carbon::parse($from)->endOfMonth()->toDateString();
        return [$from, $to];
    }

    public function index(Request $request): JsonResponse
    {
        $yearMonth = $request->query('year_month');
        if (!$yearMonth) {
            return response()->json(['data' => ['tipos' => [], 'total_geral' => 0]]);
        }

        return response()->json(['data' => $this->buildData($yearMonth)]);
    }

    private function buildData(string $yearMonth): array
    {
        [$from, $to] = $this->period($yearMonth);

        // Busca todos os apontamentos aprovados do mês com dados do projeto e pai
        $timesheets = Timesheet::with([
            'project:id,name,code,customer_id,parent_project_id,contract_type_id,hourly_rate,project_value,sold_hours',
            'project.contractType:id,name,code',
            'project.parentProject:id,name,code,customer_id,contract_type_id,hourly_rate,project_value,sold_hours',
            'project.parentProject.contractType:id,name,code',
            'project.customer:id,name,company_name',
            'project.parentProject.customer:id,name,company_name',
        ])
            ->whereBetween('date', [$from, $to])
            ->whereNotIn('status', [Timesheet::STATUS_ADJUSTMENT_REQUESTED, Timesheet::STATUS_REJECTED, Timesheet::STATUS_LATE])
            ->whereNull('deleted_at')
            ->get();

        // Acumula minutos: [typeCode][customerId][rootProjectId]
        $minutesMap = [];
        $typeMeta   = [];
        $custMeta   = [];
        $projMeta   = [];

        foreach ($timesheets as $ts) {
            $project = $ts->project;
            if (!$project) continue;

            // Regra: contrato filho não entra — usa o projeto pai como raiz
            $root = ($project->parent_project_id && $project->parentProject)
                ? $project->parentProject
                : $project;

            $ct = $root->contractType;
            if (!$ct) continue;

            $typeCode = $ct->code;
            $custId   = $root->customer_id ?? $project->customer_id;
            $rootId   = $root->id;

            // Metadados de tipo
            $typeMeta[$typeCode] ??= ['code' => $typeCode, 'nome' => $ct->name];

            // Metadados de cliente
            if (!isset($custMeta[$custId])) {
                $custModel = $root->customer ?? $project->customer;
                $custMeta[$custId] = [
                    'customer_id' => $custId,
                    'nome'        => $custModel?->company_name ?: ($custModel?->name ?? '—'),
                ];
            }

            // Metadados de projeto raiz
            $projMeta[$rootId] ??= [
                'projeto_id'    => $rootId,
                'nome'          => $root->name,
                'codigo'        => $root->code ?? '—',
                'hourly_rate'   => (float) ($root->hourly_rate ?? 0),
                'sold_hours'    => (float) ($root->sold_hours ?? 0),
                'project_value' => (float) ($root->project_value ?? 0),
                'type_code'     => $typeCode,
            ];

            $minutesMap[$typeCode][$custId][$rootId] =
                ($minutesMap[$typeCode][$custId][$rootId] ?? 0) + (int) $ts->effort_minutes;
        }

        // Flag "faturado / NFS-e enviada" por projeto (pai) neste mês (existência da linha).
        $invoicedSet = \App\Models\OnDemandInvoicedMonth::where('year_month', $yearMonth)
            ->whereIn('project_id', array_keys($projMeta))
            ->pluck('project_id')
            ->flip()
            ->toArray();

        // Para banco de horas: horas consumidas acumuladas (all-time) por projeto raiz
        $bhProjectIds = collect($projMeta)
            ->filter(fn ($p) => in_array($p['type_code'], ['fixed_hours', 'monthly_hours']))
            ->keys()
            ->values();

        $allTimeMinutes = [];
        if ($bhProjectIds->isNotEmpty()) {
            $allTimeMinutes = Timesheet::whereNotIn('status', [Timesheet::STATUS_ADJUSTMENT_REQUESTED, Timesheet::STATUS_REJECTED, Timesheet::STATUS_LATE])
                ->whereNull('deleted_at')
                ->whereIn('project_id', $bhProjectIds)
                ->selectRaw('project_id, SUM(effort_minutes) as total')
                ->groupBy('project_id')
                ->pluck('total', 'project_id')
                ->toArray();
        }

        // Monta resultado
        $tipos      = [];
        $totalGeral = 0.0;

        foreach ($minutesMap as $typeCode => $byCustomer) {
            $typeTotalHoras   = 0.0;
            $typeTotalReceita = 0.0;
            $clientes         = [];

            foreach ($byCustomer as $custId => $byProject) {
                $custHoras   = 0.0;
                $custReceita = 0.0;
                $projetos    = [];

                foreach ($byProject as $projId => $mins) {
                    $p    = $projMeta[$projId];
                    $horas = round($mins / 60, 2);
                    $rate  = $p['hourly_rate'];
                    $sold  = $p['sold_hours'];
                    $pval  = $p['project_value'];

                    if (in_array($typeCode, ['fixed_hours', 'monthly_hours'])) {
                        $consumedAll = round(($allTimeMinutes[$projId] ?? 0) / 60, 2);
                        $excHoras    = round(max(0, $consumedAll - $sold), 2);
                        $excValor    = round($excHoras * $rate, 2);
                        $mensal      = round($sold * $rate, 2);
                        $receita     = round($mensal + $excValor, 2);
                        $displayRate = $rate;
                    } elseif ($typeCode === 'closed') {
                        $receita     = $pval;
                        $displayRate = 0.0;
                        $excHoras    = 0.0;
                        $excValor    = 0.0;
                        $mensal      = $pval;
                    } else {
                        // on_demand e outros
                        $receita     = round($horas * $rate, 2);
                        $displayRate = $rate;
                        $excHoras    = 0.0;
                        $excValor    = 0.0;
                        $mensal      = 0.0;
                    }

                    $projetos[] = [
                        'projeto_id'      => $projId,
                        'nome'            => $p['nome'],
                        'codigo'          => $p['codigo'],
                        'horas'           => $horas,
                        'valor_hora'      => $displayRate,
                        'excedente_horas' => $excHoras,
                        'excedente_valor' => $excValor,
                        'valor_mensal'    => $mensal,
                        'total_receita'   => $receita,
                        'invoiced'        => isset($invoicedSet[$projId]),
                    ];

                    $custHoras   += $horas;
                    $custReceita += $receita;
                }

                usort($projetos, fn ($a, $b) => strcmp($a['nome'], $b['nome']));

                $clientes[] = [
                    'customer_id'   => $custId,
                    'nome'          => $custMeta[$custId]['nome'],
                    'projetos'      => $projetos,
                    'total_horas'   => round($custHoras, 2),
                    'total_receita' => round($custReceita, 2),
                ];

                $typeTotalHoras   += $custHoras;
                $typeTotalReceita += $custReceita;
            }

            usort($clientes, fn ($a, $b) => strcmp($a['nome'], $b['nome']));

            $tipos[] = [
                'code'           => $typeCode,
                'nome'           => $typeMeta[$typeCode]['nome'],
                'clientes'       => $clientes,
                'total_clientes' => count($clientes),
                'total_horas'    => round($typeTotalHoras, 2),
                'total_receita'  => round($typeTotalReceita, 2),
            ];

            $totalGeral += $typeTotalReceita;
        }

        usort($tipos, fn ($a, $b) => strcmp($a['nome'], $b['nome']));

        return [
            'tipos'       => $tipos,
            'total_geral' => round($totalGeral, 2),
        ];
    }
}
