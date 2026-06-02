<?php

namespace App\Http\Controllers;

use App\Exports\FechamentoClienteExport;
use App\Exports\FechamentoConsultorDespesaExport;
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
            ? round($hourlyRate / 160, 4)
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
        // Além disso, o contrato on_demand precisa ser PAI (parent_project_id null);
        // projeto FILHO on_demand (sub-contrato de um pai de outro tipo) NÃO habilita
        // o cliente na rotina de fechamento On Demand.
        $expFrom = $yearMonth ? "{$yearMonth}-01" : null;
        $expTo   = $yearMonth ? Carbon::parse("{$yearMonth}-01")->endOfMonth()->toDateString() : null;

        $customers = Customer::whereRaw('"active" = true')
            ->where(function ($outer) use ($yearMonth, $expFrom, $expTo) {
                // (1) clientes com projeto On Demand PAI (regra original).
                $outer->whereHas('projects', function ($q) {
                    $q->where(function ($qq) {
                            $qq->where('is_investimento_comercial', false)
                               ->orWhereNull('is_investimento_comercial');
                        })
                      ->whereNull('parent_project_id')
                      ->whereHas('contractType', fn ($q2) => $q2->where('code', 'on_demand'));
                });
                // (2) OU clientes com DESPESA A COBRAR no mês (qualquer tipo de contrato) —
                // antes só apareciam clientes On Demand, escondendo quem tem só despesa.
                if ($yearMonth) {
                    $outer->orWhereHas('projects', function ($q) use ($expFrom, $expTo) {
                        $q->where(function ($qq) {
                                $qq->where('is_investimento_comercial', false)
                                   ->orWhereNull('is_investimento_comercial');
                            })
                          ->whereHas('expenses', function ($qe) use ($expFrom, $expTo) {
                              $qe->where('charge_client', true)
                                 ->where('status', Expense::STATUS_APPROVED)
                                 ->whereBetween('expense_date', [$expFrom, $expTo]);
                          });
                    });
                }
            })
            ->orderBy('name')
            ->get(['id', 'name', 'company_name']);

        $fechamentos = $yearMonth
            ? FechamentoCliente::where('year_month', $yearMonth)
                ->with('closedByUser:id,name')
                ->get()
                ->keyBy('customer_id')
            : collect();

        $envioMap = \App\Models\FechamentoSendStatus::mapFor(
            \App\Models\FechamentoSendStatus::TIPO_CLIENTE, $yearMonth, $customers->pluck('id')->all(),
        );

        $data = $customers->map(function ($customer) use ($fechamentos, $envioMap) {
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
                'envio_em'       => $envioMap[$customer->id]['envio_em'] ?? null,
                'envio_por'      => $envioMap[$customer->id]['envio_por'] ?? null,
            ];
        });

        return response()->json(['data' => $data]);
    }

    /**
     * GET /fechamento-cliente/despesas-resumo?year_month=AAAA-MM
     * Todos os clientes com despesa A COBRAR no mês (charge_client=true, não pagas),
     * agregadas por cliente — para a aba de envio de despesas.
     */
    public function despesasResumo(Request $request): JsonResponse
    {
        $yearMonth = $request->query('year_month');
        if (!$yearMonth) {
            return response()->json(['data' => []]);
        }

        $from = "{$yearMonth}-01";
        $to   = Carbon::parse("{$yearMonth}-01")->endOfMonth()->toDateString();

        // is_paid (consultor reembolsado) é independente de cobrar o cliente — filtrar por
        // ele aqui escondia despesas legítimas do fechamento. Mantém só status válidos.
        $byCustomer = Expense::query()
            ->where('charge_client', true)
            ->where('status', Expense::STATUS_APPROVED)
            ->whereBetween('expense_date', [$from, $to])
            ->whereHas('project', fn ($q) => $q->where('is_investimento_comercial', false)->whereNotNull('customer_id'))
            ->with('project:id,customer_id')
            ->get()
            ->groupBy(fn ($e) => $e->project?->customer_id)
            ->filter(fn ($g, $cid) => (bool) $cid);

        $nomes = Customer::whereIn('id', $byCustomer->keys()->map(fn ($k) => (int) $k)->all())
            ->pluck('name', 'id');

        $data = $byCustomer
            ->map(fn ($g, $cid) => [
                'customer_id' => (int) $cid,
                'nome'        => $nomes[(int) $cid] ?? '—',
                'qtd'         => $g->count(),
                'total'       => round($g->sum('amount'), 2),
            ])
            ->values()
            ->sortByDesc('total')
            ->values();

        return response()->json([
            'data'        => $data,
            'total_geral' => round($data->sum('total'), 2),
        ]);
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

    /**
     * GET /fechamento-cliente/apontamentos-geral?from=AAAA-MM&to=AAAA-MM
     * Lista PLANA de todos os apontamentos On Demand do período (todos os clientes),
     * para a aba Apontamentos. Os filtros de cliente/projeto são aplicados no front
     * (o conjunto de um mês é pequeno; envia tudo e filtra/agrupa lá).
     */
    public function apontamentosGeral(Request $request): JsonResponse
    {
        $fromMonth = $request->query('from');
        $toMonth   = $request->query('to', $fromMonth);
        if (!$fromMonth) {
            return response()->json(['data' => []]);
        }

        $from = "{$fromMonth}-01";
        $to   = Carbon::parse("{$toMonth}-01")->endOfMonth()->toDateString();

        $rows = Timesheet::query()
            ->with([
                'user:id,name',
                'project:id,name,code,customer_id',
                'project.customer:id,name',
            ])
            ->select('timesheets.*', 'movidesk_tickets.titulo as ticket_titulo', 'movidesk_tickets.solicitante as ticket_solicitante')
            ->leftJoin('movidesk_tickets', 'movidesk_tickets.ticket_id', '=', 'timesheets.ticket')
            ->whereBetween('timesheets.date', [$from, $to])
            ->whereNotIn('timesheets.status', [Timesheet::STATUS_ADJUSTMENT_REQUESTED, Timesheet::STATUS_REJECTED, Timesheet::STATUS_CONFLICTED, Timesheet::STATUS_INTERNAL])
            ->whereNull('timesheets.deleted_at')
            ->whereHas('project', function ($q) {
                $q->where('is_investimento_comercial', false)
                  ->whereNotNull('customer_id')
                  ->where($this->rootOnDemandScope());
            })
            ->orderBy('timesheets.date')
            ->get();

        $data = $rows->map(function ($t) {
            $solicitanteRaw = $t->ticket_solicitante;
            if (is_string($solicitanteRaw)) {
                $solicitanteRaw = json_decode($solicitanteRaw, true);
            }
            $solicitante = is_array($solicitanteRaw) ? ($solicitanteRaw['name'] ?? null) : null;

            return [
                'id'             => $t->id,
                'data'           => $t->date->format('Y-m-d'),
                'customer_id'    => $t->project?->customer_id,
                'cliente'        => $t->project?->customer?->name ?? '—',
                'projeto_id'     => $t->project_id,
                'projeto_codigo' => $t->project?->code ?? '—',
                'projeto_nome'   => $t->project?->name ?? '—',
                'colaborador'    => $t->user?->name ?? '—',
                'solicitante'    => $solicitante,
                'ticket'         => $t->ticket,
                'titulo'         => $t->ticket_titulo,
                'horas'          => round($t->effort_minutes / 60, 2),
            ];
        })->values();

        return response()->json(['data' => $data]);
    }

    /**
     * Escopo "Contrato RAIZ On Demand": restringe uma query de Project (ou um
     * whereHas('project', ...)) aos projetos cujo CONTRATO PAI é On Demand.
     *
     * Um projeto entra se:
     *   (a) é PAI (parent_project_id null) com contractType.code = on_demand; OU
     *   (b) é FILHO cujo PAI é On Demand (não-investimento).
     *
     * Sem isso, um FILHO On Demand de um PAI de OUTRO tipo (ex.: "Banco de Horas")
     * puxava o contrato pai NÃO-On-Demand pro fechamento On Demand — porque o
     * apontamentosData consolida filho→pai. Regra "só On Demand PAI", espelhando
     * FechamentoClienteController::index e FechamentoContratoController.
     */
    private function rootOnDemandScope(): \Closure
    {
        return function ($qr) {
            $qr->where(function ($qp) {
                    $qp->whereNull('parent_project_id')
                       ->whereHas('contractType', fn ($c) => $c->where('code', 'on_demand'));
                })
              ->orWhereHas('parentProject', function ($pp) {
                    $pp->where('is_investimento_comercial', false)
                       ->whereHas('contractType', fn ($c) => $c->where('code', 'on_demand'));
                });
        };
    }

    private function apontamentosData(int $customerId, string $fromMonth, string $toMonth, ?string $contractCode = null, ?int $projectId = null): array
    {
        $from = "{$fromMonth}-01";
        $to   = Carbon::parse("{$toMonth}-01")->endOfMonth()->toDateString();

        // $projectId filtra pelo projeto PAI (escolhido no dropdown "Contrato"); inclui
        // os filhos via parent_project_id pra que o relatório/PDF respeite a seleção.
        $projectIds = Timesheet::whereBetween('date', [$from, $to])
            ->whereNotIn('status', [Timesheet::STATUS_ADJUSTMENT_REQUESTED, Timesheet::STATUS_REJECTED, Timesheet::STATUS_CONFLICTED, Timesheet::STATUS_INTERNAL])
            ->whereNull('deleted_at')
            ->whereHas('project', function ($q) use ($customerId, $contractCode, $projectId) {
                $q->where('customer_id', $customerId)
                  ->where('is_investimento_comercial', false)
                  ->where($this->rootOnDemandScope());
                if ($contractCode) {
                    $q->whereHas('contractType', fn ($q2) => $q2->where('code', $contractCode));
                }
                if ($projectId) {
                    $q->where(fn ($q2) => $q2->where('id', $projectId)->orWhere('parent_project_id', $projectId));
                }
            })
            ->distinct()
            ->pluck('project_id');

        if ($projectIds->isEmpty()) {
            return ['projetos' => [], 'total_horas' => 0.0, 'total_geral' => 0.0];
        }

        $projects = Project::with(['contractType:id,name,code', 'hourlyRateChanges'])
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
            Project::with(['contractType:id,name,code', 'hourlyRateChanges'])
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
            $project     = $projects[$projId] ?? null;
            // Taxa do projeto p/ a competência de fechamento (cabeçalho/coluna do projeto).
            $projetoRate = (float) ($project?->hourlyRateForCompetencia($toMonth) ?? 0);

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

                $horas      = $t->effort_minutes / 60;
                // Valoriza cada apontamento pela taxa vigente NO MÊS do apontamento — legado intacto.
                $hourlyRate = (float) ($project?->hourlyRateForCompetencia($t->date->format('Y-m')) ?? 0);
                $mult       = 1 + (((float) ($t->client_extra_pct ?? 0)) / 100);
                $valorTs = round($horas * $hourlyRate * $mult, 2);

                $horasProjeto += $horas;
                $basesProjeto += $horas * $hourlyRate;
                $totalProjeto += $valorTs;

                $subProj = $projects[$t->project_id] ?? null;
                $apontamentos[] = [
                    'id'                 => $t->id,
                    'data'               => $t->date->format('Y-m-d'),
                    'colaborador'        => $t->user?->name ?? '—',
                    // Sub-projeto real (p/ separar filho dentro do contrato pai no relatório).
                    'sub_projeto_id'     => $t->project_id,
                    'sub_projeto_codigo' => $subProj?->code ?? '—',
                    'sub_projeto_nome'   => $subProj?->name ?? '—',
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
                'valor_hora'     => $projetoRate,
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

    public function pendencias(Request $request, string $customerId, string $yearMonth): JsonResponse
    {
        // Suporta range de meses (filtro da página); default = mês único.
        $fromMonth = $request->query('from', $yearMonth);
        $toMonth   = $request->query('to', $yearMonth);
        $from = "{$fromMonth}-01";
        $to   = Carbon::parse("{$toMonth}-01")->endOfMonth()->toDateString();

        $tsModels = Timesheet::with(['user:id,name', 'project', 'project.hourlyRateChanges'])
            ->whereHas('project', fn ($q) => $q->where('customer_id', $customerId)
                ->where('is_investimento_comercial', false)
                ->where($this->rootOnDemandScope()))
            ->whereBetween('date', [$from, $to])
            ->whereIn('status', [Timesheet::STATUS_PENDING, Timesheet::STATUS_ADJUSTMENT_REQUESTED])
            ->whereNull('deleted_at')
            ->orderBy('date')
            ->get();

        // Valor pendente do apontamento = horas × valor/hora On Demand × (1 + client_extra_pct).
        $rateCache = [];
        $timesheets = $tsModels->map(function ($t) use (&$rateCache) {
            $comp  = $t->date->format('Y-m');
            $key   = $t->project_id . '|' . $comp;
            $rate  = $rateCache[$key] ??= (float) ($t->project?->hourlyRateForCompetencia($comp) ?? 0);
            $horas = round($t->effort_minutes / 60, 2);
            $mult  = 1 + (((float) ($t->client_extra_pct ?? 0)) / 100);
            return [
                'id'             => $t->id,
                'tipo'           => 'timesheet',
                'data'           => $t->date->format('Y-m-d'),
                'colaborador'    => $t->user?->name ?? '—',
                'projeto'        => $t->project?->name ?? '—',
                'projeto_codigo' => $t->project?->code ?? '—',
                'horas'          => $horas,
                'valor'          => round($horas * $rate * $mult, 2),
                'status'         => $t->status,
                'ticket'         => $t->ticket,
                'observacao'     => $t->observation,
            ];
        });

        $despesas = Expense::with(['user:id,name', 'project:id,name,code', 'category:id,name'])
            ->whereHas('project', fn ($q) => $q->where('customer_id', $customerId)->where('is_investimento_comercial', false))
            ->whereBetween('expense_date', [$from, $to])
            ->whereIn('status', ['pending', 'adjustment_requested'])
            ->orderBy('expense_date')
            ->get()
            ->map(fn ($e) => [
                'id'             => $e->id,
                'tipo'           => 'expense',
                'data'           => $e->expense_date->format('Y-m-d'),
                'colaborador'    => $e->user?->name ?? '—',
                'projeto'        => $e->project?->name ?? '—',
                'projeto_codigo' => $e->project?->code ?? '—',
                'descricao'      => $e->description,
                'categoria'      => $e->category?->name ?? '—',
                'valor'          => (float) $e->amount,
                'status'         => $e->status,
            ]);

        $valorTimesheets = round($timesheets->sum('valor'), 2);
        $valorDespesas   = round($despesas->sum('valor'), 2);

        return response()->json([
            'timesheets'        => $timesheets->values(),
            'despesas'          => $despesas->values(),
            'qtd_timesheets'    => $timesheets->count(),
            'qtd_despesas'      => $despesas->count(),
            'total_pendencias'  => $timesheets->count() + $despesas->count(),
            'valor_timesheets'  => $valorTimesheets,
            'valor_despesas'    => $valorDespesas,
            'valor_pendente'    => round($valorTimesheets + $valorDespesas, 2),
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

        // Fechamento de cliente lista APENAS contratos On Demand do cliente.
        // Base nos PROJETOS On Demand (não em timesheets) para que um contrato
        // On Demand sem consumo no mês também apareça (= consumo / 0h).
        $projectIds = Project::where('customer_id', $customerId)
            ->where('is_investimento_comercial', false)
            ->where($this->rootOnDemandScope())
            ->pluck('id');

        if ($projectIds->isEmpty()) {
            return [];
        }

        $projects = Project::with(['contractType:id,name,code', 'hourlyRateChanges'])
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
            $hourlyRate   = (float) ($project->hourlyRateForCompetencia($yearMonth) ?? 0);
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

    /** Acréscimo fixo por despesa na visão do CLIENTE (só Auster). */
    private const AUSTER_EXPENSE_SURCHARGE = 30.0;

    /** R$30 por despesa quando o cliente é Auster; 0 para os demais. */
    private function austerExpenseSurcharge(int $customerId): float
    {
        $name = \App\Models\Customer::where('id', $customerId)->value('name');
        return ($name !== null && stripos($name, 'auster') !== false)
            ? self::AUSTER_EXPENSE_SURCHARGE
            : 0.0;
    }

    private function despesasData(int $customerId, string $fromMonth, string $toMonth): array
    {
        $from = "{$fromMonth}-01";
        $to   = Carbon::parse("{$toMonth}-01")->endOfMonth()->toDateString();

        // Particularidade Auster: toda despesa cobrada leva +R$30 SÓ na visão do cliente
        // (fechamento enviado + perfil). O consultor recebe o valor original (amount), que
        // fica intacto no banco e na visão interna. Markup puramente de faturamento.
        $surcharge = $this->austerExpenseSurcharge($customerId);

        // Fechamento do cliente lista as despesas a cobrar (charge_client) APROVADAS.
        // Pendente/ajuste/rejeitado ficam de fora (a despesa só entra no fechamento depois
        // de aprovada). is_paid (reembolso ao consultor) é controle interno e não filtra aqui.
        return Expense::with([
            'user:id,name',
            'project:id,name,code',
            'category:id,name',
        ])
            ->where('charge_client', true)
            ->where('status', Expense::STATUS_APPROVED)
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
                'valor'       => (float) $e->amount + $surcharge,
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
        // Horas em DECIMAL 2 casas (pt-BR) — total bate com horas × taxa.
        return number_format($h, 2, ',', '.');
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
    private function defaultMensagem(string $periodo, string $mode = 'servicos'): string
    {
        if ($mode === 'despesa') {
            return "Segue em anexo a apuração das despesas a cobrar referente ao período de {$periodo}.\n\nEm caso de dúvidas ou divergências, por gentileza entrar em contato.";
        }
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
    private function clienteApontamentosFlat(int $customerId, string $yearMonth, bool $vedamotors, ?int $projectId = null): array
    {
        $data = $this->apontamentosData($customerId, $yearMonth, $yearMonth, 'on_demand', $projectId);

        $rows = [];
        foreach (($data['projetos'] ?? []) as $proj) {
            foreach (($proj['apontamentos'] ?? []) as $ap) {
                $titulo = $vedamotors
                    ? $this->vedaTicket($ap['titulo'] ?? null)
                    : ($ap['titulo'] ?? '');

                $rows[] = [
                    // Contrato (projeto PAI consolidado) — usado p/ agrupar no PDF.
                    'contrato'    => $proj['projeto_nome'] ?? '—',
                    'valor_hora'  => (float) ($proj['valor_hora'] ?? 0),
                    // Sub-projeto real do apontamento (projeto FILHO, ou o próprio pai).
                    'projeto'    => $ap['sub_projeto_nome'] ?? ($proj['projeto_nome'] ?? '—'),
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
            ->where($this->rootOnDemandScope())
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

    /**
     * Agrupa as linhas achatadas para o PDF (Relatório de Apontamentos).
     *
     * Agrupa por CONTRATO (projeto pai consolidado) e, dentro de cada contrato,
     * sub-agrupa por SUB-PROJETO (projeto filho real do apontamento). Isso espelha
     * o relatório em tela: contrato pai + blocos filhos com sub-subtotais e um único
     * total por contrato.
     *
     * Cada grupo devolve:
     *   - projeto:    nome do contrato (cabeçalho + subtotal geral)
     *   - horas_fmt:  total de horas do contrato (subtotal geral)
     *   - multi_sub:  bool — true se o contrato tem >1 sub-projeto (renderiza
     *                 cabeçalhos/sub-subtotais); false renderiza plano (caso normal)
     *   - linhas:     todas as linhas do contrato (usado quando multi_sub = false)
     *   - subgrupos:  [{ projeto, horas_fmt, linhas }] (usado quando multi_sub = true)
     */
    private function buildPdfGroups(array $rows): array
    {
        // 1) Agrupa por contrato (pai), preservando ordem de aparição.
        $byContrato = [];
        foreach ($rows as $r) {
            $byContrato[$r['contrato'] ?? ($r['projeto'] ?? '—')][] = $r;
        }

        // Observação → HTML seguro pro PDF/preview: preserva quebras de linha
        // (tags de bloco/<br> viram \n), remove o resto das tags, decodifica
        // entidades, colapsa quebras múltiplas, escapa e re-quebra com <br>.
        $cleanObs = function ($raw): string {
            $raw = (string) ($raw ?? '');
            if (trim($raw) === '') return '—';
            $t = preg_replace('/<\s*(br|\/p|\/div|\/li|\/tr|\/h[1-6])\s*\/?>/i', "\n", $raw);
            $t = html_entity_decode(strip_tags((string) $t), ENT_QUOTES | ENT_HTML5);
            // URLs com percent-encoding (%C3%B4…) viram texto legível — tira o "lixo de código".
            $t = preg_replace_callback('#https?://\S+#u', fn ($m) => rawurldecode($m[0]), (string) $t);
            // nbsp (&nbsp; → \xC2\xA0) vira espaço normal, senão linhas "em branco" não colapsam.
            $t = str_replace("\xC2\xA0", ' ', (string) $t);
            // Remove espaços ao redor de cada quebra → linhas-fantasma (de imagem removida) viram \n contíguos.
            $t = preg_replace('/[ \t]*\n[ \t]*/u', "\n", (string) $t);
            // Colapsa o gap deixado pelas imagens: 3+ quebras → 1 linha em branco só.
            $t = preg_replace('/\n{3,}/u', "\n\n", (string) $t);
            $t = trim((string) $t);
            return $t === '' ? '—' : nl2br(e($t));
        };

        $fmtLinha = fn (array $l): array => [
            'data'        => isset($l['data']) ? Carbon::parse($l['data'])->format('d/m/Y') : '',
            'consultor'   => $l['consultor'] ?? '—',
            'ticket'      => $l['ticket'] ?? '',
            'titulo'      => $l['titulo'] ?? '',
            'horas_fmt'   => $this->fmtHoras((float) ($l['horas'] ?? 0)),
            'observacao'  => $l['observacao'] ?? null,
            'observacao_html' => $cleanObs($l['observacao'] ?? null),
        ];

        $grupos = [];
        foreach ($byContrato as $contrato => $items) {
            // 2) Sub-agrupa por sub-projeto (filho real), preservando ordem.
            $bySub = [];
            foreach ($items as $r) {
                $bySub[$r['projeto'] ?? $contrato][] = $r;
            }

            $horasContrato = 0.0;
            $linhasFlat    = [];   // todas as linhas (caso plano, 1 sub-projeto)
            $subgrupos     = [];   // blocos por sub-projeto (caso consolidado)

            foreach ($bySub as $sub => $subItems) {
                $horasSub   = 0.0;
                $linhasSub  = [];
                foreach ($subItems as $l) {
                    $h = (float) ($l['horas'] ?? 0);
                    $horasSub      += $h;
                    $horasContrato += $h;
                    $linha          = $fmtLinha($l);
                    $linhasSub[]    = $linha;
                    $linhasFlat[]   = $linha;
                }
                $subgrupos[] = [
                    'projeto'   => $sub,
                    'linhas'    => $linhasSub,
                    'horas_fmt' => $this->fmtHoras($horasSub),
                ];
            }

            $grupos[] = [
                'projeto'    => $contrato,
                'valor_hora' => (float) ($items[0]['valor_hora'] ?? 0),
                'horas_fmt' => $this->fmtHoras($horasContrato),
                // >1 sub-projeto = filho consolidado: renderiza sub-cabeçalhos + sub-subtotais.
                'multi_sub' => count($subgrupos) > 1,
                'linhas'    => $linhasFlat,
                'subgrupos' => $subgrupos,
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
    private function generateClienteFiles(Customer $customer, string $yearMonth, string $mode = 'servicos', ?int $projectId = null): array
    {
        $safeName = $this->sanitizeFilename($customer->name);
        $dir      = 'fechamentos';
        $dirFull  = storage_path("app/{$dir}");
        if (!is_dir($dirFull)) {
            mkdir($dirFull, 0775, true);
        }

        // ── Modo DESPESA — relatório de despesas a cobrar (PDF + XLSX próprios) ──
        if ($mode === 'despesa') {
            $pdfFileName  = "Despesas_{$yearMonth}_{$safeName}.pdf";
            $xlsxFileName = "Despesas_{$yearMonth}_{$safeName}.xlsx";
            $pdfRelPath   = "{$dir}/{$pdfFileName}";
            $xlsxRelPath  = "{$dir}/{$xlsxFileName}";
            $pdfFullPath  = storage_path("app/{$pdfRelPath}");
            $xlsxFullPath = storage_path("app/{$xlsxRelPath}");

            $pdf = Pdf::loadView('pdf.fechamento-cliente-despesa', $this->buildDespesaViewData($customer, $yearMonth))
                ->setPaper('a4', 'portrait')->setOption(['defaultMediaType' => 'print']);
            file_put_contents($pdfFullPath, $pdf->output());

            $despesas = array_map(
                fn ($d) => $d + ['cliente' => $customer->name, 'is_paid' => false, 'paid_at' => null],
                $this->despesasData((int) $customer->id, $yearMonth, $yearMonth),
            );
            $export = new FechamentoConsultorDespesaExport($despesas, $customer->name, $this->periodoExtenso($yearMonth));
            file_put_contents($xlsxFullPath, Excel::raw($export, \Maatwebsite\Excel\Excel::XLSX));

            return [
                'pdf_rel'     => $pdfRelPath,
                'xlsx_rel'    => $xlsxRelPath,
                'pdf_full'    => $pdfFullPath,
                'xlsx_full'   => $xlsxFullPath,
                'pdf_name'    => $pdfFileName,
                'xlsx_name'   => $xlsxFileName,
                'total_value' => $this->despesaTotal((int) $customer->id, $yearMonth),
                'projetos'    => [],
            ];
        }

        $periodo    = $this->periodoExtenso($yearMonth);
        $vedamotors = $this->isVedamotors($customer);
        $rows       = $this->clienteApontamentosFlat((int) $customer->id, $yearMonth, $vedamotors, $projectId);
        // Total geral: cliente inteiro quando sem filtro; soma dos rows filtrados c/ projectId.
        $totalValue = $projectId
            ? round(collect($rows)->sum(fn ($r) => (float) $r['horas'] * (float) ($r['valor_hora'] ?? 0)), 2)
            : $this->clienteTotal((int) $customer->id, $yearMonth);

        // Projeto(s) do fechamento (código + nome) — usado no retorno p/ o caller.
        $apData       = $this->apontamentosData((int) $customer->id, $yearMonth, $yearMonth, 'on_demand', $projectId);
        $projetosList = array_values(array_map(
            fn ($p) => ['codigo' => $p['projeto_codigo'] ?? '—', 'nome' => $p['projeto_nome'] ?? '—'],
            $apData['projetos'] ?? []
        ));

        $safeName     = $this->sanitizeFilename($customer->name);
        // Sufixo do projeto no filename quando filtrado, pra não sobrescrever os arquivos
        // do fechamento completo (mesma pasta storage/app/fechamentos) — mantém ambos.
        $projSuffix   = '';
        if ($projectId && !empty($projetosList) && !empty($projetosList[0]['codigo']) && $projetosList[0]['codigo'] !== '—') {
            $projSuffix = '_' . $this->sanitizeFilename($projetosList[0]['codigo']);
        }
        $pdfFileName  = "Fechamento_{$yearMonth}_{$safeName}{$projSuffix}.pdf";
        $xlsxFileName = "Fechamento_{$yearMonth}_{$safeName}{$projSuffix}.xlsx";
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

        // ── PDF — MESMA view-data do preview da tela (fonte única = a Blade) ──
        $pdf = Pdf::loadView('pdf.fechamento-cliente', $this->buildReportViewData($customer, $yearMonth, $projectId))
            ->setPaper('a4', 'portrait')->setOption(['defaultMediaType' => 'print']);
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
            'projetos'    => $projetosList,
        ];
    }

    /**
     * Dados da view do relatório de apontamentos do cliente — FONTE ÚNICA usada
     * tanto pelo PDF (dompdf) quanto pelo preview da tela (reportHtml), pra garantir
     * que o relatório visto na página e o documento enviado sejam IDÊNTICOS.
     *
     * @return array<string, mixed>
     */
    private function buildReportViewData(Customer $customer, string $yearMonth, ?int $projectId = null): array
    {
        $periodo    = $this->periodoExtenso($yearMonth);
        $vedamotors = $this->isVedamotors($customer);
        $rows       = $this->clienteApontamentosFlat((int) $customer->id, $yearMonth, $vedamotors, $projectId);
        // Total geral do cliente NÃO usa $projectId (legado intacto) — só os rows do
        // relatório filtram. Os totais por contrato/projeto exibidos vêm dos rows.
        $totalValue = $projectId
            ? round(collect($rows)->sum(fn ($r) => (float) $r['horas'] * (float) ($r['valor_hora'] ?? 0)), 2)
            : $this->clienteTotal((int) $customer->id, $yearMonth);
        $totalHoras = round(collect($rows)->sum('horas'), 2);

        $apData       = $this->apontamentosData((int) $customer->id, $yearMonth, $yearMonth, 'on_demand', $projectId);
        $projetosList = array_values(array_map(
            fn ($p) => ['codigo' => $p['projeto_codigo'] ?? '—', 'nome' => $p['projeto_nome'] ?? '—'],
            $apData['projetos'] ?? []
        ));

        // Valor hora p/ o resumo do topo: só quando há uma única taxa entre os projetos.
        $ratesUnicas = array_values(array_unique(array_filter(
            array_map(fn ($p) => (float) ($p['valor_hora'] ?? 0), $apData['projetos'] ?? []),
            fn ($v) => $v > 0
        )));
        $valorHoraResumo = count($ratesUnicas) === 1 ? $this->brl($ratesUnicas[0]) : null;

        // Rótulo do projeto pro cabeçalho: 1 → "código — nome"; vários → "Todos".
        $projetoLabel = match (count($projetosList)) {
            0       => '—',
            1       => trim(($projetosList[0]['codigo'] ?? '') . ' — ' . ($projetosList[0]['nome'] ?? '')),
            default => 'Todos',
        };

        // Logo ERPSERV embutido (base64) — funciona no dompdf (PDF) e no iframe (tela).
        $logoFile    = public_path('logo-erpserv.png');
        $logoDataUri = is_file($logoFile)
            ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($logoFile))
            : null;

        // Apuração por Ticket — só Vedamotors (espelha o totalizador da tela).
        $ticketSummary = $vedamotors ? $this->clienteTicketSummary((int) $customer->id, $yearMonth) : [];
        $ticketRows    = array_map(fn ($t) => [
            'ticket'       => $t['ticket'],
            'veda_ticket'  => $t['veda_ticket'],
            'requester'    => $t['requester'],
            'period_fmt'   => $this->fmtHoras($t['period_minutes'] / 60),
            'lifetime_fmt' => $this->fmtHoras($t['lifetime_minutes'] / 60),
        ], $ticketSummary);

        return [
            'clienteName'          => $customer->name,
            'periodo'              => $periodo,
            'logoDataUri'          => $logoDataUri,
            'projetoLabel'         => $projetoLabel,
            'emitidoEm'            => now()->format('d/m/Y'),
            'projetos'             => $projetosList,
            'totalHorasFmt'        => $this->fmtHoras($totalHoras),
            'valorTotal'           => $this->brl($totalValue),
            'valorHoraResumo'      => $valorHoraResumo,
            'grupos'               => $this->buildPdfGroups($rows),
            'vedamotors'           => $vedamotors,
            'ticketRows'           => $ticketRows,
            'ticketTotPeriodFmt'   => $this->fmtHoras(array_sum(array_column($ticketSummary, 'period_minutes')) / 60),
            'ticketTotLifetimeFmt' => $this->fmtHoras(array_sum(array_column($ticketSummary, 'lifetime_minutes')) / 60),
        ];
    }

    /**
     * Dados da view do RELATÓRIO DE DESPESAS A COBRAR do cliente — fonte única do
     * PDF e do preview (mesma Blade), espelhando o relatório de serviços.
     *
     * @return array<string, mixed>
     */
    private function buildDespesaViewData(Customer $customer, string $yearMonth): array
    {
        $periodo  = $this->periodoExtenso($yearMonth);
        $despesas = $this->despesasData((int) $customer->id, $yearMonth, $yearMonth);
        $total    = round(collect($despesas)->sum('valor'), 2);

        $logoFile    = public_path('logo-erpserv.png');
        $logoDataUri = is_file($logoFile)
            ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($logoFile))
            : null;

        $linhas = array_map(fn ($d) => [
            'data'        => Carbon::parse($d['data'])->format('d/m/Y'),
            'descricao'   => $d['descricao'] ?: '—',
            'categoria'   => $d['categoria'] ?? '—',
            'colaborador' => $d['colaborador'] ?? '—',
            'projeto'     => $d['projeto'] ?? '—',
            'valor'       => $this->brl((float) $d['valor']),
        ], $despesas);

        return [
            'clienteName' => $customer->name,
            'periodo'     => $periodo,
            'logoDataUri' => $logoDataUri,
            'emitidoEm'   => now()->format('d/m/Y'),
            'despesas'    => $linhas,
            'qtd'         => count($linhas),
            'totalFmt'    => $this->brl($total),
        ];
    }

    /** Soma das despesas a cobrar do cliente no mês (não pagas). */
    private function despesaTotal(int $customerId, string $yearMonth): float
    {
        return round(collect($this->despesasData($customerId, $yearMonth, $yearMonth))->sum('valor'), 2);
    }

    /**
     * HTML do relatório de apontamentos (a MESMA Blade do PDF enviado) — consumido
     * pelo preview da tela, garantindo paridade total com o documento anexado.
     */
    public function reportHtml(Request $request, string $customerId, string $yearMonth): JsonResponse
    {
        $customer = Customer::find($customerId);
        if (!$customer) {
            return response()->json(['success' => false, 'message' => 'Cliente não encontrado.'], 404);
        }

        $mode      = $request->query('mode') === 'despesa' ? 'despesa' : 'servicos';
        $projectId = $request->query('project_id') ? (int) $request->query('project_id') : null;
        $html = $mode === 'despesa'
            ? view('pdf.fechamento-cliente-despesa', $this->buildDespesaViewData($customer, $yearMonth))->render()
            : view('pdf.fechamento-cliente', $this->buildReportViewData($customer, $yearMonth, $projectId))->render();

        return response()->json(['html' => $html]);
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

        $mode           = $request->input('mode') === 'despesa' ? 'despesa' : 'servicos';
        // project_id propagado no preview pra que o template do e-mail liste só o
        // projeto filtrado (espelha o anexo PDF que o enviarEmail vai gerar).
        $projectId      = $mode === 'servicos' && $request->filled('project_id')
            ? (int) $request->input('project_id')
            : null;
        $periodo        = $this->periodoExtenso($yearMonth);
        $mensagemPadrao = $this->defaultMensagem($periodo, $mode);
        $mensagem       = trim((string) $request->input('mensagem'));
        $mensagem       = $mensagem !== '' ? $mensagem : $mensagemPadrao;

        $projetosList = [];
        $valorTotal   = $this->brl($this->despesaTotal((int) $customer->id, $yearMonth));
        if ($mode !== 'despesa') {
            $apData       = $this->apontamentosData((int) $customer->id, $yearMonth, $yearMonth, 'on_demand', $projectId);
            $projetosList = array_values(array_map(
                fn ($p) => ['codigo' => $p['projeto_codigo'] ?? '—', 'nome' => $p['projeto_nome'] ?? '—'],
                $apData['projetos'] ?? []
            ));
            // Valor total: dos rows filtrados quando há projectId; cliente inteiro caso contrário.
            if ($projectId) {
                $rowsFiltered = $this->clienteApontamentosFlat((int) $customer->id, $yearMonth, $this->isVedamotors($customer), $projectId);
                $valorTotal   = $this->brl(round(collect($rowsFiltered)->sum(fn ($r) => (float) $r['horas'] * (float) ($r['valor_hora'] ?? 0)), 2));
            } else {
                $valorTotal   = $this->brl($this->clienteTotal((int) $customer->id, $yearMonth));
            }
        }

        $html = view('emails.fechamento.cliente', [
            'clienteName'     => $customer->name,
            'senderName'      => $sender->name,
            'periodo'         => $periodo,
            'mode'            => $mode,
            'valorTotal'      => $valorTotal,
            'withAttachments' => true,
            'mensagem'        => $mensagem,
            'projetos'        => $projetosList,
        ])->render();

        // Prévia só: força o logo claro (escuro-colorido) a aparecer no card branco —
        // o swap de dark-mode do template trocaria pro logo branco (invisível aqui).
        $override = '<style>.erp-light{display:inline-block !important}.erp-dark{display:none !important}</style>';
        $html = str_ireplace('</head>', $override . '</head>', $html);

        return response()->json([
            'html'                   => $html,
            'mensagem_padrao'        => $mensagemPadrao,
            'fechamento_email'       => $customer->fechamento_email,
            'emails_administrativos' => $customer->adminEmails(),
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
            'mensagem'   => 'nullable|string', // corpo editável; vazio = mensagem padrão
            'emails'     => 'required|array|min:1',
            'emails.*'   => 'email',
            'mode'       => 'nullable|in:servicos,despesa',
            'project_id' => 'nullable|integer', // filtro de contrato (projeto pai)
            'anexos'     => 'nullable|array',
            'anexos.*'   => 'file|max:10240', // até 10 MB cada (Graph soma anexos ~3 MB no total)
        ], [
            'emails.required' => 'Informe ao menos um e-mail de destino antes de enviar.',
            'emails.array'    => 'Lista de e-mails inválida.',
            'emails.min'      => 'Informe ao menos um e-mail de destino antes de enviar.',
            'emails.*.email'  => 'Um dos e-mails informados é inválido.',
            'anexos.*.file'   => 'Anexo inválido.',
            'anexos.*.max'    => 'Cada anexo deve ter no máximo 10 MB.',
        ]);

        $customer = Customer::find($customerId);
        if (!$customer) {
            return response()->json(['success' => false, 'message' => 'Cliente não encontrado.'], 404);
        }

        $mode         = $request->input('mode') === 'despesa' ? 'despesa' : 'servicos';
        // project_id só faz sentido no modo serviços (relatório por contrato); no modo
        // despesa o relatório agrega despesas — ignora o filtro pra não esconder dados.
        $projectId    = $mode === 'servicos' && $request->filled('project_id')
            ? (int) $request->input('project_id')
            : null;
        $periodo      = $this->periodoExtenso($yearMonth);
        $financeiroCc = (string) (config('mail.financeiro_cc') ?? '');
        $mensagem     = trim((string) $request->input('mensagem')) ?: $this->defaultMensagem($periodo, $mode);

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

        $subject = $mode === 'despesa'
            ? 'Despesas ' . $this->periodoMMAAAA($yearMonth) . ' | Reembolso - ' . $customer->name
            : 'Fechamento ' . $this->periodoMMAAAA($yearMonth) . ' | Relatório de Apontamentos - ' . $customer->name;

        $files      = $this->generateClienteFiles($customer, $yearMonth, $mode, $projectId);
        $totalValue = $files['total_value'];

        // Anexos extras escolhidos pelo usuário — salvos em pasta única (preserva o
        // nome original p/ exibição) e removidos ao final do envio.
        $extraPaths = [];
        foreach ((array) $request->file('anexos', []) as $file) {
            if (!$file || !$file->isValid()) {
                continue;
            }
            $dir  = storage_path('app/fechamentos/anexos/' . uniqid('a', true));
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $name = $file->getClientOriginalName() ?: ('anexo_' . uniqid() . '.' . ($file->getClientOriginalExtension() ?: 'bin'));
            $file->move($dir, $name);
            $extraPaths[] = $dir . '/' . $name;
        }

        // Limite de envio: via Graph os anexos (PDF + XLSX + extras) somam até ~3 MB.
        // Bloqueia ANTES de enviar — não deixa passar do limite.
        if (\App\Services\GraphMailer::enabled()) {
            $totalBytes = (is_file($files['pdf_full'])  ? (int) filesize($files['pdf_full'])  : 0)
                        + (is_file($files['xlsx_full']) ? (int) filesize($files['xlsx_full']) : 0);
            foreach ($extraPaths as $p) {
                $totalBytes += is_file($p) ? (int) filesize($p) : 0;
            }
            if ($totalBytes > \App\Services\GraphMailer::MAX_INLINE_ATTACHMENTS_BYTES) {
                foreach ($extraPaths as $p) { @unlink($p); @rmdir(dirname($p)); }
                $maxMb = number_format(\App\Services\GraphMailer::MAX_INLINE_ATTACHMENTS_BYTES / 1048576, 1, ',', '.');
                $totMb = number_format($totalBytes / 1048576, 1, ',', '.');
                return response()->json([
                    'success' => false,
                    'message' => "Anexos excedem o limite de envio: {$totMb} MB de {$maxMb} MB (inclui o relatório PDF e a planilha). Remova ou reduza os anexos extras.",
                ], 422);
            }
        }

        // Envia COMO o remetente (App Password O365) quando configurado; senão, conta NF-e (fallback atual).
        $mc = \App\Services\SenderMailer::for(
            $sender,
            (string) config('mail.fechamento_cliente_mailer', 'nfe'),
            (string) config('mail.fechamento_cliente_from', config('mail.from.address')),
            config('mail.fechamento_cliente_from_name', config('mail.fechamento_from_name', 'Fechamento ERPSERV')),
        );

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
                projetos:        $files['projetos'] ?? [],
                withAttachments: true,
                fromAddress:     $mc['from_address'],
                fromName:        $mc['from_name'],
                mode:            $mode,
                extraAttachments: $extraPaths,
            );

            // Microsoft Graph (Send As do remetente) quando configurado; senão, SMTP/NF-e atual.
            if (\App\Services\GraphMailer::enabled() && filled($sender->email)) {
                \App\Services\GraphMailer::sendAs(
                    $sender->email,
                    $to,
                    $cc,
                    $subject,
                    $mailable->render(),
                    array_merge([$files['pdf_full'], $files['xlsx_full']], $extraPaths),
                );
            } else {
                Mail::mailer($mc['mailer'])->to($to)->cc($cc)->send($mailable);
            }

            \App\Models\FechamentoSendStatus::marcarEnviado(
                \App\Models\FechamentoSendStatus::TIPO_CLIENTE, (int) $customer->id, $yearMonth, (int) $sender->id,
            );

            Log::info('Fechamento de cliente enviado por e-mail', [
                'cliente' => $customer->id, 'remetente' => $sender->id,
                'to' => $to, 'cc' => $cc, 'total' => $totalValue, 'anexos_extras' => count($extraPaths),
            ]);
            foreach ($extraPaths as $p) { @unlink($p); @rmdir(dirname($p)); }
        } catch (\Throwable $e) {
            foreach ($extraPaths as $p) { @unlink($p); @rmdir(dirname($p)); }
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

    // ─── Limpar status de envio ─────────────────────────────────────────────────
    public function limparEnvio(Request $request, string $customerId, string $yearMonth): JsonResponse
    {
        $sender = $request->user();
        if (!$sender || !($sender->isAdmin() || $sender->isAdministrativo())) {
            return response()->json(['success' => false, 'message' => 'Sem permissão.'], 403);
        }

        \App\Models\FechamentoSendStatus::limpar(
            \App\Models\FechamentoSendStatus::TIPO_CLIENTE, (int) $customerId, $yearMonth,
        );

        return response()->json(['success' => true, 'message' => 'Status de envio limpo.']);
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

        $mode = $request->query('mode') === 'despesa' ? 'despesa' : 'servicos';
        // project_id (filtro de contrato) só faz sentido no modo serviços; ignora em despesa.
        $projectId = $mode === 'servicos' && $request->query('project_id')
            ? (int) $request->query('project_id')
            : null;

        if ($mode === 'despesa') {
            $despesas = array_map(
                fn ($d) => $d + ['cliente' => $customer->name, 'is_paid' => false, 'paid_at' => null],
                $this->despesasData((int) $customer->id, $yearMonth, $yearMonth),
            );
            $export   = new FechamentoConsultorDespesaExport($despesas, $customer->name, $this->periodoExtenso($yearMonth));
            $fileName = "Despesas_{$yearMonth}_" . $this->sanitizeFilename($customer->name) . ".xlsx";
            return Excel::download($export, $fileName);
        }

        $periodo    = $this->periodoExtenso($yearMonth);
        $vedamotors = $this->isVedamotors($customer);
        $rows       = $this->clienteApontamentosFlat((int) $customer->id, $yearMonth, $vedamotors, $projectId);
        $totalValue = $projectId
            ? round(collect($rows)->sum(fn ($r) => (float) $r['horas'] * (float) ($r['valor_hora'] ?? 0)), 2)
            : $this->clienteTotal((int) $customer->id, $yearMonth);
        $export     = new FechamentoClienteExport($rows, $customer->name, $periodo, $totalValue, $vedamotors);

        // Sufixo com o código do projeto pra diferenciar do download do fechamento completo.
        $projSuffix = '';
        if ($projectId) {
            $apData = $this->apontamentosData((int) $customer->id, $yearMonth, $yearMonth, 'on_demand', $projectId);
            $first  = $apData['projetos'][0] ?? null;
            if ($first && !empty($first['projeto_codigo']) && $first['projeto_codigo'] !== '—') {
                $projSuffix = '_' . $this->sanitizeFilename($first['projeto_codigo']);
            }
        }
        $fileName = "Fechamento_{$yearMonth}_" . $this->sanitizeFilename($customer->name) . "{$projSuffix}.xlsx";

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
            'fechamento_email'         => 'nullable|string',
            'emails_administrativos'   => 'nullable|array',
            'emails_administrativos.*' => 'email',
        ]);

        // Lista única (mesma usada no comunicado de reajuste). Aceita array OU a string
        // legada (separada por , ; ou espaço). setAdminEmails sincroniza fechamento_email.
        $emails = $request->input('emails_administrativos');
        if ($emails === null && $request->filled('fechamento_email')) {
            $emails = preg_split('/[,;\s]+/', (string) $request->input('fechamento_email'));
        }
        $customer->setAdminEmails($emails ?? []);
        $customer->save();

        return response()->json([
            'success'                => true,
            'fechamento_email'       => $customer->fechamento_email,
            'emails_administrativos' => $customer->adminEmails(),
        ]);
    }
}
