<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseType;
use App\Models\Project;
use App\Http\Traits\ResponseHelpers;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use App\Exports\ExpensesExport;
use Maatwebsite\Excel\Facades\Excel;

/**
 * @OA\Schema(
 *     schema="Expense",
 *     type="object",
 *     title="Expense",
 *     description="Modelo de despesa",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="user_id", type="integer", example=1),
 *     @OA\Property(property="project_id", type="integer", example=1),
 *     @OA\Property(property="expense_category_id", type="integer", example=1),
 *     @OA\Property(property="expense_date", type="string", format="date", example="2024-01-15"),
 *     @OA\Property(property="description", type="string", example="Táxi para visita ao cliente XPTO"),
 *     @OA\Property(property="amount", type="number", format="decimal", example=78.90),
 *     @OA\Property(property="expense_type", type="string", example="reimbursement", description="Código do tipo de despesa (deve existir na tabela expense_types)"),
 *     @OA\Property(property="payment_method", type="string", description="Código do método de pagamento (deve existir na tabela payment_methods e estar ativo)", example="cash"),
 *     @OA\Property(property="receipt_path", type="string", nullable=true, example="receipts/2024/01/receipt123.pdf"),
 *     @OA\Property(property="receipt_original_name", type="string", nullable=true, example="comprovante_taxi.pdf"),
 *     @OA\Property(property="status", type="string", enum={"pending", "approved", "rejected", "adjustment_requested"}, example="pending"),
 *     @OA\Property(property="status_display", type="string", example="Pendente"),
 *     @OA\Property(property="expense_type_display", type="string", example="Reembolso"),
 *     @OA\Property(property="payment_method_display", type="string", example="Dinheiro"),
 *     @OA\Property(property="formatted_amount", type="string", example="R$ 78,90"),
 *     @OA\Property(property="receipt_url", type="string", nullable=true, example="http://localhost/storage/receipts/2024/01/receipt123.pdf"),
 *     @OA\Property(property="rejection_reason", type="string", nullable=true, example="Comprovante ilegível"),
 *     @OA\Property(property="charge_client", type="boolean", example=false),
 *     @OA\Property(property="reviewed_by", type="integer", nullable=true, example=2),
 *     @OA\Property(property="reviewed_at", type="string", format="datetime", nullable=true, example="2024-01-16T10:30:00Z"),
 *     @OA\Property(property="created_at", type="string", format="datetime", example="2024-01-15T09:00:00Z"),
 *     @OA\Property(property="updated_at", type="string", format="datetime", example="2024-01-15T09:00:00Z"),
 * )
 */
class ExpenseController extends Controller
{
    use ResponseHelpers;
    use \App\Http\Traits\ListCacheable;

    /**
     * Retorna o limite diário efetivo de despesas para um projeto.
     * Prioridade: project.max_expense_per_consultant > contract.limite_despesa > parent.max_expense_per_consultant > parent.contract.limite_despesa
     * Retorna null se ilimitado.
     */
    private function getEffectiveDailyLimit(Project $project): ?float
    {
        // 1. Limite explícito no projeto tem prioridade máxima
        if ($project->max_expense_per_consultant !== null && (float) $project->max_expense_per_consultant > 0) {
            return (float) $project->max_expense_per_consultant;
        }
        // 2. Checar se é explicitamente ilimitado
        if ($project->unlimited_expense) return null;
        // 3. Limite definido no contrato vinculado ao projeto
        if ($project->contract_id) {
            $contract = $project->contract ?? \App\Models\Contract::find($project->contract_id);
            if ($contract && $contract->limite_despesa !== null && (float) $contract->limite_despesa > 0) {
                return (float) $contract->limite_despesa;
            }
        }
        // 4. Herdar do projeto pai
        if ($project->parent_project_id) {
            $parent = Project::find($project->parent_project_id);
            if ($parent) {
                if ($parent->max_expense_per_consultant !== null && (float) $parent->max_expense_per_consultant > 0) {
                    return (float) $parent->max_expense_per_consultant;
                }
                if ($parent->unlimited_expense) return null;
                if ($parent->contract_id) {
                    $parentContract = $parent->contract ?? \App\Models\Contract::find($parent->contract_id);
                    if ($parentContract && $parentContract->limite_despesa !== null && (float) $parentContract->limite_despesa > 0) {
                        return (float) $parentContract->limite_despesa;
                    }
                }
            }
        }
        return null;
    }

