<?php

namespace App\Http\Controllers;

use App\Exports\FechamentoClienteExport;
use App\Mail\FechamentoClienteMail;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\FechamentoCliente;
use App\Models\Partner;
use App\Models\Project;
use App\Models\Timesheet;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Maatwebsite\Excel\Facades\Excel;

class FechamentoClienteController extends Controller
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

    public function index(Request $request): JsonResponse
    {
        $yearMonth = $request->query('year_month');

        // Só lista clientes com projeto On Demand REAL — exclui os buckets internos
        // de investimento (is_investimento_comercial=true: "Investimento Comercial",
        // "Investimento Suporte", "Investimento Projetos"), que tecnicamente têm
        // contract_type=on_demand mas não são contratos com o cliente.
        $customers = Customer::whereRaw('"active" = true')
            ->whereHas('projects', function ($q) {
                $q->where(function ($qq) {
                        $qq->where('is_investimento_comercial', false)
                           ->orWhereNull('is_investimento_comercial');
                    })
                  ->whereHas('contractType', fn ($q2) => $q2->where('code', 'on_demand'));
            })
            ->orderBy('name')
            ->get(['id', 'name', 'company_name']);

        $fechamentos = $yearMonth
            ? FechamentoCliente::where('year_month', $yearMonth)
                ->with('closedByUser:id,name')
                ->get()
                ->keyBy('customer_id')
            : collect();

        $data = $customers->map(function ($customer) use ($fechamentos) {
            $f = $fechamentos->get($customer->id);
            return [
                'customer_id'    => $customer->id,
                'nome'           => $customer->name ?: $customer->company_name, // nome fantasia (não a razão social)
                'status'         => $f?->status ?? 'sem_registro',
                'total_servicos' => (float) ($f?->total_servicos ?? 0),
                'total_despesas' => (float) ($f?->total_despesas ?? 0),
                'total_geral'    => (float) ($f?->total_geral ?? 0),
                'closed_at'      => $f?->closed_at?->toISOString(),
                'closed_by_name' => $f?->closedByUser?->name,
            ];
        });

        return response()->json(['data' => $data]);
    }

    // ─── Contratos (endpoint legado — mantido para compatibilidade) ───────────

    public function contratos(string $customerId, string $yearMonth): JsonResponse
    {
        $fechamento = FechamentoCliente::where('customer_id', $customerId)
            ->where('year_month', $yearMonth)
            ->first();

        if ($fechamento?->isClosed() && $fechamento->snapshot_contratos) {
            return response()->json(['data' => $fechamento->snapshot_contratos, 'from_snapshot' => true]);
        }

        $data = $this->contratosData((int) $customerId, $yearMonth);
        return response()->json(['data' => $data, 'from_snapshot' => false]);
    }

    // ─── Por Tipo (novo — dados agrupados por tipo_faturamento) ──────────────

    public function porTipo(Request $request, string $customerId, string $yearMonth): JsonResponse
    {
        $fechamento = FechamentoCliente::where('customer_id', $customerId)
            ->where('year_month', $yearMonth)
            ->first();

        if ($fechamento?->isClosed() && $fechamento->snapshot_contratos) {
            return response()->json(['data' => $fechamento->snapshot_contratos, 'from_snapshot' => true]);
        }

        $includeTimesheets = $request->boolean('include_timesheets', false);
        $data = $this->porTipoData((int) $customerId, $yearMonth, $includeTimesheets);
        return response()->json(['data' => $data, 'from_snapshot' => false]);
    }

    // ─── Despesas ────────────────────────────────────────────────────────────

    public function despesas(Request $request, string $customerId, string $yearMonth): JsonResponse
    {
        $fromMonth = $request->query('from', $yearMonth);
        $toMonth   = $request->query('to',   $yearMonth);

        // Snapshot só para mês único fechado
        if ($fromMonth === $yearMonth && $toMonth === $yearMonth) {
            $fechamento = FechamentoCliente::where('customer_id', $customerId)
                ->where('year_month', $yearMonth)
                ->first();
            if ($fechamento?->isClosed() && $fechamento->snapshot_despesas) {
                return response()->json(['data' => $fechamento->snapshot_despesas, 'from_snapshot' => true]);
            }
        }

        $data = $this->despesasData((int) $customerId, $fromMonth, $toMonth);
        return response()->json(['data' => $data, 'from_snapshot' => false]);
    }

    // ─── Apontamentos On Demand (suporta range de meses e filtro de contrato) ──

    public function apontamentos(Request $request, string $customerId, string $yearMonth): JsonResponse
    {
        $fromMonth    = $request->query('from', $yearMonth);
        $toMonth      = $request->query('to',   $yearMonth);
        $contractCode = $request->query('contract_type'); // null = todos

        // Snapshot só para mês único fechado
        if ($fromMonth === $yearMonth && $toMonth === $yearMonth) {
            $fechamento = FechamentoCliente::where('customer_id', $customerId)
                ->where('year_month', $yearMonth)
                ->first();
            if ($fechamento?->isClosed() && $fechamento->snapshot_contratos) {
                return response()->json(['data' => $fechamento->snapshot_contratos, 'from_snapshot' => true]);
            }
        }

        $data = $this->apontamentosData((int) $customerId, $fromMonth, $toMonth, $contractCode);
        return response()->json(['data' => $data, 'from_snapshot' => false]);
    }

    private function apontamentosData(int $customerId, string $fromMonth, string $toMonth, ?string $contractCode = null): array
    {
        $from = "{$fromMonth}-01";
        $to   = Carbon::parse("{$toMonth}-01")->endOfMonth()->toDateString();

        $projectIds = Timesheet::whereBetween('date', [$from, $to])
            ->whereNotIn('status', [Timesheet::STATUS_ADJUSTMENT_REQUESTED, Timesheet::STATUS_REJECTED, Timesheet::STATUS_CONFLICTED, Timesheet::STATUS_INTERNAL])
            ->whereNull('deleted_at')
            ->whereHas('project', function ($q) use ($customerId, $contractCode) {
                $q->where('customer_id', $customerId)
                  ->where('is_investimento_comercial', false);
                if ($contractCode) {
                    $q->whereHas('contractType', fn ($q2) => $q2->where('code', $contractCode));
                }
            })
            ->distinct()
            ->pluck('project_id');

        if ($projectIds->isEmpty()) {
            return ['projetos' => [], 'total_horas' => 0.0, 'total_geral' => 0.0];
        }

        $projects = Project::with(['contractType:id,name,code'])
            ->whereIn('id', $projectIds)
            ->get()
            ->keyBy('id');

        // Consolida projeto FILHO no PAI: o fechamento do filho entra no contrato pai
        // (o filho NÃO aparece como contrato separado). effective = parent_project_id ?? id.
        $effectiveId = [];
        foreach ($projects as $p) {
            $effectiveId[$p->id] = $p->parent_project_id ?: $p->id;
        }
        $missingParents = array_diff(array_unique(array_values($effectiveId)), $projects->keys()->all());
        if (!empty($missingParents)) {
            Project::with(['contractType:id,name,code'])
                ->whereIn('id', $missingParents)
                ->get()
                ->each(fn ($p) => $projects[$p->id] = $p);
        }

        $timesheets = Timesheet::with('user:id,name')
            ->select('timesheets.*', 'movidesk_tickets.titulo as ticket_titulo', 'movidesk_tickets.solicitante as ticket_solicitante')
            ->leftJoin('movidesk_tickets', 'movidesk_tickets.ticket_id', '=', 'timesheets.ticket')
            ->whereBetween('timesheets.date', [$from, $to])
            ->whereNotIn('timesheets.status', [Timesheet::STATUS_ADJUSTMENT_REQUESTED, Timesheet::STATUS_REJECTED, Timesheet::STATUS_CONFLICTED, Timesheet::STATUS_INTERNAL])
            ->whereNull('timesheets.deleted_at')
            ->whereIn('timesheets.project_id', $projectIds)
            ->orderBy('timesheets.project_id')
            ->orderBy('timesheets.date')
            ->get();

        $byProject  = $timesheets->groupBy(fn ($t) => $effectiveId[$t->project_id] ?? $t->project_id);
        $projetos   = [];
        $totalHoras = 0.0;
        $totalGeral = 0.0;

        foreach ($byProject as $projId => $pts) {
            $project    = $projects[$projId] ?? null;
            $hourlyRate = (float) ($project?->hourly_rate ?? 0);

            $horasProjeto  = 0.0;
            $basesProjeto  = 0.0;
            $totalProjeto  = 0.0;
            $apontamentos  = [];

            foreach ($pts as $t) {
                $solicitanteRaw = $t->ticket_solicitante;
                if (is_string($solicitanteRaw)) {
                    $solicitanteRaw = json_decode($solicitanteRaw, true);
                }
                $solicitante = is_array($solicitanteRaw) ? ($solicitanteRaw['name'] ?? null) : null;

                $horas   = $t->effort_minutes / 60;
                $mult    = 1 + (((float) ($t->client_extra_pct ?? 0)) / 100);
                $valorTs = round($horas * $hourlyRate * $mult, 2);

                $horasProjeto += $horas;
                $basesProjeto += $horas * $hourlyRate;
                $totalProjeto += $valorTs;

                $apontamentos[] = [
                    'id'               => $t->id,
                    'data'             => $t->date->format('Y-m-d'),
                    'colaborador'      => $t->user?->name ?? '—',
                    'horas'            => round($horas, 2),
                    'ticket'           => $t->ticket,
                    'titulo'           => $t->ticket_titulo,
                    'solicitante'      => $solicitante,
                    'observacao'       => $t->observation,
                    'client_extra_pct' => $t->client_extra_pct ? (float) $t->client_extra_pct : null,
                    'valor_extra'      => $t->client_extra_pct
                        ? round($horas * $hourlyRate * ((float) $t->client_extra_pct / 100), 2)
                        : null,
                ];
            }

            $horasProjeto = round($horasProjeto, 2);
            $totalProjeto = round($totalProjeto, 2);
            $basesProjeto = round($basesProjeto, 2);

            $projetos[] = [
                'projeto_id'     => $projId,
                'projeto_nome'   => $project?->name ?? '—',
                'projeto_codigo' => $project?->code ?? '—',
                'tipo_contrato'  => $project?->contractType?->name ?? '—',
                'horas'          => $horasProjeto,
                'valor_hora'     => $hourlyRate,
                'total_receita'  => $totalProjeto,
                'extra_receita'  => round($totalProjeto - $basesProjeto, 2),
                'apontamentos'   => $apontamentos,
            ];

            $totalHoras += $horasProjeto;
            $totalGeral += $totalProjeto;
        }

        return [
            'projetos'    => $projetos,
            'total_horas' => round($totalHoras, 2),
            'total_geral' => round($totalGeral, 2),
        ];
    }

    // ─── Pendências ──────────────────────────────────────────────────────────

    public function pendencias(string $customerId, string $yearMonth): JsonResponse
    {
        [$from, $to] = $this->period($yearMonth);

        $timesheets = Timesheet::with(['user:id,name', 'project:id,name,code'])
            ->whereHas('project', fn ($q) => $q->where('customer_id', $customerId))
            ->whereBetween('date', [$from, $to])
            ->whereIn('status', [Timesheet::STATUS_PENDING, Timesheet::STATUS_ADJUSTMENT_REQUESTED])
            ->whereNull('deleted_at')
            ->orderBy('date')
            ->get()
            ->map(fn ($t) => [
                'id'          => $t->id,
                'tipo'        => 'timesheet',
                'data'        => $t->date->format('Y-m-d'),
                'colaborador' => $t->user?->name ?? '—',
                'projeto'     => $t->project?->name ?? '—',
                'projeto_codigo' => $t->project?->code ?? '—',
                'horas'       => round($t->effort_minutes / 60, 2),
                'status'      => $t->status,
                'ticket'      => $t->ticket,
                'observacao'  => $t->observation,
            ]);

        $despesas = Expense::with(['user:id,name', 'project:id,name,code', 'category:id,name'])
            ->whereHas('project', fn ($q) => $q->where('customer_id', $customerId))
            ->whereBetween('expense_date', [$from, $to])
            ->whereIn('status', ['pending', 'adjustment_requested'])
            ->orderBy('expense_date')
            ->get()
            ->map(fn ($e) => [
                'id'          => $e->id,
                'tipo'        => 'expense',
                'data'        => $e->expense_date->format('Y-m-d'),
                'colaborador' => $e->user?->name ?? '—',
                'projeto'     => $e->project?->name ?? '—',
                'projeto_codigo' => $e->project?->code ?? '—',
                'descricao'   => $e->description,
                'categoria'   => $e->category?->name ?? '—',
                'valor'       => (float) $e->amount,
                'status'      => $e->status,
            ]);

        return response()->json([
            'timesheets'        => $timesheets,
            'despesas'          => $despesas,
            'total_pendencias'  => count($timesheets) + count($despesas),
        ]);
    }

    // ─── Pagamento ───────────────────────────────────────────────────────────

    public function pagamento(string $customerId, string $yearMonth): JsonResponse
    {
        $fechamento = FechamentoCliente::where('customer_id', $customerId)
            ->where('year_month', $yearMonth)
            ->first();

        if ($fechamento?->isClosed() && $fechamento->snapshot_pagamento) {
            return response()->json(['data' => $fechamento->snapshot_pagamento, 'from_snapshot' => true]);
        }

        $data = $this->pagamentoData((int) $customerId, $yearMonth);
        return response()->json(['data' => $data, 'from_snapshot' => false]);
    }

    // ─── Fechar ──────────────────────────────────────────────────────────────

    public function fechar(Request $request, string $customerId, string $yearMonth): JsonResponse
    {
        $fechamento = FechamentoCliente::firstOrNew([
            'customer_id' => $customerId,
            'year_month'  => $yearMonth,
        ]);

        if ($fechamento->exists && $fechamento->isClosed()) {
            return response()->json(['message' => 'Fechamento já está encerrado.'], 422);
        }

        $apontamentos = $this->apontamentosData((int) $customerId, $yearMonth, $yearMonth, 'on_demand');
        $despesas     = $this->despesasData((int) $customerId, $yearMonth, $yearMonth);
        $pagamento    = $this->pagamentoData((int) $customerId, $yearMonth);

        $totalServicos = $apontamentos['total_geral'] ?? 0;
        $totalDespesas = round(collect($despesas)->sum('valor'), 2);

        $fechamento->fill([
            'status'             => 'closed',
            'snapshot_contratos' => $apontamentos,
            'snapshot_despesas'  => $despesas,
            'snapshot_pagamento' => $pagamento,
            'total_servicos'     => round($totalServicos, 2),
            'total_despesas'     => $totalDespesas,
            'total_geral'        => round($totalServicos + $totalDespesas, 2),
            'closed_at'          => now(),
            'closed_by'          => $request->user()->id,
            'notes'              => $request->input('notes'),
        ])->save();

        return response()->json(['message' => "Fechamento do cliente para {$yearMonth} encerrado.", 'fechamento' => $fechamento]);
    }

    // ─── Reabrir ─────────────────────────────────────────────────────────────

    public function reabrir(Request $request, string $customerId, string $yearMonth): JsonResponse
    {
        if (!$request->user()->isAdmin()) {
            return response()->json(['message' => 'Sem permissão para reabrir fechamentos.'], 403);
        }

        $fechamento = FechamentoCliente::where('customer_id', $customerId)
            ->where('year_month', $yearMonth)
            ->firstOrFail();

        $fechamento->update([
            'status'             => 'open',
            'closed_at'          => null,
            'closed_by'          => null,
            'snapshot_contratos' => null,
            'snapshot_despesas'  => null,
            'snapshot_pagamento' => null,
        ]);

        return response()->json(['message' => "Fechamento do cliente reaberto para {$yearMonth}."]);
    }

    // ─── Helpers privados ────────────────────────────────────────────────────

    private function porTipoData(int $customerId, string $yearMonth, bool $includeTimesheets): array
    {
        $rows = $this->contratosData($customerId, $yearMonth, $includeTimesheets);

        $byTipo = [
            'on_demand'   => ['projetos' => [], 'total' => 0.0],
            'banco_horas' => ['projetos' => [], 'total' => 0.0],
            'fechado'     => ['projetos' => [], 'total' => 0.0],
            'outros'      => ['projetos' => [], 'total' => 0.0],
        ];

        foreach ($rows as $row) {
            $tipo = $row['tipo_faturamento'] ?? 'outros';
            if (!isset($byTipo[$tipo])) {
                $tipo = 'outros';
            }
            $byTipo[$tipo]['projetos'][] = $row;
            $byTipo[$tipo]['total']      = round($byTipo[$tipo]['total'] + ($row['total_receita'] ?? 0), 2);
        }

        return $byTipo;
    }

    private function contratosData(int $customerId, string $yearMonth, bool $includeTimesheets = false): array
    {
        [$from, $to] = $this->period($yearMonth);

        $excludeStatuses = [Timesheet::STATUS_ADJUSTMENT_REQUESTED, Timesheet::STATUS_REJECTED, Timesheet::STATUS_CONFLICTED, Timesheet::STATUS_INTERNAL];

        $projectIds = Timesheet::whereBetween('date', [$from, $to])
            ->whereNotIn('status', $excludeStatuses)
            ->whereNull('deleted_at')
            ->whereHas('project', fn ($q) => $q->where('customer_id', $customerId))
            ->distinct()
            ->pluck('project_id');

        if ($projectIds->isEmpty()) {
            return [];
        }

        $projects = Project::with(['contractType:id,name,code'])
            ->whereIn('id', $projectIds)
            ->get();

        $hoursByProject = Timesheet::whereBetween('date', [$from, $to])
            ->whereNotIn('status', $excludeStatuses)
            ->whereNull('deleted_at')
            ->where('is_internal_action', false)
            ->whereIn('project_id', $projectIds)
            ->selectRaw('project_id, SUM(effort_minutes) as total_minutes')
            ->groupBy('project_id')
            ->pluck('total_minutes', 'project_id');

        $totalConsumedByProject = Timesheet::whereNotIn('status', $excludeStatuses)
            ->whereNull('deleted_at')
            ->where('is_internal_action', false)
            ->whereIn('project_id', $projectIds)
            ->selectRaw('project_id, SUM(effort_minutes) as total_minutes')
            ->groupBy('project_id')
            ->pluck('total_minutes', 'project_id');

        // Weighted revenue per project applying client_extra_pct (on_demand billing)
        $weightedMinutesByProject = Timesheet::whereBetween('date', [$from, $to])
            ->whereNotIn('status', $excludeStatuses)
            ->whereNull('deleted_at')
            ->where('is_internal_action', false)
            ->whereIn('project_id', $projectIds)
            ->selectRaw('project_id, SUM(effort_minutes * (1 + COALESCE(client_extra_pct, 0) / 100.0)) as weighted_minutes')
            ->groupBy('project_id')
            ->pluck('weighted_minutes', 'project_id');

        // Apontamentos detalhados por projeto (se solicitado)
        $timesheetsByProject = [];
        if ($includeTimesheets) {
            $allTs = Timesheet::with('user:id,name')
                ->whereBetween('date', [$from, $to])
                ->whereNotIn('status', $excludeStatuses)
                ->whereNull('deleted_at')
                ->whereIn('project_id', $projectIds)
                ->orderBy('date')
                ->get();

            foreach ($allTs->groupBy('project_id') as $pid => $pts) {
                $timesheetsByProject[$pid] = $pts->map(fn ($t) => [
                    'id'          => $t->id,
                    'data'        => $t->date->format('Y-m-d'),
                    'colaborador' => $t->user?->name ?? '—',
                    'horas'       => round($t->effort_minutes / 60, 2),
                    'ticket'      => $t->ticket,
                    'observacao'  => $t->observation,
                ])->values()->toArray();
            }
        }

        $rows = [];
        foreach ($projects as $project) {
            $totalHours   = round((int) ($hoursByProject[$project->id] ?? 0) / 60, 2);
            $consumedAll  = round((int) ($totalConsumedByProject[$project->id] ?? 0) / 60, 2);
            $contractCode = strtolower($project->contractType->code ?? '');
            $hourlyRate   = (float) ($project->hourly_rate ?? 0);
            $projectValue = (float) ($project->project_value ?? 0);
            $soldHours    = (float) ($project->sold_hours ?? 0);

            $isBancoHoras = in_array($contractCode, ['fixed_hours', 'monthly_hours', 'banco_horas', 'bank_hours'])
                || str_contains($contractCode, 'hours') || str_contains($contractCode, 'banco');
            $isFechado    = in_array($contractCode, ['closed', 'fechado'])
                || str_contains($contractCode, 'closed') || str_contains($contractCode, 'fechado');
            $isOnDemand   = in_array($contractCode, ['on_demand', 'ondemand'])
                || str_contains($contractCode, 'on_demand') || str_contains($contractCode, 'ondemand');

            if ($isOnDemand) {
                $tipoFaturamento = 'on_demand';
                $totalReceita    = round((float) ($weightedMinutesByProject[$project->id] ?? 0) / 60 * $hourlyRate, 2);
                $valorBase       = $hourlyRate;
                $excessHoras     = 0.0;
                $excessValor     = 0.0;
                $valorMensal     = 0.0;
            } elseif ($isBancoHoras) {
                $tipoFaturamento = 'banco_horas';
                $excessHoras     = round(max(0, $consumedAll - $soldHours), 2);
                $excessValor     = round($excessHoras * $hourlyRate, 2);
                $valorMensal     = round($soldHours * $hourlyRate, 2);
                $totalReceita    = round($valorMensal + $excessValor, 2);
                $valorBase       = $hourlyRate;
            } elseif ($isFechado) {
                $tipoFaturamento = 'fechado';
                $totalReceita    = $projectValue;
                $valorBase       = $projectValue;
                $excessHoras     = 0.0;
                $excessValor     = 0.0;
                $valorMensal     = 0.0;
            } else {
                $tipoFaturamento = 'outros';
                $totalReceita    = round($totalHours * $hourlyRate, 2);
                $valorBase       = $hourlyRate;
                $excessHoras     = 0.0;
                $excessValor     = 0.0;
                $valorMensal     = 0.0;
            }

            $row = [
                'projeto_id'          => $project->id,
                'projeto_nome'        => $project->name,
                'projeto_codigo'      => $project->code ?? '—',
                'tipo_contrato'       => $project->contractType->name ?? '—',
                'tipo_faturamento'    => $tipoFaturamento,
                'horas_aprovadas'     => $totalHours,
                'horas_aprovadas_no_mes' => $totalHours,
                'horas_contratadas'   => $soldHours,
                'horas_consumidas'    => $consumedAll,
                'horas_consumidas_total' => $consumedAll,
                'excedente_horas'     => $excessHoras,
                'excedente_valor'     => $excessValor,
                'valor_mensal'        => $valorMensal,
                'valor_base'          => $valorBase,
                'total_receita'       => $totalReceita,
            ];

            if ($includeTimesheets) {
                $row['apontamentos'] = $timesheetsByProject[$project->id] ?? [];
            }

            $rows[] = $row;
        }

        return $rows;
    }

    private function despesasData(int $customerId, string $fromMonth, string $toMonth): array
    {
        $from = "{$fromMonth}-01";
        $to   = Carbon::parse("{$toMonth}-01")->endOfMonth()->toDateString();

        return Expense::with([
            'user:id,name',
            'project:id,name,code',
            'category:id,name',
        ])
            ->where('charge_client', true)
            ->whereNotIn('status', [Expense::STATUS_REJECTED, Expense::STATUS_ADJUSTMENT_REQUESTED])
            ->where('is_paid', false)
            ->whereBetween('expense_date', [$from, $to])
            ->whereHas('project', fn ($q) => $q->where('customer_id', $customerId)->where('is_investimento_comercial', false))
            ->get()
            ->map(fn ($e) => [
                'id'          => $e->id,
                'data'        => $e->expense_date->format('Y-m-d'),
                'descricao'   => $e->description,
                'categoria'   => $e->category?->name ?? '—',
                'colaborador' => $e->user?->name ?? '—',
                'projeto'     => $e->project?->name ?? '—',
                'valor'       => (float) $e->amount,
            ])
            ->toArray();
    }

    private function pagamentoData(int $customerId, string $yearMonth): array
    {
        [$from, $to] = $this->period($yearMonth);

        $timesheets = Timesheet::with([
            'user:id,name,type,hourly_rate,rate_type,partner_id,consultant_type',
            'user.partner:id,name,pricing_type,hourly_rate',
        ])
            ->whereHas('project', fn ($q) => $q->where('customer_id', $customerId)->where('is_investimento_comercial', false))
            ->whereBetween('date', [$from, $to])
            ->whereNotIn('status', [Timesheet::STATUS_ADJUSTMENT_REQUESTED, Timesheet::STATUS_REJECTED, Timesheet::STATUS_CONFLICTED, Timesheet::STATUS_INTERNAL])
            ->whereNull('deleted_at')
            ->get();

        $internos  = [];
        $parceiros = [];

        foreach ($timesheets->groupBy('user_id') as $userId => $userTs) {
            $user       = $userTs->first()->user;
            $totalHoras = round($userTs->sum('effort_minutes') / 60, 2);

            if ($user->type === 'parceiro_admin') {
                // Agrupa parceiros por partner_id
                $partnerId    = $user->partner_id;
                $partner      = $user->partner;
                $isFixed      = $partner?->pricing_type === Partner::PRICING_FIXED;
                $partnerRate  = (float) ($partner?->hourly_rate ?? 0);

                $taxaHora = $isFixed
                    ? $partnerRate
                    : $this->effectiveHourlyRate((float) ($user->hourly_rate ?? 0), $user->rate_type ?? 'hourly');

                if (!isset($parceiros[$partnerId])) {
                    $parceiros[$partnerId] = [
                        'partner_id'   => $partnerId,
                        'partner_nome' => $partner?->name ?? '—',
                        'pricing_type' => $partner?->pricing_type ?? 'variable',
                        'horas_total'  => 0.0,
                        'total_a_pagar'=> 0.0,
                    ];
                }
                $parceiros[$partnerId]['horas_total']   = round($parceiros[$partnerId]['horas_total'] + $totalHoras, 2);
                $parceiros[$partnerId]['total_a_pagar'] = round($parceiros[$partnerId]['total_a_pagar'] + ($totalHoras * $taxaHora), 2);
            } else {
                $hourlyRate    = (float) ($user->hourly_rate ?? 0);
                $rateType      = $user->rate_type ?? 'hourly';
                $effectiveRate = $this->effectiveHourlyRate($hourlyRate, $rateType);

                $internos[] = [
                    'user_id'         => $userId,
                    'nome'            => $user->name ?? '—',
                    'consultant_type' => $user->consultant_type ?? $user->type ?? '—',
                    'horas'           => $totalHoras,
                    'valor_hora'      => $hourlyRate,
                    'rate_type'       => $rateType,
                    'effective_rate'  => $effectiveRate,
                    'total'           => round($totalHoras * $effectiveRate, 2),
                ];
            }
        }

        $parceirosArr = array_values($parceiros);

        return [
            'internos'        => $internos,
            'parceiros'       => $parceirosArr,
            'total_internos'  => round(collect($internos)->sum('total'), 2),
            'total_parceiros' => round(collect($parceirosArr)->sum('total_a_pagar'), 2),
            'total_geral'     => round(
                collect($internos)->sum('total') + collect($parceirosArr)->sum('total_a_pagar'),
                2
            ),
        ];
    }

    // ─── Helpers de e-mail / relatório ──────────────────────────────────────────

    private const MESES = ['', 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

    /** "Maio de 2026" */
    private function periodoExtenso(string $yearMonth): string
    {
        [$year, $month] = array_map('intval', explode('-', $yearMonth));
        $nome = ($month >= 1 && $month <= 12) ? self::MESES[$month] : $yearMonth;
        return "{$nome} de {$year}";
    }

    /** "05/2026" (MM/AAAA) — usado no assunto do e-mail. */
    private function periodoMMAAAA(string $yearMonth): string
    {
        [$year, $month] = array_map('intval', explode('-', $yearMonth));
        return sprintf('%02d/%04d', $month, $year);
    }

    private function brl(float $value): string
    {
        return 'R$ ' . number_format($value, 2, ',', '.');
    }

    /** Formata horas decimais como HHhMM (ex.: 12.5 -> "12h30"). */
    private function fmtHoras(float $h): string
    {
        $totalMins = abs((int) round($h * 60));
        $hrs  = intdiv($totalMins, 60);
        $mins = $totalMins % 60;
        return sprintf('%dh%02d', $hrs, $mins);
    }

    /** Remove acentos/espaços/barras de um nome para uso em filename. */
    private function sanitizeFilename(string $name): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $name);
        if ($ascii === false) {
            $ascii = $name;
        }
        $ascii = preg_replace('/[^A-Za-z0-9]+/', '_', $ascii);
        return trim((string) $ascii, '_') ?: 'cliente';
    }

    /** Mensagem padrão (corpo) do e-mail de fechamento — editável na tela antes de enviar. */
    private function defaultMensagem(string $periodo): string
    {
        return "Segue em anexo o fechamento referente ao período de {$periodo}.\n\nEm caso de dúvidas ou divergências, por gentileza entrar em contato.";
    }

    /** Cliente é a VEDAMOTORS? (modelo especial só pra ela). */
    private function isVedamotors(Customer $customer): bool
    {
        return str_contains(mb_strtoupper((string) $customer->name), 'VEDAMOTORS');
    }

    /**
     * Extrai o "ticket Vedamotors" do título/assunto do ticket (formato NNNN-NNNNNN,
     * ex.: "0326-000007"). Retorna "Sem ticket" quando não encontra.
     */
    private function vedaTicket(?string $title): string
    {
        if ($title !== null && preg_match('/\d{4}-\d{6}/', $title, $m)) {
            return $m[0];
        }
        return 'Sem ticket';
    }

    /**
     * Valor a pagar do cliente no mês — MESMO total usado pelo fechamento (fechar()):
     * serviços On Demand ponderados (horas × rate × (1+client_extra_pct)) + despesas
     * faturáveis. Usa o snapshot (total_geral) quando o fechamento está encerrado.
     */
    private function clienteTotal(int $customerId, string $yearMonth): float
    {
        $fechamento = FechamentoCliente::where('customer_id', $customerId)
            ->where('year_month', $yearMonth)
            ->first();

        if ($fechamento?->isClosed()) {
            return (float) $fechamento->total_geral;
        }

        $apontamentos  = $this->apontamentosData($customerId, $yearMonth, $yearMonth, 'on_demand');
        $despesas      = $this->despesasData($customerId, $yearMonth, $yearMonth);
        $totalServicos = (float) ($apontamentos['total_geral'] ?? 0);
        $totalDespesas = round(collect($despesas)->sum('valor'), 2);

        return round($totalServicos + $totalDespesas, 2);
    }

    /**
     * Linhas achatadas de apontamentos do cliente (a partir de apontamentosData),
     * uma por timesheet, prontas pro XLSX / PDF. Quando Vedamotors, o campo "titulo"
     * passa a ser o ticket Vedamotors extraído (NNNN-NNNNNN ou "Sem ticket").
     *
     * @return array<int,array<string,mixed>>
     */
    private function clienteApontamentosFlat(int $customerId, string $yearMonth, bool $vedamotors): array
    {
        $data = $this->apontamentosData($customerId, $yearMonth, $yearMonth, 'on_demand');

        $rows = [];
        foreach (($data['projetos'] ?? []) as $proj) {
            foreach (($proj['apontamentos'] ?? []) as $ap) {
                $titulo = $vedamotors
                    ? $this->vedaTicket($ap['titulo'] ?? null)
                    : ($ap['titulo'] ?? '');

                $rows[] = [
                    'projeto'    => $proj['projeto_nome'] ?? '—',
                    'data'       => $ap['data'] ?? null,
                    'consultor'  => $ap['colaborador'] ?? '—',
                    'ticket'     => $ap['ticket'] ?? '',
                    'titulo'     => $titulo,
                    'horas'      => (float) ($ap['horas'] ?? 0),
                    'observacao' => $ap['observacao'] ?? null,
                ];
            }
        }

        return $rows;
    }

    /**
     * Apuração por Ticket (só Vedamotors) — espelha o totalizador da tela
     * (TimesheetController::summaryByTicket). Para cada ticket que teve ao menos
     * 1 apontamento no mês, devolve o total no período + o total histórico
     * (lifetime: TODOS os apontamentos do mesmo ticket no mesmo cliente, desde o
     * início no sistema, somando o saldo inicial cadastrado em
     * ticket_initial_balances). Escopo idêntico ao apontamentosData do
     * fechamento: projetos On Demand do cliente (não investimento_comercial),
     * mesmos status excluídos, sem soft-deleted, e só tickets de 5 dígitos
     * (padrão Movidesk).
     *
     * @return array<int,array{ticket:string,title:?string,veda_ticket:string,requester:?string,period_minutes:int,lifetime_minutes:int}>
     */
    private function clienteTicketSummary(int $customerId, string $yearMonth): array
    {
        [$from, $to] = $this->period($yearMonth);

        $excludeStatuses = [Timesheet::STATUS_ADJUSTMENT_REQUESTED, Timesheet::STATUS_REJECTED, Timesheet::STATUS_CONFLICTED, Timesheet::STATUS_INTERNAL];

        // Projetos On Demand do cliente (mesma base que apontamentosData usa no fechamento).
        $projectIds = Project::where('customer_id', $customerId)
            ->where('is_investimento_comercial', false)
            ->whereHas('contractType', fn ($q) => $q->where('code', 'on_demand'))
            ->pluck('id');

        if ($projectIds->isEmpty()) {
            return [];
        }

        // Base: timesheets desses projetos, com ticket Movidesk válido (5 dígitos), fora dos status descartados.
        $base = Timesheet::query()
            ->whereIn('timesheets.project_id', $projectIds)
            ->whereNotIn('timesheets.status', $excludeStatuses)
            ->whereNull('timesheets.deleted_at')
            ->whereNotNull('timesheets.ticket')
            ->where('timesheets.ticket', '!=', '')
            ->whereRaw("timesheets.ticket ~ '^[0-9]{5}$'");

        // 1) Tickets que tiveram apontamento DENTRO do período.
        $ticketsInPeriod = (clone $base)
            ->whereBetween('timesheets.date', [$from, $to])
            ->select('timesheets.ticket')
            ->distinct()
            ->pluck('timesheets.ticket')
            ->toArray();

        if (empty($ticketsInPeriod)) {
            return [];
        }

        // 2) Agregação: lifetime (todos do ticket nesses projetos) + total no período.
        $rows = (clone $base)
            ->whereIn('timesheets.ticket', $ticketsInPeriod)
            ->leftJoin('movidesk_tickets', 'movidesk_tickets.ticket_id', '=', 'timesheets.ticket')
            ->selectRaw('timesheets.ticket as ticket')
            ->selectRaw('MAX(movidesk_tickets.titulo) as title')
            ->selectRaw("MAX(movidesk_tickets.solicitante::jsonb->>'name') as requester")
            ->selectRaw('SUM(timesheets.effort_minutes) as lifetime_minutes')
            ->selectRaw('SUM(CASE WHEN timesheets.date BETWEEN ? AND ? THEN timesheets.effort_minutes ELSE 0 END) as period_minutes', [$from, $to])
            ->groupBy('timesheets.ticket')
            ->orderBy('timesheets.ticket')
            ->get();

        // Saldos iniciais cadastrados pra esse cliente — somam SOMENTE no lifetime
        // (histórico anterior à entrada do ticket no Minutor), nunca no período.
        $initialByTicket = \DB::table('ticket_initial_balances')
            ->whereNull('deleted_at')
            ->where('customer_id', $customerId)
            ->whereIn('ticket', $rows->pluck('ticket')->all())
            ->pluck('initial_minutes', 'ticket');

        return $rows->map(function ($r) use ($initialByTicket) {
            $initial = (int) ($initialByTicket[$r->ticket] ?? 0);
            return [
                'ticket'           => $r->ticket,
                'title'            => $r->title,
                'veda_ticket'      => $this->vedaTicket($r->title),
                'requester'        => $r->requester,
                'period_minutes'   => (int) $r->period_minutes,
                'lifetime_minutes' => (int) $r->lifetime_minutes + $initial,
            ];
        })->values()->toArray();
    }

    /** Agrupa as linhas achatadas por projeto, para o PDF (Relatório de Apontamentos). */
    private function buildPdfGroups(array $rows): array
    {
        $byProjeto = [];
        foreach ($rows as $r) {
            $byProjeto[$r['projeto'] ?? '—'][] = $r;
        }

        $grupos = [];
        foreach ($byProjeto as $projeto => $items) {
            $horas  = 0.0;
            $linhas = [];
            foreach ($items as $l) {
                $horas += (float) ($l['horas'] ?? 0);
                $linhas[] = [
                    'data'      => isset($l['data']) ? Carbon::parse($l['data'])->format('d/m/Y') : '',
                    'consultor' => $l['consultor'] ?? '—',
                    'ticket'    => $l['ticket'] ?? '',
                    'titulo'    => $l['titulo'] ?? '',
                    'horas_fmt' => $this->fmtHoras((float) ($l['horas'] ?? 0)),
                ];
            }
            $grupos[] = [
                'projeto'   => $projeto,
                'linhas'    => $linhas,
                'horas_fmt' => $this->fmtHoras($horas),
            ];
        }

        return $grupos;
    }

    /**
     * Gera (PDF + XLSX) do fechamento do cliente e grava em storage/app/fechamentos.
     *
     * @return array{
     *   pdf_rel:string, xlsx_rel:string, pdf_full:string, xlsx_full:string,
     *   pdf_name:string, xlsx_name:string, total_value:float
     * }
     */
    private function generateClienteFiles(Customer $customer, string $yearMonth): array
    {
        $periodo    = $this->periodoExtenso($yearMonth);
        $vedamotors = $this->isVedamotors($customer);
        $rows       = $this->clienteApontamentosFlat((int) $customer->id, $yearMonth, $vedamotors);
        $totalValue = $this->clienteTotal((int) $customer->id, $yearMonth);
        $totalHoras = round(collect($rows)->sum('horas'), 2);

        $safeName     = $this->sanitizeFilename($customer->name);
        $pdfFileName  = "Fechamento_{$yearMonth}_{$safeName}.pdf";
        $xlsxFileName = "Fechamento_{$yearMonth}_{$safeName}.xlsx";
        $dir          = 'fechamentos';
        $pdfRelPath   = "{$dir}/{$pdfFileName}";
        $xlsxRelPath  = "{$dir}/{$xlsxFileName}";
        $pdfFullPath  = storage_path("app/{$pdfRelPath}");
        $xlsxFullPath = storage_path("app/{$xlsxRelPath}");

        // Cria a pasta REAL onde os arquivos são gravados/anexados (storage/app/fechamentos).
        $dirFull = storage_path("app/{$dir}");
        if (!is_dir($dirFull)) {
            mkdir($dirFull, 0775, true);
        }

        // Apuração por Ticket — só Vedamotors (espelha o totalizador da tela).
        // Pré-formata horas em HH:MM (mesmo fmtHoras do resto do PDF) e os totais.
        $ticketSummary = $vedamotors ? $this->clienteTicketSummary((int) $customer->id, $yearMonth) : [];
        $ticketRows    = array_map(fn ($t) => [
            'ticket'        => $t['ticket'],
            'veda_ticket'   => $t['veda_ticket'],
            'requester'     => $t['requester'],
            'period_fmt'    => $this->fmtHoras($t['period_minutes'] / 60),
            'lifetime_fmt'  => $this->fmtHoras($t['lifetime_minutes'] / 60),
        ], $ticketSummary);
        $ticketTotPeriodFmt   = $this->fmtHoras(array_sum(array_column($ticketSummary, 'period_minutes')) / 60);
        $ticketTotLifetimeFmt = $this->fmtHoras(array_sum(array_column($ticketSummary, 'lifetime_minutes')) / 60);

        // ── PDF (agrupado por projeto, sem coluna de valor por linha) ──
        $pdf = Pdf::loadView('pdf.fechamento-cliente', [
            'clienteName'          => $customer->name,
            'periodo'              => $periodo,
            'totalHorasFmt'        => $this->fmtHoras($totalHoras),
            'valorTotal'           => $this->brl($totalValue),
            'grupos'               => $this->buildPdfGroups($rows),
            'vedamotors'           => $vedamotors,
            'ticketRows'           => $ticketRows,
            'ticketTotPeriodFmt'   => $ticketTotPeriodFmt,
            'ticketTotLifetimeFmt' => $ticketTotLifetimeFmt,
        ])->setPaper('a4', 'portrait');
        file_put_contents($pdfFullPath, $pdf->output());

        // ── XLSX ──
        $export = new FechamentoClienteExport($rows, $customer->name, $periodo, $totalValue, $vedamotors);
        file_put_contents($xlsxFullPath, Excel::raw($export, \Maatwebsite\Excel\Excel::XLSX));

        return [
            'pdf_rel'     => $pdfRelPath,
            'xlsx_rel'    => $xlsxRelPath,
            'pdf_full'    => $pdfFullPath,
            'xlsx_full'   => $xlsxFullPath,
            'pdf_name'    => $pdfFileName,
            'xlsx_name'   => $xlsxFileName,
            'total_value' => $totalValue,
        ];
    }

    // ─── Prévia do e-mail (template real) com a mensagem editável ───────────────
    // Renderiza o MESMO template do envio, pra mostrar na tela e atualizar ao vivo
    // conforme o admin edita a mensagem. Clientes NÃO têm admins automáticos.
    public function emailPreview(Request $request, string $customerId, string $yearMonth): JsonResponse
    {
        $sender = $request->user();
        if (!$sender || !($sender->isAdmin() || $sender->isAdministrativo())) {
            return response()->json(['success' => false, 'message' => 'Sem permissão.'], 403);
        }
        $customer = Customer::find($customerId);
        if (!$customer) {
            return response()->json(['success' => false, 'message' => 'Cliente não encontrado.'], 404);
        }

        $periodo        = $this->periodoExtenso($yearMonth);
        $mensagemPadrao = $this->defaultMensagem($periodo);
        $mensagem       = trim((string) $request->input('mensagem'));
        $mensagem       = $mensagem !== '' ? $mensagem : $mensagemPadrao;

        $html = view('emails.fechamento.cliente', [
            'clienteName'     => $customer->name,
            'senderName'      => $sender->name,
            'periodo'         => $periodo,
            'valorTotal'      => $this->brl($this->clienteTotal((int) $customer->id, $yearMonth)),
            'withAttachments' => true,
            'mensagem'        => $mensagem,
        ])->render();

        // Prévia só: força o logo claro (escuro-colorido) a aparecer no card branco —
        // o swap de dark-mode do template trocaria pro logo branco (invisível aqui).
        $override = '<style>.erp-light{display:inline-block !important}.erp-dark{display:none !important}</style>';
        $html = str_ireplace('</head>', $override . '</head>', $html);

        return response()->json([
            'html'             => $html,
            'mensagem_padrao'  => $mensagemPadrao,
            'fechamento_email' => $customer->fechamento_email,
        ]);
    }

    // ─── Enviar fechamento por e-mail ───────────────────────────────────────────
    // Envia o fechamento do cliente por e-mail, com detalhamento em anexos (PDF + XLSX).
    // De = conta autenticada (mail.from) com o NOME do usuário logado (sem Send As).
    // Reply-To = quem enviou + financeiro; To = e-mails informados; CC = financeiro.
    public function enviarEmail(Request $request, string $customerId, string $yearMonth): JsonResponse
    {
        $sender = $request->user();
        if (!$sender || !($sender->isAdmin() || $sender->isAdministrativo())) {
            return response()->json(['success' => false, 'message' => 'Sem permissão para enviar o fechamento.'], 403);
        }
        if (!$sender->email) {
            return response()->json(['success' => false, 'message' => 'Seu usuário não tem e-mail cadastrado para usar como remetente.'], 422);
        }

        $request->validate([
            'mensagem' => 'nullable|string', // corpo editável; vazio = mensagem padrão
            'emails'   => 'required|array',
            'emails.*' => 'email',
        ]);

        $customer = Customer::find($customerId);
        if (!$customer) {
            return response()->json(['success' => false, 'message' => 'Cliente não encontrado.'], 404);
        }

        $periodo      = $this->periodoExtenso($yearMonth);
        $financeiroCc = (string) (config('mail.financeiro_cc') ?? '');
        $mensagem     = trim((string) $request->input('mensagem')) ?: $this->defaultMensagem($periodo);

        // Destinatários: SOMENTE os e-mails informados na tela (clientes não têm admin automático).
        $to = array_values(array_unique(array_filter($request->input('emails') ?: [])));

        if (empty($to)) {
            return response()->json([
                'success' => false,
                'message' => 'Nenhum destinatário: informe ao menos um e-mail.',
            ], 422);
        }

        // CC: só o financeiro (não-vazio), sem duplicar quem já está no To.
        $cc = array_values(array_diff(array_filter([$financeiroCc]), $to));

        $subject = 'Fechamento ' . $this->periodoMMAAAA($yearMonth) . ' | Relatório de Apontamentos - ' . $customer->name;

        $files      = $this->generateClienteFiles($customer, $yearMonth);
        $totalValue = $files['total_value'];

        try {
            $mailable = new FechamentoClienteMail(
                clienteName:     $customer->name,
                senderName:      $sender->name,
                periodo:         $periodo,
                valorTotal:      $this->brl($totalValue),
                subjectLine:     $subject,
                pdfPath:         $files['pdf_full'],
                xlsxPath:        $files['xlsx_full'],
                pdfFileName:     $files['pdf_name'],
                xlsxFileName:    $files['xlsx_name'],
                senderEmail:     $sender->email,
                financeiroCc:    $financeiroCc ?: null,
                mensagem:        $mensagem,
                withAttachments: true,
            );
            Mail::to($to)->cc($cc)->send($mailable);

            Log::info('Fechamento de cliente enviado por e-mail', [
                'cliente' => $customer->id, 'remetente' => $sender->id,
                'to' => $to, 'cc' => $cc, 'total' => $totalValue,
            ]);
        } catch (\Throwable $e) {
            Log::error('Falha ao enviar fechamento de cliente por e-mail', [
                'cliente' => $customer->id, 'remetente' => $sender->id, 'erro' => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'message' => 'Falha ao enviar o e-mail: ' . $e->getMessage()], 500);
        }

        $toLabel = implode(', ', $to);
        return response()->json([
            'success' => true,
            'message' => "Fechamento enviado para {$toLabel}" . (!empty($cc) ? ' (cópia: ' . implode(', ', $cc) . ')' : '') . '.',
        ]);
    }

    // ─── Download do Excel (XLSX) do fechamento ─────────────────────────────────
    // Mesmo XLSX que vai como anexo no e-mail, baixável direto pela tela do relatório.
    public function excel(Request $request, string $customerId, string $yearMonth)
    {
        $sender = $request->user();
        if (!$sender || !($sender->isAdmin() || $sender->isAdministrativo())) {
            return response()->json(['success' => false, 'message' => 'Sem permissão.'], 403);
        }
        $customer = Customer::find($customerId);
        if (!$customer) {
            return response()->json(['success' => false, 'message' => 'Cliente não encontrado.'], 404);
        }

        $periodo    = $this->periodoExtenso($yearMonth);
        $vedamotors = $this->isVedamotors($customer);
        $rows       = $this->clienteApontamentosFlat((int) $customer->id, $yearMonth, $vedamotors);
        $totalValue = $this->clienteTotal((int) $customer->id, $yearMonth);
        $export     = new FechamentoClienteExport($rows, $customer->name, $periodo, $totalValue, $vedamotors);
        $fileName   = "Fechamento_{$yearMonth}_" . $this->sanitizeFilename($customer->name) . ".xlsx";

        return Excel::download($export, $fileName);
    }

    // ─── Salvar e-mail de fechamento do cliente ─────────────────────────────────
    // Persiste o(s) destinatário(s) padrão (separados por vírgula) do fechamento.
    public function saveFechamentoEmail(Request $request, string $customerId): JsonResponse
    {
        $sender = $request->user();
        if (!$sender || !($sender->isAdmin() || $sender->isAdministrativo())) {
            return response()->json(['success' => false, 'message' => 'Sem permissão.'], 403);
        }
        $customer = Customer::find($customerId);
        if (!$customer) {
            return response()->json(['success' => false, 'message' => 'Cliente não encontrado.'], 404);
        }

        $request->validate([
            'fechamento_email' => 'nullable|string',
        ]);

        $customer->update(['fechamento_email' => $request->input('fechamento_email')]);

        return response()->json([
            'success'          => true,
            'fechamento_email' => $customer->fechamento_email,
        ]);
    }
}
