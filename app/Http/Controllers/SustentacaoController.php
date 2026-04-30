<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\MovideskAgent;
use App\Models\MovideskOrganization;
use App\Models\MovideskTicket;
use App\Services\MovideskService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SustentacaoController extends Controller
{
    /** Query base que exclui tickets cujo responsável é @promax.bardahl.com.br */
    private function tickets(): \Illuminate\Database\Eloquent\Builder
    {
        return MovideskTicket::where(function ($q) {
            $q->whereNull('owner_email')
              ->orWhere('owner_email', 'not ilike', '%@promax.bardahl.com.br');
        });
    }

    /**
     * Aplica o filtro de "tickets abertos":
     *  - Novos (New) ou Em Atendimento (InAttendance)
     *  - Parado (Stopped) apenas se sub-status for "Pendente Terceiros" ou "Pendente TOTVS"
     */
    private function applyOpen(\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder
    {
        return $q->where(function ($inner) {
            $inner->whereIn('base_status', ['New', 'InAttendance'])
                  ->orWhere(fn($s) => $s->where('base_status', 'Stopped')
                                        ->whereIn('status', ['Pendente Terceiros', 'Pendente TOTVS', 'Agendado']));
        });
    }

    /** Apenas organizações reais (com CNPJ ou vinculadas ao Minutor) — exclui departamentos internos */
    private function orgLookup(): \Illuminate\Support\Collection
    {
        return MovideskOrganization::where(function ($q) {
            $q->whereNotNull('customer_id')->orWhere('cnpj', '!=', '')->whereNotNull('cnpj');
        })->get(['name'])
          ->keyBy(fn($o) => strtolower(trim($o->name)));
    }

    /** Mapa domínio-de-email → org real, construído a partir dos tickets existentes */
    private function domainOrgMap(\Illuminate\Support\Collection $orgByName): array
    {
        $map = [];
        $this->tickets()->whereNotNull('solicitante')
            ->whereRaw("solicitante->>'email' IS NOT NULL")
            ->whereRaw("solicitante->>'organization' IS NOT NULL")
            ->get(['solicitante'])
            ->each(function ($t) use ($orgByName, &$map) {
                $sol    = $t->solicitante ?? [];
                $email  = strtolower($sol['email'] ?? '');
                $orgKey = strtolower(trim($sol['organization'] ?? ''));
                if (!$email || !$orgKey || !isset($orgByName[$orgKey])) return;
                $domain = substr($email, strrpos($email, '@') + 1);
                if ($domain && !isset($map[$domain])) {
                    $map[$domain] = $orgByName[$orgKey]->name;
                }
            });
        return $map;
    }

    private function resolveOrgName(array $sol, \Illuminate\Support\Collection $orgByName, array $domainMap = []): ?string
    {
        // 1. Campo organization — só aceita se for uma org real (não departamento interno)
        $orgKey = strtolower(trim($sol['organization'] ?? ''));
        if ($orgKey && isset($orgByName[$orgKey])) return $orgByName[$orgKey]->name;

        // 2. Campo name: cliente é a própria empresa
        $nameKey = strtolower(trim($sol['name'] ?? ''));
        if ($nameKey && isset($orgByName[$nameKey])) return $orgByName[$nameKey]->name;

        // 3. Heurístico "Nome | Empresa": extrai partes após " | " e busca na tabela
        $name  = $sol['name'] ?? '';
        $parts = str_contains($name, ' | ') ? explode(' | ', $name) : [$name];
        foreach (array_reverse($parts) as $part) {
            $partLower = strtolower(trim($part));
            if (!$partLower) continue;
            if (isset($orgByName[$partLower])) return $orgByName[$partLower]->name;
            foreach ($orgByName as $key => $org) {
                if (strlen($key) >= 5 && str_contains($partLower, $key)) {
                    return $org->name;
                }
            }
        }

        // 4. Fallback: domínio do e-mail → org conhecida
        $email = strtolower(trim($sol['email'] ?? ''));
        if ($email && str_contains($email, '@')) {
            $domain = substr($email, strrpos($email, '@') + 1);
            if ($domain && isset($domainMap[$domain])) return $domainMap[$domain];
        }

        return null;
    }

    private function authorize(): void
    {
        $user = auth()->user();
        if ($user->type !== 'admin' && !($user->type === 'coordenador' && $user->coordinator_type === 'sustentacao')) {
            abort(403, 'Acesso restrito ao Portal de Sustentação');
        }
    }

    private function dateRange(Request $request): array
    {
        $from = $request->query('from') ? Carbon::parse($request->query('from')) : now()->subDays(30);
        $to   = $request->query('to')   ? Carbon::parse($request->query('to'))   : now();
        return [$from->startOfDay(), $to->endOfDay()];
    }

    public function kpis(Request $request): JsonResponse
    {
        $this->authorize();
        [$from, $to] = $this->dateRange($request);

        $totalOpen      = $this->applyOpen($this->tickets())->count();
        $newToday       = $this->tickets()->whereDate('created_date', today())->count();
        $resolvedPeriod = $this->tickets()->whereBetween('resolved_in', [$from, $to])->count();
        $closedPeriod   = $this->tickets()->whereBetween('closed_in',   [$from, $to])->count();

        // SLA response compliance
        $slaRespTotal   = $this->tickets()->whereBetween('created_date', [$from, $to])->whereNotNull('sla_response_date')->count();
        $slaRespOnTime  = $this->tickets()->whereBetween('created_date', [$from, $to])
            ->whereNotNull('sla_real_response_date')
            ->whereColumn('sla_real_response_date', '<=', 'sla_response_date')
            ->count();
        $slaResponseRate = $slaRespTotal > 0 ? round(($slaRespOnTime / $slaRespTotal) * 100, 1) : null;

        // SLA solution compliance
        $slaSolTotal  = $this->tickets()->whereBetween('created_date', [$from, $to])
            ->whereNotNull('sla_solution_date')->whereNotNull('resolved_in')->count();
        $slaSolOnTime = $this->tickets()->whereBetween('created_date', [$from, $to])
            ->whereNotNull('sla_solution_date')->whereNotNull('resolved_in')
            ->whereColumn('resolved_in', '<=', 'sla_solution_date')
            ->count();
        $slaSolutionRate = $slaSolTotal > 0 ? round(($slaSolOnTime / $slaSolTotal) * 100, 1) : null;

        $openAtRisk = $this->applyOpen($this->tickets())
            ->whereNull('resolved_in')
            ->whereNull('closed_in')
            ->whereNotNull('sla_solution_date')
            ->where('sla_solution_date', '<', now())
            ->count();

        $avgSolutionTime = $this->tickets()->whereBetween('resolved_in', [$from, $to])
            ->whereNotNull('sla_solution_time')
            ->avg('sla_solution_time');

        return response()->json([
            'total_open'         => $totalOpen,
            'new_today'          => $newToday,
            'resolved_period'    => $resolvedPeriod,
            'closed_period'      => $closedPeriod,
            'sla_response_rate'  => $slaResponseRate,
            'sla_solution_rate'  => $slaSolutionRate,
            'open_at_risk'       => $openAtRisk,
            'avg_solution_time'  => $avgSolutionTime ? round($avgSolutionTime) : null,
            'period'             => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ]);
    }

    public function queue(Request $request): JsonResponse
    {
        $this->authorize();

        $orgByName       = $this->orgLookup();
        $orgByCustomerId = MovideskOrganization::whereNotNull('customer_id')
            ->get(['name', 'customer_id'])
            ->keyBy('customer_id');
        $domainMap       = $this->domainOrgMap($orgByName);

        $split = fn($v) => $v ? array_filter(array_map('trim', explode(',', $v))) : [];

        $responsavelFilter = $split($request->query('responsavel'));
        $clienteFilter     = $split($request->query('cliente'));
        $urgenciaFilter    = $split($request->query('urgencia'));
        $statusFilter      = $split($request->query('status'));
        $searchFilter      = $request->query('search');

        $tickets = $this->applyOpen($this->tickets())
            ->with(['user:id,name', 'customer:id,name'])
            ->when($responsavelFilter, fn($q) => $q->whereIn(DB::raw('LOWER(owner_email)'), array_map('strtolower', $responsavelFilter)))
            ->when($clienteFilter, fn($q) => $q->where(function ($q2) use ($clienteFilter) {
                foreach ($clienteFilter as $c) {
                    $q2->orWhereRaw("solicitante->>'organization' ILIKE ?", ["%{$c}%"]);
                }
            }))
            ->when($urgenciaFilter, fn($q) => $q->whereIn('urgencia', $urgenciaFilter))
            ->when($statusFilter, fn($q) => $q->whereIn('status', $statusFilter))
            ->when($searchFilter, fn($q) => $q->where(function ($q2) use ($searchFilter) {
                $q2->where('titulo', 'ilike', "%{$searchFilter}%")
                   ->orWhere(DB::raw('ticket_id::text'), 'like', "%{$searchFilter}%");
            }))
            ->orderByRaw("CASE urgencia
                WHEN 'Urgente' THEN 1
                WHEN 'Alta'    THEN 2
                WHEN 'Normal'  THEN 3
                WHEN 'Baixa'   THEN 4
                ELSE 5 END")
            ->orderBy(DB::raw('sla_solution_date IS NULL'), 'asc')
            ->orderBy('sla_solution_date')
            ->orderBy('created_date')
            ->paginate($request->query('per_page', 50));

        $tickets->getCollection()->transform(function ($ticket) use ($orgByName, $orgByCustomerId, $domainMap) {
            // Prioridade: customer_id direto → solicitante fields → heurístico → domínio e-mail
            $ticket->org_name =
                ($orgByCustomerId[$ticket->customer_id]->name ?? null)
                ?? $this->resolveOrgName($ticket->solicitante ?? [], $orgByName, $domainMap);
            return $ticket;
        });

        return response()->json($tickets);
    }

    public function sla(Request $request): JsonResponse
    {
        $this->authorize();
        [$from, $to] = $this->dateRange($request);

        $byUrgency = $this->tickets()->selectRaw('urgencia')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN sla_real_response_date IS NOT NULL AND sla_real_response_date <= sla_response_date THEN 1 ELSE 0 END) as on_time_response')
            ->selectRaw('SUM(CASE WHEN resolved_in IS NOT NULL AND resolved_in <= sla_solution_date THEN 1 ELSE 0 END) as on_time_solution')
            ->whereBetween('created_date', [$from, $to])
            ->groupBy('urgencia')
            ->orderByRaw("CASE urgencia WHEN 'Urgente' THEN 1 WHEN 'Alta' THEN 2 WHEN 'Normal' THEN 3 WHEN 'Baixa' THEN 4 ELSE 5 END")
            ->get();

        $orgByNameSla       = $this->orgLookup();
        $orgByCustomerIdSla = MovideskOrganization::whereNotNull('customer_id')
            ->get(['name', 'customer_id'])
            ->keyBy('customer_id');
        $domainMapSla       = $this->domainOrgMap($orgByNameSla);

        $breachingNow = $this->applyOpen($this->tickets())
            ->whereNull('resolved_in')
            ->whereNull('closed_in')
            ->where(function ($q) {
                $q->where(function ($q2) {
                    $q2->whereNotNull('sla_response_date')
                       ->whereNull('sla_real_response_date')
                       ->where('sla_response_date', '<', now());
                })->orWhere(function ($q2) {
                    $q2->whereNotNull('sla_solution_date')
                       ->where('sla_solution_date', '<', now());
                });
            })
            ->with(['user:id,name', 'customer:id,name'])
            ->orderBy('sla_solution_date')
            ->get()
            ->each(function ($ticket) use ($orgByNameSla, $orgByCustomerIdSla, $domainMapSla) {
                $ticket->org_name =
                    ($orgByCustomerIdSla[$ticket->customer_id]->name ?? null)
                    ?? $this->resolveOrgName($ticket->solicitante ?? [], $orgByNameSla, $domainMapSla);
            });

        $trend = $this->tickets()->selectRaw("TO_CHAR(DATE_TRUNC('month', created_date), 'YYYY-MM') as month")
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN resolved_in IS NOT NULL AND resolved_in <= sla_solution_date THEN 1 ELSE 0 END) as on_time')
            ->whereNotNull('created_date')
            ->where('created_date', '>=', now()->subMonths(6))
            ->groupByRaw("DATE_TRUNC('month', created_date)")
            ->orderByRaw("DATE_TRUNC('month', created_date)")
            ->get();

        return response()->json([
            'by_urgency'    => $byUrgency,
            'breaching_now' => $breachingNow,
            'monthly_trend' => $trend,
        ]);
    }

    public function productivity(Request $request): JsonResponse
    {
        $this->authorize();
        [$from, $to] = $this->dateRange($request);

        // Agrupa por owner_email (Movidesk) — inclui responsáveis sem vínculo no Minutor
        $byConsultant = $this->tickets()->selectRaw("LOWER(owner_email) as owner_email")
            ->selectRaw("MAX(responsavel->>'name') as owner_name")
            ->selectRaw('MAX(user_id) as user_id')
            ->selectRaw('COUNT(*) as tickets_resolved')
            ->selectRaw('AVG(sla_solution_time) as avg_solution_minutes')
            ->whereNotNull('owner_email')
            ->where('owner_email', 'not ilike', '%@promax.bardahl.com.br')
            ->whereNotNull('resolved_in')
            ->whereBetween('resolved_in', [$from, $to])
            ->groupByRaw("LOWER(owner_email)")
            ->orderByDesc('tickets_resolved')
            ->get();

        $hoursFromTimesheets = DB::table('timesheets')
            ->join('projects', 'projects.id', '=', 'timesheets.project_id')
            ->join('service_types', 'service_types.id', '=', 'projects.service_type_id')
            ->where(function ($q) {
                $q->where('service_types.code', 'sustentacao')
                  ->orWhere('service_types.name', 'ilike', '%sustenta%');
            })
            ->whereBetween('timesheets.date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('timesheets.status', ['approved', 'pending'])
            ->select('timesheets.user_id', DB::raw('SUM(timesheets.effort_minutes) as total_minutes'))
            ->groupBy('timesheets.user_id')
            ->get()
            ->keyBy('user_id');

        $byConsultant = $byConsultant->map(function ($item) use ($hoursFromTimesheets) {
            $ts = $hoursFromTimesheets->get($item->user_id);
            $item->total_minutes_worked = $ts ? (int) $ts->total_minutes : 0;
            return $item;
        });

        return response()->json([
            'by_consultant' => $byConsultant,
            'period'        => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ]);
    }

    public function financial(Request $request): JsonResponse
    {
        $this->authorize();
        [$from, $to] = $this->dateRange($request);

        $byProject = DB::table('timesheets')
            ->join('projects', 'projects.id', '=', 'timesheets.project_id')
            ->join('service_types', 'service_types.id', '=', 'projects.service_type_id')
            ->join('customers', 'customers.id', '=', 'projects.customer_id')
            ->where(function ($q) {
                $q->where('service_types.code', 'sustentacao')
                  ->orWhere('service_types.name', 'ilike', '%sustenta%');
            })
            ->whereBetween('timesheets.date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('timesheets.status', ['approved', 'pending'])
            ->select(
                'projects.id as project_id',
                'projects.name as project_name',
                'projects.sold_hours',
                'customers.id as customer_id',
                'customers.name as customer_name',
                DB::raw('SUM(timesheets.effort_minutes) as total_minutes'),
                DB::raw('COUNT(DISTINCT timesheets.ticket) as ticket_count')
            )
            ->groupBy('projects.id', 'projects.name', 'projects.sold_hours', 'customers.id', 'customers.name')
            ->orderByDesc('total_minutes')
            ->get();

        return response()->json([
            'by_project' => $byProject,
            'period'     => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ]);
    }

    public function clients(Request $request): JsonResponse
    {
        $this->authorize();
        [$from, $to] = $this->dateRange($request);

        $byClient = $this->tickets()->select('customer_id')
            ->selectRaw('COUNT(*) as total_period')
            ->selectRaw("SUM(CASE WHEN base_status IN ('New','InAttendance') OR (base_status = 'Stopped' AND status IN ('Pendente Terceiros','Pendente TOTVS','Agendado')) THEN 1 ELSE 0 END) as open_now")
            ->selectRaw('SUM(CASE WHEN resolved_in IS NOT NULL AND resolved_in <= sla_solution_date THEN 1 ELSE 0 END) as sla_ok')
            ->selectRaw('ROUND(AVG(sla_solution_time)::numeric, 0) as avg_solution_minutes')
            ->whereNotNull('customer_id')
            ->whereBetween('created_date', [$from, $to])
            ->groupBy('customer_id')
            ->with('customer:id,name')
            ->orderByDesc('total_period')
            ->get();

        return response()->json([
            'by_client' => $byClient,
            'period'    => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ]);
    }

    public function distribution(Request $request): JsonResponse
    {
        $this->authorize();
        [$from, $to] = $this->dateRange($request);

        $base = fn() => $this->tickets()->whereBetween('created_date', [$from, $to]);

        return response()->json([
            'by_urgency'    => $base()->selectRaw('urgencia as label, COUNT(*) as count')->whereNotNull('urgencia')->groupBy('urgencia')->orderByDesc('count')->get(),
            'by_category'   => $base()->selectRaw('categoria as label, COUNT(*) as count')->whereNotNull('categoria')->groupBy('categoria')->orderByDesc('count')->get(),
            'by_service'    => $base()->selectRaw('servico as label, COUNT(*) as count')->whereNotNull('servico')->groupBy('servico')->orderByDesc('count')->get(),
            'by_team'       => $base()->selectRaw('owner_team as label, COUNT(*) as count')->whereNotNull('owner_team')->groupBy('owner_team')->orderByDesc('count')->get(),
            'by_base_status' => $base()->selectRaw('base_status as label, COUNT(*) as count')->whereNotNull('base_status')->groupBy('base_status')->orderByDesc('count')->get(),
            'by_origin'     => $base()->selectRaw('origin as label, COUNT(*) as count')->whereNotNull('origin')->groupBy('origin')->orderByDesc('count')->get(),
            'period'        => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ]);
    }

    public function evolution(Request $request): JsonResponse
    {
        $this->authorize();

        $monthly = $this->tickets()->selectRaw("TO_CHAR(DATE_TRUNC('month', created_date), 'YYYY-MM') as month")
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN resolved_in IS NOT NULL THEN 1 ELSE 0 END) as resolved')
            ->selectRaw('SUM(CASE WHEN resolved_in IS NOT NULL AND resolved_in <= sla_solution_date THEN 1 ELSE 0 END) as sla_ok')
            ->whereNotNull('created_date')
            ->where('created_date', '>=', now()->subMonths(12)->startOfMonth())
            ->groupByRaw("DATE_TRUNC('month', created_date)")
            ->orderByRaw("DATE_TRUNC('month', created_date)")
            ->get();

        return response()->json(['monthly' => $monthly]);
    }

    public function debugClientes(): JsonResponse
    {
        $this->authorize();

        $customersByCnpj = Customer::whereNotNull('cgc')
            ->get()
            ->keyBy(fn($c) => preg_replace('/[^0-9]/', '', $c->cgc));

        // Se movidesk_organizations já foi populada, usa ela como fonte principal
        // (mostra TODAS as empresas, mesmo sem tickets)
        $movideskOrgs = MovideskOrganization::count() > 0
            ? MovideskOrganization::with('customer')->get()
            : null;

        if ($movideskOrgs) {
            // Contagem de tickets por nome de organização
            $ticketCounts = $this->tickets()->whereNotNull('solicitante')
                ->selectRaw("
                    LOWER(solicitante->>'organization')                         as org_key,
                    COUNT(*)                                                    as tickets,
                    SUM(CASE WHEN customer_id IS NOT NULL THEN 1 ELSE 0 END)   as vinculados
                ")
                ->groupByRaw("LOWER(solicitante->>'organization')")
                ->get()
                ->keyBy('org_key');

            $result = $movideskOrgs->map(function ($org) use ($customersByCnpj, $ticketCounts) {
                $cnpjNorm = preg_replace('/[^0-9]/', '', $org->cnpj ?? '');
                $counts   = $ticketCounts[strtolower($org->name)] ?? null;

                if ($cnpjNorm && isset($customersByCnpj[$cnpjNorm])) {
                    $match       = 'cnpj';
                    $minutorName = $customersByCnpj[$cnpjNorm]->name ?? $customersByCnpj[$cnpjNorm]->company_name;
                    $minutorCgc  = $customersByCnpj[$cnpjNorm]->cgc;
                } elseif ($org->customer) {
                    $match       = 'nome';
                    $minutorName = $org->customer->name ?? $org->customer->company_name;
                    $minutorCgc  = $org->customer->cgc;
                } else {
                    $byName      = Customer::where('name', $org->name)->orWhere('company_name', $org->name)->first();
                    $match       = $byName ? 'nome' : 'nao';
                    $minutorName = $byName?->name ?? $byName?->company_name;
                    $minutorCgc  = $byName?->cgc;
                }

                return [
                    'org'           => $org->name,
                    'cnpj_movidesk' => $cnpjNorm ?: null,
                    'is_active'     => $org->is_active,
                    'tickets'       => (int) ($counts->tickets ?? 0),
                    'vinculados'    => (int) ($counts->vinculados ?? 0),
                    'match'         => $match,
                    'minutor_name'  => $minutorName,
                    'minutor_cgc'   => $minutorCgc,
                ];
            })->sortByDesc('tickets')->values();

            return response()->json(['rows' => $result, 'source' => 'organizations']);
        }

        // Fallback: lê direto dos tickets (antes do primeiro sync-orgs)
        $rows = $this->tickets()->whereNotNull('solicitante')
            ->selectRaw("
                solicitante->>'organization'                                    as org,
                MAX(solicitante->>'cpf_cnpj')                                   as cnpj_movidesk,
                COUNT(*)                                                        as tickets,
                SUM(CASE WHEN customer_id IS NOT NULL THEN 1 ELSE 0 END)       as vinculados
            ")
            ->groupByRaw("solicitante->>'organization'")
            ->orderByDesc('tickets')
            ->get();

        $result = $rows->map(function ($row) use ($customersByCnpj) {
            $cnpjNorm = preg_replace('/[^0-9]/', '', $row->cnpj_movidesk ?? '');

            if ($cnpjNorm && isset($customersByCnpj[$cnpjNorm])) {
                $match       = 'cnpj';
                $minutorName = $customersByCnpj[$cnpjNorm]->name ?? $customersByCnpj[$cnpjNorm]->company_name;
                $minutorCgc  = $customersByCnpj[$cnpjNorm]->cgc;
            } else {
                $byName      = Customer::where('name', $row->org)->orWhere('company_name', $row->org)->first();
                $match       = $byName ? 'nome' : 'nao';
                $minutorName = $byName?->name ?? $byName?->company_name;
                $minutorCgc  = $byName?->cgc;
            }

            return [
                'org'           => $row->org,
                'cnpj_movidesk' => $cnpjNorm ?: null,
                'tickets'       => (int) $row->tickets,
                'vinculados'    => (int) $row->vinculados,
                'match'         => $match,
                'minutor_name'  => $minutorName,
                'minutor_cgc'   => $minutorCgc,
            ];
        });

        return response()->json(['rows' => $result, 'source' => 'tickets']);
    }

    public function debugResponsaveis(): JsonResponse
    {
        $this->authorize();

        // Contagem de tickets por owner_email + data do último ticket
        $ticketCounts = $this->tickets()->whereNotNull('owner_email')
            ->selectRaw("
                LOWER(owner_email)                                              as email_key,
                COUNT(*)                                                        as tickets,
                SUM(CASE WHEN user_id IS NOT NULL THEN 1 ELSE 0 END)           as vinculados,
                MAX(owner_team)                                                 as team,
                MAX(created_date)                                               as last_ticket_at
            ")
            ->groupBy('email_key')
            ->get()
            ->keyBy('email_key');

        $usersByEmail = \App\Models\User::whereNotNull('email')
            ->get()
            ->keyBy(fn($u) => strtolower(trim($u->email)));

        $cutoff90 = now()->subDays(90);

        $result = $this->tickets()->whereNotNull('owner_email')
            ->selectRaw("
                LOWER(owner_email)                          as email_key,
                MAX(owner_email)                            as owner_email,
                MAX(responsavel->>'name')                   as owner_name,
                COUNT(*)                                    as tickets,
                SUM(CASE WHEN user_id IS NOT NULL THEN 1 ELSE 0 END) as vinculados,
                MAX(owner_team)                             as team,
                MAX(created_date)                           as last_ticket_at
            ")
            ->groupBy('email_key')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->get()
            ->map(function ($row) use ($usersByEmail, $cutoff90) {
                $minutorUser   = $usersByEmail[$row->email_key] ?? null;
                $lastTicket    = $row->last_ticket_at ? \Carbon\Carbon::parse($row->last_ticket_at) : null;
                $activeRecent  = $lastTicket && $lastTicket->gte($cutoff90);
                return [
                    'owner_email'    => $row->owner_email,
                    'owner_name'     => $row->owner_name,
                    'team'           => $row->team,
                    'last_ticket_at' => $lastTicket?->toDateString(),
                    'is_active'      => $activeRecent,
                    'tickets'        => (int) $row->tickets,
                    'vinculados'     => (int) $row->vinculados,
                    'match'          => $minutorUser ? 'encontrado' : 'nao',
                    'minutor_name'   => $minutorUser?->name,
                    'minutor_id'     => $minutorUser?->id,
                ];
            })->values();

        return response()->json(['rows' => $result]);
    }

    public function contextStats(Request $request): JsonResponse
    {
        $this->authorize();
        [$from, $to] = $this->dateRange($request);

        $split = fn($v) => $v ? array_filter(array_map('trim', explode(',', $v))) : [];

        $responsavelFilter = $split($request->query('responsavel'));
        $clienteFilter     = $split($request->query('cliente'));

        // Aplica os filtros de contexto
        $base = fn() => $this->tickets()
            ->when($responsavelFilter, fn($q) => $q->whereIn(DB::raw('LOWER(owner_email)'), array_map('strtolower', $responsavelFilter)))
            ->when($clienteFilter, fn($q) => $q->where(function ($q2) use ($clienteFilter) {
                foreach ($clienteFilter as $c) {
                    $q2->orWhereRaw("solicitante->>'organization' ILIKE ?", ["%{$c}%"]);
                }
            }));

        // Tickets abertos agora
        $ticketsOpen = $this->applyOpen($base())->count();

        // SLA violado agora (abertos com sla_solution_date no passado, não resolvidos/fechados)
        $slaBreached = $this->applyOpen($base())
            ->whereNull('resolved_in')->whereNull('closed_in')
            ->whereNotNull('sla_solution_date')
            ->where('sla_solution_date', '<', now())
            ->count();

        // SLA em risco (abertos com sla_solution_date nas próximas 4h, não resolvidos/fechados)
        $slaAtRisk = $this->applyOpen($base())
            ->whereNull('resolved_in')->whereNull('closed_in')
            ->whereNotNull('sla_solution_date')
            ->whereBetween('sla_solution_date', [now(), now()->addHours(4)])
            ->count();

        // Resolvidos no período
        $ticketsResolved = $base()->whereNotNull('resolved_in')
            ->whereBetween('resolved_in', [$from, $to])
            ->count();

        // Taxa SLA (resolvidos no prazo)
        $slaTotalPeriod = $base()->whereNotNull('resolved_in')
            ->whereBetween('resolved_in', [$from, $to])
            ->whereNotNull('sla_solution_date')
            ->count();
        $slaOkPeriod = $base()->whereNotNull('resolved_in')
            ->whereBetween('resolved_in', [$from, $to])
            ->whereColumn('resolved_in', '<=', 'sla_solution_date')
            ->count();
        $slaRate = $slaTotalPeriod > 0 ? round(($slaOkPeriod / $slaTotalPeriod) * 100, 1) : null;

        // Tempo médio de resolução (minutos)
        $avgSolution = $base()->whereNotNull('resolved_in')
            ->whereBetween('resolved_in', [$from, $to])
            ->whereNotNull('sla_solution_time')
            ->avg('sla_solution_time');

        // Ticket mais antigo em aberto (dias) — fix: parse antes de diffInDays
        $oldestDays = null;
        $oldest = $this->applyOpen($base())
            ->whereNotNull('created_date')
            ->orderBy('created_date')
            ->value('created_date');
        if ($oldest) {
            $oldestDays = (int) \Carbon\Carbon::parse($oldest)->diffInDays(now());
        }

        // Tickets abertos há mais de 4h
        $over4h = $this->applyOpen($base())
            ->where('created_date', '<', now()->subHours(4))
            ->count();

        // Por consultor: abertos, em atendimento, SLA violado
        $byConsultant = $this->applyOpen($base())
            ->whereNotNull('owner_email')
            ->selectRaw("MAX(responsavel->>'name') as owner_name")
            ->selectRaw("LOWER(owner_email) as owner_email")
            ->selectRaw("COUNT(*) as total_open")
            ->selectRaw("SUM(CASE WHEN base_status = 'InAttendance' THEN 1 ELSE 0 END) as in_attendance")
            ->selectRaw("SUM(CASE WHEN sla_solution_date IS NOT NULL AND sla_solution_date < NOW() AND resolved_in IS NULL AND closed_in IS NULL THEN 1 ELSE 0 END) as sla_breached_count")
            ->groupByRaw("LOWER(owner_email)")
            ->orderByDesc('total_open')
            ->get()
            ->map(fn($r) => [
                'name'          => $r->owner_name ?? $r->owner_email,
                'email'         => $r->owner_email,
                'total_open'    => (int) $r->total_open,
                'in_attendance' => (int) $r->in_attendance,
                'sla_breached'  => (int) $r->sla_breached_count,
                'sla_ok_pct'    => $r->total_open > 0
                    ? round((1 - $r->sla_breached_count / $r->total_open) * 100)
                    : 100,
            ]);

        // Por cliente: agrupa por organização do solicitante
        $byClient = $this->applyOpen($base())
            ->whereNotNull('solicitante')
            ->whereRaw("solicitante->>'organization' IS NOT NULL AND solicitante->>'organization' != ''")
            ->selectRaw("solicitante->>'organization' as org")
            ->selectRaw("COUNT(*) as total_open")
            ->selectRaw("SUM(CASE WHEN base_status = 'InAttendance' THEN 1 ELSE 0 END) as in_attendance")
            ->selectRaw("SUM(CASE WHEN sla_solution_date IS NOT NULL AND sla_solution_date < NOW() AND resolved_in IS NULL AND closed_in IS NULL THEN 1 ELSE 0 END) as sla_breached_count")
            ->groupByRaw("solicitante->>'organization'")
            ->orderByDesc('total_open')
            ->limit(15)
            ->get()
            ->map(fn($r) => [
                'name'          => $r->org,
                'total_open'    => (int) $r->total_open,
                'in_attendance' => (int) $r->in_attendance,
                'sla_breached'  => (int) $r->sla_breached_count,
            ]);

        // Horas apontadas no Minutor (só faz sentido quando filtra por responsável)
        $hoursWorked = null;
        if ($responsavelFilter) {
            $userIds = \App\Models\User::whereIn(DB::raw('LOWER(email)'), array_map('strtolower', $responsavelFilter))
                ->pluck('id');
            if ($userIds->isNotEmpty()) {
                $minutes = DB::table('timesheets')
                    ->join('projects', 'projects.id', '=', 'timesheets.project_id')
                    ->join('service_types', 'service_types.id', '=', 'projects.service_type_id')
                    ->where(function ($q) {
                        $q->where('service_types.code', 'sustentacao')
                          ->orWhere('service_types.name', 'ilike', '%sustenta%');
                    })
                    ->whereBetween('timesheets.date', [$from->toDateString(), $to->toDateString()])
                    ->whereIn('timesheets.status', ['approved', 'pending'])
                    ->whereIn('timesheets.user_id', $userIds)
                    ->sum('timesheets.effort_minutes');
                $hoursWorked = (int) $minutes;
            }
        }

        // Produtividade: tickets resolvidos / hora trabalhada
        $productivity = ($hoursWorked && $hoursWorked > 0)
            ? round($ticketsResolved / ($hoursWorked / 60), 2)
            : null;

        return response()->json([
            'tickets_open'       => $ticketsOpen,
            'tickets_resolved'   => $ticketsResolved,
            'sla_breached'       => $slaBreached,
            'sla_at_risk'        => $slaAtRisk,
            'sla_rate'           => $slaRate,
            'avg_solution_min'   => $avgSolution ? round($avgSolution) : null,
            'oldest_open_days'   => $oldestDays,
            'over_4h'            => $over4h,
            'hours_worked_min'   => $hoursWorked,
            'productivity'       => $productivity,
            'by_consultant'      => $byConsultant,
            'by_client'          => $byClient,
            'period'             => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'filter'             => [
                'responsavel' => $responsavelFilter,
                'cliente'     => $clienteFilter,
            ],
        ]);
    }

    public function filterOptions(): JsonResponse
    {
        $this->authorize();

        $statuses = $this->applyOpen($this->tickets())
            ->whereNotNull('status')
            ->where('status', '!=', '')
            ->select('status', 'base_status')
            ->distinct()
            ->orderBy('base_status')
            ->orderBy('status')
            ->get()
            ->map(fn($r) => [
                'value'       => $r->status,
                'label'       => $r->status,
                'base_status' => $r->base_status,
            ]);

        return response()->json(['statuses' => $statuses]);
    }

    public function executive(Request $request): JsonResponse
    {
        $this->authorize();
        [$from, $to] = $this->dateRange($request);

        // 1. % Críticos
        $total    = $this->tickets()->whereBetween('created_date', [$from, $to])->count();
        $critical = $this->tickets()->whereBetween('created_date', [$from, $to])
            ->whereIn('urgencia', ['Alta', 'Urgente'])->count();
        $pctCritical = $total > 0 ? round($critical / $total * 100, 1) : 0;

        // 2. % Parados
        $allActive  = $this->tickets()->whereIn('base_status', ['New', 'InAttendance', 'Stopped'])->count();
        $stopped    = $this->tickets()->where('base_status', 'Stopped')->count();
        $pctStopped = $allActive > 0 ? round($stopped / $allActive * 100, 1) : 0;

        // 3. SLA Violado % (resolvidos fora do prazo)
        $slaTotal = $this->tickets()->whereBetween('resolved_in', [$from, $to])
            ->whereNotNull('sla_solution_date')->count();
        $slaOk = $this->tickets()->whereBetween('resolved_in', [$from, $to])
            ->whereNotNull('sla_solution_date')
            ->whereColumn('resolved_in', '<=', 'sla_solution_date')->count();
        $slaBreachPct = $slaTotal > 0 ? round((1 - $slaOk / $slaTotal) * 100, 1) : null;

        // 4. Tempo médio resolução (horas)
        $avgMin = $this->tickets()->whereBetween('resolved_in', [$from, $to])
            ->whereNotNull('sla_solution_time')->avg('sla_solution_time');
        $avgResolutionHours = $avgMin ? round($avgMin / 60, 1) : null;

        // 5. Lead Time médio abertura→fechamento (horas)
        $leadTimeHours = DB::table('movidesk_tickets')
            ->whereRaw("owner_email NOT ILIKE '%@promax.bardahl.com.br'")
            ->whereNotNull('created_date')
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('closed_in', [$from, $to])
                  ->orWhereBetween('resolved_in', [$from, $to]);
            })
            ->selectRaw("AVG(EXTRACT(EPOCH FROM (COALESCE(closed_in, resolved_in) - created_date)) / 3600) as avg_h")
            ->value('avg_h');

        // 6. Aging buckets — tickets abertos agora
        $aging = $this->applyOpen($this->tickets())
            ->whereNotNull('created_date')
            ->selectRaw("
                SUM(CASE WHEN NOW() - created_date < INTERVAL '4 days'  THEN 1 ELSE 0 END) as d0_3,
                SUM(CASE WHEN NOW() - created_date >= INTERVAL '4 days'  AND NOW() - created_date < INTERVAL '8 days'  THEN 1 ELSE 0 END) as d4_7,
                SUM(CASE WHEN NOW() - created_date >= INTERVAL '8 days'  AND NOW() - created_date < INTERVAL '16 days' THEN 1 ELSE 0 END) as d8_15,
                SUM(CASE WHEN NOW() - created_date >= INTERVAL '16 days' THEN 1 ELSE 0 END) as d15_plus
            ")->first();

        // 7. Financeiro por cliente
        $clientHours = DB::table('timesheets')
            ->join('projects',      'projects.id',      '=', 'timesheets.project_id')
            ->join('service_types', 'service_types.id', '=', 'projects.service_type_id')
            ->join('customers',     'customers.id',     '=', 'projects.customer_id')
            ->where(fn($q) => $q->where('service_types.code', 'sustentacao')
                                 ->orWhere('service_types.name', 'ilike', '%sustenta%'))
            ->whereBetween('timesheets.date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('timesheets.status', ['approved', 'pending'])
            ->select(
                'customers.name as customer_name',
                DB::raw('SUM(timesheets.effort_minutes) as used_min'),
                DB::raw('SUM(projects.sold_hours * 60) as sold_min')
            )
            ->groupBy('customers.name', 'customers.id')
            ->orderByDesc(DB::raw('SUM(timesheets.effort_minutes)'))
            ->limit(12)
            ->get();

        $totalUsedMin = $clientHours->sum('used_min');
        $totalSoldMin = $clientHours->sum('sold_min');
        $pctConsumed  = $totalSoldMin > 0 ? round($totalUsedMin / $totalSoldMin * 100, 1) : null;

        $resolvedCount  = $this->tickets()->whereBetween('resolved_in', [$from, $to])->count();
        $hoursPerTicket = ($resolvedCount > 0 && $totalUsedMin > 0)
            ? round(($totalUsedMin / 60) / $resolvedCount, 2) : null;

        $topClients = $clientHours->map(fn($r) => [
            'name'   => $r->customer_name,
            'used_h' => round($r->used_min / 60, 1),
            'sold_h' => round($r->sold_min / 60, 1),
            'pct'    => $r->sold_min > 0 ? round($r->used_min / $r->sold_min * 100, 1) : null,
        ])->values();

        // 8. Distribuição
        $byCategory = $this->tickets()->whereBetween('created_date', [$from, $to])
            ->selectRaw('categoria as label, COUNT(*) as count')
            ->whereNotNull('categoria')
            ->groupBy('categoria')
            ->orderByDesc('count')
            ->get();

        $byUrgency = $this->tickets()->whereBetween('created_date', [$from, $to])
            ->selectRaw('urgencia as label, COUNT(*) as count')
            ->whereNotNull('urgencia')
            ->groupBy('urgencia')
            ->orderByRaw("CASE urgencia WHEN 'Urgente' THEN 1 WHEN 'Alta' THEN 2 WHEN 'Normal' THEN 3 WHEN 'Baixa' THEN 4 ELSE 5 END")
            ->get();

        return response()->json([
            'pct_critical'          => $pctCritical,
            'pct_stopped'           => $pctStopped,
            'sla_breach_pct'        => $slaBreachPct,
            'avg_resolution_hours'  => $avgResolutionHours,
            'lead_time_avg_hours'   => $leadTimeHours ? round($leadTimeHours, 1) : null,
            'aging'                 => [
                'd0_3'    => (int) ($aging->d0_3    ?? 0),
                'd4_7'    => (int) ($aging->d4_7    ?? 0),
                'd8_15'   => (int) ($aging->d8_15   ?? 0),
                'd15_plus' => (int) ($aging->d15_plus ?? 0),
            ],
            'pct_hours_consumed'    => $pctConsumed,
            'total_sold_h'          => round($totalSoldMin / 60, 1),
            'total_used_h'          => round($totalUsedMin / 60, 1),
            'hours_per_ticket'      => $hoursPerTicket,
            'top_clients'           => $topClients,
            'by_category'           => $byCategory,
            'by_urgency'            => $byUrgency,
            'period'                => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ]);
    }

    public function syncOrgs(): JsonResponse
    {
        $this->authorize();

        // Responde imediatamente e roda o comando APÓS fechar a conexão HTTP
        // (evita o timeout de 30s do proxy do Render)
        $debugFile = storage_path('logs/sync-orgs-debug.txt');
        file_put_contents($debugFile, date('H:i:s') . " syncOrgs chamado\n", FILE_APPEND | LOCK_EX);

        app()->terminating(function () use ($debugFile) {
            file_put_contents($debugFile, date('H:i:s') . " terminating INICIO\n", FILE_APPEND | LOCK_EX);
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            ignore_user_abort(true);
            set_time_limit(300);

            try {
                $exitCode = Artisan::call('movidesk:sync-orgs');
                $out = trim(Artisan::output());
                file_put_contents($debugFile, date('H:i:s') . " done exit=$exitCode\n$out\n", FILE_APPEND | LOCK_EX);
                fwrite(\STDERR, "[sync-orgs] concluído exit=$exitCode" . PHP_EOL);
            } catch (\Throwable $e) {
                file_put_contents($debugFile, date('H:i:s') . " ERRO: " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
                fwrite(\STDERR, '[sync-orgs] ERRO: ' . $e->getMessage() . PHP_EOL);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Integração iniciada. Aguarde ~1 minuto e recarregue a aba.',
        ]);
    }

    public function syncAgents(): JsonResponse
    {
        $this->authorize();

        $php     = PHP_BINARY;
        $artisan = base_path('artisan');
        $log     = storage_path('logs/sync-agents.log');

        exec("{$php} {$artisan} movidesk:sync-agents >> {$log} 2>&1 &");

        return response()->json([
            'success' => true,
            'message' => 'Sincronização iniciada em background. Aguarde ~3 minutos e recarregue a aba.',
        ]);
    }
}
