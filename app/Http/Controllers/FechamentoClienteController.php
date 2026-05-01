<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\FechamentoCliente;
use App\Models\Partner;
use App\Models\Project;
use App\Models\Timesheet;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $customers = Customer::whereRaw('"active" = true')
            ->whereHas('projects', function ($q) {
                $q->whereHas('contractType', fn ($q2) => $q2->where('code', 'on_demand'));
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
                'nome'           => $customer->company_name ?: $customer->name,
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
                $q->where('customer_id', $customerId);
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

        $byProject  = $timesheets->groupBy('project_id');
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
            ->whereHas('project', fn ($q) => $q->where('customer_id', $customerId))
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
            ->whereHas('project', fn ($q) => $q->where('customer_id', $customerId))
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
}
