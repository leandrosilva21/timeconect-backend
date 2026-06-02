<?php

namespace App\Http\Controllers;

use App\Http\Traits\ListCacheable;
use App\Models\Expense;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * @OA\Tag(
 *     name="Aprovações",
 *     description="Endpoints para gerenciar aprovações de timesheets e despesas"
 * )
 */
class ApprovalController extends Controller
{
    use ListCacheable;

    /**
     * @OA\Get(
     *     path="/api/v1/approvals/pending",
     *     summary="Listar todas as aprovações pendentes do usuário logado",
     *     description="Retorna timesheets e despesas pendentes de aprovação para o usuário logado",
     *     tags={"Aprovações"},
     *     security={{"sanctum": {}}},
     *     @OA\Response(
     *         response=200,
     *         description="Lista de aprovações pendentes"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Não autenticado"
     *     )
     * )
     */
    public function getPendingApprovals(): JsonResponse
    {
        $user = Auth::user();

        try {
            // Buscar timesheets pendentes que o usuário pode aprovar
            $pendingTimesheets = $this->getPendingTimesheetsForUser($user);

            // Buscar despesas pendentes que o usuário pode aprovar
            $pendingExpenses = $this->getPendingExpensesForUser($user);

            return response()->json([
                'success' => true,
                'data' => [
                    'timesheets' => $pendingTimesheets,
                    'expenses' => $pendingExpenses,
                    'summary' => [
                        'total_timesheets' => count($pendingTimesheets),
                        'total_expenses' => count($pendingExpenses),
                        'total_items' => count($pendingTimesheets) + count($pendingExpenses)
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao buscar aprovações pendentes',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/v1/approvals/timesheets",
     *     summary="Listar timesheets pendentes de aprovação",
     *     description="Retorna apenas timesheets pendentes de aprovação para o usuário logado",
     *     tags={"Aprovações"},
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         description="Número da página",
     *         required=false,
     *         @OA\Schema(type="integer", default=1)
     *     ),
     *     @OA\Parameter(
     *         name="per_page",
     *         in="query",
     *         description="Itens por página",
     *         required=false,
     *         @OA\Schema(type="integer", default=15)
     *     ),
     *     @OA\Parameter(
     *         name="customer_id",
     *         in="query",
     *         description="ID do cliente para filtrar",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="project_id",
     *         in="query",
     *         description="ID do projeto para filtrar",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="user_id",
     *         in="query",
     *         description="ID do usuário para filtrar",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="date_from",
     *         in="query",
     *         description="Data inicial (YYYY-MM-DD)",
     *         required=false,
     *         @OA\Schema(type="string", format="date")
     *     ),
     *     @OA\Parameter(
     *         name="date_to",
     *         in="query",
     *         description="Data final (YYYY-MM-DD)",
     *         required=false,
     *         @OA\Schema(type="string", format="date")
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
     *         description="Lista de timesheets pendentes"
     *     )
     * )
     */
    public function getPendingTimesheets(Request $request): JsonResponse
    {
        $user = Auth::user();
        $perPage = $request->get('per_page', 15);

        try {
            $query = $this->buildTimesheetQuery($user, $request);

            $timesheets = $query->paginate($perPage);

            // Total acumulado por ticket (lifetime, mesmo cliente) — coluna "Consumo do Ticket"
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
                // somando apontamentos já soft-deletados.
                $totalsQ = DB::table('timesheets')
                    ->whereNull('deleted_at')
                    ->where('status', '!=', 'rejected')
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
                $initQ = DB::table('ticket_initial_balances')
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
            // Coordenadores de sustentação (fallback p/ projetos sem override/coordenadores).
            $sustentacaoCoordNames = \App\Models\User::where('coordinator_type', 'sustentacao')
                ->where('enabled', true)->pluck('name')->all();

            $items = collect($timesheets->items())->map(function ($ts) use ($ticketTotalsMap, $sustentacaoCoordNames) {
                $arr = $ts->toArray();
                $tk = (string) ($ts->ticket ?? '');
                $arr['ticket_total_minutes'] = ($tk !== '' && preg_match('/^\d{5}$/', $tk) && $ts->customer_id)
                    ? ($ticketTotalsMap[$ts->customer_id . ':' . $tk] ?? null)
                    : null;
                // Coordenador: override do coordenador > (se sustentação) coordenador de
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

            return response()->json([
                'success' => true,
                'data' => $items,
                'pagination' => [
                    'current_page' => $timesheets->currentPage(),
                    'last_page' => $timesheets->lastPage(),
                    'per_page' => $timesheets->perPage(),
                    'total' => $timesheets->total(),
                    'from' => $timesheets->firstItem(),
                    'to' => $timesheets->lastItem()
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao buscar timesheets pendentes',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/v1/approvals/expenses",
     *     summary="Listar despesas pendentes de aprovação",
     *     description="Retorna apenas despesas pendentes de aprovação para o usuário logado",
     *     tags={"Aprovações"},
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         description="Número da página",
     *         required=false,
     *         @OA\Schema(type="integer", default=1)
     *     ),
     *     @OA\Parameter(
     *         name="per_page",
     *         in="query",
     *         description="Itens por página",
     *         required=false,
     *         @OA\Schema(type="integer", default=15)
     *     ),
     *     @OA\Parameter(
     *         name="customer_id",
     *         in="query",
     *         description="ID do cliente para filtrar",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="project_id",
     *         in="query",
     *         description="ID do projeto para filtrar",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="user_id",
     *         in="query",
     *         description="ID do usuário para filtrar",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="date_from",
     *         in="query",
     *         description="Data inicial (YYYY-MM-DD)",
     *         required=false,
     *         @OA\Schema(type="string", format="date")
     *     ),
     *     @OA\Parameter(
     *         name="date_to",
     *         in="query",
     *         description="Data final (YYYY-MM-DD)",
     *         required=false,
     *         @OA\Schema(type="string", format="date")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lista de despesas pendentes"
     *     )
     * )
     */
    public function getPendingExpenses(Request $request): JsonResponse
    {
        $user = Auth::user();
        $perPage = $request->get('per_page', 15);

        try {
            $query = $this->buildExpenseQuery($user, $request);

            $expenses = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $expenses->items(),
                'pagination' => [
                    'current_page' => $expenses->currentPage(),
                    'last_page' => $expenses->lastPage(),
                    'per_page' => $expenses->perPage(),
                    'total' => $expenses->total(),
                    'from' => $expenses->firstItem(),
                    'to' => $expenses->lastItem()
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao buscar despesas pendentes',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/approvals/timesheets/bulk-approve",
     *     summary="Aprovar múltiplos timesheets",
     *     description="Aprova uma lista de timesheets em lote",
     *     tags={"Aprovações"},
     *     security={{"sanctum": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="timesheet_ids",
     *                 type="array",
     *                 @OA\Items(type="integer"),
     *                 description="IDs dos timesheets a serem aprovados"
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Aprovações processadas com sucesso"
     *     )
     * )
     */
    public function bulkApproveTimesheets(Request $request): JsonResponse
    {
        $request->validate([
            'timesheet_ids' => 'required|array|min:1',
            'timesheet_ids.*' => 'integer|exists:timesheets,id'
        ]);

        $user = Auth::user();
        $timesheetIds = $request->get('timesheet_ids');

        $results = [
            'approved' => [],
            'failed' => [],
            'errors' => []
        ];

        DB::beginTransaction();

        try {
            foreach ($timesheetIds as $timesheetId) {
                $timesheet = Timesheet::with(['project'])->find($timesheetId);

                if (!$timesheet) {
                    $results['failed'][] = $timesheetId;
                    $results['errors'][] = "Timesheet $timesheetId não encontrado";
                    continue;
                }

                if ($timesheet->canBeApprovedBy($user)) {
                    if ($timesheet->approve($user)) {
                        $results['approved'][] = $timesheetId;
                    } else {
                        $results['failed'][] = $timesheetId;
                        $results['errors'][] = "Erro ao aprovar timesheet $timesheetId";
                    }
                } else {
                    $results['failed'][] = $timesheetId;
                    $results['errors'][] = "Sem permissão para aprovar timesheet $timesheetId";
                }
            }

            // Resolver conflitos obsoletos para cada usuário/data processado
            foreach (Timesheet::whereIn('id', $timesheetIds)->get()->unique(fn($t) => $t->user_id . '_' . $t->date) as $t) {
                Timesheet::resolveStaleConflicts($t->user_id, $t->date);
            }

            DB::commit();
            $this->invalidateListCache('timesheets');

            return response()->json([
                'success' => true,
                'message' => sprintf(
                    'Processamento concluído. %d aprovados, %d falharam',
                    count($results['approved']),
                    count($results['failed'])
                ),
                'data' => $results
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Erro ao processar aprovações em lote',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function bulkRejectTimesheets(Request $request): JsonResponse
    {
        $request->validate([
            'timesheet_ids' => 'required|array|min:1',
            'timesheet_ids.*' => 'integer|exists:timesheets,id',
            'reason' => 'required|string|min:1|max:1000',
        ]);

        $user = Auth::user();
        $timesheetIds = $request->get('timesheet_ids');
        $reason = $request->get('reason', '');
        $results = ['rejected' => [], 'failed' => [], 'errors' => []];

        DB::beginTransaction();
        try {
            foreach ($timesheetIds as $timesheetId) {
                $timesheet = Timesheet::find($timesheetId);
                if (!$timesheet) {
                    $results['failed'][] = $timesheetId;
                    $results['errors'][] = "Timesheet $timesheetId não encontrado";
                    continue;
                }
                if ($timesheet->reject($user, $reason)) {
                    $results['rejected'][] = $timesheetId;
                } else {
                    $results['failed'][] = $timesheetId;
                    $results['errors'][] = "Sem permissão ou erro ao rejeitar timesheet $timesheetId";
                }
            }

            // Resolver conflitos obsoletos para cada usuário/data processado
            foreach (Timesheet::whereIn('id', $timesheetIds)->get()->unique(fn($t) => $t->user_id . '_' . $t->date) as $t) {
                Timesheet::resolveStaleConflicts($t->user_id, $t->date);
            }

            DB::commit();
            $this->invalidateListCache('timesheets');
            return response()->json([
                'success' => true,
                'message' => sprintf('%d rejeitados, %d falharam', count($results['rejected']), count($results['failed'])),
                'data' => $results,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Erro ao rejeitar em lote', 'error' => $e->getMessage()], 500);
        }
    }

    public function bulkRequestAdjustmentTimesheets(Request $request): JsonResponse
    {
        $request->validate([
            'timesheet_ids' => 'required|array|min:1',
            'timesheet_ids.*' => 'integer|exists:timesheets,id',
            'reason' => 'nullable|string|max:1000',
        ]);

        $user = Auth::user();
        $timesheetIds = $request->get('timesheet_ids');
        $reason = $request->get('reason', '');
        $results = ['requested' => [], 'failed' => [], 'errors' => []];

        DB::beginTransaction();
        try {
            foreach ($timesheetIds as $timesheetId) {
                $timesheet = Timesheet::find($timesheetId);
                if (!$timesheet) {
                    $results['failed'][] = $timesheetId;
                    $results['errors'][] = "Timesheet $timesheetId não encontrado";
                    continue;
                }
                if ($timesheet->requestAdjustment($user, $reason)) {
                    $results['requested'][] = $timesheetId;
                } else {
                    $results['failed'][] = $timesheetId;
                    $results['errors'][] = "Sem permissão ou erro ao solicitar ajuste no timesheet $timesheetId";
                }
            }
            DB::commit();
            $this->invalidateListCache('timesheets');
            return response()->json([
                'success' => true,
                'message' => sprintf('%d ajustes solicitados, %d falharam', count($results['requested']), count($results['failed'])),
                'data' => $results,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Erro ao solicitar ajustes em lote', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/approvals/expenses/bulk-approve",
     *     summary="Aprovar múltiplas despesas",
     *     description="Aprova uma lista de despesas em lote",
     *     tags={"Aprovações"},
     *     security={{"sanctum": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="expense_ids",
     *                 type="array",
     *                 @OA\Items(type="integer"),
     *                 description="IDs das despesas a serem aprovadas"
     *             ),
     *             @OA\Property(
     *                 property="charge_client",
     *                 type="boolean",
     *                 description="Se deve cobrar do cliente (aplicado a todas)",
     *                 default=false
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Aprovações processadas com sucesso"
     *     )
     * )
     */
    public function bulkApproveExpenses(Request $request): JsonResponse
    {
        $request->validate([
            'expense_ids' => 'required|array|min:1',
            'expense_ids.*' => 'integer|exists:expenses,id',
            'charge_client' => 'boolean'
        ]);

        $user = Auth::user();
        $expenseIds = $request->get('expense_ids');
        $chargeClient = $request->boolean('charge_client', false);

        $results = [
            'approved' => [],
            'failed' => [],
            'errors' => []
        ];

        DB::beginTransaction();

        try {
            foreach ($expenseIds as $expenseId) {
                $expense = Expense::with(['project'])->find($expenseId);

                if (!$expense) {
                    $results['failed'][] = $expenseId;
                    $results['errors'][] = "Despesa $expenseId não encontrada";
                    continue;
                }

                // Administradores podem aprovar qualquer despesa
                if ($user->isAdmin() || $expense->canBeApprovedBy($user)) {
                    if ($expense->approve($user, $chargeClient)) {
                        $results['approved'][] = $expenseId;
                    } else {
                        $results['failed'][] = $expenseId;
                        $results['errors'][] = "Erro ao aprovar despesa $expenseId";
                    }
                } else {
                    $results['failed'][] = $expenseId;
                    $results['errors'][] = "Sem permissão para aprovar despesa $expenseId";
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => sprintf(
                    'Processamento concluído. %d aprovadas, %d falharam',
                    count($results['approved']),
                    count($results['failed'])
                ),
                'data' => $results
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Erro ao processar aprovações em lote',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Busca timesheets pendentes que o usuário pode aprovar
     */
    private function getPendingTimesheetsForUser(User $user): array
    {
        return $this->buildTimesheetQuery($user, null)->limit(50)->get()->toArray();
    }

    /**
     * Busca despesas pendentes que o usuário pode aprovar
     */
    private function getPendingExpensesForUser(User $user): array
    {
        return $this->buildExpenseQuery($user, null)->limit(50)->get()->toArray();
    }

    /**
     * Constrói query para timesheets pendentes
     */
    private function buildTimesheetQuery(User $user, ?Request $request = null)
    {
        $query = Timesheet::with([
            'user:id,name,email',
            'customer:id,name',
            'project:id,name,customer_id,service_type_id,kanban_coordinator_override_id',
            'project.customer:id,name,executive_id',
            'project.customer.executive:id,name',
            'project.serviceType:id,name,code',
            'project.coordinators:id,name',
            'project.kanbanOverrideCoordinator:id,name',
        ])
        ->where('status', Timesheet::STATUS_PENDING);

        // Portal de Sustentação: restringe aos projetos elegíveis (respeita override de coord).
        if ($request && $request->get('scope') === 'sustentacao') {
            $scopedIds = app(\App\Services\SustentacaoScopeService::class)->projectIds();
            if (empty($scopedIds)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('project_id', $scopedIds);
            }
        }

        // Coordenador de SUSTENTAÇÃO: regra de perfil — só vê fila de sustentacao/cloud
        // (não é flexível, é definição do perfil). Para coord-projetos (default) NÃO
        // forçamos filtro: o FE controla o escopo via chip "Meus projetos / Todos"
        // mandando `coordinator_id` quando o coord quer ver só os dele — mesmo padrão
        // que Apontamentos/Despesas (PRs #36 / #33). Middleware
        // `permission.or.admin:timesheets.approve` continua bloqueando perfis sem acesso.
        if (!$user->isAdmin() && $user->isCoordenador() && $user->coordinator_type === 'sustentacao') {
            $query->whereHas('project.serviceType', fn ($q) => $q->whereIn('code', ['sustentacao', 'cloud']));
        }

        // Aplicar filtros se fornecidos
        if ($request) {
            $this->applyTimesheetFilters($query, $request);
        }

        $this->applyTimesheetOrder($query, $request);

        return $query;
    }

    /**
     * Ordenação configurável da fila de apontamentos (param `order`, prefixo "-" = desc).
     * Relações ordenadas por subquery (sem join — evita ambiguidade com os filtros whereHas).
     * Sem `order` = comportamento atual (data desc, depois inclusão desc).
     */
    private function applyTimesheetOrder($query, ?Request $request): void
    {
        $order = $request?->get('order');
        if (!$order) {
            $query->orderBy('date', 'desc')->orderBy('created_at', 'desc');
            return;
        }
        $dir   = str_starts_with($order, '-') ? 'desc' : 'asc';
        $field = ltrim($order, '-');
        $direct = [
            'date'           => 'date',
            'start_time'     => 'start_time',
            'end_time'       => 'end_time',
            'effort_minutes' => 'effort_minutes',
            'created_at'     => 'created_at',
            'ticket'         => 'ticket',
            'status'         => 'status',
            'title'          => 'title',
        ];
        if (isset($direct[$field])) {
            $query->orderBy($direct[$field], $dir);
        } elseif ($field === 'user.name') {
            $query->orderBy(\App\Models\User::select('name')->whereColumn('users.id', 'timesheets.user_id')->limit(1), $dir);
        } elseif ($field === 'customer.name') {
            $query->orderBy(\App\Models\Customer::select('name')->whereColumn('customers.id', 'timesheets.customer_id')->limit(1), $dir);
        } elseif ($field === 'project.name') {
            $query->orderBy(\App\Models\Project::withoutGlobalScopes()->select('name')->whereColumn('projects.id', 'timesheets.project_id')->limit(1), $dir);
        } else {
            $query->orderBy('date', 'desc')->orderBy('created_at', 'desc');
            return;
        }
        $query->orderBy('created_at', 'desc'); // desempate estável
    }

    /**
     * Constrói query para despesas pendentes
     */
    private function buildExpenseQuery(User $user, ?Request $request = null)
    {
        $query = Expense::with([
            'user:id,name,email',
            'project:id,name,customer_id,service_type_id',
            'project.customer:id,name',
            'project.serviceType:id,name',
            'category:id,name,parent_id'
        ])
        ->where('status', Expense::STATUS_PENDING);

        // Portal de Sustentação: restringe aos projetos elegíveis (respeita override de coord).
        if ($request && $request->get('scope') === 'sustentacao') {
            $scopedIds = app(\App\Services\SustentacaoScopeService::class)->projectIds();
            if (empty($scopedIds)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('project_id', $scopedIds);
            }
        }

        // Coordenador de SUSTENTAÇÃO: ver comentário em buildTimesheetQuery (mesmo padrão).
        // Coord-projetos sem filtro forçado; FE controla via chip "Meus projetos / Todos".
        if (!$user->isAdmin() && $user->isCoordenador() && $user->coordinator_type === 'sustentacao') {
            $query->whereHas('project.serviceType', fn ($q) => $q->whereIn('code', ['sustentacao', 'cloud']));
        }

        // Aplicar filtros se fornecidos
        if ($request) {
            $this->applyExpenseFilters($query, $request);
        }

        $this->applyExpenseOrder($query, $request);

        return $query;
    }

    /**
     * Ordenação configurável da fila de despesas (param `order`, prefixo "-" = desc).
     * Sem `order` = comportamento atual (data da despesa desc, depois inclusão desc).
     */
    private function applyExpenseOrder($query, ?Request $request): void
    {
        $order = $request?->get('order');
        if (!$order) {
            $query->orderBy('expense_date', 'desc')->orderBy('created_at', 'desc');
            return;
        }
        $dir   = str_starts_with($order, '-') ? 'desc' : 'asc';
        $field = ltrim($order, '-');
        $direct = [
            'date'         => 'expense_date',
            'expense_date' => 'expense_date',
            'amount'       => 'amount',
            'created_at'   => 'created_at',
            'status'       => 'status',
        ];
        if (isset($direct[$field])) {
            $query->orderBy($direct[$field], $dir);
        } elseif ($field === 'user.name') {
            $query->orderBy(\App\Models\User::select('name')->whereColumn('users.id', 'expenses.user_id')->limit(1), $dir);
        } elseif ($field === 'project.name') {
            $query->orderBy(\App\Models\Project::withoutGlobalScopes()->select('name')->whereColumn('projects.id', 'expenses.project_id')->limit(1), $dir);
        } elseif ($field === 'category.name') {
            $query->orderBy(\App\Models\ExpenseCategory::select('name')->whereColumn('expense_categories.id', 'expenses.expense_category_id')->limit(1), $dir);
        } else {
            $query->orderBy('expense_date', 'desc')->orderBy('created_at', 'desc');
            return;
        }
        $query->orderBy('created_at', 'desc'); // desempate estável
    }

    /**
     * Aplica filtros na query de timesheets
     */
    private function applyTimesheetFilters($query, Request $request): void
    {
        // Filtro por cliente
        if ($request->filled('customer_id')) {
            $query->whereHas('project', function ($q) use ($request) {
                $q->where('customer_id', $request->get('customer_id'));
            });
        }

        // Filtro por executivo responsável do cliente
        if ($request->filled('executive_id')) {
            $query->whereHas('project.customer', function ($q) use ($request) {
                $q->where('executive_id', $request->get('executive_id'));
            });
        }

        // Filtro por projeto
        if ($request->filled('project_id')) {
            $query->where('project_id', $request->get('project_id'));
        }

        // Filtro por ticket (Movidesk)
        if ($request->filled('ticket')) {
            $query->where('ticket', trim((string) $request->get('ticket')));
        }

        // Filtro por usuário (colaborador)
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->get('user_id'));
        }

        // Filtro por coordenador do projeto
        if ($request->filled('coordinator_id')) {
            $query->whereHas('project.coordinators', function ($q) use ($request) {
                $q->where('users.id', $request->get('coordinator_id'));
            });
        }

        // Filtro por tipo de serviço
        if ($request->filled('service_type_id')) {
            $query->whereHas('project', function ($q) use ($request) {
                $q->where('service_type_id', $request->get('service_type_id'));
            });
        }

        // Filtro por categoria de serviço (chips coloridos no frontend)
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

        // Filtro por data (período)
        if ($request->filled('date_from')) {
            $query->where('date', '>=', $request->get('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('date', '<=', $request->get('date_to'));
        }
    }

    /**
     * Aplica filtros na query de despesas
     */
    private function applyExpenseFilters($query, Request $request): void
    {
        // Filtro por cliente
        if ($request->filled('customer_id')) {
            $query->whereHas('project', function ($q) use ($request) {
                $q->where('customer_id', $request->get('customer_id'));
            });
        }

        // Filtro por executivo responsável do cliente
        if ($request->filled('executive_id')) {
            $query->whereHas('project.customer', function ($q) use ($request) {
                $q->where('executive_id', $request->get('executive_id'));
            });
        }

        // Filtro por projeto
        if ($request->filled('project_id')) {
            $query->where('project_id', $request->get('project_id'));
        }

        // Filtro por usuário (colaborador)
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->get('user_id'));
        }

        // Filtro por coordenador do projeto
        if ($request->filled('coordinator_id')) {
            $query->whereHas('project.coordinators', function ($q) use ($request) {
                $q->where('users.id', $request->get('coordinator_id'));
            });
        }

        // Filtro por categoria de serviço (chips coloridos no frontend)
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

        // Filtro por data (período)
        if ($request->filled('date_from')) {
            $query->where('expense_date', '>=', $request->get('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('expense_date', '<=', $request->get('date_to'));
        }
    }
}
