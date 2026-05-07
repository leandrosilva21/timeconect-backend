<?php

namespace App\Http\Controllers;

use App\Models\ContractType;
use App\Models\Customer;
use App\Models\Project;
use App\Models\ServiceType;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * @OA\Tag(
 *     name="Customers",
 *     description="Gerenciamento de Clientes"
 * )
 */
class CustomerController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/v1/customers",
     *     tags={"Customers"},
     *     summary="Listar customers",
     *     description="Lista customers com paginação, filtros e ordenação",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer", minimum=1, example=1),
     *         description="Página (padrão: 1)"
     *     ),
     *     @OA\Parameter(
     *         name="pageSize",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer", minimum=1, maximum=100, example=15),
     *         description="Registros por página (padrão: 15, máximo: 100)"
     *     ),
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="string", example="João"),
     *         description="Busca por name ou CGC",
     *     ),
     *     @OA\Parameter(
     *         name="order",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="string", example="name,-created_at"),
     *         description="Ordenação (ex: name,-created_at)",
     *     ),
     *     @OA\Parameter(
     *         name="has_contract_type_name",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="string", example="Banco de Horas Fixo"),
     *         description="Filtrar apenas clientes que possuam pelo menos um projeto com o tipo de contrato informado (nome do tipo de contrato)"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lista de customers",
     *         @OA\JsonContent(
     *             @OA\Property(property="hasNext", type="boolean", example=true),
     *             @OA\Property(
     *                 property="items",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer"),
     *                     @OA\Property(property="name", type="string"),
     *                     @OA\Property(property="cgc", type="string"),
     *                     @OA\Property(property="formatted_cgc", type="string"),
     *                     @OA\Property(property="cgc_type", type="string"),
     *                     @OA\Property(property="created_at", type="string"),
     *                     @OA\Property(property="updated_at", type="string")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Não autenticado"),
     *     @OA\Response(response=403, description="Sem permissão")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min($request->get('pageSize', 15), 500);
        $search = $request->get('filter') ?? $request->get('search');
        $hasContractTypeName = $request->get('has_contract_type_name');

        $executiveId = $request->get('executive_id');

        $query = Customer::with('executive');

        // Filtros PO-UI (ilike = case-insensitive no PostgreSQL)
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('cgc', 'ilike', "%{$search}%");
            });
        }

        // Filtro por executivo responsável
        if ($executiveId) {
            $query->where('executive_id', $executiveId);
        }

        // Filtro por status ativo/inativo
        if ($request->has('active')) {
            $active = $request->boolean('active');
            $query->where('active', $active);
        }

        // Filtro por existência de projetos PAI com determinado tipo de contrato (por nome)
        if ($hasContractTypeName) {
            $query->whereHas('projects', function ($projectQuery) use ($hasContractTypeName) {
                $projectQuery
                    ->whereNull('parent_project_id') // apenas projetos pai
                    ->whereHas('contractType', function ($contractTypeQuery) use ($hasContractTypeName) {
                        $contractTypeQuery->where('name', $hasContractTypeName);
                    });
            });
        }

        // Ordenação PO-UI
        if ($request->has('order')) {
            $orderFields = explode(',', $request->get('order'));
            foreach ($orderFields as $field) {
                if (str_starts_with($field, '-')) {
                    $query->orderBy(substr($field, 1), 'desc');
                } else {
                    $query->orderBy($field, 'asc');
                }
            }
        } else {
            $query->orderBy('name'); // Ordenação padrão
        }

        // Paginação PO-UI
        $page = (int) $request->get('page', 1);
        $customers = $query->paginate($perPage, ['*'], 'page', $page);

        // Resposta PO-UI
        return response()->json([
            'hasNext' => $customers->hasMorePages(),
            'items' => $customers->items()
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/customers",
     *     summary="Criar customer",
     *     description="Cria um novo customer no sistema",
     *     tags={"Customers"},
     *     security={{"sanctum": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name", "cgc"},
     *             @OA\Property(property="name", type="string", example="João Silva", description="Nome do cliente"),
     *             @OA\Property(property="cgc", type="string", example="12345678901", description="CPF ou CNPJ (apenas números)")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Customer criado com sucesso",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="id", type="integer"),
     *             @OA\Property(property="name", type="string"),
     *             @OA\Property(property="cgc", type="string"),
     *             @OA\Property(property="formatted_cgc", type="string"),
     *             @OA\Property(property="cgc_type", type="string"),
     *             @OA\Property(property="created_at", type="string"),
     *             @OA\Property(property="updated_at", type="string")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Dados inválidos",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="code", type="string"),
     *             @OA\Property(property="type", type="string"),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="detailMessage", type="string")
     *         )
     *     )
     * )
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|min:2',
            'company_name' => 'nullable|string|max:255',
            'cgc' => 'required|string|unique:customers,cgc,NULL,id,deleted_at,NULL',
            'active' => 'nullable|boolean',
            'executive_id' => 'nullable|exists:users,id',
            'code_prefix' => 'nullable|string|size:3|alpha|unique:customers,code_prefix',
        ], [
            'code_prefix.size' => 'O prefixo de código deve ter exatamente 3 letras',
            'code_prefix.alpha' => 'O prefixo de código deve conter apenas letras',
            'code_prefix.unique' => 'Este prefixo já está sendo usado por outro cliente',
        ]);

        // Remove caracteres especiais do CGC
        $validated['cgc'] = preg_replace('/[^0-9]/', '', $validated['cgc']);

        // Valida se é CPF ou CNPJ (tamanho)
        if (!in_array(strlen($validated['cgc']), [11, 14])) {
            return response()->json([
                'code' => 'INVALID_CGC_LENGTH',
                'type' => 'error',
                'message' => 'CGC deve ter 11 dígitos (CPF) ou 14 dígitos (CNPJ)',
                'detailMessage' => 'O CGC informado deve conter exatamente 11 dígitos para CPF ou 14 dígitos para CNPJ'
            ], 422);
        }

        // Cria uma instância temporária para validar o CGC ANTES de salvar no banco
        $tempCustomer = new Customer($validated);

        // Valida se o CGC é realmente válido (algoritmo de validação)
        if (!$tempCustomer->isValidCgc()) {
            return response()->json([
                'code' => 'INVALID_CGC',
                'type' => 'error',
                'message' => 'CGC inválido',
                'detailMessage' => 'O CGC informado não passou na validação de algoritmo'
            ], 422);
        }

        // Normaliza o prefixo para maiúsculas
        if (!empty($validated['code_prefix'])) {
            $validated['code_prefix'] = strtoupper($validated['code_prefix']);
        }

        // Só agora cria no banco, pois sabemos que é válido
        $customer = Customer::create($validated);

        $this->createInvestimentoProjects($customer);

        // Resposta PO-UI
        return response()->json($customer->load('executive'), 201);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/customers/{id}",
     *     summary="Visualizar customer",
     *     description="Exibe detalhes de um customer específico",
     *     tags={"Customers"},
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID do customer",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Detalhes do customer",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Customer não encontrado"
     *     )
     * )
     */
    public function show(Customer $customer): JsonResponse
    {
        return response()->json($customer->load('executive'));
    }

    /**
     * @OA\Put(
     *     path="/api/v1/customers/{id}",
     *     summary="Atualizar customer",
     *     description="Atualiza um customer existente",
     *     tags={"Customers"},
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID do customer",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string", example="João Silva Santos"),
     *             @OA\Property(property="cgc", type="string", example="12345678901")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Customer atualizado com sucesso"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Customer não encontrado"
     *     )
     * )
     */
    public function update(Request $request, Customer $customer): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255|min:2',
            'company_name' => 'nullable|string|max:255',
            'cgc' => 'sometimes|string|unique:customers,cgc,' . $customer->id . ',id,deleted_at,NULL',
            'active' => 'nullable|boolean',
            'executive_id' => 'nullable|exists:users,id',
            'code_prefix' => 'nullable|string|size:3|alpha|unique:customers,code_prefix,' . $customer->id,
        ], [
            'code_prefix.size' => 'O prefixo de código deve ter exatamente 3 letras',
            'code_prefix.alpha' => 'O prefixo de código deve conter apenas letras',
            'code_prefix.unique' => 'Este prefixo já está sendo usado por outro cliente',
        ]);

        if (isset($validated['cgc'])) {
            // Remove caracteres especiais do CGC
            $validated['cgc'] = preg_replace('/[^0-9]/', '', $validated['cgc']);

            // Valida se é CPF ou CNPJ (tamanho)
            if (!in_array(strlen($validated['cgc']), [11, 14])) {
                return response()->json([
                    'code' => 'INVALID_CGC_LENGTH',
                    'type' => 'error',
                    'message' => 'CGC deve ter 11 dígitos (CPF) ou 14 dígitos (CNPJ)',
                    'detailMessage' => 'O CGC informado deve conter exatamente 11 dígitos para CPF ou 14 dígitos para CNPJ'
                ], 422);
            }

            // Cria uma instância temporária para validar o CGC
            $tempCustomer = new Customer($validated);
            $tempCustomer->cgc = $validated['cgc'];

            // Valida se o CGC é realmente válido (algoritmo de validação)
            if (!$tempCustomer->isValidCgc()) {
                return response()->json([
                    'code' => 'INVALID_CGC',
                    'type' => 'error',
                    'message' => 'CGC inválido',
                    'detailMessage' => 'O CGC informado não passou na validação de algoritmo'
                ], 422);
            }
        }

        if (!empty($validated['code_prefix'])) {
            $validated['code_prefix'] = strtoupper($validated['code_prefix']);
        }

        $customer->update($validated);

        // Resposta PO-UI
        return response()->json($customer->load('executive'));
    }

    /**
     * @OA\Get(
     *     path="/api/v1/customers/user-linked",
     *     tags={"Customers"},
     *     summary="Listar customers vinculados ao usuário",
     *     description="Lista customers onde o usuário logado é consultor ou aprovador em projetos",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer", minimum=1, example=1),
     *         description="Página (padrão: 1)"
     *     ),
     *     @OA\Parameter(
     *         name="pageSize",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer", minimum=1, maximum=100, example=15),
     *         description="Registros por página (padrão: 15, máximo: 100)"
     *     ),
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="string", example="João"),
     *         description="Busca por name ou CGC",
     *     ),
     *     @OA\Parameter(
     *         name="order",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="string", example="name,-created_at"),
     *         description="Ordenação (ex: name,-created_at)",
     *     ),
     *     @OA\Parameter(
     *         name="user_id",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer", example=1),
     *         description="ID do usuário (apenas para administradores)",
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lista de customers vinculados ao usuário",
     *         @OA\JsonContent(
     *             @OA\Property(property="hasNext", type="boolean", example=true),
     *             @OA\Property(
     *                 property="items",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer"),
     *                     @OA\Property(property="name", type="string"),
     *                     @OA\Property(property="cgc", type="string"),
     *                     @OA\Property(property="formatted_cgc", type="string"),
     *                     @OA\Property(property="cgc_type", type="string"),
     *                     @OA\Property(property="created_at", type="string"),
     *                     @OA\Property(property="updated_at", type="string")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Não autenticado"),
     *     @OA\Response(response=403, description="Sem permissão")
     * )
     */
    public function getUserLinkedCustomers(Request $request): JsonResponse
    {
        $currentUser = $request->user();
        $perPage = min($request->get('pageSize', 15), 500);
        $search = $request->get('filter') ?? $request->get('search');
        $requestedUserId = $request->get('user_id');

        // Determina qual usuário usar para a consulta
        $targetUserId = $currentUser->id;
        $targetUser = $currentUser;

        if ($requestedUserId && ($currentUser->isAdmin() || $currentUser->isCoordenador())) {
            // Admin e Coordenador podem consultar para qualquer usuário
            $targetUserId = $requestedUserId;
            $targetUser = \App\Models\User::find($targetUserId);
        }

        // Apenas admin retorna TODOS os clientes; demais usuários (incluindo coordenador) são limitados à sua alocação
        if ($targetUser && $targetUser->isAdmin()) {
            $query = Customer::query();
        } else {
            // Para usuários não-admin, busca apenas clientes onde o usuário é consultor ou aprovador
            $customerIds = Customer::whereHas('projects', function ($query) use ($targetUserId) {
                $query->where(function ($projectQuery) use ($targetUserId) {
                    $projectQuery->whereHas('consultants', function ($consultantQuery) use ($targetUserId) {
                        $consultantQuery->where('user_id', $targetUserId);
                    })
                    ->orWhereHas('coordinators', function ($coordinatorQuery) use ($targetUserId) {
                        $coordinatorQuery->where('user_id', $targetUserId);
                    });
                });
            })->pluck('id');

            // Query principal com os clientes vinculados
            $query = Customer::whereIn('id', $customerIds);
        }

        // Filtros PO-UI (ilike = case-insensitive no PostgreSQL)
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('cgc', 'ilike', "%{$search}%");
            });
        }

        // Ordenação PO-UI
        if ($request->has('order')) {
            $orderFields = explode(',', $request->get('order'));
            foreach ($orderFields as $field) {
                if (str_starts_with($field, '-')) {
                    $query->orderBy(substr($field, 1), 'desc');
                } else {
                    $query->orderBy($field, 'asc');
                }
            }
        } else {
            $query->orderBy('name'); // Ordenação padrão
        }

        // Paginação PO-UI
        $page = (int) $request->get('page', 1);
        $customers = $query->paginate($perPage, ['*'], 'page', $page);

        // Resposta PO-UI
        return response()->json([
            'hasNext' => $customers->hasMorePages(),
            'items' => $customers->items()
        ]);
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/customers/{id}",
     *     summary="Excluir customer",
     *     description="Remove um customer do sistema (soft delete)",
     *     tags={"Customers"},
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID do customer",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Customer excluído com sucesso"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Customer não encontrado"
     *     )
     * )
     */
    public function destroy(Customer $customer): JsonResponse
    {
        // Bloquear exclusão se algum projeto auto (Investimento Interno) tiver movimento.
        $autoProjects = Project::where('customer_id', $customer->id)
            ->where('is_investimento_comercial', true)
            ->get();

        $autoProjectIds = $autoProjects->pluck('id');

        if ($autoProjectIds->isNotEmpty()) {
            $hasTimesheet = \App\Models\Timesheet::whereIn('project_id', $autoProjectIds)->exists();
            $hasExpense   = \App\Models\Expense::whereIn('project_id', $autoProjectIds)->exists();

            if ($hasTimesheet || $hasExpense) {
                return response()->json([
                    'message' => 'Não é possível excluir o cliente: existem apontamentos ou despesas vinculados aos projetos de Investimento Interno.',
                ], 422);
            }

            // Soft-delete dos projetos auto antes de deletar o cliente
            foreach ($autoProjects as $p) {
                $p->delete();
            }
        }

        $customer->delete();

        return response()->json([], 204);
    }

    /**
     * Cria os 3 projetos automáticos de Investimento Interno para o cliente:
     * - Investimento Comercial (code IC-{prefix})
     * - Investimento Suporte   (code IS-{prefix})
     * - Investimento Projetos  (code IP-{prefix})
     * Se o cliente não tem code_prefix, cai pra customer_id como fallback.
     */
    private function createInvestimentoProjects(Customer $customer): void
    {
        $serviceTypeId  = ServiceType::where('code', 'projeto')->value('id');
        $contractTypeId = ContractType::where('code', 'on_demand')->value('id');

        if (!$serviceTypeId || !$contractTypeId) {
            return;
        }

        $codeKey = $customer->code_prefix ?: (string) $customer->id;

        $defaults = [
            ['name' => 'Investimento Comercial', 'code' => "IC-{$codeKey}", 'categoria' => 'Comercial'],
            ['name' => 'Investimento Suporte',   'code' => "IS-{$codeKey}", 'categoria' => 'Suporte'],
            ['name' => 'Investimento Projetos',  'code' => "IP-{$codeKey}", 'categoria' => 'Projeto'],
        ];

        foreach ($defaults as $cfg) {
            $exists = Project::withTrashed()->where('code', $cfg['code'])->exists();
            if ($exists) {
                continue;
            }
            Project::create([
                'name'                      => $cfg['name'],
                'code'                      => $cfg['code'],
                'customer_id'               => $customer->id,
                'service_type_id'           => $serviceTypeId,
                'contract_type_id'          => $contractTypeId,
                'status'                    => 'started',
                'is_investimento_comercial' => true,
                'is_manual_code'            => true,
                'categoria_interna'         => $cfg['categoria'],
            ]);
        }
    }
}
