<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\ServiceType;
use App\Models\StageDelivery;
use App\Models\Timesheet;
use App\Models\MovideskTicket;
use App\Services\ProjectWorkflowService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ClientPortalController extends Controller
{
    public function portal(Request $request): JsonResponse
    {
        $user       = $request->user();
        $customerId  = $request->get('customer_id');
        $period      = $request->get('period', 'all'); // all | month | quarter | year
        $filterMonth = $request->get('filter_month'); // 1-12
        $filterYear  = $request->get('filter_year');  // e.g. 2025

        // Admins podem passar customer_id livremente; coordenadores não têm customer_id;
        // clientes (futuramente) veriam apenas o próprio customer_id.
        if (!$customerId) {
            return response()->json(['message' => 'customer_id obrigatório'], 422);
        }

        $customer = Customer::find($customerId);
        if (!$customer) {
            return response()->json(['message' => 'Cliente não encontrado'], 404);
        }

        // Coordenador só pode ver clientes dos seus próprios projetos
        if ($user->isCoordenador()) {
            $isSustentacao = $user->coordinator_type === 'sustentacao';
            $hasAccess = Project::where('customer_id', $customerId)
                ->where(function ($q) use ($user, $isSustentacao) {
                    $q->whereHas('coordinators', fn($sq) => $sq->where('users.id', $user->id));
                    if ($isSustentacao) {
                        $q->orWhereHas('serviceType', fn($sq) => $sq->where('code', 'sustentacao'));
                    }
                })
                ->exists();
            if (!$hasAccess) {
                return response()->json(['message' => 'Acesso negado'], 403);
            }
        }

        // ── Projetos do cliente ─────────────────────────────────────────────
        $projectQuery = Project::with(['contractType', 'serviceType', 'childProjects.contractType', 'childProjects.serviceType'])
            ->where('customer_id', $customerId)
            ->whereNull('parent_project_id');

        if ($user->isCoordenador()) {
            $isSustentacao = $user->coordinator_type === 'sustentacao';
            $projectQuery->where(function ($q) use ($user, $isSustentacao) {
                $q->whereHas('coordinators', fn($sq) => $sq->where('users.id', $user->id));
                if ($isSustentacao) {
                    $q->orWhereHas('serviceType', fn($sq) => $sq->where('code', 'sustentacao'));
                }
            });
        }

        $projects = $projectQuery->get();

        // Coleta todos os IDs (pai + filho) para query de timesheets
        $allProjectIds = $projects->flatMap(fn($p) => collect([$p->id])->merge(
            $p->childProjects->pluck('id')
        ))->unique()->values()->toArray();

        // Período para filtrar timesheets
        $tsQuery = Timesheet::whereIn('project_id', $allProjectIds)
            ->where('status', '!=', Timesheet::STATUS_REJECTED);

        $this->applyPeriod($tsQuery, $period);

        $loggedMinutesByProject = $tsQuery
            ->select('project_id', DB::raw('SUM(effort_minutes) as total_minutes'))
            ->groupBy('project_id')
            ->pluck('total_minutes', 'project_id');

        // ── Consumo do mês filtrado (independente do period) ────────────────
        $now = Carbon::now();
        $fMonth = $filterMonth ? (int)$filterMonth : $now->month;
        $fYear  = $filterYear  ? (int)$filterYear  : $now->year;

        $monthMinutes = Timesheet::whereIn('project_id', $allProjectIds)
            ->where('status', '!=', Timesheet::STATUS_REJECTED)
            ->whereMonth('date', $fMonth)->whereYear('date', $fYear)
            ->sum('effort_minutes');
        $monthConsumed = round($monthMinutes / 60, 1);

        // Mês anterior para tendência
        $prevDate = Carbon::create($fYear, $fMonth, 1)->subMonth();
        $prevMinutes = Timesheet::whereIn('project_id', $allProjectIds)
            ->where('status', '!=', Timesheet::STATUS_REJECTED)
            ->whereMonth('date', $prevDate->month)->whereYear('date', $prevDate->year)
            ->sum('effort_minutes');
        $prevConsumed = round($prevMinutes / 60, 1);

        $trendPct = $prevConsumed > 0
            ? round((($monthConsumed - $prevConsumed) / $prevConsumed) * 100, 1)
            : ($monthConsumed > 0 ? 100 : 0);
        $trendDir = $trendPct > 5 ? 'up' : ($trendPct < -5 ? 'down' : 'stable');

        // ── Gráfico mensal (últimos 6 meses) ────────────────────────────────
        $monthlyChart = [];
        for ($i = 5; $i >= 0; $i--) {
            $d = Carbon::create($fYear, $fMonth, 1)->subMonths($i);
            $mins = Timesheet::whereIn('project_id', $allProjectIds)
                ->where('status', '!=', Timesheet::STATUS_REJECTED)
                ->whereMonth('date', $d->month)->whereYear('date', $d->year)
                ->sum('effort_minutes');
            $monthlyChart[] = [
                'month'          => $d->format('Y-m'),
                'label'          => $d->locale('pt_BR')->isoFormat('MMM/YY'),
                'consumed_hours' => round($mins / 60, 1),
            ];
        }

        // ── Projeção de esgotamento ──────────────────────────────────────────
        $avgMonthly = collect($monthlyChart)->avg('consumed_hours');
        $projection = null;

        // ── Overview ────────────────────────────────────────────────────────
        $overview = $this->buildOverview($projects, $loggedMinutesByProject);
        $overview['month_consumed']   = $monthConsumed;
        $overview['prev_consumed']    = $prevConsumed;
        $overview['trend_pct']        = $trendPct;
        $overview['trend_dir']        = $trendDir;
        $overview['avg_monthly']      = round($avgMonthly, 1);
        $overview['avg_weekly']       = round($avgMonthly / 4.3, 1);
        $overview['filter_month']     = $fMonth;
        $overview['filter_year']      = $fYear;
        if ($overview['balance_hours'] > 0 && $avgMonthly > 0) {
            $weeksLeft = round(($overview['balance_hours'] / $avgMonthly) * 4.3, 1);
            $overview['weeks_remaining'] = $weeksLeft;
        } else {
            $overview['weeks_remaining'] = null;
        }

        // ── Contratos agrupados ─────────────────────────────────────────────
        $contracts = $this->buildContracts($projects, $loggedMinutesByProject);

        // ── Projetos simplificados ──────────────────────────────────────────
        $projectsList = $this->buildProjects($projects, $loggedMinutesByProject);

        // ── Sustentação ─────────────────────────────────────────────────────
        $support = $this->buildSupport($customerId, $period);

        // ── Alertas melhorados ───────────────────────────────────────────────
        $alerts = $this->buildAlerts($projectsList, $overview);

        return response()->json([
            'customer' => ['id' => $customer->id, 'name' => $customer->name],
            'period'        => $period,
            'overview'      => $overview,
            'monthly_chart' => $monthlyChart,
            'contracts'     => $contracts,
            'projects'      => $projectsList,
            'support'       => $support,
            'alerts'        => $alerts,
        ]);
    }

    // ── Customers list (para o select do portal) ─────────────────────────────
    public function customers(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isCoordenador()) {
            $isSustentacao = $user->coordinator_type === 'sustentacao';
            $customerIds = Project::where(function ($q) use ($user, $isSustentacao) {
                    $q->whereHas('coordinators', fn($sq) => $sq->where('users.id', $user->id));
                    if ($isSustentacao) {
                        $q->orWhereHas('serviceType', fn($sq) => $sq->where('code', 'sustentacao'));
                    }
                })
                ->whereNotNull('customer_id')
                ->pluck('customer_id')
                ->unique();

            $customers = Customer::whereIn('id', $customerIds)->orderBy('name')->get(['id', 'name']);
        } else {
            $customers = Customer::orderBy('name')->get(['id', 'name']);
        }

        return response()->json($customers);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function applyPeriod($query, string $period): void
    {
        match ($period) {
            'month'   => $query->whereMonth('date', Carbon::now()->month)->whereYear('date', Carbon::now()->year),
            'quarter' => $query->where('date', '>=', Carbon::now()->startOfQuarter()),
            'year'    => $query->whereYear('date', Carbon::now()->year),
            default   => null, // all — sem filtro
        };
    }

    private function buildOverview(iterable $projects, $loggedMinutes): array
    {
        $totalSold     = 0;
        $totalConsumed = 0;
        $totalValue    = 0;

        foreach ($projects as $p) {
            $sold = $p->sold_hours ?? 0;
            $consumed = ($loggedMinutes[$p->id] ?? 0) / 60;

            // Incluir filhos também (exceto subprojetos Auster históricos)
            foreach ($p->childProjects ?? [] as $child) {
                if ($child->isAusterFrozen()) continue;
                $sold     += $child->sold_hours ?? 0;
                $consumed += ($loggedMinutes[$child->id] ?? 0) / 60;
                $totalValue += ($child->sold_hours ?? 0) * ($child->hourly_rate ?? 0);
            }

            $totalSold     += $sold;
            $totalConsumed += $consumed;
            $totalValue    += $sold * ($p->hourly_rate ?? 0);
        }

        $balancePct = $totalSold > 0 ? round(($totalConsumed / $totalSold) * 100, 1) : 0;
        $balance    = round($totalSold - $totalConsumed, 1);

        $status = 'ok';
        if ($balancePct >= 90 || $balance < 0) $status = 'critical';
        elseif ($balancePct >= 70) $status = 'warning';

        return [
            'balance_hours'   => $balance,
            'consumption_pct' => $balancePct,
            'investment'      => round($totalValue, 2),
            'status'          => $status,
            'total_sold'      => round($totalSold, 1),
            'total_consumed'  => round($totalConsumed, 1),
        ];
    }

    private function buildContracts(iterable $projects, $loggedMinutes): array
    {
        $grouped = [];

        $allProjects = collect();
        foreach ($projects as $p) {
            $allProjects->push($p);
            foreach ($p->childProjects ?? [] as $child) {
                if ($child->isAusterFrozen()) continue;
                $allProjects->push($child);
            }
        }

        foreach ($allProjects as $p) {
            $typeName = $p->contract_type_display ?? ($p->contractType->name ?? 'Sem tipo');
            $sold     = (float)($p->sold_hours ?? 0);
            $consumed = ($loggedMinutes[$p->id] ?? 0) / 60;
            $balance  = $sold - $consumed;
            $pct      = $sold > 0 ? round(($consumed / $sold) * 100, 1) : 0;

            if (!isset($grouped[$typeName])) {
                $grouped[$typeName] = [
                    'contract_type' => $typeName,
                    'sold_hours'    => 0,
                    'consumed_hours'=> 0,
                    'balance_hours' => 0,
                    'consumption_pct' => 0,
                    'project_count' => 0,
                    'status'        => 'ok',
                ];
            }

            $grouped[$typeName]['sold_hours']     += $sold;
            $grouped[$typeName]['consumed_hours']  += round($consumed, 1);
            $grouped[$typeName]['balance_hours']   += round($balance, 1);
            $grouped[$typeName]['project_count']++;
        }

        foreach ($grouped as &$g) {
            $s = $g['sold_hours'];
            $c = $g['consumed_hours'];
            $g['consumption_pct'] = $s > 0 ? round(($c / $s) * 100, 1) : 0;
            $g['sold_hours']      = round($s, 1);
            $pct = $g['consumption_pct'];
            $g['status'] = $pct >= 90 ? 'critical' : ($pct >= 70 ? 'warning' : 'ok');
        }

        return array_values($grouped);
    }

    private function buildProjects(iterable $projects, $loggedMinutes): array
    {
        $normalize = fn($s) => mb_strtolower(
            preg_replace('/[\x{0300}-\x{036f}]/u', '',
                normalizer_normalize($s ?? '', \Normalizer::FORM_D) ?: ($s ?? '')
            )
        );

        $list = [];

        foreach ($projects as $p) {
            $sold     = (float)($p->sold_hours ?? 0);
            $consumed = ($loggedMinutes[$p->id] ?? 0) / 60;
            $balance  = round($sold - $consumed, 1);
            $pct      = $sold > 0 ? round(($consumed / $sold) * 100, 1) : 0;
            $isSust   = str_contains($normalize($p->contract_type_display ?? ''), 'sustenta');

            $status = 'ok';
            if ($pct >= 90 || $balance < 0) $status = 'critical';
            elseif ($pct >= 70) $status = 'warning';

            $children = [];
            foreach ($p->childProjects ?? [] as $child) {
                $cSold     = (float)($child->sold_hours ?? 0);
                $cConsumed = ($loggedMinutes[$child->id] ?? 0) / 60;
                $cBalance  = round($cSold - $cConsumed, 1);
                $cPct      = $cSold > 0 ? round(($cConsumed / $cSold) * 100, 1) : 0;
                $cStatus   = $cPct >= 90 || $cBalance < 0 ? 'critical' : ($cPct >= 70 ? 'warning' : 'ok');

                $children[] = [
                    'id'              => $child->id,
                    'name'            => $child->name,
                    'status_display'  => $child->status_display,
                    'sold_hours'      => $cSold,
                    'consumed_hours'  => round($cConsumed, 1),
                    'balance_hours'   => $cBalance,
                    'consumption_pct' => $cPct,
                    'health'          => $cStatus,
                    'contract_type'   => $child->contract_type_display,
                    'is_sustentacao'  => str_contains($normalize($child->contract_type_display ?? ''), 'sustenta'),
                ];
            }

            $list[] = [
                'id'              => $p->id,
                'name'            => $p->name,
                'code'            => $p->code,
                'status_display'  => $p->status_display,
                'sold_hours'      => $sold,
                'consumed_hours'  => round($consumed, 1),
                'balance_hours'   => $balance,
                'consumption_pct' => $pct,
                'health'          => $status,
                'contract_type'   => $p->contract_type_display,
                'is_sustentacao'  => $isSust,
                'children'        => $children,
            ];
        }

        // Ordena por risco (critical primeiro, depois warning, depois ok) e por saldo asc
        usort($list, function ($a, $b) {
            $order = ['critical' => 0, 'warning' => 1, 'ok' => 2];
            $diff  = ($order[$a['health']] ?? 2) - ($order[$b['health']] ?? 2);
            return $diff !== 0 ? $diff : ($a['balance_hours'] - $b['balance_hours']);
        });

        return $list;
    }

    private function buildSupport(int $customerId, string $period): array
    {
        // Busca projetos de Sustentação do cliente
        $sustServiceType = ServiceType::where('code', 'sustentacao')
            ->orWhere('name', 'Sustentação')->first();

        if (!$sustServiceType) {
            return $this->emptySupportData();
        }

        $sustProjectIds = Project::where('customer_id', $customerId)
            ->where('service_type_id', $sustServiceType->id)
            ->pluck('id')
            ->toArray();

        if (empty($sustProjectIds)) {
            return $this->emptySupportData();
        }

        $tsBase = Timesheet::whereIn('project_id', $sustProjectIds)
            ->where('status', '!=', Timesheet::STATUS_REJECTED);

        $this->applyPeriod($tsBase, $period);

        $timesheets = (clone $tsBase)->get(['project_id', 'ticket', 'effort_minutes', 'date']);

        // Chamados únicos (pelo número do ticket)
        $uniqueTickets = $timesheets->whereNotNull('ticket')->pluck('ticket')->unique();
        $totalChamados = $uniqueTickets->count();

        // Horas consumidas
        $totalMinutes  = $timesheets->sum('effort_minutes');
        $totalHours    = round($totalMinutes / 60, 1);

        // Tempo médio por chamado
        $avgHours = $totalChamados > 0 ? round($totalHours / $totalChamados, 1) : 0;

        // Chamados abertos vs resolvidos (via MovideskTicket)
        $ticketIds = $uniqueTickets->filter(fn($t) => !empty($t))->values()->toArray();
        $moviTickets = MovideskTicket::whereIn('ticket_id', $ticketIds)->get(['ticket_id', 'status']);

        $resolved = $moviTickets->filter(fn($t) => in_array(
            mb_strtolower($t->status ?? ''),
            ['resolvido', 'fechado', 'encerrado', 'resolved', 'closed']
        ))->count();

        $open = $moviTickets->filter(fn($t) => !in_array(
            mb_strtolower($t->status ?? ''),
            ['resolvido', 'fechado', 'encerrado', 'resolved', 'closed']
        ))->count();

        // Chamados por mês (últimos 6 meses)
        try {
            $monthlyData = Timesheet::whereIn('project_id', $sustProjectIds)
                ->where('status', '!=', Timesheet::STATUS_REJECTED)
                ->whereNotNull('ticket')
                ->where('date', '>=', Carbon::now()->subMonths(5)->startOfMonth())
                ->selectRaw("strftime('%Y-%m', date) as month, COUNT(DISTINCT ticket) as count")
                ->groupBy('month')
                ->orderBy('month')
                ->get()
                ->map(fn($r) => ['month' => $r->month, 'count' => (int)$r->count])
                ->values();
        } catch (\Throwable $e) {
            try {
                $monthlyData = Timesheet::whereIn('project_id', $sustProjectIds)
                    ->where('status', '!=', Timesheet::STATUS_REJECTED)
                    ->whereNotNull('ticket')
                    ->where('date', '>=', Carbon::now()->subMonths(5)->startOfMonth())
                    ->selectRaw("DATE_FORMAT(date, '%Y-%m') as month, COUNT(DISTINCT ticket) as count")
                    ->groupBy('month')
                    ->orderBy('month')
                    ->get()
                    ->map(fn($r) => ['month' => $r->month, 'count' => (int)$r->count])
                    ->values();
            } catch (\Throwable $e2) {
                $monthlyData = collect();
            }
        }

        return [
            'open_tickets'      => $open,
            'resolved_tickets'  => $resolved,
            'total_tickets'     => $totalChamados,
            'consumed_hours'    => $totalHours,
            'avg_hours_ticket'  => $avgHours,
            'monthly_tickets'   => $monthlyData,
        ];
    }

    private function emptySupportData(): array
    {
        return [
            'open_tickets'     => 0,
            'resolved_tickets' => 0,
            'total_tickets'    => 0,
            'consumed_hours'   => 0,
            'avg_hours_ticket' => 0,
            'monthly_tickets'  => [],
        ];
    }

    private function buildAlerts(array $projects, array $overview): array
    {
        $alerts = [];

        foreach ($projects as $p) {
            if ($p['balance_hours'] < 0) {
                $exceeded = round(abs($p['balance_hours']), 1);
                $alerts[] = [
                    'type'       => 'critical',
                    'icon'       => 'negative',
                    'title'      => $p['name'],
                    'message'    => "Contrato excedido em {$exceeded}h — uso acima do contratado",
                    'project_id' => $p['id'],
                ];
            } elseif ($p['consumption_pct'] >= 90) {
                $remaining = round($p['balance_hours'], 1);
                $alerts[] = [
                    'type'       => 'critical',
                    'icon'       => 'alert',
                    'title'      => $p['name'],
                    'message'    => "Apenas {$remaining}h disponíveis — contrato quase esgotado",
                    'project_id' => $p['id'],
                ];
            } elseif ($p['balance_hours'] > 0 && $p['balance_hours'] < 10 && $p['sold_hours'] > 0) {
                $remaining = round($p['balance_hours'], 1);
                $alerts[] = [
                    'type'       => 'warning',
                    'icon'       => 'low-balance',
                    'title'      => $p['name'],
                    'message'    => "Saldo baixo: {$remaining}h restantes",
                    'project_id' => $p['id'],
                ];
            } elseif ($p['consumption_pct'] >= 70) {
                $pct = round($p['consumption_pct'], 1);
                $alerts[] = [
                    'type'       => 'warning',
                    'icon'       => 'warning',
                    'title'      => $p['name'],
                    'message'    => "{$pct}% utilizado — fique de olho no ritmo de consumo",
                    'project_id' => $p['id'],
                ];
            }
        }

        // Ordenar: critical primeiro
        usort($alerts, fn($a, $b) => ($a['type'] === 'critical' ? 0 : 1) - ($b['type'] === 'critical' ? 0 : 1));

        return $alerts;
    }
    /**
     * Visão macro do projeto pra perfil cliente — read-only.
     * 6 indicadores derivados; sem etapas/entregas/timeline técnica.
     *
     * Ver ADR 0002: cliente nunca vê `status` cru — só labels derivados.
     */
    public function operationalSummary(Request $request, int $projectId): JsonResponse
    {
        $user = $request->user();

        $project = Project::with(['customer:id,name', 'consultants:id,name'])
            ->find($projectId);

        if (!$project) {
            return response()->json(['message' => 'Projeto não encontrado.'], 404);
        }

        if ($user && method_exists($user, 'isCliente') && $user->isCliente()) {
            if ((int) $user->customer_id !== (int) $project->customer_id) {
                return response()->json(['message' => 'Acesso negado.'], 403);
            }
        }

        $statusMacroMap = [
            Project::STATUS_AWAITING_START       => 'Aguardando início',
            Project::STATUS_BACKLOG              => 'Em planejamento',
            Project::STATUS_STARTED              => 'Em execução',
            Project::STATUS_LIBERADO_PARA_TESTES => 'Em homologação',
            Project::STATUS_FINISHED             => 'Concluído',
            Project::STATUS_PAUSED               => 'Pausado',
            Project::STATUS_CANCELLED            => 'Cancelado',
        ];
        $statusMacro = $statusMacroMap[$project->status] ?? 'Em andamento';

        $stages = ProjectStage::where('project_id', $project->id)
            ->withCount(['deliveries', 'deliveries as deliveries_done_count' => function ($q) {
                $q->where('status', StageDelivery::STATUS_DONE);
            }])
            ->withSum('deliveries as deliveries_hours_planned_sum', 'hours_planned')
            ->withSum(['deliveries as deliveries_hours_planned_done_sum' => function ($q) {
                $q->where('status', StageDelivery::STATUS_DONE);
            }], 'hours_planned')
            ->get();

        $totalPlanned = (float) $stages->sum('deliveries_hours_planned_sum');
        $totalDone    = (float) $stages->sum('deliveries_hours_planned_done_sum');
        $sold         = (float) ($project->sold_hours ?? 0);
        $consumed     = method_exists($project, 'getTotalLoggedHours')
            ? (float) $project->getTotalLoggedHours()
            : 0.0;

        if ($totalPlanned > 0) {
            $progressPct = round(($totalDone / $totalPlanned) * 100, 1);
        } elseif ($sold > 0) {
            $progressPct = round(min(100, ($consumed / $sold) * 100), 1);
        } else {
            $progressPct = 0.0;
        }

        $today    = now()->startOfDay();
        $deadline = $project->expected_end_date ? Carbon::parse($project->expected_end_date) : null;
        $daysLeft = $deadline ? $today->diffInDays($deadline, false) : null;

        $isTerminal = ProjectWorkflowService::isTerminal($project->status);
        $health = 'ok';
        if (!$isTerminal) {
            $ratioHours = $sold > 0 ? $consumed / $sold : 0;
            if ($deadline && $daysLeft < 0 && $progressPct < 100) {
                $health = 'atrasado';
            } elseif ($ratioHours > 1.1) {
                $health = 'estourando';
            } elseif (
                ($deadline && $daysLeft !== null && $daysLeft <= 7) ||
                ($sold > 0 && $ratioHours > 0.9)
            ) {
                $health = 'atencao';
            }
        }

        $approvedTimesheets = Timesheet::where('project_id', $project->id)
            ->where('status', Timesheet::STATUS_APPROVED)
            ->with('user:id,name')
            ->latest('date')
            ->limit(10)
            ->get(['id', 'date', 'effort_minutes', 'user_id']);

        $completedDeliveries = StageDelivery::whereHas('stage', fn ($q) => $q->where('project_id', $project->id))
            ->where('status', StageDelivery::STATUS_DONE)
            ->orderByDesc('completed_at')
            ->limit(10)
            ->get(['id', 'title', 'completed_at']);

        $updates = collect();
        foreach ($approvedTimesheets as $t) {
            $updates->push([
                'type'  => 'hours_approved',
                'label' => 'Horas registradas',
                'who'   => $t->user?->name ?? 'Consultor',
                'when'  => $t->date?->toIso8601String(),
                'value' => round(($t->effort_minutes ?? 0) / 60, 1) . 'h',
            ]);
        }
        foreach ($completedDeliveries as $d) {
            $updates->push([
                'type'  => 'delivery_completed',
                'label' => 'Entrega concluída',
                'who'   => null,
                'when'  => $d->completed_at?->toIso8601String(),
                'value' => $d->title,
            ]);
        }
        $updates = $updates->sortByDesc('when')->take(10)->values();

        return response()->json([
            'project' => [
                'id'   => $project->id,
                'name' => $project->name,
                'code' => $project->code,
                'customer' => $project->customer ? [
                    'id'   => $project->customer->id,
                    'name' => $project->customer->name,
                ] : null,
            ],
            'status_macro'      => $statusMacro,
            'progress_pct'      => $progressPct,
            'expected_end_date' => $project->expected_end_date?->toDateString(),
            'sold_hours'        => $sold,
            'consumed_hours'    => $consumed,
            'health'            => $health,
            'recent_activity'   => $updates,
        ]);
    }
}
