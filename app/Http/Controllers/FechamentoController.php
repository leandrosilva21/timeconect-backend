<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\FechamentoAdministrativo;
use App\Models\Project;
use App\Models\Timesheet;
use App\Models\UserHourlyRateLog;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FechamentoController extends Controller
{
    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function period(string $yearMonth): array
    {
        $from = "{$yearMonth}-01";
        $to   = Carbon::parse($from)->endOfMonth()->toDateString();
        return [$from, $to];
    }

    private function effectiveHourlyRate(float $hourlyRate, string $rateType): float
    {
        return ($rateType === 'monthly' && $hourlyRate > 0)
            ? round($hourlyRate / 180, 4)
            : $hourlyRate;
    }

    // ─── Index ────────────────────────────────────────────────────────────────

    public function index(): JsonResponse
    {
        $fechamentos = FechamentoAdministrativo::orderByDesc('year_month')
            ->with('closedByUser:id,name')
            ->get()
            ->map(fn ($f) => [
                'year_month'            => $f->year_month,
                'status'                => $f->status,
                'total_custo_interno'   => (float) $f->total_custo_interno,
                'total_custo_parceiros' => (float) $f->total_custo_parceiros,
                'total_receita'         => (float) $f->total_receita,
                'margem'                => (float) $f->margem,
                'margem_percentual'     => (float) $f->margem_percentual,
                'closed_at'             => $f->closed_at?->toISOString(),
                'closed_by_name'        => $f->closedByUser?->name,
            ]);

        return response()->json(['data' => $fechamentos]);
    }

    // ─── Produção ─────────────────────────────────────────────────────────────

    public function producao(Request $request, string $yearMonth): JsonResponse
    {
        $fechamento = FechamentoAdministrativo::where('year_month', $yearMonth)->first();
        if ($fechamento?->isClosed() && $fechamento->snapshot_producao) {
            return response()->json(['data' => $fechamento->snapshot_producao, 'from_snapshot' => true]);
        }

        [$from, $to] = $this->period($yearMonth);

        $timesheets = Timesheet::with([
            'user:id,name,type',
            'project:id,name,code,contract_type_id',
            'project.contractType:id,name,code',
            'project.customer:id,name',
        ])
            ->whereBetween('date', [$from, $to])
            ->whereNotIn('status', [Timesheet::STATUS_ADJUSTMENT_REQUESTED, Timesheet::STATUS_REJECTED])
            ->whereNull('deleted_at')
            ->get();

        $expenses = Expense::with([
            'project:id,name,code',
            'project.customer:id,name',
        ])
            ->whereBetween('expense_date', [$from, $to])
            ->whereIn('status', ['approved', 'pending'])
            ->where('is_paid', false)
            ->get()
            ->groupBy('project_id');

        // Agrupar por consultor → projeto
        $rows = [];
        foreach ($timesheets->groupBy('user_id') as $userId => $userTs) {
            $user = $userTs->first()->user;
            foreach ($userTs->groupBy('project_id') as $projectId => $projTs) {
                $project = $projTs->first()->project;
                $approved = round($projTs->where('status', Timesheet::STATUS_APPROVED)->sum('effort_minutes') / 60, 2);
                $pending  = round($projTs->whereIn('status', [Timesheet::STATUS_PENDING, Timesheet::STATUS_CONFLICTED])->sum('effort_minutes') / 60, 2);
                $projExpenses = $expenses->get($projectId, collect());
                $rows[] = [
                    'consultor_id'       => $userId,
                    'consultor_nome'     => $user->name ?? '—',
                    'consultor_tipo'     => $user->type ?? '—',
                    'projeto_id'         => $projectId,
                    'projeto_nome'       => $project->name ?? '—',
                    'projeto_codigo'     => $project->code ?? '—',
                    'cliente_nome'       => $project->customer->name ?? '—',
                    'tipo_contrato'      => $project->contractType->name ?? '—',
                    'tipo_contrato_code' => $project->contractType->code ?? '—',
                    'horas_aprovadas'    => $approved,
                    'horas_pendentes'    => $pending,
                    'despesas_aprovadas' => round($projExpenses->where('status', 'approved')->sum('amount'), 2),
                    'despesas_pendentes' => round($projExpenses->where('status', 'pending')->sum('amount'), 2),
                ];
            }
        }

        return response()->json(['data' => $rows, 'from_snapshot' => false]);
    }

    // ─── Custo ────────────────────────────────────────────────────────────────

    public function custo(string $yearMonth): JsonResponse
    {
        $fechamento = FechamentoAdministrativo::where('year_month', $yearMonth)->first();
        if ($fechamento?->isClosed() && $fechamento->snapshot_custo) {
            return response()->json(['data' => $fechamento->snapshot_custo, 'from_snapshot' => true]);
        }

        [$from, $to] = $this->period($yearMonth);

        $timesheets = Timesheet::with('user:id,name,type,hourly_rate,rate_type')
            ->whereBetween('date', [$from, $to])
            ->whereNotIn('status', [Timesheet::STATUS_ADJUSTMENT_REQUESTED, Timesheet::STATUS_REJECTED])
            ->whereNull('deleted_at')
            ->get();

        $internos  = [];
        $parceiros = [];

        foreach ($timesheets->groupBy('user_id') as $userId => $userTs) {
            $user          = $userTs->first()->user;
            $hist          = UserHourlyRateLog::effectiveValuesAt((int) $userId, $user, $from);
            $hourlyRate    = (float) ($hist['hourly_rate'] ?? 0);
            $rateType      = $hist['rate_type'] ?? 'hourly';
            $effectiveRate = $this->effectiveHourlyRate($hourlyRate, $rateType);
            $totalHours    = round($userTs->sum('effort_minutes') / 60, 2);
            $totalCost     = round($userTs->sum(function ($t) use ($effectiveRate) {
                $horas = $t->effort_minutes / 60;
                $mult  = 1 + (((float) ($t->consultant_extra_pct ?? 0)) / 100);
                return $horas * $effectiveRate * $mult;
            }), 2);

            $row = [
                'user_id'        => $userId,
                'nome'           => $user->name ?? '—',
                'tipo_usuario'   => $user->type ?? '—',
                'horas'          => $totalHours,
                'valor_hora'     => $hourlyRate,
                'rate_type'      => $rateType,
                'effective_rate' => $effectiveRate,
                'total'          => $totalCost,
            ];

            if ($user->type === 'parceiro_admin') {
                $parceiros[] = $row;
            } else {
                $internos[] = $row;
            }
        }

        $data = [
            'internos'               => $internos,
            'parceiros'              => $parceiros,
            'total_custo_interno'    => round(collect($internos)->sum('total'), 2),
            'total_custo_parceiros'  => round(collect($parceiros)->sum('total'), 2),
        ];

        return response()->json(['data' => $data, 'from_snapshot' => false]);
    }

    // ─── Receita ─────────────────────────────────────────────────────────────

    public function receita(string $yearMonth): JsonResponse
    {
        $fechamento = FechamentoAdministrativo::where('year_month', $yearMonth)->first();
        if ($fechamento?->isClosed() && $fechamento->snapshot_receita) {
            return response()->json(['data' => $fechamento->snapshot_receita, 'from_snapshot' => true]);
        }

        [$from, $to] = $this->period($yearMonth);

        $excludeStatuses = [Timesheet::STATUS_ADJUSTMENT_REQUESTED, Timesheet::STATUS_REJECTED];

        // Projetos que tiveram apontamentos no período (exceto ajuste/rejeitado)
        $projectIds = Timesheet::whereBetween('date', [$from, $to])
            ->whereNotIn('status', $excludeStatuses)
            ->whereNull('deleted_at')
            ->distinct()
            ->pluck('project_id');

        $projects = Project::with([
            'customer:id,name',
            'contractType:id,name,code',
        ])
            ->whereIn('id', $projectIds)
            ->get();

        // Horas por projeto no mês
        $hoursByProject = Timesheet::whereBetween('date', [$from, $to])
            ->whereNotIn('status', $excludeStatuses)
            ->whereNull('deleted_at')
            ->whereIn('project_id', $projectIds)
            ->selectRaw('project_id, SUM(effort_minutes) as total_minutes')
            ->groupBy('project_id')
            ->pluck('total_minutes', 'project_id');

        // Weighted revenue applying client_extra_pct (for on_demand billing)
        $weightedMinutesByProject = Timesheet::whereBetween('date', [$from, $to])
            ->whereNotIn('status', $excludeStatuses)
            ->whereNull('deleted_at')
            ->whereIn('project_id', $projectIds)
            ->selectRaw('project_id, SUM(effort_minutes * (1 + COALESCE(client_extra_pct, 0) / 100.0)) as weighted_minutes')
            ->groupBy('project_id')
            ->pluck('weighted_minutes', 'project_id');

        $rows = [];
        foreach ($projects as $project) {
            $totalMinutes  = (int) ($hoursByProject[$project->id] ?? 0);
            $totalHours    = round($totalMinutes / 60, 2);
            $contractCode  = strtolower($project->contractType->code ?? '');
            $hourlyRate    = (float) ($project->hourly_rate ?? 0);
            $projectValue  = (float) ($project->project_value ?? 0);

            // Calcular receita conforme tipo de contrato
            $extraReceita = 0.0;
            if (str_contains($contractCode, 'on_demand') || str_contains($contractCode, 'ondemand')) {
                $baseReceita      = round($totalHours * $hourlyRate, 2);
                $totalReceita     = round((float) ($weightedMinutesByProject[$project->id] ?? 0) / 60 * $hourlyRate, 2);
                $extraReceita     = round($totalReceita - $baseReceita, 2);
                $tipoFaturamento  = 'on_demand';
                $valorBase        = $hourlyRate;
            } elseif (str_contains($contractCode, 'banco_horas') || str_contains($contractCode, 'bank_hours')) {
                // Banco de horas: valor mensal = horas vendidas × valor/hora
                $totalReceita    = round(($project->sold_hours ?? 0) * $hourlyRate, 2);
                $tipoFaturamento = 'banco_horas';
                $valorBase       = $hourlyRate;
            } elseif (str_contains($contractCode, 'fechado')) {
                // Projeto fechado: receita é o valor total do projeto (exibido por ocasião)
                $totalReceita    = $projectValue;
                $tipoFaturamento = 'fechado';
                $valorBase       = $projectValue;
            } else {
                // Outros (cloud, etc.): horas × valor/hora
                $totalReceita    = round($totalHours * $hourlyRate, 2);
                $tipoFaturamento = $contractCode ?: 'outros';
                $valorBase       = $hourlyRate;
            }

            $rows[] = [
                'projeto_id'      => $project->id,
                'projeto_nome'    => $project->name,
                'projeto_codigo'  => $project->code,
                'cliente_id'      => $project->customer_id,
                'cliente_nome'    => $project->customer->name ?? '—',
                'tipo_contrato'   => $project->contractType->name ?? '—',
                'tipo_faturamento'=> $tipoFaturamento,
                'horas_aprovadas' => $totalHours,
                'valor_base'      => $valorBase,
                'total_receita'   => $totalReceita,
                'extra_receita'   => $extraReceita,
            ];
        }

        // Agrupar por cliente
        $byCliente = collect($rows)->groupBy('cliente_id')->map(fn ($g) => [
            'cliente_id'    => $g->first()['cliente_id'],
            'cliente_nome'  => $g->first()['cliente_nome'],
            'projetos'      => $g->values()->toArray(),
            'total_cliente' => round($g->sum('total_receita'), 2),
        ])->values();

        $data = [
            'by_cliente'    => $byCliente,
            'total_receita' => round(collect($rows)->sum('total_receita'), 2),
        ];

        return response()->json(['data' => $data, 'from_snapshot' => false]);
    }

    // ─── Consolidado ─────────────────────────────────────────────────────────

    public function consolidado(string $yearMonth): JsonResponse
    {
        $fechamento = FechamentoAdministrativo::where('year_month', $yearMonth)->first();
        if ($fechamento?->isClosed()) {
            return response()->json([
                'data' => [
                    'total_custo_interno'   => (float) $fechamento->total_custo_interno,
                    'total_custo_parceiros' => (float) $fechamento->total_custo_parceiros,
                    'total_receita'         => (float) $fechamento->total_receita,
                    'margem'                => (float) $fechamento->margem,
                    'margem_percentual'     => (float) $fechamento->margem_percentual,
                ],
                'from_snapshot' => true,
            ]);
        }

        $custo   = $this->custoData($yearMonth);
        $receita = $this->receitaData($yearMonth);

        $totalCusto   = $custo['total_custo_interno'] + $custo['total_custo_parceiros'];
        $totalReceita = $receita['total_receita'];
        $margem       = round($totalReceita - $totalCusto, 2);
        $margemPct    = $totalReceita > 0 ? round(($margem / $totalReceita) * 100, 4) : 0;

        return response()->json([
            'data' => [
                'total_custo_interno'   => $custo['total_custo_interno'],
                'total_custo_parceiros' => $custo['total_custo_parceiros'],
                'total_receita'         => $totalReceita,
                'margem'                => $margem,
                'margem_percentual'     => $margemPct,
            ],
            'from_snapshot' => false,
        ]);
    }

    // ─── Validar ─────────────────────────────────────────────────────────────

    public function validar(string $yearMonth): JsonResponse
    {
        $fechamento = FechamentoAdministrativo::where('year_month', $yearMonth)->first();
        if ($fechamento?->isClosed()) {
            return response()->json(['pode_fechar' => false, 'ja_fechado' => true, 'alertas' => []]);
        }

        [$from, $to] = $this->period($yearMonth);

        $apontamentosPendentes = Timesheet::whereBetween('date', [$from, $to])
            ->where('status', Timesheet::STATUS_PENDING)
            ->whereNull('deleted_at')
            ->count();

        $despesasPendentes = Expense::whereBetween('expense_date', [$from, $to])
            ->where('status', 'pending')
            ->count();

        // Projetos com saldo negativo que tiveram atividade no mês
        $projectIds = Timesheet::whereBetween('date', [$from, $to])
            ->whereNull('deleted_at')
            ->distinct()->pluck('project_id');

        $projetosSaldoNegativo = Project::whereIn('id', $projectIds)
            ->whereNotNull('general_hours_balance')
            ->where('general_hours_balance', '<', 0)
            ->count();

        $alertas = [];
        if ($apontamentosPendentes > 0) {
            $alertas[] = [
                'tipo'      => 'warning',
                'mensagem'  => "{$apontamentosPendentes} apontamento(s) pendente(s) de aprovação",
                'link_path' => "/timesheets?status=pending",
            ];
        }
        if ($despesasPendentes > 0) {
            $alertas[] = [
                'tipo'      => 'warning',
                'mensagem'  => "{$despesasPendentes} despesa(s) pendente(s) de aprovação",
                'link_path' => "/expenses?status=pending",
            ];
        }
        if ($projetosSaldoNegativo > 0) {
            $alertas[] = [
                'tipo'      => 'info',
                'mensagem'  => "{$projetosSaldoNegativo} projeto(s) com saldo de horas negativo",
                'link_path' => null,
            ];
        }

        return response()->json([
            'pode_fechar'            => $apontamentosPendentes === 0 && $despesasPendentes === 0,
            'ja_fechado'             => false,
            'apontamentos_pendentes' => $apontamentosPendentes,
            'despesas_pendentes'     => $despesasPendentes,
            'projetos_saldo_negativo'=> $projetosSaldoNegativo,
            'alertas'                => $alertas,
        ]);
    }

    // ─── Fechar ──────────────────────────────────────────────────────────────

    public function fechar(Request $request, string $yearMonth): JsonResponse
    {
        $fechamento = FechamentoAdministrativo::firstOrNew(['year_month' => $yearMonth]);
        if ($fechamento->isClosed()) {
            return response()->json(['message' => 'Competência já está fechada.'], 422);
        }

        $producao = $this->producaoData($yearMonth);
        $custo    = $this->custoData($yearMonth);
        $receita  = $this->receitaData($yearMonth);

        $totalCusto   = $custo['total_custo_interno'] + $custo['total_custo_parceiros'];
        $totalReceita = $receita['total_receita'];
        $margem       = round($totalReceita - $totalCusto, 2);
        $margemPct    = $totalReceita > 0 ? round(($margem / $totalReceita) * 100, 4) : 0;

        $fechamento->fill([
            'status'                => 'closed',
            'total_custo_interno'   => $custo['total_custo_interno'],
            'total_custo_parceiros' => $custo['total_custo_parceiros'],
            'total_receita'         => $totalReceita,
            'margem'                => $margem,
            'margem_percentual'     => $margemPct,
            'snapshot_producao'     => $producao,
            'snapshot_custo'        => $custo,
            'snapshot_receita'      => $receita,
            'closed_at'             => now(),
            'closed_by'             => $request->user()->id,
            'notes'                 => $request->input('notes'),
        ])->save();

        return response()->json([
            'message'    => "Competência {$yearMonth} fechada com sucesso.",
            'fechamento' => $fechamento,
        ], 200);
    }

    // ─── Reabrir ─────────────────────────────────────────────────────────────

    public function reabrir(Request $request, string $yearMonth): JsonResponse
    {
        if (!$request->user()->isAdmin()) {
            return response()->json(['message' => 'Sem permissão para reabrir fechamentos.'], 403);
        }

        $fechamento = FechamentoAdministrativo::where('year_month', $yearMonth)->firstOrFail();
        $fechamento->update([
            'status'            => 'open',
            'closed_at'         => null,
            'closed_by'         => null,
            'snapshot_producao' => null,
            'snapshot_custo'    => null,
            'snapshot_receita'  => null,
        ]);

        return response()->json(['message' => "Competência {$yearMonth} reaberta."]);
    }

    // ─── Helpers privados (reutilizados em fechar()) ─────────────────────────

    private function producaoData(string $yearMonth): array
    {
        [$from, $to] = $this->period($yearMonth);

        $timesheets = Timesheet::with([
            'user:id,name,type',
            'project:id,name,code,contract_type_id',
            'project.contractType:id,name,code',
            'project.customer:id,name',
        ])
            ->whereBetween('date', [$from, $to])
            ->whereNotIn('status', [Timesheet::STATUS_ADJUSTMENT_REQUESTED, Timesheet::STATUS_REJECTED])
            ->whereNull('deleted_at')
            ->get();

        $expenses = Expense::with(['project:id,name,code'])
            ->whereBetween('expense_date', [$from, $to])
            ->whereIn('status', ['approved', 'pending'])
            ->where('is_paid', false)
            ->get()
            ->groupBy('project_id');

        $rows = [];
        foreach ($timesheets->groupBy('user_id') as $userId => $userTs) {
            $user = $userTs->first()->user;
            foreach ($userTs->groupBy('project_id') as $projectId => $projTs) {
                $project      = $projTs->first()->project;
                $approved     = round($projTs->where('status', Timesheet::STATUS_APPROVED)->sum('effort_minutes') / 60, 2);
                $pending      = round($projTs->whereIn('status', [Timesheet::STATUS_PENDING, Timesheet::STATUS_CONFLICTED])->sum('effort_minutes') / 60, 2);
                $projExpenses = $expenses->get($projectId, collect());
                $rows[] = [
                    'consultor_id'       => $userId,
                    'consultor_nome'     => $user->name ?? '—',
                    'consultor_tipo'     => $user->type ?? '—',
                    'projeto_id'         => $projectId,
                    'projeto_nome'       => $project->name ?? '—',
                    'projeto_codigo'     => $project->code ?? '—',
                    'cliente_nome'       => $project->customer->name ?? '—',
                    'tipo_contrato'      => $project->contractType->name ?? '—',
                    'tipo_contrato_code' => $project->contractType->code ?? '—',
                    'horas_aprovadas'    => $approved,
                    'horas_pendentes'    => $pending,
                    'despesas_aprovadas' => round($projExpenses->where('status', 'approved')->sum('amount'), 2),
                    'despesas_pendentes' => round($projExpenses->where('status', 'pending')->sum('amount'), 2),
                ];
            }
        }

        return $rows;
    }

    private function custoData(string $yearMonth): array
    {
        [$from, $to] = $this->period($yearMonth);

        $timesheets = Timesheet::with('user:id,name,type,hourly_rate,rate_type')
            ->whereBetween('date', [$from, $to])
            ->whereNotIn('status', [Timesheet::STATUS_ADJUSTMENT_REQUESTED, Timesheet::STATUS_REJECTED])
            ->whereNull('deleted_at')
            ->get();

        $internos  = [];
        $parceiros = [];

        foreach ($timesheets->groupBy('user_id') as $userId => $userTs) {
            $user          = $userTs->first()->user;
            $hist          = UserHourlyRateLog::effectiveValuesAt((int) $userId, $user, $from);
            $hourlyRate    = (float) ($hist['hourly_rate'] ?? 0);
            $rateType      = $hist['rate_type'] ?? 'hourly';
            $effectiveRate = $this->effectiveHourlyRate($hourlyRate, $rateType);
            $totalHours    = round($userTs->sum('effort_minutes') / 60, 2);
            $totalCost     = round($userTs->sum(function ($t) use ($effectiveRate) {
                $horas = $t->effort_minutes / 60;
                $mult  = 1 + (((float) ($t->consultant_extra_pct ?? 0)) / 100);
                return $horas * $effectiveRate * $mult;
            }), 2);

            $row = [
                'user_id'        => $userId,
                'nome'           => $user->name ?? '—',
                'tipo_usuario'   => $user->type ?? '—',
                'horas'          => $totalHours,
                'valor_hora'     => $hourlyRate,
                'rate_type'      => $rateType,
                'effective_rate' => $effectiveRate,
                'total'          => $totalCost,
            ];

            if ($user->type === 'parceiro_admin') {
                $parceiros[] = $row;
            } else {
                $internos[] = $row;
            }
        }

        return [
            'internos'              => $internos,
            'parceiros'             => $parceiros,
            'total_custo_interno'   => round(collect($internos)->sum('total'), 2),
            'total_custo_parceiros' => round(collect($parceiros)->sum('total'), 2),
        ];
    }

    private function receitaData(string $yearMonth): array
    {
        [$from, $to] = $this->period($yearMonth);

        $excludeStatuses = [Timesheet::STATUS_ADJUSTMENT_REQUESTED, Timesheet::STATUS_REJECTED];

        $projectIds = Timesheet::whereBetween('date', [$from, $to])
            ->whereNotIn('status', $excludeStatuses)
            ->whereNull('deleted_at')
            ->distinct()->pluck('project_id');

        $projects = Project::with(['customer:id,name', 'contractType:id,name,code'])
            ->whereIn('id', $projectIds)->get();

        $hoursByProject = Timesheet::whereBetween('date', [$from, $to])
            ->whereNotIn('status', $excludeStatuses)
            ->whereNull('deleted_at')
            ->whereIn('project_id', $projectIds)
            ->selectRaw('project_id, SUM(effort_minutes) as total_minutes')
            ->groupBy('project_id')
            ->pluck('total_minutes', 'project_id');

        $weightedMinutesByProject = Timesheet::whereBetween('date', [$from, $to])
            ->whereNotIn('status', $excludeStatuses)
            ->whereNull('deleted_at')
            ->whereIn('project_id', $projectIds)
            ->selectRaw('project_id, SUM(effort_minutes * (1 + COALESCE(client_extra_pct, 0) / 100.0)) as weighted_minutes')
            ->groupBy('project_id')
            ->pluck('weighted_minutes', 'project_id');

        $rows = [];
        foreach ($projects as $project) {
            $totalHours   = round((int) ($hoursByProject[$project->id] ?? 0) / 60, 2);
            $contractCode = strtolower($project->contractType->code ?? '');
            $hourlyRate   = (float) ($project->hourly_rate ?? 0);
            $projectValue = (float) ($project->project_value ?? 0);

            $extraReceita = 0.0;
            if (str_contains($contractCode, 'on_demand') || str_contains($contractCode, 'ondemand')) {
                $baseReceita     = round($totalHours * $hourlyRate, 2);
                $totalReceita    = round((float) ($weightedMinutesByProject[$project->id] ?? 0) / 60 * $hourlyRate, 2);
                $extraReceita    = round($totalReceita - $baseReceita, 2);
                $tipoFaturamento = 'on_demand';
                $valorBase       = $hourlyRate;
            } elseif (str_contains($contractCode, 'banco_horas') || str_contains($contractCode, 'bank_hours')) {
                $totalReceita    = round(($project->sold_hours ?? 0) * $hourlyRate, 2);
                $tipoFaturamento = 'banco_horas';
                $valorBase       = $hourlyRate;
            } elseif (str_contains($contractCode, 'fechado')) {
                $totalReceita    = $projectValue;
                $tipoFaturamento = 'fechado';
                $valorBase       = $projectValue;
            } else {
                $totalReceita    = round($totalHours * $hourlyRate, 2);
                $tipoFaturamento = $contractCode ?: 'outros';
                $valorBase       = $hourlyRate;
            }

            $rows[] = [
                'projeto_id'      => $project->id,
                'projeto_nome'    => $project->name,
                'projeto_codigo'  => $project->code,
                'cliente_id'      => $project->customer_id,
                'cliente_nome'    => $project->customer->name ?? '—',
                'tipo_contrato'   => $project->contractType->name ?? '—',
                'tipo_faturamento'=> $tipoFaturamento,
                'horas_aprovadas' => $totalHours,
                'valor_base'      => $valorBase,
                'total_receita'   => $totalReceita,
                'extra_receita'   => $extraReceita,
            ];
        }

        $byCliente = collect($rows)->groupBy('cliente_id')->map(fn ($g) => [
            'cliente_id'    => $g->first()['cliente_id'],
            'cliente_nome'  => $g->first()['cliente_nome'],
            'projetos'      => $g->values()->toArray(),
            'total_cliente' => round($g->sum('total_receita'), 2),
        ])->values()->toArray();

        return [
            'by_cliente'    => $byCliente,
            'total_receita' => round(collect($rows)->sum('total_receita'), 2),
        ];
    }
}
