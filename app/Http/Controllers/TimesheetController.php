<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Timesheet;
use App\Models\Project;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Http\Traits\ResponseHelpers;
use App\Exports\TimesheetsExport;
use Maatwebsite\Excel\Facades\Excel;

/**
 * @OA\Tag(
 *     name="Timesheets",
 *     description="API endpoints para gerenciamento de apontamentos de horas"
 * )
 */

/**
 * @OA\Schema(
 *     schema="Timesheet",
 *     type="object",
 *     title="Timesheet",
 *     description="Modelo de apontamento de horas",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="user_id", type="integer", example=1),
 *     @OA\Property(property="customer_id", type="integer", example=1),
 *     @OA\Property(property="project_id", type="integer", example=1),
 *     @OA\Property(property="date", type="string", format="date", example="2024-01-15"),
 *     @OA\Property(property="start_time", type="string", format="time", example="09:00"),
 *     @OA\Property(property="end_time", type="string", format="time", example="17:00"),
 *     @OA\Property(property="effort_minutes", type="integer", example=480),
     *     @OA\Property(property="effort_hours", type="string", example="8:00"),
     *     @OA\Property(property="observation", type="string", example="Desenvolvimento de funcionalidade X"),
     *     @OA\Property(property="ticket", type="string", example="TICKET-123"),
     *     @OA\Property(property="origin", type="string", nullable=true, example="webhook", description="Origem do apontamento: 'webhook' para automação do Movidesk, null para criação manual"),
     *     @OA\Property(property="status", type="string", enum={"pending", "approved", "rejected"}, example="pending"),
 *     @OA\Property(property="status_display", type="string", example="Pendente"),
 *     @OA\Property(property="rejection_reason", type="string", nullable=true, example="Horário incompatível"),
 *     @OA\Property(property="reviewed_by", type="integer", nullable=true, example=2),
 *     @OA\Property(property="reviewed_at", type="string", format="datetime", nullable=true, example="2024-01-16T10:30:00Z"),
 *     @OA\Property(property="created_at", type="string", format="datetime", example="2024-01-15T09:00:00Z"),
 *     @OA\Property(property="updated_at", type="string", format="datetime", example="2024-01-15T09:00:00Z"),
 *     @OA\Property(
 *         property="user",
 *         type="object",
 *         @OA\Property(property="id", type="integer", example=1),
 *         @OA\Property(property="name", type="string", example="João Silva"),
 *         @OA\Property(property="email", type="string", example="joao@empresa.com")
 *     ),
 *     @OA\Property(
 *         property="customer",
 *         type="object",
 *         @OA\Property(property="id", type="integer", example=1),
 *         @OA\Property(property="name", type="string", example="Cliente ABC")
 *     ),
 *     @OA\Property(
 *         property="project",
 *         type="object",
 *         @OA\Property(property="id", type="integer", example=1),
 *         @OA\Property(property="name", type="string", example="Projeto XYZ"),
 *         @OA\Property(property="code", type="string", example="PROJ-001")
 *     )
 * )
 */
class TimesheetController extends Controller
{
    use ResponseHelpers;
    use \App\Http\Traits\ListCacheable;