    private function checkDailyLimit(Project $project, int $userId, float $newAmount, string $expenseDate, ?int $excludeExpenseId = null): ?JsonResponse
    {
        $maxLimit = $this->getEffectiveDailyLimit($project);
        if ($maxLimit === null) return null;

        $query = Expense::where('project_id', $project->id)
            ->where('user_id', $userId)
            ->whereDate('expense_date', $expenseDate)
            ->whereNotIn('status', ['rejected']);
        if ($excludeExpenseId) $query->where('id', '!=', $excludeExpenseId);
        $accumulated = (float) $query->sum('amount');
        $totalAfter  = $accumulated + $newAmount;

        if ($totalAfter > $maxLimit) {
            return $this->businessRuleResponse(
                'EXPENSE_AMOUNT_EXCEEDED',
                'Limite diário de despesas excedido',
                sprintf(
                    'O limite diário de despesas neste projeto é R$ %s. Já registrado no dia: R$ %s. Disponível: R$ %s.',
                    number_format($maxLimit, 2, ',', '.'),
                    number_format($accumulated, 2, ',', '.'),
                    number_format(max(0, $maxLimit - $accumulated), 2, ',', '.')
                )
            );
        }
        return null;
    }

    /**
     * @OA\Get(
     *     path="/api/v1/expenses",
     *     summary="Listar despesas",
     *     description="Lista despesas com paginação, filtros e ordenação seguindo padrões PO-UI",
     *     operationId="getExpenses",
     *     tags={"Expenses"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         description="Número da página",
     *         required=false,
     *         @OA\Schema(type="integer", default=1)
     *     ),
     *     @OA\Parameter(
     *         name="pageSize",
     *         in="query",
     *         description="Itens por página",
     *         required=false,
     *         @OA\Schema(type="integer", default=20)
     *     ),
     *     @OA\Parameter(
     *         name="order",
     *         in="query",
     *         description="Ordenação (ex: expense_date,-amount)",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         description="Busca na descrição",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
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
     *         description="Filtrar por cliente do projeto",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="user_id",
     *         in="query",
     *         description="Filtrar por usuário solicitante",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         description="Filtrar por status",
     *         required=false,
     *         @OA\Schema(type="string", enum={"pending", "approved", "rejected", "adjustment_requested"})
     *     ),
     *     @OA\Parameter(
     *         name="expense_type",
     *         in="query",
     *         description="Filtrar por tipo de despesa",
     *         required=false,
     *         @OA\Schema(type="string", enum={"corporate_card", "reimbursement"})
     *     ),
     *     @OA\Parameter(
     *         name="charge_client",
     *         in="query",
     *         description="Filtrar por se será cobrado do cliente (true/false)",
     *         required=false,
     *         @OA\Schema(type="boolean")
     *     ),
     *     @OA\Parameter(
     *         name="category_id",
     *         in="query",
     *         description="Filtrar por categoria",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="start_date",
     *         in="query",
     *         description="Data inicial (YYYY-MM-DD)",
     *         required=false,
     *         @OA\Schema(type="string", format="date")
     *     ),
     *     @OA\Parameter(
     *         name="end_date",
     *         in="query",
     *         description="Data final (YYYY-MM-DD)",
     *         required=false,
     *         @OA\Schema(type="string", format="date")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lista de despesas",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="hasNext", type="boolean"),
     *             @OA\Property(property="items", type="array", @OA\Items(ref="#/components/schemas/Expense"))
     *         )
     *     )
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        $pageSize = min((int) $request->get('pageSize', 20), 100);
        $page = (int) $request->get('page', 1);

        $query = Expense::with(['user', 'project.customer', 'project.contractType', 'project.serviceType', 'project.coordinators:id,name', 'category', 'reviewedBy']);

        // Portal de Sustentação: restringe aos projetos elegíveis (respeita override de coord).
        if ($request->get('scope') === 'sustentacao') {
            $scopedIds = app(\App\Services\SustentacaoScopeService::class)->projectIds();
            if (empty($scopedIds)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('expenses.project_id', $scopedIds);
            }
        }

        // Controle de visibilidade por perfil
        if ($user->isCliente()) {
            // Cliente vê apenas despesas do seu cliente, somente pendentes e aprovadas
            $query->whereHas('project', function ($q) use ($user) {
                $q->where('customer_id', $user->customer_id);
            })->whereNotIn('expenses.status', ['rejected', 'adjustment_requested']);
        } elseif ($user->type === 'parceiro_admin' && $request->boolean('team_view') && $user->partner_id) {
            $partnerUserIds = \App\Models\User::where('partner_id', $user->partner_id)->pluck('id');
            $query->whereIn('expenses.user_id', $partnerUserIds);
        } elseif (!$user->isAdmin() && !$user->hasAccess('expenses.view_all')) {
            $query->where('user_id', $user->id);
        } elseif ($user->isCoordenador()) {
            $coordinatorProjectIds = $user->coordinatorProjects()->pluck('projects.id');
            if ($user->coordinator_type === 'sustentacao') {
                // Sustentação: apenas despesas de projetos com tipo sustentacao ou cloud
                $query->whereHas('project.serviceType', fn($q) => $q->whereIn('code', ['sustentacao', 'cloud']));
            } else {
                $query->whereIn('expenses.project_id', $coordinatorProjectIds);
            }
        }

        // Filtros
        if ($request->filled('contract_type_id')) {
            $query->whereHas('project', function ($q) use ($request) {
                $q->where('contract_type_id', $request->contract_type_id);
            });
        }

        if ($request->filled('search')) {
            $query->where('description', 'ilike', '%' . $request->search . '%');
        }

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->project_id);
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

        $customerIds = array_values(array_filter((array) $request->input('customer_id', [])));
        if (!empty($customerIds)) {
            $query->whereHas('project', function ($q) use ($customerIds) {
                $q->whereIn('customer_id', $customerIds);
            });
        }

        $executiveIds = array_values(array_filter((array) $request->input('executive_id', [])));
        if (!empty($executiveIds)) {
            $query->whereHas('project.customer', function ($q) use ($executiveIds) {
                $q->whereIn('executive_id', $executiveIds);
            });
        }

        $filterUserIds = array_values(array_filter((array) $request->input('user_id', [])));
        if (!empty($filterUserIds)) {
            $query->whereIn('user_id', $filterUserIds);
        }

        // Filtro por coordenador (despesas de projetos coordenados pelo usuário escolhido).
        if ($request->filled('coordinator_id')) {
            $query->whereHas('project.coordinators', fn ($q) => $q->where('users.id', $request->coordinator_id));
        }

        // has_partner=1 → só usuários de parceiro ("No Fechamento"); =0 → só não-parceiro
        // ("A pagar" avulso); ausente → todos.
        if ($request->has('has_partner') && $request->input('has_partner') !== '') {
            $request->boolean('has_partner')
                ? $query->whereHas('user', fn ($q) => $q->whereNotNull('partner_id'))
                : $query->whereHas('user', fn ($q) => $q->whereNull('partner_id'));
        }

        if ($request->filled('status')) {
            // Aceita status único (?status=pending) ou múltiplos (?status[]=pending&status[]=rejected).
            $statusVal = $request->status;
            is_array($statusVal)
                ? $query->whereIn('status', $statusVal)
                : $query->where('status', $statusVal);
        }

        if ($request->filled('expense_type')) {
            $query->where('expense_type', $request->expense_type);
        }

        if ($request->has('charge_client')) {
            // Trata tanto string 'true'/'false' quanto boolean
            $chargeClientValue = $request->input('charge_client');

            // Converte para boolean
            if (is_string($chargeClientValue)) {
                $chargeClient = strtolower($chargeClientValue) === 'true' || $chargeClientValue === '1';
            } else {
                $chargeClient = (bool) $chargeClientValue;
            }

            $query->where('charge_client', $chargeClient);
        }

        if ($request->filled('category_id')) {
            $query->where('expense_category_id', $request->category_id);
        }

        if ($request->has('is_paid') && $request->input('is_paid') !== '') {
            $query->where('is_paid', filter_var($request->input('is_paid'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('expense_date', [$request->start_date, $request->end_date]);
        }

        // Ordenação padrão PO-UI
        $orderFields = $request->get('order', '-expense_date');
        if ($orderFields) {
            $fields = explode(',', $orderFields);
            foreach ($fields as $field) {
                $direction = 'asc';
                if (str_starts_with($field, '-')) {
                    $direction = 'desc';
                    $field = substr($field, 1);
                }

                $allowedFields = ['expense_date', 'amount', 'status', 'created_at', 'updated_at'];
                if (in_array($field, $allowedFields)) {
                    $query->orderBy($field, $direction);
                }
            }
        }

        try {
            $result = $this->cachedList($request, 'expenses', function () use ($query, $pageSize, $page) {
                $expenses = $query->paginate($pageSize, ['*'], 'page', $page);
                return [
                    'hasNext' => $expenses->hasMorePages(),
                    'items'   => $expenses->items(),
                ];
            });
            return response()->json($result);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ExpenseController@index error', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);
            return response()->json(['error' => 'Erro ao listar despesas', 'details' => $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine()], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/expenses",
     *     summary="Criar nova despesa",
     *     description="Cria uma nova despesa com upload opcional de comprovante",
     *     operationId="createExpense",
     *     tags={"Expenses"},
     *     security={{"sanctum":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 type="object",
     *                 required={"project_id", "expense_category_id", "expense_date", "description", "amount", "expense_type", "payment_method"},
     *                 @OA\Property(property="user_id", type="integer", nullable=true, example=1, description="ID do usuário (opcional - apenas para administradores)"),
     *                 @OA\Property(property="project_id", type="integer", example=1),
     *                 @OA\Property(property="expense_category_id", type="integer", example=1),
     *                 @OA\Property(property="expense_date", type="string", format="date", example="2024-01-15"),
     *                 @OA\Property(property="description", type="string", example="Táxi para visita ao cliente"),
     *                 @OA\Property(property="amount", type="number", format="decimal", example=78.90),
                 *                 @OA\Property(property="expense_type", type="string", example="reimbursement", description="Código do tipo de despesa (deve existir na tabela expense_types)"),
                 *                 @OA\Property(property="payment_method", type="string", description="Código do método de pagamento (deve existir na tabela payment_methods e estar ativo)", example="cash"),
     *                 @OA\Property(property="receipt", type="string", format="binary", description="Arquivo de comprovante (opcional)")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Despesa criada com sucesso",
     *         @OA\JsonContent(ref="#/components/schemas/Expense")
     *     )
     * )
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'nullable|exists:users,id', // Opcional - apenas para administradores
            'project_id' => 'required|exists:projects,id',
            'expense_category_id' => 'required|exists:expense_categories,id',
            'expense_date' => 'required|date',
            'description' => 'required|string|max:1000',
            'amount' => 'required|numeric|min:0.01|max:999999.99',
            'expense_type' => ['nullable', 'string'],
            'payment_method' => ['nullable', 'string'],
            'receipt' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120', // 5MB max
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors()->all());
        }

        $project = Project::find($request->project_id);
        $user = Auth::user();

        // Clientes não podem registrar despesas
        if ($user->isCliente()) {
            return $this->accessDeniedResponse('Usuários com perfil Cliente não podem registrar despesas.');
        }

        // Verificar se o projeto existe e permite novos lançamentos (paused ainda permite)
        if (!$project || !$project->isOpen()) {
            return response()->json([
                'success' => false,
                'message' => 'Não é possível lançar despesas em projetos com status ' . ($project ? '"' . $project->status_display . '"' : 'inativo') . '.',
            ], 422);
        }

        // Determinar o usuário alvo da despesa
        $canActAsUser  = $user->isAdmin() || $user->isCoordenador();
        $isParceiroAdm = $user->isParceiroAdmin() && $user->partner_id;

        if (!empty($request->user_id) && ($canActAsUser || $isParceiroAdm)) {
            // parceiro_admin só pode agir por membros da própria equipe
            if ($isParceiroAdm && !$canActAsUser) {
                $inTeam = \App\Models\User::where('id', $request->user_id)
                    ->where('partner_id', $user->partner_id)
                    ->exists();
                if (!$inTeam) {
                    return $this->accessDeniedResponse('Usuário não pertence à sua equipe.');
                }
            }
            $targetUserId = $request->user_id;
        } else {
            $targetUserId = $user->id;
        }

        if (!$canActAsUser && !$isParceiroAdm && !$project->isUserConsultant($targetUserId)) {
            return $this->accessDeniedResponse('O usuário não tem acesso a este projeto');
        }

        // Validar limite diário do usuário alvo (admin pode selecionar qualquer usuário, mas o limite do usuário alvo ainda se aplica)
        $limitError = $this->checkDailyLimit(
            $project,
            $targetUserId,
            (float) $validator->validated()['amount'],
            $validator->validated()['expense_date']
        );
        if ($limitError) return $limitError;

        $expenseData = $validator->validated();

        // Definir o user_id final baseado nas permissões
        $expenseData['user_id'] = $targetUserId;
        $expenseData['status'] = Expense::STATUS_PENDING;
        $expenseData['expense_type']   = $expenseData['expense_type']   ?? 'reimbursement';
        $expenseData['payment_method'] = $expenseData['payment_method'] ?? 'pix';

        // Upload do comprovante se fornecido
        if ($request->hasFile('receipt')) {
            $file = $request->file('receipt');
            $filename = time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('receipts/' . date('Y/m'), $filename, 'public');

            $expenseData['receipt_path'] = $path;
            $expenseData['receipt_original_name'] = $file->getClientOriginalName();
        }

        $expense = Expense::create($expenseData);
        $expense->load(['user', 'project.customer', 'category', 'reviewedBy', 'reversals.reversedBy', 'reversals.originalApprover']);
        $this->invalidateListCache('expenses');

        return response()->json($expense, 201);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/expenses/{id}",
     *     summary="Visualizar despesa específica",
     *     description="Retorna detalhes de uma despesa específica",
     *     operationId="getExpense",
     *     tags={"Expenses"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID da despesa",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Detalhes da despesa",
     *         @OA\JsonContent(ref="#/components/schemas/Expense")
     *     )
     * )
     */
    public function show(int $id): JsonResponse
    {
        $user = Auth::user();

        $expense = Expense::with(['user', 'project.customer', 'category', 'reviewedBy', 'reversals.reversedBy', 'reversals.originalApprover'])->find($id);

        if (!$expense) {
            return $this->notFoundResponse('Despesa não encontrada');
        }

        // Verificar se o usuário pode visualizar esta despesa
        $canView = $user->isAdmin() ||
                   $user->hasAccess('expenses.view_all') ||
                   $expense->user_id === $user->id;

        if (!$canView) {
            return $this->accessDeniedResponse('Você não tem permissão para visualizar esta despesa');
        }

        return response()->json($expense);
    }

    /**
     * @OA\Put(
     *     path="/api/v1/expenses/{id}",
     *     summary="Atualizar despesa",
     *     description="Atualiza uma despesa existente (apenas se pendente ou com ajuste solicitado)",
     *     operationId="updateExpense",
     *     tags={"Expenses"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID da despesa",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 type="object",
     *                 @OA\Property(property="user_id", type="integer", nullable=true, example=1, description="ID do usuário (opcional - apenas para administradores)"),
     *                 @OA\Property(property="project_id", type="integer", example=1),
     *                 @OA\Property(property="expense_category_id", type="integer", example=1),
     *                 @OA\Property(property="expense_date", type="string", format="date", example="2024-01-15"),
     *                 @OA\Property(property="description", type="string", example="Táxi para visita ao cliente"),
     *                 @OA\Property(property="amount", type="number", format="decimal", example=78.90),
                 *                 @OA\Property(property="expense_type", type="string", example="reimbursement", description="Código do tipo de despesa (deve existir na tabela expense_types)"),
                 *                 @OA\Property(property="payment_method", type="string", description="Código do método de pagamento (deve existir na tabela payment_methods e estar ativo)", example="cash"),
     *                 @OA\Property(property="receipt", type="string", format="binary", description="Novo arquivo de comprovante (opcional)")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Despesa atualizada com sucesso",
     *         @OA\JsonContent(ref="#/components/schemas/Expense")
     *     )
     * )
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = Auth::user();
        $expense = Expense::find($id);

        if (!$expense) {
            return $this->notFoundResponse('Despesa não encontrada');
        }

        // Verificar se pode editar (só o próprio usuário ou admin)
        if (!$user->isAdmin() && $expense->user_id !== $user->id) {
            return $this->accessDeniedResponse('Você só pode editar suas próprias despesas');
        }

        // Verificar se a despesa pode ser editada
        if (!$expense->canBeEdited()) {
            return $this->businessRuleResponse(
                'EXPENSE_NOT_EDITABLE',
                'Despesa não pode ser editada',
                'Apenas despesas pendentes, rejeitadas ou com ajuste solicitado podem ser editadas'
            );
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'sometimes|exists:users,id', // Apenas para administradores
            'project_id' => 'sometimes|exists:projects,id',
            'expense_category_id' => 'sometimes|exists:expense_categories,id',
            'expense_date' => 'sometimes|date',
            'description' => 'sometimes|string|max:1000',
            'amount' => 'sometimes|numeric|min:0.01|max:999999.99',
            'expense_type' => ['nullable', 'string'],
            'payment_method' => ['nullable', 'string'],
            'receipt' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors()->all());
        }

        $updateData = $validator->validated();

        // Apenas administradores podem alterar o user_id
        if (isset($updateData['user_id']) && !$user->isAdmin()) {
            unset($updateData['user_id']);
        }

        // Verificar acesso ao novo projeto se alterado
        if (isset($updateData['project_id'])) {
            $project = Project::find($updateData['project_id']);
            if (!$project || !$project->isOpen()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Não é possível lançar despesas em projetos com status ' . ($project ? '"' . $project->status_display . '"' : 'inativo') . '.',
                ], 422);
            }
            if (!$user->isAdmin() && !$project->consultants()->where('user_id', $user->id)->exists()) {
                return $this->accessDeniedResponse('Você não tem acesso ao projeto selecionado');
            }
        } else {
            // Se não está alterando projeto, usar o projeto atual
            $project = $expense->project;
        }

        // Validar limite diário ao editar (aplica ao usuário alvo, independente de quem edita)
        if (isset($updateData['amount'])) {
            $expenseDate = $updateData['expense_date'] ?? (
                $expense->expense_date instanceof \Carbon\Carbon
                    ? $expense->expense_date->format('Y-m-d')
                    : (string) $expense->expense_date
            );
            $limitError = $this->checkDailyLimit(
                $project,
                $expense->user_id,
                (float) $updateData['amount'],
                $expenseDate,
                $expense->id
            );
            if ($limitError) return $limitError;
        }

        // Upload de novo comprovante se fornecido
        if ($request->hasFile('receipt')) {
            // Deletar arquivo anterior se existir
            if ($expense->receipt_path) {
                Storage::disk('public')->delete($expense->receipt_path);
            }

            $file = $request->file('receipt');
            $filename = time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('receipts/' . date('Y/m'), $filename, 'public');

            $updateData['receipt_path'] = $path;
            $updateData['receipt_original_name'] = $file->getClientOriginalName();
        }

        // Resetar status para pendente se houve alterações após solicitação de ajuste ou rejeição
        if ($expense->status === Expense::STATUS_ADJUSTMENT_REQUESTED || $expense->status === Expense::STATUS_REJECTED) {
            $updateData['status'] = Expense::STATUS_PENDING;
            $updateData['rejection_reason'] = null;
            $updateData['reviewed_by'] = null;
            $updateData['reviewed_at'] = null;
        }

        $expense->update($updateData);
        $expense->load(['user', 'project.customer', 'category', 'reviewedBy', 'reversals.reversedBy', 'reversals.originalApprover']);
        $this->invalidateListCache('expenses');

        return response()->json($expense);
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/expenses/{id}",
     *     summary="Excluir despesa",
     *     description="Exclui uma despesa (apenas se pendente)",
     *     operationId="deleteExpense",
     *     tags={"Expenses"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID da despesa",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=204,
     *         description="Despesa excluída com sucesso"
     *     )
     * )
     */
    public function destroy(int $id): JsonResponse
    {
        $user = Auth::user();
        $expense = Expense::find($id);

        if (!$expense) {
            return $this->notFoundResponse('Despesa não encontrada');
        }

        // Verificar se pode excluir (só o próprio usuário ou admin)
        if (!$user->isAdmin() && $expense->user_id !== $user->id) {
            return $this->accessDeniedResponse('Você só pode excluir suas próprias despesas');
        }

        // Só pode excluir se pendente
        if ($expense->status !== Expense::STATUS_PENDING) {
            return $this->businessRuleResponse(
                'EXPENSE_NOT_DELETABLE',
                'Despesa não pode ser excluída',
                'Apenas despesas pendentes podem ser excluídas'
            );
        }

        // Deletar arquivo de comprovante se existir
        if ($expense->receipt_path) {
            Storage::disk('public')->delete($expense->receipt_path);
        }

        $expense->delete();
        $this->invalidateListCache('expenses');

        return response()->json(null, 204);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/expenses/{id}/approve",
     *     summary="Aprovar despesa",
     *     description="Aprova uma despesa pendente ou com ajuste solicitado",
     *     operationId="approveExpense",
     *     tags={"Expenses"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID da despesa",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="charge_client", type="boolean", example=false, description="Se será cobrado do cliente")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Despesa aprovada com sucesso",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", ref="#/components/schemas/Expense"),
     *             @OA\Property(property="message", type="string", example="Despesa aprovada com sucesso!")
     *         )
     *     )
     * )
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $user = Auth::user();
        $expense = Expense::find($id);

        if (!$expense) {
            return $this->notFoundResponse('Despesa não encontrada');
        }

        // Ninguém aprova a própria despesa que lançou — nem administrador.
        if ((int) $expense->user_id === (int) $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Você não pode aprovar uma despesa que você mesmo lançou.'
            ], 403);
        }

        // Administradores podem aprovar qualquer despesa
        if (!$user->isAdmin() && !$expense->canBeApprovedBy($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Você não tem permissão para aprovar esta despesa'
            ], 403);
        }

        $chargeClient = $request->boolean('charge_client', false);

        if ($expense->approve($user, $chargeClient)) {
            $expense->load(['user', 'project.customer', 'category', 'reviewedBy', 'reversals.reversedBy', 'reversals.originalApprover']);
            $this->invalidateListCache('expenses');

            return response()->json([
                'success' => true,
                'data' => $expense,
                'message' => 'Despesa aprovada com sucesso!'
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Erro ao aprovar despesa'
        ], 500);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/expenses/{id}/reject",
     *     summary="Rejeitar despesa",
     *     description="Rejeita uma despesa pendente ou com ajuste solicitado",
     *     operationId="rejectExpense",
     *     tags={"Expenses"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID da despesa",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             required={"reason"},
     *             @OA\Property(property="reason", type="string", example="Comprovante ilegível")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Despesa rejeitada com sucesso",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", ref="#/components/schemas/Expense"),
     *             @OA\Property(property="message", type="string", example="Despesa rejeitada com sucesso!")
     *         )
     *     )
     * )
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $user = Auth::user();
        $expense = Expense::find($id);

        if (!$expense) {
            return $this->notFoundResponse('Despesa não encontrada');
        }

        // Administradores podem rejeitar qualquer despesa
        if (!$user->isAdmin() && !$expense->canBeApprovedBy($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Você não tem permissão para rejeitar esta despesa'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:1000'
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors()->all());
        }

        if ($expense->reject($user, $request->reason)) {
            $expense->load(['user', 'project.customer', 'category', 'reviewedBy', 'reversals.reversedBy', 'reversals.originalApprover']);
            $this->invalidateListCache('expenses');

            return response()->json([
                'success' => true,
                'data' => $expense,
                'message' => 'Despesa rejeitada com sucesso!'
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Erro ao rejeitar despesa'
        ], 500);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/expenses/{id}/request-adjustment",
     *     summary="Solicitar ajuste na despesa",
     *     description="Solicita ajuste em uma despesa pendente",
     *     operationId="requestAdjustmentExpense",
     *     tags={"Expenses"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID da despesa",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             required={"reason"},
     *             @OA\Property(property="reason", type="string", example="Favor corrigir a data da despesa")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Ajuste solicitado com sucesso",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", ref="#/components/schemas/Expense"),
     *             @OA\Property(property="message", type="string", example="Ajuste solicitado com sucesso!")
     *         )
     *     )
     * )
     */
    public function requestAdjustment(Request $request, int $id): JsonResponse
    {
        $user = Auth::user();
        $expense = Expense::find($id);

        if (!$expense) {
            return $this->notFoundResponse('Despesa não encontrada');
        }

        // Administradores podem solicitar ajuste em qualquer despesa
        if (!$user->isAdmin() && !$expense->canBeApprovedBy($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Você não tem permissão para solicitar ajuste nesta despesa'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:1000'
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors()->all());
        }

        if ($expense->requestAdjustment($user, $request->reason)) {
            $expense->load(['user', 'project.customer', 'category', 'reviewedBy', 'reversals.reversedBy', 'reversals.originalApprover']);
            $this->invalidateListCache('expenses');

            return response()->json([
                'success' => true,
                'data' => $expense,
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
     *     path="/api/v1/expenses/{id}/reverse-approval",
     *     summary="Estornar aprovação da despesa",
     *     description="Estorna a aprovação de uma despesa, retornando-a ao status pendente",
     *     operationId="reverseApprovalExpense",
     *     tags={"Expenses"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID da despesa",
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
     *             @OA\Property(property="data", ref="#/components/schemas/Expense"),
     *             @OA\Property(property="message", type="string", example="Aprovação estornada com sucesso!")
     *         )
     *     )
     * )
     */
    public function reverseApproval(Request $request, int $id): JsonResponse
    {
        $user = Auth::user();
        $expense = Expense::find($id);

        if (!$expense) {
            return $this->notFoundResponse('Despesa não encontrada');
        }

        if ($expense->status !== 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'Apenas despesas aprovadas podem ser estornadas',
            ], 422);
        }

        if ($expense->is_paid) {
            return response()->json([
                'success' => false,
                'message' => 'Despesas pagas não podem ser estornadas',
            ], 422);
        }

        if (!$user->isAdmin() && !$user->isAdministrativo() && !$expense->canBeReversedBy($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Você não tem permissão para estornar esta aprovação',
            ], 403);
        }

        try {
            \DB::beginTransaction();

            try {
                $expense->reversals()->create([
                    'reversed_by'            => $user->id,
                    'reversal_reason'        => $request->input('reason', 'Estorno de aprovação'),
                    'original_approver_id'   => $expense->reviewed_by ?? $user->id,
                    'original_approval_date' => $expense->reviewed_at ?? now(),
                ]);
            } catch (\Exception $e) {
                // Tabela expense_reversals ainda não migrada — continua sem o log
            }

            $expense->status           = 'pending';
            $expense->reviewed_by      = null;
            $expense->reviewed_at      = null;
            $expense->rejection_reason = null;
            $expense->charge_client    = false;
            $expense->save();

            \DB::commit();

            $this->invalidateListCache('expenses');

            $expense->load(['user', 'project.customer', 'category']);

            return response()->json([
                'success' => true,
                'data'    => $expense,
                'message' => 'Aprovação estornada com sucesso!',
            ]);
        } catch (\Exception $e) {
            \DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erro ao estornar aprovação: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Download/visualização de comprovante — serve o arquivo direto via PHP
     */
    public function downloadReceipt(Request $request, int $id)
    {
        $user = Auth::user();
        $expense = Expense::findOrFail($id);

        // Permissão: dono, admin ou coordenador do projeto
        if (!$user->isAdmin() && $expense->user_id !== $user->id) {
            if (!$expense->canBeApprovedBy($user)) {
                return response()->json(['message' => 'Sem permissão'], 403);
            }
        }

        if (!$expense->receipt_path) {
            return response()->json(['message' => 'Comprovante não encontrado'], 404);
        }

        try {
            $disk = \Storage::disk('public');

            if (!$disk->exists($expense->receipt_path)) {
                return response()->json(['message' => 'Arquivo não encontrado no servidor. O comprovante pode ter sido perdido após uma atualização do servidor.'], 404);
            }

            $mime = $disk->mimeType($expense->receipt_path) ?: 'application/octet-stream';
            $name = $expense->receipt_original_name ?? basename($expense->receipt_path);

            return response($disk->get($expense->receipt_path), 200, [
                'Content-Type'        => $mime,
                'Content-Disposition' => 'inline; filename="' . addslashes($name) . '"',
                'Cache-Control'       => 'no-cache',
            ]);
        } catch (\Exception $e) {
            \Log::error('Erro ao servir comprovante de despesa', [
                'expense_id'   => $id,
                'receipt_path' => $expense->receipt_path,
                'error'        => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Erro ao acessar o comprovante. O arquivo pode não estar disponível neste servidor.'], 503);
        }
    }

    /**
     * Upload de comprovante de despesa
     */
    public function uploadReceipt(Request $request, int $id): JsonResponse
    {
        $user = Auth::user();
        $expense = Expense::findOrFail($id);

        // Permissão: só o dono ou admin
        if (!$user->isAdmin() && $expense->user_id !== $user->id) {
            return $this->accessDeniedResponse('Você só pode enviar comprovante para suas próprias despesas');
        }

        // Validação do arquivo
        $request->validate([
            'receipt' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ]);

        // Deletar arquivo anterior se existir
        if ($expense->receipt_path) {
            \Storage::disk('public')->delete($expense->receipt_path);
        }

        $file = $request->file('receipt');
        $filename = time() . '_' . $file->getClientOriginalName();
        $path = $file->storeAs('receipts/' . date('Y/m'), $filename, 'public');

        $expense->receipt_path = $path;
        $expense->receipt_original_name = $file->getClientOriginalName();
        $expense->save();
        $expense->load(['user', 'project.customer', 'category', 'reviewedBy', 'reversals.reversedBy', 'reversals.originalApprover']);

        return response()->json($expense);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/expenses/{id}/reverse-rejection",
     *     summary="Estornar rejeição da despesa",
     *     description="Estorna a rejeição de uma despesa, retornando-a ao status pendente",
     *     operationId="reverseRejectionExpense",
     *     tags={"Expenses"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID da despesa",
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
     *             @OA\Property(property="data", ref="#/components/schemas/Expense"),
     *             @OA\Property(property="message", type="string", example="Rejeição estornada com sucesso!")
     *         )
     *     )
     * )
     */
    public function reverseRejection(Request $request, int $id): JsonResponse
    {
        $user = Auth::user();
        $expense = Expense::find($id);

        if (!$expense) {
            return $this->notFoundResponse('Despesa não encontrada');
        }

        // Administradores podem estornar qualquer rejeição
        if (!$user->isAdmin() && !$expense->canBeRejectionReversedBy($user)) {
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

        if ($expense->reverseRejection($user, $request->reason)) {
            $expense->load(['user', 'project.customer', 'category', 'reviewedBy', 'reversals.reversedBy', 'reversals.originalApprover']);
            $this->invalidateListCache('expenses');

            return response()->json([
                'success' => true,
                'data' => $expense,
                'message' => 'Rejeição estornada com sucesso!'
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Erro ao estornar rejeição'
        ], 500);
    }

    // ─── Set Paid ─────────────────────────────────────────────────────────────

    public function setPaid(Request $request, int $id): JsonResponse
    {
        $user = Auth::user();

        if (!$user->isAdmin() && !$user->isAdministrativo() && !$user->hasAccess('expenses.pay')) {
            return $this->accessDeniedResponse('Sem permissão para marcar despesas como pagas.');
        }

        $expense = Expense::find($id);

        if (!$expense) {
            return $this->notFoundResponse('Despesa não encontrada');
        }

        $isPaid = $request->boolean('is_paid', true);

        if ($isPaid && $expense->status !== Expense::STATUS_APPROVED) {
            return response()->json(['message' => 'Apenas despesas aprovadas podem ser marcadas como pagas.'], 422);
        }

        $expense->update([
            'is_paid' => $isPaid,
            'paid_by' => $isPaid ? $user->id : null,
            'paid_at' => $isPaid ? now() : null,
        ]);

        return response()->json([
            'success' => true,
            'message' => $isPaid ? 'Despesa marcada como paga.' : 'Marcação de pago removida.',
            'is_paid' => $expense->is_paid,
            'paid_at' => $expense->paid_at?->toISOString(),
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/expenses/export",
     *     summary="Exportar despesas para Excel",
     *     description="Exporta despesas para arquivo Excel com os mesmos filtros da listagem",
     *     operationId="exportExpenses",
     *     tags={"Expenses"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         description="Busca na descrição",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
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
     *         description="Filtrar por cliente do projeto",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="user_id",
     *         in="query",
     *         description="Filtrar por usuário solicitante",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         description="Filtrar por status",
     *         required=false,
     *         @OA\Schema(type="string", enum={"pending", "approved", "rejected", "adjustment_requested"})
     *     ),
     *     @OA\Parameter(
     *         name="expense_type",
     *         in="query",
     *         description="Filtrar por tipo de despesa",
     *         required=false,
     *         @OA\Schema(type="string", enum={"corporate_card", "reimbursement"})
     *     ),
     *     @OA\Parameter(
     *         name="category_id",
     *         in="query",
     *         description="Filtrar por categoria",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="start_date",
     *         in="query",
     *         description="Data inicial (YYYY-MM-DD)",
     *         required=false,
     *         @OA\Schema(type="string", format="date")
     *     ),
     *     @OA\Parameter(
     *         name="end_date",
     *         in="query",
     *         description="Data final (YYYY-MM-DD)",
     *         required=false,
     *         @OA\Schema(type="string", format="date")
     *     ),
     *     @OA\Parameter(
     *         name="order",
     *         in="query",
     *         description="Ordenação (ex: expense_date,-amount)",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Arquivo Excel com despesas exportadas",
     *         @OA\MediaType(
     *             mediaType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
     *         )
     *     )
     * )
     */
    public function export(Request $request)
    {
        $user = Auth::user();

        // Gerar nome do arquivo com timestamp
        $filename = 'despesas_' . date('Y-m-d_H-i-s') . '.xlsx';

        return Excel::download(new ExpensesExport($request, $user), $filename);
    }
}