    /**
     * @OA\Get(
     *     path="/api/v1/timesheets",
     *     summary="Listar apontamentos de horas",
     *     tags={"Timesheets"},
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="project_id",
     *         in="query",
     *         description="Filtrar por projeto",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="customer_id",
     *         in="query",
     *         description="Filtrar por cliente",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="user_id",
     *         in="query",
     *         description="Filtrar por usuário",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         description="Filtrar por status",
     *         required=false,
     *         @OA\Schema(type="string", enum={"pending", "approved", "rejected"})
     *     ),
     *     @OA\Parameter(
     *         name="start_date",
     *         in="query",
     *         description="Data de início do período",
     *         required=false,
     *         @OA\Schema(type="string", format="date")
     *     ),
     *     @OA\Parameter(
     *         name="end_date",
     *         in="query",
     *         description="Data de fim do período",
     *         required=false,
     *         @OA\Schema(type="string", format="date")
     *     ),
     *     @OA\Parameter(
     *         name="ticket",
     *         in="query",
     *         description="Filtrar por número do ticket",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="service_type_id",
     *         in="query",
     *         description="Filtrar por tipo de serviço",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lista de timesheets",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Timesheet"))
     *         )
     *     )
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();

        // Paginação PO-UI
        $perPage = min($request->get('pageSize', 15), 100);
        $page = (int) $request->get('page', 1);

        // Eager-load com colunas específicas — evita trazer rows inteiras de relações
        // (cada user/customer/project tem muitos campos não usados pela listagem).
        // Reduz payload e tempo de serialização significativamente.
        $query = Timesheet::with([
                'user:id,name,email,type,partner_id,customer_id',
                'customer:id,name',
                'project' => fn($q) => $q->select('id', 'name', 'service_type_id', 'contract_type_id', 'customer_id', 'is_investimento_comercial', 'parent_project_id', 'status', 'kanban_coordinator_override_id'),
                'project.contractType:id,name',
                'project.customer:id,name,executive_id',
                'project.customer.executive:id,name',
                'project.serviceType:id,code,name',
                'project.coordinators:id,name',
                'project.kanbanOverrideCoordinator:id,name',
                'realProject:id,name',
                'reviewedBy:id,name',
            ])
            ->select('timesheets.*', 'movidesk_tickets.titulo as ticket_subject', 'movidesk_tickets.solicitante as ticket_solicitante')
            ->leftJoin('movidesk_tickets', 'movidesk_tickets.ticket_id', '=', 'timesheets.ticket');

        // Portal de Sustentação: restringe aos projetos elegíveis (respeita override de coord).
        if ($request->get('scope') === 'sustentacao') {
            $scopedIds = app(\App\Services\SustentacaoScopeService::class)->projectIds();
            if (empty($scopedIds)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('timesheets.project_id', $scopedIds);
            }
        }

        // Controle de visibilidade por perfil
        if ($user->isCliente()) {
            // Cliente vê apenas apontamentos do seu cliente (empresa)
            // Somente status pendente e aprovado — conflitante, rejeitado e ajustes são omitidos
            $query->where('timesheets.customer_id', $user->customer_id)
                  ->whereIn('timesheets.status', ['pending', 'approved']);
        } elseif ($user->type === 'parceiro_admin' && $request->boolean('team_view') && $user->partner_id) {
            $partnerUserIds = \App\Models\User::where('partner_id', $user->partner_id)->pluck('id');
            $query->whereIn('timesheets.user_id', $partnerUserIds);
        } elseif (!$user->isAdmin() && !$user->hasAccess('hours.view_all')) {
            $query->forUser($user->id);
        } elseif ($user->isCoordenador()) {
            // Sustentação: apenas projetos do tipo sustentacao ou cloud (escopo do perfil).
            // Projetos (default): vê TODOS os apontamentos — filtro client-side via
            // chip "Meus projetos / Todos" no FE manda coordinator_id[]=user.id.
            if ($user->coordinator_type === 'sustentacao') {
                $query->whereHas('project.serviceType', fn($q) => $q->whereIn('code', ['sustentacao', 'cloud']));
            }
        }

        // Consultor (e demais perfis não-admin/não-coordenador) não vê apontamentos somente faturáveis
        if (!$user->isAdmin() && !$user->isCoordenador()) {
            $query->where('timesheets.is_billable_only', false);
        }

        // Percentuais de acréscimo: consultor e cliente nunca veem client_extra_pct
        $hideClientPct = !$user->isAdmin() && !$user->isCoordenador() && $user->type !== 'administrativo';

        // Filtros PO-UI
        if ($request->filled('project_id')) {
            $projectInput = $request->input('project_id');
            $projectIds   = is_array($projectInput) ? $projectInput : [$projectInput];
            $query->whereIn('timesheets.project_id', $projectIds);
        }

        $customerIds = array_values(array_filter((array) $request->input('customer_id', [])));
        if (!empty($customerIds)) {
            $query->whereIn('timesheets.customer_id', $customerIds);
        }

        // Triagem dos "padrões Movidesk": filtra timesheets cujo user_id, customer_id
        // OU project_id coincida com algum dos IDs configurados em system_settings
        // (movidesk_default_*). Usado pela rotina Triagem da Sustentação pra revisar
        // apontamentos atribuídos ao fallback.
        if ($request->boolean('triagem_padrao')) {
            $defaultUserId     = \App\Models\SystemSetting::get('movidesk_default_user_id');
            $defaultCustomerId = \App\Models\SystemSetting::get('movidesk_default_customer_id');
            $defaultProjectId  = \App\Models\SystemSetting::get('movidesk_default_project_id');

            // triagem_field opcional: user | customer | project — filtra só uma
            // dimensão. Sem o parâmetro, faz OR entre os 3 (default Todos).
            $field = $request->input('triagem_field');

            $query->where(function ($q) use ($defaultUserId, $defaultCustomerId, $defaultProjectId, $field) {
                $applyUser     = (!$field || $field === 'user')     && $defaultUserId;
                $applyCustomer = (!$field || $field === 'customer') && $defaultCustomerId;
                $applyProject  = (!$field || $field === 'project')  && $defaultProjectId;

                if ($applyUser)     $q->orWhere('timesheets.user_id',     (int) $defaultUserId);
                if ($applyCustomer) $q->orWhere('timesheets.customer_id', (int) $defaultCustomerId);
                if ($applyProject)  $q->orWhere('timesheets.project_id',  (int) $defaultProjectId);
                if (!$applyUser && !$applyCustomer && !$applyProject) {
                    $q->whereRaw('1 = 0');
                }
            });
        }

        $executiveIds = array_values(array_filter((array) $request->input('executive_id', [])));
        if (!empty($executiveIds)) {
            $query->whereHas('customer', function ($q) use ($executiveIds) {
                $q->whereIn('executive_id', $executiveIds);
            });
        }

        $canFilterByUser = $user->isAdmin() || $user->hasAccess('hours.view_all')
            || ($user->type === 'parceiro_admin' && $request->boolean('team_view'));
        $userIds = array_values(array_filter((array) $request->input('user_id', [])));
        if (!empty($userIds) && $canFilterByUser) {
            $query->whereIn('timesheets.user_id', $userIds);
        }

        if ($request->filled('status')) {
            $statusInput = $request->input('status');
            $statuses    = is_array($statusInput) ? $statusInput : [$statusInput];
            $query->whereIn('timesheets.status', $statuses);
        }

        if ($request->filled('ticket')) {
            $query->where('ticket', 'ilike', "%{$request->ticket}%");
        }

        if ($request->filled('requester')) {
            $query->whereRaw("movidesk_tickets.solicitante::jsonb->>'name' = ?", [$request->requester]);
        }

        if ($request->filled('ticket_service')) {
            $query->where('movidesk_tickets.servico', $request->ticket_service);
        }

        $originVals = array_values(array_filter((array) $request->input('origin', [])));
        if (!empty($originVals)) {
            $query->where(function ($q) use ($originVals) {
                foreach ($originVals as $originVal) {
                    if ($originVal === 'web') {
                        $q->orWhere(function ($inner) {
                            $inner->whereNull('timesheets.origin')->orWhere('timesheets.origin', 'web');
                        });
                    } else {
                        $q->orWhere('timesheets.origin', $originVal);
                    }
                }
            });
        }

        $serviceTypeIds = array_values(array_filter((array) $request->input('service_type_id', [])));
        if (!empty($serviceTypeIds)) {
            $query->whereHas('project', function ($q) use ($serviceTypeIds) {
                $q->whereIn('service_type_id', $serviceTypeIds);
            });
        }

        $contractTypeIds = array_values(array_filter((array) $request->input('contract_type_id', [])));
        if (!empty($contractTypeIds)) {
            $query->whereHas('project', function ($q) use ($contractTypeIds) {
                $q->whereIn('contract_type_id', $contractTypeIds);
            });
        }

        // Filtro por coordenador — espelha a regra de exibição do coordinator_label:
        //   override do coord > (se sustentação) coordenador de sustentação > coordenadores do projeto.
        $coordinatorIds = array_values(array_filter((array) $request->input('coordinator_id', [])));
        if (!empty($coordinatorIds)) {
            $sustSelected = \App\Models\User::whereIn('id', $coordinatorIds)
                ->where('coordinator_type', 'sustentacao')->exists();
            $query->where(function ($q) use ($coordinatorIds, $sustSelected) {
                // (A) override do coordenador é um dos selecionados
                $q->whereHas('project', fn($pq) => $pq->whereIn('kanban_coordinator_override_id', $coordinatorIds));
                // (C) sem override, projeto NÃO-sustentação, coordenador do projeto é um dos selecionados
                $q->orWhere(function ($q2) use ($coordinatorIds) {
                    $q2->whereHas('project', fn($pq) => $pq->whereNull('kanban_coordinator_override_id')
                            ->whereDoesntHave('serviceType', fn($sq) => $sq->where('code', 'sustentacao')))
                       ->whereHas('project.coordinators', fn($cq) => $cq->whereIn('users.id', $coordinatorIds));
                });
                // (B) sem override, serviço de sustentação e um coord de sustentação foi selecionado
                if ($sustSelected) {
                    $q->orWhereHas('project', fn($pq) => $pq->whereNull('kanban_coordinator_override_id')
                        ->whereHas('serviceType', fn($sq) => $sq->where('code', 'sustentacao')));
                }
            });
        }

        // date_field: 'date' (data do apontamento, padrão) ou 'created_at' (data de inclusão).
        // O relatório de cliente usa 'created_at' pra reconciliar com o que foi LANÇADO no
        // período de cobrança (independe de quando o consultor diz que trabalhou).
        $useCreatedAt = $request->input('date_field') === 'created_at';

        if ($request->filled('start_date')) {
            $endDate = $request->filled('end_date') ? $request->end_date : now()->toDateString();
            if ($useCreatedAt) {
                $query->whereRaw('timesheets.created_at::date BETWEEN ? AND ?', [$request->start_date, $endDate]);
            } else {
                $query->inPeriod($request->start_date, $endDate);
            }
        } elseif ($request->filled('end_date')) {
            if ($useCreatedAt) {
                $query->whereRaw('timesheets.created_at::date <= ?', [$request->end_date]);
            } else {
                $query->where('timesheets.date', '<=', $request->end_date);
            }
        }

        // Competência: trava a DATA DO SERVIÇO no intervalo, independente do date_field.
        // Permite filtrar por digitação num range amplo sem trazer serviço de outros meses.
        if ($request->filled('competencia_start')) {
            $query->where('timesheets.date', '>=', $request->competencia_start);
        }
        if ($request->filled('competencia_end')) {
            $query->where('timesheets.date', '<=', $request->competencia_end);
        }

        if ($request->boolean('only_investimento_comercial')) {
            $query->whereHas('project', fn ($q) => $q->where('is_investimento_comercial', true));
        }

        // Filtro por categoria de serviço (chips coloridos no frontend):
        //   sustentacao  → service_type.code='sustentacao' E NÃO IC
        //   projeto      → service_type.code='projeto'    E NÃO IC
        //   bizify       → service_type.code='bizify'     E NÃO IC
        //   investimento → is_investimento_comercial=true (engloba os 3 auto)
        $categoriaServico = $request->get('categoria_servico');
        if (in_array($categoriaServico, ['sustentacao', 'projeto', 'bizify', 'investimento'], true)) {
            $query->whereHas('project', function ($q) use ($categoriaServico) {
                if ($categoriaServico === 'investimento') {
                    $q->where('is_investimento_comercial', true);
                } else {
                    $q->where('is_investimento_comercial', false)
                      ->whereHas('serviceType', fn($sq) => $sq->where('code', $categoriaServico));
                }
            });
        }

        // Busca geral
        if ($request->filled('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('observation', 'ilike', "%{$search}%")
                  ->orWhere('ticket', 'ilike', "%{$search}%")
                  ->orWhereHas('project', function ($pq) use ($search) {
                      $pq->where('name', 'ilike', "%{$search}%");
                  })
                  ->orWhereHas('customer', function ($cq) use ($search) {
                      $cq->where('name', 'ilike', "%{$search}%");
                  });
            });
        }

        // Ordenação PO-UI (com suporte a relacionamentos)
        if ($request->has('order')) {
            $orderFields = explode(',', $request->get('order'));

            // Flags de join para evitar joins duplicados
            $needsUserJoin = false;
            $needsProjectJoin = false;
            $needsCustomerJoin = false;

            $relationOrders = [];
            $scalarOrders = [];

            foreach ($orderFields as $field) {
                $direction = 'asc';

                if (str_starts_with($field, '-')) {
                    $direction = 'desc';
                    $field = substr($field, 1);
                }

                // Mapear campos calculados/virtuais para colunas reais
                if ($field === 'effort_hours') {
                    $field = 'effort_minutes';
                }

                // Ordenação por relacionamentos, ex: user.name, project.name, customer.name
                if (str_contains($field, '.')) {
                    [$relation, $column] = explode('.', $field, 2);

                    switch ($relation) {
                        case 'user':
                            $needsUserJoin = true;
                            $relationOrders[] = [
                                'table' => 'users',
                                'column' => $column,
                                'direction' => $direction,
                            ];
                            break;

                        case 'project':
                            $needsProjectJoin = true;
                            $relationOrders[] = [
                                'table' => 'projects',
                                'column' => $column,
                                'direction' => $direction,
                            ];
                            break;

                        case 'customer':
                            $needsCustomerJoin = true;
                            $relationOrders[] = [
                                'table' => 'customers',
                                'column' => $column,
                                'direction' => $direction,
                            ];
                            break;

                        default:
                            // Relacionamento não suportado: ignorar silenciosamente
                            break;
                    }
                } else {
                    // Campos da própria tabela timesheets
                    $scalarOrders[] = [
                        'column' => $field,
                        'direction' => $direction,
                    ];
                }
            }

            // Aplicar joins necessários para ordenação por relacionamentos
            if ($needsUserJoin) {
                $query->leftJoin('users', 'users.id', '=', 'timesheets.user_id');
            }

            if ($needsProjectJoin) {
                $query->leftJoin('projects', 'projects.id', '=', 'timesheets.project_id');
            }

            if ($needsCustomerJoin) {
                $query->leftJoin('customers', 'customers.id', '=', 'timesheets.customer_id');
            }

            // Ordenação por campos da própria tabela (prefixo para evitar ambiguidade com JOINs)
            foreach ($scalarOrders as $order) {
                $query->orderBy('timesheets.' . $order['column'], $order['direction']);
            }

            // Ordenação por campos de relacionamentos
            foreach ($relationOrders as $order) {
                $query->orderBy($order['table'] . '.' . $order['column'], $order['direction']);
            }
        } else {
            $query->orderBy('timesheets.date', 'desc')->orderBy('timesheets.start_time', 'desc');
        }

        // Resposta PO-UI (com cache Redis de 60s por usuário + filtros)
        try {
            $result = $this->cachedList($request, 'timesheets', function () use ($query, $perPage, $page, $hideClientPct) {
                $totalEffortMinutes = (int) (clone $query)->sum('effort_minutes');
                $totalHours   = intdiv($totalEffortMinutes, 60);
                $totalMinutes = $totalEffortMinutes % 60;

                $totalBillableOnlyMinutes = (int) (clone $query)
                    ->where('timesheets.is_billable_only', true)
                    ->sum('effort_minutes');

                // Agrega no SQL (SUM(effort_minutes * consultant_extra_pct/100)) em vez
                // de carregar todas as rows em PHP só pra somar — economiza tráfego e CPU.
                $totalConsultantExtraMinutes = (int) round(
                    (clone $query)
                        ->where('timesheets.is_billable_only', false)
                        ->whereNotNull('consultant_extra_pct')
                        ->sum(\Illuminate\Support\Facades\DB::raw('timesheets.effort_minutes * timesheets.consultant_extra_pct / 100'))
                );

                $timesheets = $query->paginate($perPage, ['*'], 'page', $page);

                // Total acumulado (lifetime) por ticket, no mesmo cliente — para coluna
                // "Consumo do Ticket" no frontend. Só tickets com padrão 5 dígitos.
                $ticketsByCustomer = [];
                foreach ($timesheets->items() as $ts) {
                    $t = $ts->ticket;
                    if (!$t || !$ts->customer_id) continue;
                    if (!preg_match('/^\d{5}$/', (string) $t)) continue;
                    $ticketsByCustomer[$ts->customer_id][$t] = true;
                }
                $ticketTotalsMap = [];
                if (!empty($ticketsByCustomer)) {
                    // DB::table não aplica global scope de soft-delete; sem o
                    // whereNull('deleted_at') o "Hist. de Hs Ticket" continua
                    // somando apontamentos já soft-deletados (ex.: limpeza dos <5min).
                    $totalsQ = \Illuminate\Support\Facades\DB::table('timesheets')
                        ->whereNull('deleted_at')
                        ->whereNotIn('status', [
                            Timesheet::STATUS_REJECTED,
                            Timesheet::STATUS_CONFLICTED,
                            Timesheet::STATUS_ADJUSTMENT_REQUESTED,
                            Timesheet::STATUS_INTERNAL,
                        ])
                        ->whereRaw("ticket ~ '^[0-9]{5}$'");
                    $totalsQ->where(function ($q) use ($ticketsByCustomer) {
                        foreach ($ticketsByCustomer as $cid => $tickets) {
                            $q->orWhere(function ($qq) use ($cid, $tickets) {
                                $qq->where('customer_id', $cid)->whereIn('ticket', array_keys($tickets));
                            });
                        }
                    });
                    foreach ($totalsQ->groupBy('customer_id', 'ticket')->selectRaw('customer_id, ticket, SUM(effort_minutes) AS total')->get() as $r) {
                        $ticketTotalsMap[$r->customer_id . ':' . $r->ticket] = (int) $r->total;
                    }

                    // Soma o saldo inicial cadastrado (ticket_initial_balances).
                    $initQ = \Illuminate\Support\Facades\DB::table('ticket_initial_balances')
                        ->whereNull('deleted_at')
                        ->where(function ($q) use ($ticketsByCustomer) {
                            foreach ($ticketsByCustomer as $cid => $tickets) {
                                $q->orWhere(function ($qq) use ($cid, $tickets) {
                                    $qq->where('customer_id', $cid)->whereIn('ticket', array_keys($tickets));
                                });
                            }
                        });
                    foreach ($initQ->select('customer_id', 'ticket', 'initial_minutes')->get() as $r) {
                        $key = $r->customer_id . ':' . $r->ticket;
                        $ticketTotalsMap[$key] = ($ticketTotalsMap[$key] ?? 0) + (int) $r->initial_minutes;
                    }
                }

                // Batch das queries de conflicting_timesheets: 1 SELECT pra todos os
                // conflitos do lote em vez de N queries (1 por conflito).
                $conflictedItems = array_values(array_filter(
                    $timesheets->items(),
                    fn($t) => $t->status === Timesheet::STATUS_CONFLICTED && $t->start_time && $t->end_time && $t->date
                ));
                $conflictCandidatesByKey = [];
                if (!empty($conflictedItems)) {
                    try {
                        $userIds     = array_unique(array_map(fn($t) => $t->user_id, $conflictedItems));
                        $customerIdsCnf = array_unique(array_map(fn($t) => $t->customer_id, $conflictedItems));
                        $datesCnf    = array_unique(array_map(
                            fn($t) => $t->date instanceof \Carbon\Carbon ? $t->date->format('Y-m-d') : (string) $t->date,
                            $conflictedItems
                        ));
                        $candidates = Timesheet::with(['customer', 'project.customer'])
                            ->whereIn('user_id', $userIds)
                            ->whereIn('customer_id', $customerIdsCnf)
                            ->whereIn('date', $datesCnf)
                            ->whereNotIn('status', [Timesheet::STATUS_REJECTED])
                            ->whereNotNull('start_time')
                            ->whereNotNull('end_time')
                            ->get();
                        foreach ($candidates as $c) {
                            $cDate = $c->date instanceof \Carbon\Carbon ? $c->date->format('Y-m-d') : (string) $c->date;
                            $key   = $c->user_id . ':' . $c->customer_id . ':' . $cDate;
                            $conflictCandidatesByKey[$key][] = $c;
                        }
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning('conflicting_timesheets batch lookup failed', [
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Coordenador de sustentação (regra: serviço de sustentação => coordenador é
                // sempre o(s) coordinator_type='sustentacao', ex.: Anderson Arantes).
                $sustentacaoCoordNames = \App\Models\User::where('coordinator_type', 'sustentacao')
                    ->where('enabled', true)->pluck('name')->all();

                $items = collect($timesheets->items())->map(function ($ts) use ($hideClientPct, $ticketTotalsMap, $conflictCandidatesByKey, $sustentacaoCoordNames) {
                    if ($hideClientPct) {
                        $ts->makeHidden(['client_extra_pct']);
                    }
                    $arr = $ts->toArray();
                    if (isset($arr['ticket_solicitante']) && is_string($arr['ticket_solicitante'])) {
                        $arr['ticket_solicitante'] = json_decode($arr['ticket_solicitante'], true);
                    }
                    // Para timesheets em conflito, inclui os apontamentos sobrepostos
                    // (filtragem por overlap feita in-memory sobre o lote pré-carregado).
                    $arr['conflicting_timesheets'] = [];
                    if ($ts->status === Timesheet::STATUS_CONFLICTED && $ts->start_time && $ts->end_time && $ts->date) {
                        $dateStr  = $ts->date instanceof \Carbon\Carbon ? $ts->date->format('Y-m-d') : (string) $ts->date;
                        $endStr   = $ts->end_time instanceof \Carbon\Carbon   ? $ts->end_time->format('H:i:s')   : substr((string) $ts->end_time,   0, 8);
                        $startStr = $ts->start_time instanceof \Carbon\Carbon ? $ts->start_time->format('H:i:s') : substr((string) $ts->start_time, 0, 8);
                        $key      = $ts->user_id . ':' . $ts->customer_id . ':' . $dateStr;
                        $bucket   = $conflictCandidatesByKey[$key] ?? [];
                        $arr['conflicting_timesheets'] = collect($bucket)
                            ->filter(function ($c) use ($ts, $startStr, $endStr) {
                                if ($c->id === $ts->id) return false;
                                $cStart = $c->start_time instanceof \Carbon\Carbon ? $c->start_time->format('H:i:s') : substr((string) $c->start_time, 0, 8);
                                $cEnd   = $c->end_time   instanceof \Carbon\Carbon ? $c->end_time->format('H:i:s')   : substr((string) $c->end_time,   0, 8);
                                // overlap clássico: a.start < b.end && b.start < a.end
                                return $cStart < $endStr && $startStr < $cEnd;
                            })
                            ->map(fn($c) => [
                                'id'            => $c->id,
                                'date'          => $c->date instanceof \Carbon\Carbon ? $c->date->format('Y-m-d') : (string) $c->date,
                                'start_time'    => $c->start_time instanceof \Carbon\Carbon ? $c->start_time->format('H:i') : null,
                                'end_time'      => $c->end_time instanceof \Carbon\Carbon   ? $c->end_time->format('H:i')   : null,
                                'effort_hours'  => $c->effort_hours,
                                'customer_name' => $c->customer?->name ?? $c->project?->customer?->name,
                                'project_name'  => $c->project?->name,
                                'origin'        => $c->origin,
                            ])
                            ->values()
                            ->all();
                    }
                    // Total acumulado do ticket no mesmo cliente (lifetime)
                    $tk = (string) ($ts->ticket ?? '');
                    if ($tk !== '' && preg_match('/^\d{5}$/', $tk) && $ts->customer_id) {
                        $arr['ticket_total_minutes'] = $ticketTotalsMap[$ts->customer_id . ':' . $tk] ?? null;
                    } else {
                        $arr['ticket_total_minutes'] = null;
                    }
                    // Coordenador exibido: override do coord > (se sustentação) coordenador de
                    // sustentação (Anderson Arantes) > coordenadores do projeto.
                    $proj = $ts->project;
                    $coordLabel = null;
                    if ($proj) {
                        $isSustentacao = optional($proj->serviceType)->code === 'sustentacao';
                        if ($proj->kanbanOverrideCoordinator) {
                            $coordLabel = $proj->kanbanOverrideCoordinator->name;
                        } elseif ($isSustentacao && !empty($sustentacaoCoordNames)) {
                            $coordLabel = implode(', ', $sustentacaoCoordNames);
                        } elseif ($proj->coordinators && $proj->coordinators->count()) {
                            $coordLabel = $proj->coordinators->pluck('name')->implode(', ');
                        }
                    }
                    $arr['coordinator_label'] = $coordLabel;
                    return $arr;
                })->all();

                return [
                    'hasNext'                       => $timesheets->hasMorePages(),
                    'items'                         => $items,
                    'totalEffortMinutes'             => $totalEffortMinutes,
                    'totalEffortHours'               => sprintf('%d:%02d', $totalHours, $totalMinutes),
                    'totalBillableOnlyMinutes'       => $totalBillableOnlyMinutes,
                    'totalConsultantExtraMinutes'    => $totalConsultantExtraMinutes,
                ];
            });

            return response()->json($result);
        } catch (\Throwable $e) {
            Log::error('TimesheetController@index error', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error'   => 'Erro ao listar apontamentos',
                'details' => $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine(),
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/timesheets",
     *     summary="Criar novo apontamento de horas",
     *     tags={"Timesheets"},
     *     security={{"sanctum": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"project_id", "date", "start_time", "end_time"},
     *             @OA\Property(property="project_id", type="integer", example=1),
     *             @OA\Property(property="user_id", type="integer", example=2, description="ID do usuário (apenas para administradores)"),
     *             @OA\Property(property="date", type="string", format="date", example="2024-01-15"),
     *             @OA\Property(property="start_time", type="string", format="time", example="09:00"),
     *             @OA\Property(property="end_time", type="string", format="time", example="17:00"),
     *             @OA\Property(property="observation", type="string", example="Desenvolvimento de funcionalidade X"),
     *             @OA\Property(property="ticket", type="string", example="TICKET-123")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Timesheet criado com sucesso",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", ref="#/components/schemas/Timesheet"),
     *             @OA\Property(property="message", type="string", example="Apontamento criado com sucesso!")
     *         )
     *     )
     * )
     */
    public function store(Request $request): JsonResponse
    {
        $user = Auth::user();

        // Clientes não podem criar apontamentos
        if ($user->isCliente()) {
            return response()->json([
                'code' => 'PERMISSION_DENIED',
                'type' => 'error',
                'message' => 'Acesso negado',
                'detailMessage' => 'Usuários com perfil Cliente não podem criar apontamentos de horas.',
            ], 403);
        }

        // Definir regras de validação base
        // start_time e end_time são opcionais quando total_hours é informado diretamente
        $hasTotalHours = !empty($request->total_hours);
        $rules = [
            'project_id' => 'required|exists:projects,id',
            'real_project_id' => 'nullable|integer|exists:projects,id',
            'date' => 'required|date|before_or_equal:today',
            'start_time' => $hasTotalHours ? 'nullable|date_format:H:i' : 'required|date_format:H:i',
            'end_time'   => $hasTotalHours ? 'nullable|date_format:H:i' : 'required|date_format:H:i|after:start_time',
            // Aceita HH:MM ("4:30"), decimal com ponto ou vírgula ("4.5", "4,5", "4.25"),
            // ou inteiro puro ("4"). Parseado por Timesheet::parseTotalHoursToMinutes.
            // Sintaxe ARRAY obrigatória: a regex tem `|` (alternation), que o Laravel
            // confunde com separador de regras quando rules vêm como string —
            // `preg_match(): No ending delimiter '/' found` em prod (29/05/2026).
            'total_hours' => ['nullable', 'string', 'regex:/^(\d+:[0-5][0-9]|\d+(?:[.,]\d{1,2})?)$/'],
            'observation' => 'nullable|string|max:5000',
            'ticket' => 'nullable',
            'attachment' => 'nullable|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:5120',
        ];

        // Se é administrador ou coordenador, pode especificar user_id
        if ($user->isAdmin() || $user->isCoordenador() || $user->hasAccess('admin.full_access')) {
            $rules['user_id'] = 'nullable|exists:users,id';
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'code' => 'VALIDATION_FAILED',
                'type' => 'error',
                'message' => 'Dados de validação inválidos',
                'detailMessage' => 'Um ou mais campos contêm valores inválidos',
                'details' => $validator->errors()->all()
            ], 422);
        }

        // Determinar o usuário do apontamento
        $timesheetUserId = Auth::id(); // Padrão: usuário logado

        // Se é admin ou coordenador e especificou user_id, usar o usuário especificado
        if (($user->isAdmin() || $user->isCoordenador() || $user->hasAccess('admin.full_access')) && $request->filled('user_id')) {
            $timesheetUserId = $request->user_id;

            // Verificar se o usuário especificado existe e está ativo
            $targetUser = User::find($timesheetUserId);
            if (!$targetUser || !$targetUser->enabled) {
                return response()->json([
                    'code' => 'INVALID_USER',
                    'type' => 'error',
                    'message' => 'Usuário inválido',
                    'detailMessage' => 'O usuário especificado não existe ou está inativo'
                ], 422);
            }
        }

        // Verificar se o projeto existe e está aberto para lançamentos (paused ainda permite)
        $project = Project::find($request->project_id);
        if (!$project || !$project->isOpen()) {
            return response()->json([
                'code' => 'INACTIVE_PROJECT',
                'type' => 'error',
                'message' => 'Projeto inativo ou não encontrado',
                'detailMessage' => 'Não é possível apontar horas em projetos cancelados ou encerrados'
            ], 422);
        }

        // Bloquear consultores e parceiros sem permissão de apontar em projetos de sustentação
        if ($user->isConsultor() || $user->isParceiroAdmin()) {
            $isSustentacao = $project->service_type_id &&
                \App\Models\ServiceType::where('id', $project->service_type_id)
                    ->whereIn('code', ['sustentacao', 'cloud'])
                    ->exists();

            if ($isSustentacao) {
                // Liberado globalmente OU liberado especificamente neste projeto
                $allowedInProject = $project->consultants()
                    ->where('users.id', $user->id)
                    ->wherePivot('allow_manual_timesheet', true)
                    ->exists();

                if (!$user->can_timesheet_sustentacao && !$allowedInProject) {
                    return response()->json([
                        'code' => 'SUSTENTACAO_TIMESHEET_NOT_ALLOWED',
                        'type' => 'error',
                        'message' => 'Apontamento em sustentação não permitido',
                        'detailMessage' => 'Você não tem permissão para criar apontamentos manuais em projetos de sustentação.',
                    ], 403);
                }
            }
        }

        // Bloquear apontamento em competência administrativamente fechada
        $serviceDate = \Carbon\Carbon::parse($request->date);
        $yearMonth = $serviceDate->format('Y-m');
        $fechamentoAdm = \App\Models\FechamentoAdministrativo::where('year_month', $yearMonth)->first();
        if ($fechamentoAdm?->isClosed()) {
            $projectHasOpenPeriod = \App\Models\ProjectOpenPeriod::where('project_id', $project->id)
                ->where('year_month', $yearMonth)
                ->whereNull('closed_at')
                ->exists();

            if (!$projectHasOpenPeriod) {
                return response()->json([
                    'code'          => 'PERIOD_CLOSED',
                    'type'          => 'error',
                    'message'       => 'Competência fechada',
                    'detailMessage' => "A competência {$serviceDate->translatedFormat('F Y')} está fechada e não aceita novos apontamentos.",
                ], 422);
            }
        }

        // Verificar prazo limite para lançamento retroativo de horas
        if (!$project->isWithinTimesheetDeadline($serviceDate)) {
            $limitDays = $project->getTimesheetRetroactiveLimitDays();
            $deadlineDate = $project->getTimesheetDeadline($serviceDate);

            $source = $project->timesheet_retroactive_limit_days !== null
                ? 'configurado para este projeto'
                : 'configuração global do sistema';

            return response()->json([
                'code' => 'TIMESHEET_DEADLINE_EXPIRED',
                'type' => 'error',
                'message' => 'Prazo expirado para lançamento de horas',
                'detailMessage' => sprintf(
                    'O prazo limite para lançamento de horas deste serviço expirou. ' .
                    'Serviço realizado em %s, prazo limite era %s (limite de %d %s %s).',
                    $serviceDate->format('d/m/Y'),
                    $deadlineDate->format('d/m/Y'),
                    $limitDays,
                    $limitDays === 1 ? 'dia' : 'dias',
                    $source
                ),
                'details' => [
                    'service_date' => $serviceDate->format('Y-m-d'),
                    'deadline_date' => $deadlineDate->format('Y-m-d'),
                    'limit_days' => $limitDays,
                    'source' => $source
                ]
            ], 422);
        }

        // Verificações de duplicado/sobreposição só fazem sentido quando há horários definidos
        if ($request->start_time && $request->end_time) {
        // Verificar se já existe um apontamento duplicado para o mesmo usuário, data, projeto e horário
        $existingTimesheet = Timesheet::where('user_id', $timesheetUserId)
            ->where('project_id', $request->project_id)
            ->where('date', $request->date)
            ->where('start_time', $request->start_time)
            ->where('end_time', $request->end_time)
            ->where('status', '!=', Timesheet::STATUS_REJECTED) // Ignorar apontamentos rejeitados
            ->first();

        if ($existingTimesheet) {
            $userName = $timesheetUserId === Auth::id() ? 'você' : User::find($timesheetUserId)->name;
            return response()->json([
                'code' => 'DUPLICATE_TIMESHEET',
                'type' => 'error',
                'message' => 'Apontamento duplicado',
                'detailMessage' => 'Já existe um apontamento para ' . $userName . ' neste projeto na mesma data e horário.',
                'details' => [
                    'Já existe um apontamento para o projeto "' . $project->name . '" no dia ' .
                    \Carbon\Carbon::parse($request->date)->format('d/m/Y') .
                    ' das ' . $request->start_time . ' às ' . $request->end_time
                ]
            ], 422);
        }

        // Verificar sobreposição de horários no mesmo dia para o mesmo usuário e mesmo cliente
        // (clientes distintos podem ter apontamentos sobrepostos no mesmo horário)
        $overlappingIds = Timesheet::where('user_id', $timesheetUserId)
            ->where('customer_id', $project->customer_id)
            ->where('date', $request->date)
            ->whereNotIn('status', [Timesheet::STATUS_REJECTED])
            ->whereNotNull('start_time')
            ->whereNotNull('end_time')
            ->where('start_time', '<', $request->end_time)
            ->where('end_time', '>', $request->start_time)
            ->pluck('id');

        $hasConflict = $overlappingIds->isNotEmpty();
        } // fim do bloco: verificações de duplicado/sobreposição com horários

        $hasConflict = $hasConflict ?? false;

        // Bloquear criação manual que criaria sobreposição de horários
        if ($hasConflict && isset($overlappingIds) && $overlappingIds->isNotEmpty()) {
            $conflictingTs = Timesheet::with(['customer', 'project.customer'])
                ->whereIn('id', $overlappingIds)
                ->first();
            return response()->json([
                'code'          => 'TIMESHEET_CONFLICT',
                'type'          => 'error',
                'message'       => 'Conflito de horário',
                'detailMessage' => 'O horário informado conflita com outro apontamento do mesmo cliente já registrado para este dia.',
                'conflicting_timesheet' => [
                    'date'          => $conflictingTs->date instanceof \Carbon\Carbon
                                        ? $conflictingTs->date->format('Y-m-d')
                                        : (string) $conflictingTs->date,
                    'start_time'    => $conflictingTs->start_time instanceof \Carbon\Carbon
                                        ? $conflictingTs->start_time->format('H:i')
                                        : null,
                    'end_time'      => $conflictingTs->end_time instanceof \Carbon\Carbon
                                        ? $conflictingTs->end_time->format('H:i')
                                        : null,
                    'customer_name' => $conflictingTs->customer?->name
                                        ?? $conflictingTs->project?->customer?->name,
                    'project_name'  => $conflictingTs->project?->name,
                ],
            ], 422);
        }

        DB::beginTransaction();
        try {
            $validatedData = $validator->validated();

            // Processar total_hours se fornecido (HH:MM | decimal | inteiro).
            $effortMinutes = null;
            if (!empty($validatedData['total_hours'])) {
                $effortMinutes = Timesheet::parseTotalHoursToMinutes($validatedData['total_hours']);
                if ($effortMinutes !== null) {
                    $validatedData['effort_minutes'] = $effortMinutes;
                }
                // Remover total_hours dos dados validados pois não é um campo do modelo
                unset($validatedData['total_hours']);
            } else {
                // Se não forneceu total_hours, calcular a partir de start_time e end_time
                // O modelo Timesheet calcula automaticamente no boot, mas precisamos calcular aqui para validação
                if (isset($validatedData['start_time']) && isset($validatedData['end_time'])) {
                    $startTime = \Carbon\Carbon::parse($validatedData['start_time']);
                    $endTime = \Carbon\Carbon::parse($validatedData['end_time']);

                    // Se o horário final for menor que o inicial, assumir que passou da meia-noite
                    if ($endTime->lt($startTime)) {
                        $endTime->addDay();
                    }

                    $effortMinutes = $startTime->diffInMinutes($endTime);
                }
            }

            // Calcular horas decimais para validação
            $hoursToAdd = $effortMinutes ? round($effortMinutes / 60, 2) : 0;

            // Verificar se o projeto é do tipo de contrato On Demand
            $project->loadMissing('contractType');
            $isOnDemandContract = $project->contractType && $project->contractType->code === 'on_demand';
            // Projetos de Investimento Interno não têm horas contratadas — pulam validação de saldo
            $isInvestimentoInterno = (bool) $project->is_investimento_comercial;

            // Apontamento de investimento exige o "Projeto Real" (referência + define o coordenador
            // que aprova). O consumo continua no projeto de investimento.
            $realProjectId = $isInvestimentoInterno ? ((int) $request->input('real_project_id') ?: null) : null;
            if ($isInvestimentoInterno && !$realProjectId) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Projeto Real é obrigatório para apontamento de investimento.',
                    'errors'  => ['real_project_id' => ['Selecione o projeto real.']],
                ], 422);
            }
            // Investimento SUPORTE: o projeto real precisa ser de SUSTENTAÇÃO.
            if ($realProjectId && $project->categoria_interna === 'Suporte') {
                $realProj = Project::with('serviceType')->find($realProjectId);
                if (optional(optional($realProj)->serviceType)->code !== 'sustentacao') {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'Investimento Suporte: o Projeto Real deve ser de Sustentação.',
                        'errors'  => ['real_project_id' => ['Selecione um projeto de Sustentação.']],
                    ], 422);
                }
            }

            Log::info('Criando apontamento - Antes de validar saldo', [
                'project_id' => $project->id,
                'user_id' => $timesheetUserId,
                'effort_minutes' => $effortMinutes,
                'hours_to_add' => $hoursToAdd,
                'date' => $validatedData['date'] ?? null,
                'start_time' => $validatedData['start_time'] ?? null,
                'end_time' => $validatedData['end_time'] ?? null,
                'contract_type_code' => $project->contractType->code ?? null,
                'is_on_demand_contract' => $isOnDemandContract,
            ]);

            // Para projetos On Demand ou Investimento Interno, não bloquear por saldo
            if (!$isOnDemandContract && !$isInvestimentoInterno) {
                // Validar saldo de horas antes de salvar
                $balanceValidation = $this->validateHoursBalance($project, $timesheetUserId, $hoursToAdd, null);
                if ($balanceValidation) {
                    Log::warning('Criação de apontamento bloqueada - Saldo insuficiente', [
                        'project_id' => $project->id,
                        'user_id' => $timesheetUserId,
                        'hours_to_add' => $hoursToAdd,
                    ]);
                    DB::rollBack();
                    return $balanceValidation;
                }

                Log::info('Validação de saldo aprovada - Prosseguindo com criação do apontamento', [
                    'project_id' => $project->id,
                    'user_id' => $timesheetUserId,
                    'hours_to_add' => $hoursToAdd,
                ]);
            } else {
                Log::info('Projeto On Demand - Pulando validação de saldo de horas para criação de apontamento', [
                    'project_id' => $project->id,
                    'user_id' => $timesheetUserId,
                    'hours_to_add' => $hoursToAdd,
                ]);
            }

            $timesheet = new Timesheet($validatedData);
            $timesheet->user_id = $timesheetUserId;
            $timesheet->customer_id = $project->customer_id;
            $timesheet->real_project_id = $realProjectId; // só preenchido em investimento
            $timesheet->status = $hasConflict ? Timesheet::STATUS_CONFLICTED : Timesheet::STATUS_PENDING;
            $timesheet->origin = 'web'; // Origem: criação manual via webapp
            $timesheet->is_billable_only = $user->isAdmin()
                && $timesheetUserId !== Auth::id()
                && $request->boolean('is_billable_only', false);

            // Percentuais de acréscimo — somente admin/coordenador, pós-criação
            // (em criação os campos não são enviados pelo frontend, mas protege caso venham)
            if ($user->isAdmin() || $user->isCoordenador()) {
                $timesheet->client_extra_pct = $request->filled('client_extra_pct')
                    ? (float) $request->input('client_extra_pct') : null;
                // Fat. Admin nunca tem % consultor
                $timesheet->consultant_extra_pct = (!$timesheet->is_billable_only && $request->filled('consultant_extra_pct'))
                    ? (float) $request->input('consultant_extra_pct') : null;
            }

            $newAttachmentInfo = null;
            if ($request->hasFile('attachment')) {
                $file = $request->file('attachment');
                $filename = time() . '_' . $file->getClientOriginalName();
                $path = $file->storeAs('timesheets/' . date('Y/m'), $filename, 'public');
                $newAttachmentInfo = ['path' => $path, 'original' => $file->getClientOriginalName(), 'mime' => $file->getMimeType()];
            }

            $timesheet->save();

            // FASE 11.7 — Attachment persiste 100% na camada Attachment.
            if ($newAttachmentInfo !== null) {
                $this->registerTimesheetAttachment($timesheet, $newAttachmentInfo);
            }

            // Auto-transição: se o projeto está "Aguardando início", marcar como "Iniciado"
            if ($project->status === Project::STATUS_AWAITING_START) {
                $project->status = Project::STATUS_STARTED;
                $project->save();
                $this->invalidateListCache('projects');
            }

            // Marcar apontamentos sobrepostos como conflitados
            $newlyConflictedIds = collect();
            if ($hasConflict && isset($overlappingIds) && $overlappingIds->isNotEmpty()) {
                $newlyConflictedIds = Timesheet::whereIn('id', $overlappingIds)
                    ->whereNotIn('status', [Timesheet::STATUS_REJECTED, Timesheet::STATUS_CONFLICTED, Timesheet::STATUS_LATE])
                    ->pluck('id');
                Timesheet::whereIn('id', $newlyConflictedIds)
                    ->update(['status' => Timesheet::STATUS_CONFLICTED]);
            }

            DB::commit();

            // Notifica donos dos apontamentos marcados como conflito
            if ($hasConflict) {
                $timesheet->notifyOwnerOfStatus('CONFLITO');
            }
            foreach ($newlyConflictedIds as $cid) {
                $conflicted = Timesheet::find($cid);
                if ($conflicted) $conflicted->notifyOwnerOfStatus('CONFLITO');
            }

            $timesheet->load(['user', 'customer', 'project']);

            Log::info('Apontamento criado com sucesso', [
                'timesheet_id' => $timesheet->id,
                'project_id' => $timesheet->project_id,
                'user_id' => $timesheet->user_id,
                'effort_minutes' => $timesheet->effort_minutes,
                'effort_hours' => round($timesheet->effort_minutes / 60, 2),
                'date' => $timesheet->date,
                'status' => $timesheet->status,
            ]);

            $message = $timesheetUserId === Auth::id()
                ? 'Apontamento criado com sucesso!'
                : 'Apontamento criado com sucesso para ' . $timesheet->user->name . '!';

            $this->invalidateListCache('timesheets');

            return response()->json([
                'success' => true,
                'data' => $timesheet,
                'message' => $message
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erro ao criar apontamento: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/v1/timesheets/{id}",
     *     summary="Visualizar apontamento específico",
     *     tags={"Timesheets"},
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID do timesheet",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Dados do timesheet",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", ref="#/components/schemas/Timesheet")
     *         )
     *     )
     * )
     */
    public function show(int $id): JsonResponse
    {
        $step = 'init';
        try {
            $user = Auth::user();

            // Carrega o timesheet base sem relações
            $step = 'find';
            $timesheet = Timesheet::find($id);

            if (!$timesheet) {
                return response()->json(['success' => false, 'message' => 'Apontamento não encontrado'], 404);
            }

            // Carrega cada relação individualmente para isolar erros
            $step = 'load:user';
            $timesheet->load('user');

            $step = 'load:customer';
            $timesheet->load('customer');

            $step = 'load:project';
            $timesheet->load(['project' => fn($q) => $q->withTrashed()]);

            $step = 'load:reviewedBy';
            $timesheet->load('reviewedBy');

            $step = 'load:reversals';
            $timesheet->load(['reversals' => fn($q) => $q->with(['reversedBy', 'originalApprover'])]);

            // Busca o título do ticket Movidesk separadamente
            $step = 'movidesk';
            if ($timesheet->ticket) {
                $movideskTicket = \DB::table('movidesk_tickets')
                    ->where('ticket_id', $timesheet->ticket)
                    ->value('titulo');
                $timesheet->setAttribute('ticket_subject', $movideskTicket);
            }

            // Verificar se pode visualizar este timesheet
            $step = 'auth_check';
            $isOwner      = $timesheet->user_id === $user->id;
            $isClienteOk  = $user->isCliente() && $user->customer_id && $timesheet->customer_id === $user->customer_id;
            // Parceiro Admin/Gestor: vê apontamentos de qualquer membro da sua parceria
            $isTeamTimesheet = $user->isParceiroAdmin() && $user->partner_id &&
                \App\Models\User::where('id', $timesheet->user_id)
                    ->where('partner_id', $user->partner_id)
                    ->exists();
            $canView      = $user->isAdmin() || $user->isCoordenador() || $user->hasAccess('hours.view_all') || $isOwner || $isClienteOk || $isTeamTimesheet;
            if ($user && !$canView) {
                return response()->json(['success' => false, 'message' => 'Acesso negado'], 403);
            }

            $step = 'serialize';
            if ($user->isConsultor() || $user->isCliente()) {
                $timesheet->makeHidden(['client_extra_pct']);
            }
            return response()->json(['success' => true, 'data' => $timesheet]);

        } catch (\Exception $e) {
            \Log::error("TimesheetController@show error at step [{$step}]: " . $e->getMessage(), [
                'id' => $id,
                'step' => $step,
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => "Erro no passo [{$step}]: [" . class_basename($e) . '] ' . $e->getMessage() . ' — ' . basename($e->getFile()) . ':' . $e->getLine()
            ], 500);
        }
    }

    /**
     * @OA\Put(
     *     path="/api/v1/timesheets/{id}",
     *     summary="Atualizar apontamento",
     *     tags={"Timesheets"},
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID do timesheet",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="user_id", type="integer", example=1, description="ID do usuário (apenas para administradores)"),
     *             @OA\Property(property="date", type="string", format="date", example="2024-01-15"),
     *             @OA\Property(property="start_time", type="string", format="time", example="09:00"),
     *             @OA\Property(property="end_time", type="string", format="time", example="17:00"),
     *             @OA\Property(property="observation", type="string", example="Desenvolvimento de funcionalidade X"),
     *             @OA\Property(property="ticket", type="string", example="TICKET-123")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Timesheet atualizado com sucesso",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", ref="#/components/schemas/Timesheet"),
     *             @OA\Property(property="message", type="string", example="Apontamento atualizado com sucesso!")
     *         )
     *     )
     * )
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = Auth::user();

        $timesheet = Timesheet::find($id);

        if (!$timesheet) {
            return response()->json([
                'success' => false,
                'message' => 'Apontamento não encontrado'
            ], 404);
        }

        // Verificar permissões
        $isOwnTimesheet  = $timesheet->user_id === $user->id;
        $isTeamTimesheet = $user->isParceiroAdmin() && $user->partner_id &&
            \App\Models\User::where('id', $timesheet->user_id)
                ->where('partner_id', $user->partner_id)
                ->exists();

        if (!$user->isAdmin() && !$user->hasAccess('hours.update_all') && !$isOwnTimesheet && !$isTeamTimesheet) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado'
            ], 403);
        }

        // Só pode editar se estiver pendente ou rejeitado
        if (!$timesheet->canBeEdited()) {
            return response()->json([
                'success' => false,
                'message' => 'Não é possível editar apontamentos já aprovados'
            ], 422);
        }

        $validationRules = [
            'user_id' => 'nullable|exists:users,id',
            'date' => 'sometimes|date|before_or_equal:today',
            'start_time' => 'sometimes|date_format:H:i',
            'end_time' => 'sometimes|date_format:H:i|after:start_time',
            // Aceita HH:MM ("4:30"), decimal com ponto ou vírgula ("4.5", "4,5", "4.25"),
            // ou inteiro puro ("4"). Parseado por Timesheet::parseTotalHoursToMinutes.
            // Sintaxe ARRAY obrigatória: a regex tem `|` (alternation), que o Laravel
            // confunde com separador de regras quando rules vêm como string —
            // `preg_match(): No ending delimiter '/' found` em prod (29/05/2026).
            'total_hours' => ['nullable', 'string', 'regex:/^(\d+:[0-5][0-9]|\d+(?:[.,]\d{1,2})?)$/'],
            'observation' => 'nullable|string|max:5000',
            'ticket' => 'nullable|string|max:100',
            'customer_id' => 'sometimes|exists:customers,id',
            'project_id' => 'sometimes|exists:projects,id',
        ];

        $validator = Validator::make($request->all(), $validationRules);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        // Processar user_id se fornecido (apenas para administradores)
        $validatedData = $validator->validated();
        if (isset($validatedData['user_id'])) {
            // Verificar se o usuário atual tem permissão para alterar o usuário do apontamento
            if (!($user->isAdmin() || $user->hasAccess('admin.full_access'))) {
                return response()->json([
                    'code' => 'PERMISSION_DENIED',
                    'type' => 'error',
                    'message' => 'Acesso negado',
                    'detailMessage' => 'Apenas administradores podem alterar o usuário do apontamento'
                ], 403);
            }

            // Verificar se o usuário especificado existe e está ativo
            $targetUser = User::find($validatedData['user_id']);
            if (!$targetUser || !$targetUser->enabled) {
                return response()->json([
                    'code' => 'INVALID_USER',
                    'type' => 'error',
                    'message' => 'Usuário inválido',
                    'detailMessage' => 'O usuário especificado não existe ou está inativo'
                ], 422);
            }
        }

        // Validar customer_id e project_id quando fornecidos
        if (isset($validatedData['project_id'])) {
            $project = Project::find($validatedData['project_id']);
            if (!$project || !$project->isOpen()) {
                return response()->json([
                    'code' => 'INACTIVE_PROJECT',
                    'type' => 'error',
                    'message' => 'Projeto inativo ou não encontrado',
                    'detailMessage' => 'Não é possível apontar horas em projetos cancelados ou encerrados'
                ], 422);
            }

            // Se customer_id também foi fornecido, validar que o projeto pertence ao cliente
            if (isset($validatedData['customer_id'])) {
                if ($project->customer_id != $validatedData['customer_id']) {
                    return response()->json([
                        'code' => 'PROJECT_CUSTOMER_MISMATCH',
                        'type' => 'error',
                        'message' => 'Projeto não pertence ao cliente',
                        'detailMessage' => 'O projeto selecionado não pertence ao cliente informado'
                    ], 422);
                }
            } else {
                // Se não forneceu customer_id mas forneceu project_id, usar o customer_id do projeto
                $validatedData['customer_id'] = $project->customer_id;
            }
        } elseif (isset($validatedData['customer_id'])) {
            // Se forneceu customer_id mas não project_id, validar que o cliente existe
            $customer = Customer::find($validatedData['customer_id']);
            if (!$customer || !$customer->active) {
                return response()->json([
                    'code' => 'INACTIVE_CUSTOMER',
                    'type' => 'error',
                    'message' => 'Cliente inativo ou não encontrado',
                    'detailMessage' => 'O cliente especificado não existe ou está inativo'
                ], 422);
            }
        }

        // Determinar qual projeto usar para validação de prazo
        $projectForValidation = null;
        if (isset($validatedData['project_id'])) {
            $projectForValidation = Project::find($validatedData['project_id']);
        } else {
            $projectForValidation = $timesheet->project;
        }

        // Bloquear consultores e parceiros sem permissão de apontar em projetos de sustentação
        if ($projectForValidation && ($user->isConsultor() || $user->isParceiroAdmin())) {
            $isSustentacao = $projectForValidation->service_type_id &&
                \App\Models\ServiceType::where('id', $projectForValidation->service_type_id)
                    ->whereIn('code', ['sustentacao', 'cloud'])
                    ->exists();

            if ($isSustentacao) {
                $allowedInProject = $projectForValidation->consultants()
                    ->where('users.id', $user->id)
                    ->wherePivot('allow_manual_timesheet', true)
                    ->exists();

                if (!$user->can_timesheet_sustentacao && !$allowedInProject) {
                    return response()->json([
                        'code' => 'SUSTENTACAO_TIMESHEET_NOT_ALLOWED',
                        'type' => 'error',
                        'message' => 'Apontamento em sustentação não permitido',
                        'detailMessage' => 'Você não tem permissão para editar apontamentos em projetos de sustentação.',
                    ], 403);
                }
            }
        }

        // Verificar prazo limite se a data foi alterada
        if (isset($validatedData['date']) && $projectForValidation) {
            $serviceDate = \Carbon\Carbon::parse($validatedData['date']);

            // Bloquear edição de apontamento em competência administrativamente fechada
            $yearMonth = $serviceDate->format('Y-m');
            $fechamentoAdm = \App\Models\FechamentoAdministrativo::where('year_month', $yearMonth)->first();
            if ($fechamentoAdm?->isClosed()) {
                $editProject = $projectForValidation;
                $projectHasOpenPeriod = \App\Models\ProjectOpenPeriod::where('project_id', $editProject->id)
                    ->where('year_month', $yearMonth)
                    ->whereNull('closed_at')
                    ->exists();

                if (!$projectHasOpenPeriod) {
                    return response()->json([
                        'code'          => 'PERIOD_CLOSED',
                        'type'          => 'error',
                        'message'       => 'Competência fechada',
                        'detailMessage' => "A competência {$serviceDate->translatedFormat('F Y')} está fechada e não aceita alterações.",
                    ], 422);
                }
            }

            if (!$projectForValidation->isWithinTimesheetDeadline($serviceDate)) {
                $limitDays = $projectForValidation->getTimesheetRetroactiveLimitDays();
                $deadlineDate = $projectForValidation->getTimesheetDeadline($serviceDate);

                $source = $projectForValidation->timesheet_retroactive_limit_days !== null
                    ? 'configurado para este projeto'
                    : 'configuração global do sistema';

                return response()->json([
                    'code' => 'TIMESHEET_DEADLINE_EXPIRED',
                    'type' => 'error',
                    'message' => 'Prazo expirado para lançamento de horas',
                    'detailMessage' => sprintf(
                        'O prazo limite para lançamento de horas deste serviço expirou. ' .
                        'Serviço realizado em %s, prazo limite era %s (limite de %d %s %s).',
                        $serviceDate->format('d/m/Y'),
                        $deadlineDate->format('d/m/Y'),
                        $limitDays,
                        $limitDays === 1 ? 'dia' : 'dias',
                        $source
                    ),
                    'details' => [
                        'service_date' => $serviceDate->format('Y-m-d'),
                        'deadline_date' => $deadlineDate->format('Y-m-d'),
                        'limit_days' => $limitDays,
                        'source' => $source
                    ]
                ], 422);
            }
        }

        DB::beginTransaction();
        try {
            // Determinar qual projeto usar para validação
            $projectForValidation = $projectForValidation ?? $timesheet->project;

            // Determinar qual usuário usar para validação
            $userIdForValidation = isset($validatedData['user_id']) ? $validatedData['user_id'] : $timesheet->user_id;

            // Processar total_hours se fornecido (HH:MM | decimal | inteiro).
            $newEffortMinutes = null;
            if (!empty($validatedData['total_hours'])) {
                $newEffortMinutes = Timesheet::parseTotalHoursToMinutes($validatedData['total_hours']);
                if ($newEffortMinutes !== null) {
                    $validatedData['effort_minutes'] = $newEffortMinutes;
                }
                // Remover total_hours dos dados validados pois não é um campo do modelo
                unset($validatedData['total_hours']);
            } elseif (isset($validatedData['start_time']) && isset($validatedData['end_time'])) {
                // Se não forneceu total_hours mas forneceu start_time e end_time, calcular
                $startTime = \Carbon\Carbon::parse($validatedData['start_time']);
                $endTime = \Carbon\Carbon::parse($validatedData['end_time']);

                // Se o horário final for menor que o inicial, assumir que passou da meia-noite
                if ($endTime->lt($startTime)) {
                    $endTime->addDay();
                }

                $newEffortMinutes = $startTime->diffInMinutes($endTime);
            } else {
                // Se não forneceu nem total_hours nem start_time/end_time, usar o valor atual
                $newEffortMinutes = $timesheet->effort_minutes;
            }

            // Calcular a diferença de horas (novas horas - horas atuais)
            $currentHours = round(($timesheet->effort_minutes ?? 0) / 60, 2);
            $newHours = round(($newEffortMinutes ?? 0) / 60, 2);
            $hoursDifference = $newHours - $currentHours;

            Log::info('Editando apontamento - Calculando diferença de horas', [
                'timesheet_id' => $timesheet->id,
                'current_project_id' => $timesheet->project_id,
                'current_user_id' => $timesheet->user_id,
                'current_hours' => $currentHours,
                'new_hours' => $newHours,
                'hours_difference' => $hoursDifference,
                'new_project_id' => $validatedData['project_id'] ?? null,
                'new_user_id' => $validatedData['user_id'] ?? null,
            ]);

            // Verificar se mudou de projeto ou usuário
            $projectChanged = isset($validatedData['project_id']) && $validatedData['project_id'] != $timesheet->project_id;
            $userChanged = isset($validatedData['user_id']) && $validatedData['user_id'] != $timesheet->user_id;

            Log::info('Editando apontamento - Verificando mudanças', [
                'timesheet_id' => $timesheet->id,
                'project_changed' => $projectChanged,
                'user_changed' => $userChanged,
                'old_project_id' => $timesheet->project_id,
                'new_project_id' => $validatedData['project_id'] ?? $timesheet->project_id,
                'old_user_id' => $timesheet->user_id,
                'new_user_id' => $userIdForValidation,
            ]);

            if ($projectChanged || $userChanged) {
                // Se mudou de projeto ou usuário, validar o novo projeto/usuário com as novas horas
                $targetProject = $projectChanged ? Project::find($validatedData['project_id']) : $projectForValidation;

                Log::info('Editando apontamento - Mudança detectada, validando novo projeto/usuário', [
                    'timesheet_id' => $timesheet->id,
                    'target_project_id' => $targetProject?->id,
                    'target_user_id' => $userIdForValidation,
                    'new_hours' => $newHours,
                    'project_changed' => $projectChanged,
                    'user_changed' => $userChanged,
                ]);

                if ($targetProject && !$targetProject->is_investimento_comercial) {
                    // Validar saldo no projeto alvo com as novas horas
                    // Não excluir o apontamento atual pois ele será movido/atribuído a outro projeto/usuário
                    // Projetos de Investimento Interno não têm horas contratadas — pulam validação
                    $balanceValidation = $this->validateHoursBalance(
                        $targetProject,
                        $userIdForValidation,
                        $newHours,
                        null
                    );

                    if ($balanceValidation) {
                        Log::warning('Edição de apontamento bloqueada - Saldo insuficiente no novo projeto/usuário', [
                            'timesheet_id' => $timesheet->id,
                            'target_project_id' => $targetProject->id,
                            'target_user_id' => $userIdForValidation,
                            'new_hours' => $newHours,
                        ]);
                        DB::rollBack();
                        return $balanceValidation;
                    }
                }
            } else {
                // Se não mudou de projeto nem usuário, validar apenas a diferença de horas (excluindo o apontamento atual)
                if ($projectForValidation && $hoursDifference > 0 && !$projectForValidation->is_investimento_comercial) {
                    Log::info('Editando apontamento - Mesmo projeto/usuário, validando diferença de horas', [
                        'timesheet_id' => $timesheet->id,
                        'project_id' => $projectForValidation->id,
                        'user_id' => $userIdForValidation,
                        'hours_difference' => $hoursDifference,
                        'exclude_timesheet_id' => $timesheet->id,
                    ]);

                    $balanceValidation = $this->validateHoursBalance(
                        $projectForValidation,
                        $userIdForValidation,
                        $hoursDifference,
                        $timesheet->id // Excluir o apontamento atual do cálculo
                    );

                    if ($balanceValidation) {
                        Log::warning('Edição de apontamento bloqueada - Saldo insuficiente para aumentar horas', [
                            'timesheet_id' => $timesheet->id,
                            'project_id' => $projectForValidation->id,
                            'user_id' => $userIdForValidation,
                            'hours_difference' => $hoursDifference,
                        ]);
                        DB::rollBack();
                        return $balanceValidation;
                    }
                } else {
                    Log::info('Editando apontamento - Não precisa validar saldo (redução de horas ou sem mudança)', [
                        'timesheet_id' => $timesheet->id,
                        'hours_difference' => $hoursDifference,
                        'project_id' => $projectForValidation?->id,
                    ]);
                }
            }

            // Resetar status para pendente quando o usuário corrige um apontamento
            // que foi rejeitado, conflitado ou marcado para ajuste. Edição = correção.
            if ($timesheet->status === Timesheet::STATUS_REJECTED) {
                $validatedData['status'] = Timesheet::STATUS_PENDING;
                $validatedData['rejection_reason'] = null;
                $validatedData['reviewed_by'] = null;
                $validatedData['reviewed_at'] = null;
            } elseif ($timesheet->status === Timesheet::STATUS_ADJUSTMENT_REQUESTED) {
                $validatedData['status'] = Timesheet::STATUS_PENDING;
                $validatedData['rejection_reason'] = null;
                $validatedData['reviewed_by'] = null;
                $validatedData['reviewed_at'] = null;
            } elseif ($timesheet->status === Timesheet::STATUS_CONFLICTED) {
                $validatedData['status'] = Timesheet::STATUS_PENDING;
            }

            $timesheet->fill($validatedData);

            // Trava manual de projeto/cliente: se o usuário alterou esses campos
            // via UI, marcar como "edição manual" para que reprocess do Movidesk
            // não sobrescreva mais.
            $customerChanged = isset($validatedData['customer_id'])
                && (int) $validatedData['customer_id'] !== (int) $timesheet->getOriginal('customer_id');
            if ($projectChanged || $customerChanged) {
                $timesheet->manual_project_edit = true;
            }

            // Atualiza is_billable_only apenas quando admin edita apontamento de outro usuário
            $targetUserId = $validatedData['user_id'] ?? $timesheet->user_id;
            if ($user->isAdmin() && $request->has('is_billable_only')) {
                $timesheet->is_billable_only = $user->isAdmin()
                    && $targetUserId != Auth::id()
                    && $request->boolean('is_billable_only', false);
            }

            // Atualiza percentuais de acréscimo (admin/coordenador)
            if ($user->isAdmin() || $user->isCoordenador()) {
                if ($request->has('client_extra_pct')) {
                    $timesheet->client_extra_pct = $request->filled('client_extra_pct')
                        ? (float) $request->input('client_extra_pct') : null;
                }
                if ($request->has('consultant_extra_pct')) {
                    // Fat. Admin nunca tem % consultor — força null
                    $isBillableOnly = $timesheet->is_billable_only;
                    $timesheet->consultant_extra_pct = (!$isBillableOnly && $request->filled('consultant_extra_pct'))
                        ? (float) $request->input('consultant_extra_pct') : null;
                }
            }

            $newAttachmentInfoUpd = null;
            if ($request->hasFile('attachment')) {
                // FASE 11.7 — soft-delete dos attachment(s) atuais (fonte única).
                $this->softDeleteTimesheetAttachments($timesheet);
                $file = $request->file('attachment');
                $filename = time() . '_' . $file->getClientOriginalName();
                $path = $file->storeAs('timesheets/' . date('Y/m'), $filename, 'public');
                $newAttachmentInfoUpd = ['path' => $path, 'original' => $file->getClientOriginalName(), 'mime' => $file->getMimeType()];
            }

            // Bloquear edição que criaria sobreposição de horários (mesmo cliente)
            if ($timesheet->start_time && $timesheet->end_time) {
                $overlappingTs = Timesheet::with(['customer', 'project.customer'])
                    ->where('user_id', $timesheet->user_id)
                    ->where('customer_id', $timesheet->customer_id)
                    ->where('date', $timesheet->date)
                    ->where('id', '!=', $timesheet->id)
                    ->whereNotIn('status', [Timesheet::STATUS_REJECTED])
                    ->whereNotNull('start_time')
                    ->whereNotNull('end_time')
                    ->where('start_time', '<', $timesheet->end_time)
                    ->where('end_time', '>', $timesheet->start_time)
                    ->first();

                if ($overlappingTs) {
                    DB::rollBack();
                    return response()->json([
                        'code'          => 'TIMESHEET_CONFLICT',
                        'type'          => 'error',
                        'message'       => 'Conflito de horário',
                        'detailMessage' => 'O horário informado conflita com outro apontamento do mesmo cliente já registrado para este dia.',
                        'conflicting_timesheet' => [
                            'date'          => $overlappingTs->date instanceof \Carbon\Carbon
                                                ? $overlappingTs->date->format('Y-m-d')
                                                : (string) $overlappingTs->date,
                            'start_time'    => $overlappingTs->start_time instanceof \Carbon\Carbon
                                                ? $overlappingTs->start_time->format('H:i')
                                                : null,
                            'end_time'      => $overlappingTs->end_time instanceof \Carbon\Carbon
                                                ? $overlappingTs->end_time->format('H:i')
                                                : null,
                            'customer_name' => $overlappingTs->customer?->name
                                                ?? $overlappingTs->project?->customer?->name,
                            'project_name'  => $overlappingTs->project?->name,
                        ],
                    ], 422);
                }
            }

            $timesheet->save();

            // FASE 11.7 — Attachment persiste 100% na camada Attachment.
            if (isset($newAttachmentInfoUpd) && $newAttachmentInfoUpd !== null) {
                $this->registerTimesheetAttachment($timesheet->fresh(), $newAttachmentInfoUpd);
            }

            // Re-detectar conflitos após a edição (mesmo cliente, com horários definidos)
            if ($timesheet->start_time && $timesheet->end_time) {
                $overlappingIds = Timesheet::where('user_id', $timesheet->user_id)
                    ->where('customer_id', $timesheet->customer_id)
                    ->where('date', $timesheet->date)
                    ->where('id', '!=', $timesheet->id)
                    ->whereNotIn('status', [Timesheet::STATUS_REJECTED])
                    ->whereNotNull('start_time')
                    ->whereNotNull('end_time')
                    ->where('start_time', '<', $timesheet->end_time)
                    ->where('end_time', '>', $timesheet->start_time)
                    ->pluck('id');

                $newlyConflictedIds = collect();
                if ($overlappingIds->isNotEmpty()) {
                    $timesheet->status = Timesheet::STATUS_CONFLICTED;
                    $timesheet->save();
                    $newlyConflictedIds = Timesheet::whereIn('id', $overlappingIds)
                        ->whereNotIn('status', [Timesheet::STATUS_REJECTED, Timesheet::STATUS_CONFLICTED, Timesheet::STATUS_LATE])
                        ->pluck('id');
                    Timesheet::whereIn('id', $newlyConflictedIds)
                        ->update(['status' => Timesheet::STATUS_CONFLICTED]);
                }
            }

            // Resolver conflitos obsoletos para o mesmo usuário/data
            $this->resolveStaleConflicts($timesheet->user_id, $timesheet->date);

            DB::commit();

            // Notifica donos dos apontamentos marcados como conflito
            if ($timesheet->status === Timesheet::STATUS_CONFLICTED) {
                $timesheet->notifyOwnerOfStatus('CONFLITO');
            }
            if (isset($newlyConflictedIds)) {
                foreach ($newlyConflictedIds as $cid) {
                    $conflicted = Timesheet::find($cid);
                    if ($conflicted) $conflicted->notifyOwnerOfStatus('CONFLITO');
                }
            }

            $timesheet->load(['user', 'customer', 'project']);

            Log::info('Apontamento atualizado com sucesso', [
                'timesheet_id' => $timesheet->id,
                'project_id' => $timesheet->project_id,
                'user_id' => $timesheet->user_id,
                'old_effort_minutes' => $timesheet->getOriginal('effort_minutes'),
                'new_effort_minutes' => $timesheet->effort_minutes,
                'old_effort_hours' => round(($timesheet->getOriginal('effort_minutes') ?? 0) / 60, 2),
                'new_effort_hours' => round($timesheet->effort_minutes / 60, 2),
                'hours_difference' => $hoursDifference,
                'date' => $timesheet->date,
                'status' => $timesheet->status,
                'project_changed' => $projectChanged ?? false,
                'user_changed' => $userChanged ?? false,
            ]);

            $this->invalidateListCache('timesheets');

            return response()->json([
                'success' => true,
                'data' => $timesheet,
                'message' => 'Apontamento atualizado com sucesso!'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erro ao atualizar apontamento: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/timesheets/{id}",
     *     summary="Excluir apontamento",
     *     tags={"Timesheets"},
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID do timesheet",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Timesheet excluído com sucesso",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Apontamento excluído com sucesso!")
     *         )
     *     )
     * )
     */
    public function destroy(int $id): JsonResponse
    {
        $user = Auth::user();

        $timesheet = Timesheet::find($id);

        if (!$timesheet) {
            return response()->json([
                'success' => false,
                'message' => 'Apontamento não encontrado'
            ], 404);
        }

        // Verificar permissões
        if (!$user->isAdmin() && !$user->hasAccess('hours.delete_all') && $timesheet->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado'
            ], 403);
        }

        // Admin e hours.delete_all podem excluir qualquer status; demais usuários só o que canBeEdited() permite
        $canBypassStatusCheck = $user->isAdmin() || $user->hasAccess('hours.delete_all');
        if (!$canBypassStatusCheck && !$timesheet->canBeEdited()) {
            return response()->json([
                'success' => false,
                'message' => 'Não é possível excluir apontamentos já aprovados ou liberados'
            ], 422);
        }

        // Captura user_id/date ANTES do delete pra resolver conflitos órfãos depois.
        $tsUserId = $timesheet->user_id;
        $tsDate   = $timesheet->date instanceof \Carbon\Carbon
            ? $timesheet->date->format('Y-m-d')
            : (string) $timesheet->date;

        // FASE 11.7 — soft-delete attachment(s) ANTES do timesheet sumir.
        $this->softDeleteTimesheetAttachments($timesheet);

        $timesheet->delete();
        $this->resolveStaleConflicts($tsUserId, $tsDate);
        $this->invalidateListCache('timesheets');

        return response()->json([
            'success' => true,
            'message' => 'Apontamento excluído com sucesso!'
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/timesheets/{id}/approve",
     *     summary="Aprovar apontamento",
     *     tags={"Timesheets"},
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID do timesheet",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Timesheet aprovado com sucesso",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", ref="#/components/schemas/Timesheet"),
     *             @OA\Property(property="message", type="string", example="Apontamento aprovado com sucesso!")
     *         )
     *     )
     * )
     */
    /**
     * Bulk update de cliente/projeto em vários apontamentos. Marca manual_project_edit=true
     * em cada um pra que o reprocess do Movidesk não reverta a alteração manual.
     */
    public function bulkUpdateProjectCustomer(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $user = Auth::user();
        if (!$user->isAdmin() && !$user->isCoordenador()) {
            return response()->json(['message' => 'Não autorizado'], 403);
        }
        $data = $request->validate([
            'ids'         => 'required|array|min:1',
            'ids.*'       => 'integer|exists:timesheets,id',
            'customer_id' => 'nullable|integer|exists:customers,id',
            'project_id'  => 'nullable|integer|exists:projects,id',
        ]);
        if (empty($data['customer_id']) && empty($data['project_id'])) {
            return response()->json(['message' => 'Informe customer_id e/ou project_id'], 422);
        }

        // Se projeto for fornecido, descobrir o customer dele e validar coerência
        $projectCustomerId = null;
        if (!empty($data['project_id'])) {
            $project = \App\Models\Project::find($data['project_id']);
            if (!$project) {
                return response()->json(['message' => 'Projeto não encontrado'], 422);
            }
            $projectCustomerId = (int) $project->customer_id;
            if (!empty($data['customer_id']) && (int) $data['customer_id'] !== $projectCustomerId) {
                return response()->json([
                    'message' => 'Projeto não pertence ao cliente informado',
                ], 422);
            }
        }

        $finalCustomerId = $data['customer_id'] ?? $projectCustomerId;
        $finalProjectId  = $data['project_id'] ?? null;

        $updated = 0;
        foreach (Timesheet::whereIn('id', $data['ids'])->get() as $ts) {
            if ($finalCustomerId !== null) $ts->customer_id = $finalCustomerId;
            if ($finalProjectId  !== null) $ts->project_id  = $finalProjectId;
            // Trava manual: sync do Movidesk não sobrescreve mais cliente/projeto.
            $ts->manual_project_edit = true;
            if ($ts->isDirty()) {
                $ts->save(); // dispara observer pra log de auditoria
                $updated++;
            }
        }

        return response()->json(['success' => true, 'updated' => $updated]);
    }

    public function bulkExtraPct(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $user = Auth::user();
        if (!$user->isAdmin() && !$user->isCoordenador()) {
            return response()->json(['message' => 'Não autorizado'], 403);
        }
        $data = $request->validate([
            'ids'                  => 'required|array|min:1',
            'ids.*'                => 'integer|exists:timesheets,id',
            'client_extra_pct'     => 'nullable|numeric|min:0|max:999.99',
            'consultant_extra_pct' => 'nullable|numeric|min:0|max:999.99',
        ]);
        $update = [];
        if ($request->has('client_extra_pct'))     $update['client_extra_pct']     = $data['client_extra_pct'];
        if ($request->has('consultant_extra_pct')) $update['consultant_extra_pct'] = $data['consultant_extra_pct'];
        if (empty($update)) {
            return response()->json(['success' => true, 'updated' => 0]);
        }
        // Fat. Admin nunca recebe % consultor — força null nos is_billable_only=true
        if (isset($update['consultant_extra_pct'])) {
            Timesheet::whereIn('id', $data['ids'])->where('is_billable_only', false)->update($update);
            if ($update['consultant_extra_pct'] !== null) {
                Timesheet::whereIn('id', $data['ids'])->where('is_billable_only', true)
                    ->update(array_merge($update, ['consultant_extra_pct' => null]));
            } else {
                Timesheet::whereIn('id', $data['ids'])->where('is_billable_only', true)->update($update);
            }
        } else {
            Timesheet::whereIn('id', $data['ids'])->update($update);
        }
        return response()->json(['success' => true, 'updated' => count($data['ids'])]);
    }

    /**
     * Lista os apontamentos em ATRASO (status late): chegaram pela integração com data
     * em competência fechada e aguardam aprovação (entrar no período ou mudar a data).
     */
    public function atrasos(Request $request): JsonResponse
    {
        $query = Timesheet::with(['user:id,name', 'customer:id,name', 'project:id,name,code'])
            ->where('status', Timesheet::STATUS_LATE)
            ->whereNull('deleted_at')
            ->orderBy('date');

        if ($request->filled('year_month')) {
            $query->whereRaw("to_char(date, 'YYYY-MM') = ?", [$request->query('year_month')]);
        }

        $rows = $query->get()->map(fn ($t) => [
            'id'             => $t->id,
            'date'           => $t->date->format('Y-m-d'),
            'year_month'     => $t->date->format('Y-m'),
            'colaborador'    => $t->user?->name ?? '—',
            'cliente'        => $t->customer?->name ?? '—',
            'projeto'        => $t->project?->name ?? '—',
            'projeto_codigo' => $t->project?->code ?? '—',
            'ticket'         => $t->ticket,
            'horas'          => round($t->effort_minutes / 60, 2),
            'observacao'     => $t->observation,
            'date_locked'    => (bool) $t->date_locked,
        ]);

        return response()->json(['data' => $rows]);
    }

    /**
     * Aprova um apontamento em ATRASO (status late):
     *  • action=keep        → entra no período (status=pending, data original).
     *  • action=change_date → muda a data de inclusão (escolhida pelo aprovador) e TRAVA a
     *    data (date_locked) p/ o reprocesso da integração não sobrescrever; status=pending.
     */
    public function aprovarAtraso(Request $request, int $id): JsonResponse
    {
        $timesheet = Timesheet::find($id);
        if (!$timesheet) {
            return response()->json(['success' => false, 'message' => 'Apontamento não encontrado'], 404);
        }
        if ($timesheet->status !== Timesheet::STATUS_LATE) {
            return response()->json(['success' => false, 'message' => 'Apontamento não está em atraso.'], 422);
        }

        $action = $request->input('action', 'keep');
        if ($action === 'change_date') {
            $validated = $request->validate(['date' => 'required|date']);
            $timesheet->date        = $validated['date'];
            $timesheet->date_locked = true;
        }

        $timesheet->status = Timesheet::STATUS_PENDING;
        $timesheet->save();

        $this->resolveStaleConflicts($timesheet->user_id, $timesheet->date);
        $this->invalidateListCache('timesheets');
        $timesheet->load(['user', 'customer', 'project']);

        return response()->json([
            'success' => true,
            'data'    => $timesheet,
            'message' => $action === 'change_date'
                ? 'Atraso aprovado com a nova data de inclusão.'
                : 'Atraso aprovado — entrou no período.',
        ]);
    }

    public function approve(int $id): JsonResponse
    {
        $user = Auth::user();

        $timesheet = Timesheet::with(['project.coordinators'])->find($id);

        if (!$timesheet) {
            return response()->json([
                'success' => false,
                'message' => 'Apontamento não encontrado'
            ], 404);
        }

        if (!$timesheet->canBeApprovedBy($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Você não tem permissão para aprovar este apontamento'
            ], 403);
        }

        if ($timesheet->approve($user)) {
            $this->resolveStaleConflicts($timesheet->user_id, $timesheet->date);
            $this->invalidateListCache('timesheets');
            $timesheet->load(['user', 'customer', 'project', 'reviewedBy']);

            return response()->json([
                'success' => true,
                'data' => $timesheet,
                'message' => 'Apontamento aprovado com sucesso!'
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Erro ao aprovar apontamento'
        ], 500);
    }

    public function release(int $id): JsonResponse
    {
        $user = Auth::user();

        $timesheet = Timesheet::find($id);

        if (!$timesheet) {
            return response()->json(['success' => false, 'message' => 'Apontamento não encontrado'], 404);
        }

        if (!$timesheet->is_internal_action) {
            return response()->json(['success' => false, 'message' => 'Apenas ações internas podem ser liberadas'], 422);
        }

        if (!$timesheet->release($user)) {
            return response()->json(['success' => false, 'message' => 'Não foi possível liberar. Status atual: ' . $timesheet->status], 422);
        }

        $timesheet->load(['user', 'customer', 'project', 'reviewedBy']);

        return response()->json([
            'success' => true,
            'data'    => $timesheet,
            'message' => 'Ação interna liberada com sucesso!',
        ]);
    }

    public function reverseRelease(int $id): JsonResponse
    {
        $timesheet = Timesheet::find($id);

        if (!$timesheet) {
            return response()->json(['success' => false, 'message' => 'Apontamento não encontrado'], 404);
        }

        if (!$timesheet->is_internal_action) {
            return response()->json(['success' => false, 'message' => 'Apenas ações internas podem ter liberação estornada'], 422);
        }

        if (!$timesheet->reverseRelease()) {
            return response()->json(['success' => false, 'message' => 'Não foi possível estornar. Status atual: ' . $timesheet->status], 422);
        }

        $timesheet->load(['user', 'customer', 'project', 'reviewedBy']);

        return response()->json([
            'success' => true,
            'data'    => $timesheet,
            'message' => 'Liberação estornada com sucesso!',
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/timesheets/{id}/reject",
     *     summary="Rejeitar apontamento",
     *     tags={"Timesheets"},
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID do timesheet",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"reason"},
     *             @OA\Property(property="reason", type="string", example="Horário não compatível com o projeto")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Timesheet rejeitado com sucesso",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", ref="#/components/schemas/Timesheet"),
     *             @OA\Property(property="message", type="string", example="Apontamento rejeitado com sucesso!")
     *         )
     *     )
     * )
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $user = Auth::user();

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Motivo da rejeição é obrigatório',
                'errors' => $validator->errors()
            ], 422);
        }

        $timesheet = Timesheet::with(['project.coordinators'])->find($id);

        if (!$timesheet) {
            return response()->json([
                'success' => false,
                'message' => 'Apontamento não encontrado'
            ], 404);
        }

        if (!$timesheet->canBeApprovedBy($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Você não tem permissão para rejeitar este apontamento'
            ], 403);
        }

        if ($timesheet->reject($user, $request->reason)) {
            $this->resolveStaleConflicts($timesheet->user_id, $timesheet->date);
            $this->invalidateListCache('timesheets');
            $timesheet->load(['user', 'customer', 'project', 'reviewedBy']);

            return response()->json([
                'success' => true,
                'data' => $timesheet,
                'message' => 'Apontamento rejeitado com sucesso!'
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Erro ao rejeitar apontamento'
        ], 500);
    }

    /**
     * Solicitar ajuste no apontamento
     */
    public function requestAdjustment(Request $request, int $id): JsonResponse
    {
        $user = Auth::user();

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Motivo do ajuste é obrigatório',
                'errors'  => $validator->errors()
            ], 422);
        }

        $timesheet = Timesheet::with(['project.coordinators'])->find($id);

        if (!$timesheet) {
            return response()->json([
                'success' => false,
                'message' => 'Apontamento não encontrado'
            ], 404);
        }

        if (!$timesheet->canBeApprovedBy($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Você não tem permissão para solicitar ajuste neste apontamento'
            ], 403);
        }

        if ($timesheet->requestAdjustment($user, $request->reason)) {
            $this->invalidateListCache('timesheets');
            $timesheet->load(['user', 'customer', 'project', 'reviewedBy']);

            return response()->json([
                'success' => true,
                'data'    => $timesheet,
                'message' => 'Ajuste solicitado com sucesso!'
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Erro ao solicitar ajuste'
        ], 500);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/timesheets/{id}/reverse-approval",
     *     summary="Estornar aprovação do apontamento",
     *     description="Estorna a aprovação de um apontamento, retornando-o ao status pendente",
     *     operationId="reverseApprovalTimesheet",
     *     tags={"Timesheets"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID do timesheet",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             required={"reason"},
     *             @OA\Property(property="reason", type="string", example="Aprovação realizada por engano")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Aprovação estornada com sucesso",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", ref="#/components/schemas/Timesheet"),
     *             @OA\Property(property="message", type="string", example="Aprovação estornada com sucesso!")
     *         )
     *     )
     * )
     */
    public function reverseApproval(Request $request, int $id): JsonResponse
    {
        $user = Auth::user();
        $timesheet = Timesheet::find($id);

        if (!$timesheet) {
            return $this->notFoundResponse('Apontamento não encontrado');
        }

        if (!$timesheet->canBeReversedBy($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Você não tem permissão para estornar esta aprovação ou o período permitido expirou'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:1000'
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors()->all());
        }

        if ($timesheet->reverseApproval($user, $request->reason)) {
            $this->invalidateListCache('timesheets');
            $timesheet->load(['user', 'customer', 'project', 'reviewedBy']);

            return response()->json([
                'success' => true,
                'data' => $timesheet,
                'message' => 'Aprovação estornada com sucesso!'
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Erro ao estornar aprovação'
        ], 500);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/timesheets/{id}/reverse-rejection",
     *     summary="Estornar rejeição do apontamento",
     *     description="Estorna a rejeição de um apontamento, retornando-o ao status pendente",
     *     operationId="reverseRejectionTimesheet",
     *     tags={"Timesheets"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID do timesheet",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             required={"reason"},
     *             @OA\Property(property="reason", type="string", example="Rejeição realizada por engano")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Rejeição estornada com sucesso",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", ref="#/components/schemas/Timesheet"),
     *             @OA\Property(property="message", type="string", example="Rejeição estornada com sucesso!")
     *         )
     *     )
     * )
     */
    public function reverseRejection(Request $request, int $id): JsonResponse
    {
        $user = Auth::user();
        $timesheet = Timesheet::find($id);

        if (!$timesheet) {
            return $this->notFoundResponse('Apontamento não encontrado');
        }

        if (!$timesheet->canBeRejectionReversedBy($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Você não tem permissão para estornar esta rejeição ou o período permitido expirou'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:1000'
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors()->all());
        }

        if ($timesheet->reverseRejection($user, $request->reason)) {
            $this->invalidateListCache('timesheets');
            $timesheet->load(['user', 'customer', 'project', 'reviewedBy']);

            return response()->json([
                'success' => true,
                'data' => $timesheet,
                'message' => 'Rejeição estornada com sucesso!'
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Erro ao estornar rejeição'
        ], 500);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/timesheets/export",
     *     summary="Exportar apontamentos para Excel",
     *     tags={"Timesheets"},
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="project_id",
     *         in="query",
     *         description="Filtrar por projeto",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="customer_id",
     *         in="query",
     *         description="Filtrar por cliente",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="user_id",
     *         in="query",
     *         description="Filtrar por usuário",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         description="Filtrar por status",
     *         required=false,
     *         @OA\Schema(type="string", enum={"pending", "approved", "rejected"})
     *     ),
     *     @OA\Parameter(
     *         name="start_date",
     *         in="query",
     *         description="Data de início do período",
     *         required=false,
     *         @OA\Schema(type="string", format="date")
     *     ),
     *     @OA\Parameter(
     *         name="end_date",
     *         in="query",
     *         description="Data de fim do período",
     *         required=false,
     *         @OA\Schema(type="string", format="date")
     *     ),
     *     @OA\Parameter(
     *         name="ticket",
     *         in="query",
     *         description="Filtrar por número do ticket",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         description="Busca geral",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="order",
     *         in="query",
     *         description="Ordenação (ex: date,-start_time)",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Arquivo Excel com apontamentos exportados",
     *         @OA\MediaType(
     *             mediaType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
     *         )
     *     )
     * )
     */
    /**
     * Apuração por ticket — para o Relatório de Apontamentos.
     * Retorna, para cada ticket que teve ao menos 1 apontamento dentro do período,
     * o total no período + total histórico (todos os apontamentos do mesmo ticket
     * no mesmo cliente).
     *
     * Filtros: customer_id (obrigatório), start_date/end_date (obrigatórios), status[].
     * Ignora apontamentos sem ticket.
     */
    public function summaryByTicket(Request $request): JsonResponse
    {
        $user = Auth::user();

        $request->validate([
            'customer_id' => 'required|integer',
            // Opcionais: quando só a competência é usada, o período (digitação) pode vir vazio.
            'start_date'  => 'nullable|date',
            'end_date'    => 'nullable|date',
        ]);

        $customerId  = (int) $request->customer_id;
        // Competência (serviço) trava o conjunto; o período (digitação) é opcional → fallback p/ a competência.
        $startDate   = $request->start_date ?: $request->competencia_start;
        $endDate     = $request->end_date ?: $request->competencia_end;
        $statusInput = $request->input('status');
        $statuses    = $statusInput === null
            ? []
            : (is_array($statusInput) ? $statusInput : [$statusInput]);
        $projectInput = $request->input('project_id');
        $projectIds   = $projectInput === null
            ? []
            : (is_array($projectInput) ? $projectInput : [$projectInput]);

        $base = Timesheet::query()
            ->where('timesheets.customer_id', $customerId)
            ->whereNotNull('timesheets.ticket')
            ->where('timesheets.ticket', '!=', '')
            // Só tickets com exatamente 5 dígitos numéricos (padrão Movidesk).
            // Descarta lançamentos sem ticket real (ex: "1", "test", "ABC").
            ->whereRaw("timesheets.ticket ~ '^[0-9]{5}$'");

        if (!empty($statuses)) {
            $base->whereIn('timesheets.status', $statuses);
        }

        // Filtro de projeto: respeita o filtro do relatório. Se um ticket
        // tem apontamentos em vários projetos, só conta o(s) projeto(s)
        // selecionado(s).
        if (!empty($projectIds)) {
            $base->whereIn('timesheets.project_id', $projectIds);
        }

        // Filtro por tipo de serviço (alinha com /timesheets index)
        $serviceTypeIds = array_values(array_filter((array) $request->input('service_type_id', [])));
        if (!empty($serviceTypeIds)) {
            $base->whereHas('project', fn($q) => $q->whereIn('service_type_id', $serviceTypeIds));
        }

        // Visibilidade por perfil — replica regras do index()
        if ($user->isCliente()) {
            $base->where('timesheets.customer_id', $user->customer_id)
                 ->whereIn('timesheets.status', ['pending', 'approved']);
        } elseif ($user->type === 'parceiro_admin' && $request->boolean('team_view') && $user->partner_id) {
            $partnerUserIds = \App\Models\User::where('partner_id', $user->partner_id)->pluck('id');
            $base->whereIn('timesheets.user_id', $partnerUserIds);
        } elseif (!$user->isAdmin() && !$user->hasAccess('hours.view_all')) {
            $base->forUser($user->id);
        } elseif ($user->isCoordenador()) {
            // Coord projetos: vê tudo. Sustentação: restrito ao escopo.
            if ($user->coordinator_type === 'sustentacao') {
                $base->whereHas('project.serviceType', fn($q) => $q->whereIn('code', ['sustentacao', 'cloud']));
            }
        }

        // Competência: trava a DATA DO SERVIÇO (afeta tickets-no-período E agregações via clone).
        if ($request->filled('competencia_start')) $base->where('timesheets.date', '>=', $request->competencia_start);
        if ($request->filled('competencia_end'))   $base->where('timesheets.date', '<=', $request->competencia_end);

        // date_field: 'date' (padrão) ou 'created_at' (data de inclusão).
        $useCreatedAt = $request->input('date_field') === 'created_at';
        $periodCol = $useCreatedAt ? 'timesheets.created_at::date' : 'timesheets.date';

        // 1) Tickets que tiveram apontamento no período
        $ticketsInPeriod = (clone $base)
            ->whereRaw("$periodCol BETWEEN ? AND ?", [$startDate, $endDate])
            ->select('timesheets.ticket')
            ->distinct()
            ->pluck('ticket')
            ->toArray();

        if (empty($ticketsInPeriod)) {
            return response()->json(['tickets' => []]);
        }

        // 2) Agregação: histórico (todos do ticket no cliente/projeto) + total no período
        $rows = (clone $base)
            ->whereIn('timesheets.ticket', $ticketsInPeriod)
            ->leftJoin('movidesk_tickets', 'movidesk_tickets.ticket_id', '=', 'timesheets.ticket')
            ->selectRaw('timesheets.ticket as ticket')
            ->selectRaw('MAX(movidesk_tickets.titulo) as title')
            ->selectRaw("MAX(movidesk_tickets.solicitante::jsonb->>'name') as requester")
            ->selectRaw('SUM(timesheets.effort_minutes) as lifetime_minutes')
            ->selectRaw('COUNT(*) as lifetime_count')
            ->selectRaw("SUM(CASE WHEN $periodCol BETWEEN ? AND ? THEN timesheets.effort_minutes ELSE 0 END) as period_minutes", [$startDate, $endDate])
            ->selectRaw("SUM(CASE WHEN $periodCol BETWEEN ? AND ? THEN 1 ELSE 0 END) as period_count", [$startDate, $endDate])
            ->groupBy('timesheets.ticket')
            ->orderBy('timesheets.ticket')
            ->get();

        // Saldos iniciais cadastrados pra esse cliente — somam SOMENTE no
        // lifetime_minutes (histórico), não no period_minutes (saldo é
        // histórico anterior à entrada do ticket no Minutor).
        $initialByTicket = \DB::table('ticket_initial_balances')
            ->whereNull('deleted_at')
            ->where('customer_id', $customerId)
            ->whereIn('ticket', $rows->pluck('ticket')->all())
            ->pluck('initial_minutes', 'ticket');

        return response()->json([
            'tickets' => $rows->map(function ($r) use ($initialByTicket) {
                $initial = (int) ($initialByTicket[$r->ticket] ?? 0);
                return [
                    'ticket'           => $r->ticket,
                    'title'            => $r->title,
                    'requester'        => $r->requester,
                    'period_minutes'   => (int) $r->period_minutes,
                    'period_count'     => (int) $r->period_count,
                    'lifetime_minutes' => (int) $r->lifetime_minutes + $initial,
                    'lifetime_count'   => (int) $r->lifetime_count,
                    'initial_minutes'  => $initial,
                ];
            })->values(),
        ]);
    }

    public function export(Request $request)
    {
        $user = Auth::user();

        // Gerar nome do arquivo com timestamp
        $filename = 'apontamentos_' . date('Y-m-d_H-i-s') . '.xlsx';

        return Excel::download(new TimesheetsExport($request, $user), $filename);
    }

    /**
     * Validar saldo de horas disponível para o usuário no projeto
     *
     * @param Project $project Projeto a validar
     * @param int $userId ID do usuário que está criando/editando o apontamento
     * @param float $hoursToAdd Horas que serão adicionadas (em horas decimais)
     * @param int|null $excludeTimesheetId ID do timesheet a excluir do cálculo (útil na edição)
     * @return JsonResponse|null Retorna erro se não houver saldo suficiente, null se OK
     */
    private function resolveStaleConflicts(int $userId, string $date): void
    {
        Timesheet::resolveStaleConflicts($userId, $date);
    }

    private function validateHoursBalance(Project $project, int $userId, float $hoursToAdd, ?int $excludeTimesheetId = null): ?JsonResponse
    {
        Log::info('Iniciando validação de saldo de horas', [
            'project_id' => $project->id,
            'project_name' => $project->name,
            'user_id' => $userId,
            'hours_to_add' => $hoursToAdd,
            'exclude_timesheet_id' => $excludeTimesheetId,
            'sold_hours' => $project->sold_hours,
            'hour_contribution' => $project->hour_contribution,
            'consultant_hours' => $project->consultant_hours,
            'coordinator_hours' => $project->coordinator_hours,
        ]);

        // On Demand é SOB DEMANDA — não controla saldo. Não valida saldo nem para o projeto
        // On Demand nem para projeto FILHO de um On Demand. (getGeneralHoursBalance retorna 0
        // para On Demand, o que antes era tratado erroneamente como "saldo insuficiente".)
        if (($project->contractType?->code ?? null) === 'on_demand'
            || ($project->parentProject?->contractType?->code ?? null) === 'on_demand') {
            Log::info('Validação de saldo IGNORADA (On Demand não tem saldo)', ['project_id' => $project->id]);
            return null;
        }

        // ── Bloqueio por HORAS APONTÁVEIS (coordination_hours) — só Fechado e BH Fixo ──
        // O teto do bloqueio deixa de ser Horas Vendidas (sold_hours) e passa a ser
        // "Horas Apontáveis" (coordination_hours). TUDO o mais fica idêntico: consumido
        // (apontadas + saldo inicial), aportes, ajuste de filhos e allow_negative_balance —
        // por isso reaproveitamos getGeneralHoursBalance e só trocamos a base sold→apontáveis.
        // Não afeta interface nem nenhum outro cálculo. BH Mensal/demais seguem a regra atual.
        $ctNameBlock = strtolower(trim((string) ($project->contractType->name ?? '')));
        $ctCodeBlock = (string) ($project->contractType->code ?? '');
        if ($ctNameBlock === 'fechado' || $ctCodeBlock === 'fixed_hours' || $ctNameBlock === 'banco de horas fixo') {
            $generalBalance    = $project->getGeneralHoursBalance(false, $excludeTimesheetId);
            $apontaveisBalance = $generalBalance - (float) ($project->sold_hours ?? 0) + (float) ($project->coordination_hours ?? 0);
            if ($apontaveisBalance < $hoursToAdd && !$project->allow_negative_balance) {
                Log::warning('Apontamento bloqueado — Horas Apontáveis insuficientes', [
                    'project_id' => $project->id, 'user_id' => $userId,
                    'horas_apontaveis' => $project->coordination_hours, 'sold_hours' => $project->sold_hours,
                    'apontaveis_balance' => $apontaveisBalance, 'hours_to_add' => $hoursToAdd,
                ]);
                return response()->json([
                    'code' => 'INSUFFICIENT_HOURS_BALANCE',
                    'type' => 'error',
                    'message' => 'Saldo de horas insuficiente',
                    'details' => [
                        'available_balance' => round($apontaveisBalance, 2),
                        'requested_hours'   => $hoursToAdd,
                        'balance_type'      => 'apontaveis',
                    ],
                ], 422);
            }
            return null;
        }

        // Carregar relacionamentos necessários
        $project->load(['consultants', 'coordinators']);

        // Verificar se é consultor
        $isConsultant = $project->isUserConsultant($userId);

        // Verificar se é coordenador
        $isCoordinator = $project->isUserCoordinator($userId);

        Log::info('Identificação de tipo de usuário', [
            'project_id' => $project->id,
            'user_id' => $userId,
            'is_consultant' => $isConsultant,
            'is_coordinator' => $isCoordinator,
            'consultant_ids' => $project->consultants->pluck('id')->toArray(),
            'coordinator_ids' => $project->coordinators->pluck('id')->toArray(),
        ]);

        // Se não é nem consultor nem coordenador, não precisa validar saldo específico
        // Mas ainda valida o saldo geral
        if (!$isConsultant && !$isCoordinator) {
            $generalBalance = $project->getGeneralHoursBalance(false, $excludeTimesheetId);
            $totalLoggedHours = $project->getTotalLoggedHours(false, $excludeTimesheetId);

            Log::info('Validação de saldo geral (usuário não é consultor nem coordenador)', [
                'project_id' => $project->id,
                'user_id' => $userId,
                'sold_hours' => $project->sold_hours ?? 0,
                'hour_contribution' => $project->hour_contribution ?? 0,
                'total_logged_hours' => $totalLoggedHours,
                'general_balance' => $generalBalance,
                'hours_to_add' => $hoursToAdd,
                'exclude_timesheet_id' => $excludeTimesheetId,
            ]);

            if ($generalBalance < $hoursToAdd && !$project->allow_negative_balance) {
                Log::warning('Saldo de horas insuficiente - Validação falhou', [
                    'project_id' => $project->id,
                    'user_id' => $userId,
                    'hours_to_add' => $hoursToAdd,
                    'general_balance' => $generalBalance,
                    'balance_type' => 'general',
                ]);
                return response()->json([
                    'code' => 'INSUFFICIENT_HOURS_BALANCE',
                    'type' => 'error',
                    'message' => 'Saldo de horas insuficiente',
                    'detailMessage' => 'O projeto não possui saldo suficiente de horas.',
                    'details' => [
                        'available_balance' => $generalBalance,
                        'requested_hours' => $hoursToAdd,
                        'balance_type' => 'general'
                    ]
                ], 422);
            }

            Log::info('Validação de saldo geral aprovada', [
                'project_id' => $project->id,
                'user_id' => $userId,
                'general_balance' => $generalBalance,
                'hours_to_add' => $hoursToAdd,
            ]);

            return null; // OK
        }

        // Validar saldo de consultor se for consultor
        if ($isConsultant) {
            $consultantConfiguredHours = $project->consultant_hours ?? 0;

            // Se consultant_hours não está configurado (0), usa o saldo geral do projeto
            if ($consultantConfiguredHours <= 0) {
                $generalBalance = $project->getGeneralHoursBalance(false, $excludeTimesheetId);
                Log::info('Consultor sem cota configurada — usando saldo geral do projeto', [
                    'project_id' => $project->id,
                    'user_id' => $userId,
                    'general_balance' => $generalBalance,
                    'hours_to_add' => $hoursToAdd,
                ]);
                if ($generalBalance < $hoursToAdd && !$project->allow_negative_balance) {
                    return response()->json([
                        'code' => 'INSUFFICIENT_HOURS_BALANCE',
                        'type' => 'error',
                        'message' => 'Saldo de horas insuficiente',
                        'detailMessage' => sprintf(
                            'O projeto não possui saldo suficiente. Saldo disponível: %.2f h, horas solicitadas: %.2f h.',
                            $generalBalance,
                            $hoursToAdd
                        ),
                        'details' => ['available_balance' => $generalBalance, 'requested_hours' => $hoursToAdd, 'balance_type' => 'general']
                    ], 422);
                }
            } else {
            $consultantBalance = $project->getConsultantHoursBalance(false, $excludeTimesheetId);
            $consultantLoggedHours = $project->getConsultantLoggedHours(false, $excludeTimesheetId);

            Log::info('Validação de saldo de consultor', [
                'project_id' => $project->id,
                'user_id' => $userId,
                'consultant_hours' => $consultantConfiguredHours,
                'consultant_logged_hours' => $consultantLoggedHours,
                'consultant_balance' => $consultantBalance,
                'hours_to_add' => $hoursToAdd,
                'exclude_timesheet_id' => $excludeTimesheetId,
            ]);

            if ($consultantBalance < $hoursToAdd && !$project->allow_negative_balance) {
                Log::warning('Saldo de horas de consultor insuficiente - Validação falhou', [
                    'project_id' => $project->id,
                    'user_id' => $userId,
                    'hours_to_add' => $hoursToAdd,
                    'consultant_balance' => $consultantBalance,
                    'consultant_hours' => $project->consultant_hours ?? 0,
                    'consultant_logged_hours' => $consultantLoggedHours,
                    'balance_type' => 'consultant',
                ]);
                return response()->json([
                    'code' => 'INSUFFICIENT_CONSULTANT_HOURS_BALANCE',
                    'type' => 'error',
                    'message' => 'Saldo de horas de consultor insuficiente',
                    'detailMessage' => sprintf(
                        'Você não possui saldo suficiente de horas como consultor neste projeto. Saldo disponível: %.2f h, horas solicitadas: %.2f h.',
                        $consultantBalance,
                        $hoursToAdd
                    ),
                    'details' => [
                        'available_balance' => $consultantBalance,
                        'requested_hours' => $hoursToAdd,
                        'balance_type' => 'consultant'
                    ]
                ], 422);
            }

            Log::info('Validação de saldo de consultor aprovada', [
                'project_id' => $project->id,
                'user_id' => $userId,
                'consultant_balance' => $consultantBalance,
                'hours_to_add' => $hoursToAdd,
            ]);
            } // end else (consultant_hours configurado)
        }

        // Validar saldo de coordenador se for coordenador
        if ($isCoordinator) {
            $coordinatorBalance = $project->getCoordinatorHoursBalance(false, $excludeTimesheetId);
            $coordinatorLoggedHours = $project->getCoordinatorLoggedHours(false, $excludeTimesheetId);
            $consultantHours = $project->consultant_hours ?? 0;
            $coordinatorHoursPercent = $project->coordinator_hours ?? 0;
            $coordinatorAvailableHours = ($consultantHours * $coordinatorHoursPercent) / 100;

            Log::info('Validação de saldo de coordenador', [
                'project_id' => $project->id,
                'user_id' => $userId,
                'coordinator_hours_percent' => $coordinatorHoursPercent,
                'consultant_hours' => $consultantHours,
                'coordinator_available_hours' => $coordinatorAvailableHours,
                'coordinator_logged_hours' => $coordinatorLoggedHours,
                'coordinator_balance' => $coordinatorBalance,
                'hours_to_add' => $hoursToAdd,
                'exclude_timesheet_id' => $excludeTimesheetId,
            ]);

            if ($coordinatorBalance < $hoursToAdd && !$project->allow_negative_balance) {
                Log::warning('Saldo de horas de coordenador insuficiente - Validação falhou', [
                    'project_id' => $project->id,
                    'user_id' => $userId,
                    'hours_to_add' => $hoursToAdd,
                    'coordinator_balance' => $coordinatorBalance,
                    'coordinator_available_hours' => $coordinatorAvailableHours,
                    'coordinator_logged_hours' => $coordinatorLoggedHours,
                    'balance_type' => 'coordinator',
                ]);
                return response()->json([
                    'code' => 'INSUFFICIENT_COORDINATOR_HOURS_BALANCE',
                    'type' => 'error',
                    'message' => 'Saldo de horas de coordenador insuficiente',
                    'detailMessage' => sprintf(
                        'Você não possui saldo suficiente de horas como coordenador neste projeto. Saldo disponível: %.2f h, horas solicitadas: %.2f h.',
                        $coordinatorBalance,
                        $hoursToAdd
                    ),
                    'details' => [
                        'available_balance' => $coordinatorBalance,
                        'requested_hours' => $hoursToAdd,
                        'balance_type' => 'coordinator'
                    ]
                ], 422);
            }

            Log::info('Validação de saldo de coordenador aprovada', [
                'project_id' => $project->id,
                'user_id' => $userId,
                'coordinator_balance' => $coordinatorBalance,
                'hours_to_add' => $hoursToAdd,
            ]);
        }

        // Também validar saldo geral
        $generalBalance = $project->getGeneralHoursBalance(false, $excludeTimesheetId);
        $totalLoggedHours = $project->getTotalLoggedHours(false, $excludeTimesheetId);

        Log::info('Validação final de saldo geral (após validações específicas)', [
            'project_id' => $project->id,
            'user_id' => $userId,
            'is_consultant' => $isConsultant,
            'is_coordinator' => $isCoordinator,
            'sold_hours' => $project->sold_hours ?? 0,
            'hour_contribution' => $project->hour_contribution ?? 0,
            'total_logged_hours' => $totalLoggedHours,
            'general_balance' => $generalBalance,
            'hours_to_add' => $hoursToAdd,
            'exclude_timesheet_id' => $excludeTimesheetId,
        ]);

        if ($generalBalance < $hoursToAdd && !$project->allow_negative_balance) {
            Log::warning('Saldo geral de horas insuficiente - Validação final falhou', [
                'project_id' => $project->id,
                'user_id' => $userId,
                'hours_to_add' => $hoursToAdd,
                'general_balance' => $generalBalance,
                'total_logged_hours' => $totalLoggedHours,
                'balance_type' => 'general',
            ]);
            return response()->json([
                'code' => 'INSUFFICIENT_HOURS_BALANCE',
                'type' => 'error',
                'message' => 'Saldo de horas insuficiente',
                'detailMessage' => sprintf(
                    'O projeto não possui saldo suficiente de horas. Saldo disponível: %.2f h, horas solicitadas: %.2f h.',
                    $generalBalance,
                    $hoursToAdd
                ),
                'details' => [
                    'available_balance' => $generalBalance,
                    'requested_hours' => $hoursToAdd,
                    'balance_type' => 'general'
                ]
            ], 422);
        }

        Log::info('Validação de saldo de horas concluída com sucesso', [
            'project_id' => $project->id,
            'user_id' => $userId,
            'is_consultant' => $isConsultant,
            'is_coordinator' => $isCoordinator,
            'general_balance' => $generalBalance,
            'hours_to_add' => $hoursToAdd,
            'validation_result' => 'approved',
        ]);

        return null; // OK
    }

    /**
     * Download do anexo de um apontamento
     */
    public function downloadAttachment(Request $request, int $id)
    {
        $user = Auth::user();
        $timesheet = Timesheet::findOrFail($id);

        if (!$user->isAdmin() && $timesheet->user_id !== $user->id) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        // FASE 11.7 — Attachment vem 100% da camada Attachment.
        $att = \App\Models\Attachment::query()
            ->forEntity('TIMESHEET', $timesheet->id)
            ->ofCategory('attachment')
            ->visible()
            ->latest('id')
            ->first();

        if (!$att) {
            return response()->json(['message' => 'Anexo não encontrado'], 404);
        }

        try {
            $service = app(\App\Attachments\AttachmentService::class);
            $stream  = $service->downloadStream($user, $att->id);

            return response()->stream(function () use ($stream) {
                while (!feof($stream)) { echo fread($stream, 8192); }
                fclose($stream);
            }, 200, [
                'Content-Type'        => $att->mime_type ?: 'application/octet-stream',
                'Content-Disposition' => 'inline; filename="' . addslashes($att->original_name ?: basename($att->storage_path)) . '"',
                'Cache-Control'       => 'no-cache',
            ]);
        } catch (\Throwable $e) {
            \Log::error('Erro ao servir anexo de apontamento', [
                'timesheet_id' => $id,
                'attachment_id'=> $att->id,
                'storage_path' => $att->storage_path,
                'error'        => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Erro ao acessar o anexo.'], 503);
        }
    }

    /**
     * Reprocessa apontamentos do Movidesk: re-busca no Movidesk e tenta corrigir
     * user_id, customer_id e project_id que ficaram como valor padrão.
     *
     * Body: { ids: [1,2,3] }  — se omitido, processa todos com usuário padrão.
     */
    public function reprocessMovidesk(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user->isAdmin() && !$user->isCoordenador()) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        $ids             = $request->input('ids', []);
        $movideskOrigins = ['movidesk', 'webhook', 'movidesk_fallback'];

        // Sem IDs: roda o MESMO fluxo do cron (movidesk:sync) — fetch tickets
        // atualizados desde o último sync, importa novos, atualiza existentes
        // e detecta órfãos. Antes o botão só re-resolvia timesheets do PROJETO
        // PADRÃO (limit 50) — divergia do automático e deixava de pegar tudo.
        if (empty($ids)) {
            try {
                // Invalida cache de departamento Movidesk antes do sync — respeita
                // mudanças recentes (pessoa→organização, dept→empresa).
                $store = \Illuminate\Support\Facades\Cache::getStore();
                if (method_exists($store, 'getRedis')) {
                    $redis = $store->getRedis();
                    $prefix = config('cache.prefix', '');
                    foreach ($redis->keys("{$prefix}*movidesk:dept:parent:*") as $key) {
                        $redis->del($key);
                    }
                }
            } catch (\Throwable $e) {
                // Cache driver sem suporte a keys() — cada entrada expira em 1h.
            }

            // Sync completo processa 100+ tickets e leva minutos — não cabe num
            // request HTTP (nginx tem proxy_read_timeout=120s). Despacha pra
            // fila e retorna imediatamente; o worker (minutor-queue) executa
            // em background e o usuário vê o resultado ao recarregar a tela.
            try {
                \Illuminate\Support\Facades\Artisan::queue('movidesk:sync');
                return response()->json([
                    'message' => 'Reprocessamento iniciado em segundo plano. Os apontamentos serão atualizados em alguns minutos — recarregue a tela em ~3 min.',
                    'updated' => 0,
                    'skipped' => 0,
                    'errors'  => 0,
                    'queued'  => true,
                ]);
            } catch (\Throwable $e) {
                Log::error('[Reprocess Movidesk] Falha ao enfileirar sync', ['error' => $e->getMessage()]);
                return response()->json(['message' => 'Erro ao iniciar sync. Verifique os logs.', 'updated' => 0, 'skipped' => 0, 'errors' => 1], 500);
            }
        }

        // Com IDs: reprocess individual (caso o usuário queira forçar apenas
        // os apontamentos selecionados via UI).
        $timesheets = Timesheet::whereIn('id', array_map('intval', $ids))
            ->whereIn('origin', $movideskOrigins)
            ->whereNotNull('ticket')
            ->whereNotNull('movidesk_appointment_id')
            ->get();

        if ($timesheets->isEmpty()) {
            return response()->json(['message' => 'Nenhum apontamento para reprocessar.', 'updated' => 0, 'skipped' => 0, 'errors' => 0]);
        }

        $service = app(\App\Services\MovideskService::class);
        $updated = 0;
        $skipped = 0;
        $errors  = 0;
        $details = [];

        foreach ($timesheets as $ts) {
            try {
                $result = $service->reprocessTimesheet($ts);
                if ($result['updated'] ?? false) {
                    $updated++;
                    $details[] = ['id' => $ts->id, 'changes' => $result['changes']];
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $errors++;
                Log::error('[Reprocess Movidesk] Erro', ['id' => $ts->id, 'error' => $e->getMessage()]);
            }
        }

        return response()->json([
            'message' => "Reprocessamento concluído: {$updated} atualizado(s), {$skipped} sem alteração, {$errors} erro(s).",
            'updated' => $updated,
            'skipped' => $skipped,
            'errors'  => $errors,
            'details' => $details,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // FASE 11.7 — Helpers da camada Attachment (fonte única).
    // ──────────────────────────────────────────────────────────────────────────

    private function registerTimesheetAttachment(Timesheet $timesheet, array $info): void
    {
        $actor = Auth::user() ?? \App\Models\User::find($timesheet->user_id);
        if (!$actor) {
            throw new \RuntimeException("Não há ator pra registrar attachment do timesheet {$timesheet->id}");
        }
        app(\App\Attachments\AttachmentService::class)->registerExisting($actor, [
            'entity_type'   => 'TIMESHEET',
            'entity_id'     => $timesheet->id,
            'category'      => 'attachment',
            'storage_path'  => $info['path'],
            'original_name' => $info['original'] ?? basename($info['path']),
            'mime_type'     => $info['mime'] ?? 'application/octet-stream',
        ]);
    }

    private function softDeleteTimesheetAttachments(Timesheet $timesheet): void
    {
        try {
            \App\Models\Attachment::query()
                ->forEntity('TIMESHEET', $timesheet->id)
                ->ofCategory('attachment')
                ->whereNull('deleted_at')
                ->get()
                ->each(fn ($att) => $att->delete());
        } catch (\Throwable $e) {
            \Log::warning('TIMESHEET.attachment soft-delete falhou', [
                'timesheet_id' => $timesheet->id, 'error' => $e->getMessage(),
            ]);
        }
    }
}
