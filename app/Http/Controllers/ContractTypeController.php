<?php

namespace App\Http\Controllers;

use App\Constants\SystemDefaults;
use App\Models\ContractType;
use App\Http\Traits\ResponseHelpers;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * @OA\Schema(
 *     schema="ContractType",
 *     type="object",
 *     title="Contract Type",
 *     description="Modelo de tipo de contrato",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="code", type="string", example="fixed_hours"),
 *     @OA\Property(property="name", type="string", example="Banco de Horas Fixo"),
 *     @OA\Property(property="description", type="string", nullable=true, example="Contrato com banco de horas fixo"),
 *     @OA\Property(property="active", type="boolean", example=true),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 *
 * @OA\Tag(
 *     name="Contract Types",
 *     description="Tipos de Contrato"
 * )
 */
class ContractTypeController extends Controller
{
    use ResponseHelpers;

    /**
     * @OA\Get(
     *     path="/api/v1/contract-types",
     *     summary="Lista tipos de contrato",
     *     description="Retorna lista de tipos de contrato com paginação, busca e filtros",
     *     tags={"Contract Types"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         description="Página (padrão: 1)",
     *         required=false,
     *         @OA\Schema(type="integer", minimum=1, example=1)
     *     ),
     *     @OA\Parameter(
     *         name="pageSize",
     *         in="query",
     *         description="Registros por página (padrão: 20, máximo: 100)",
     *         required=false,
     *         @OA\Schema(type="integer", minimum=1, maximum=100, example=20)
     *     ),
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         description="Busca por nome ou código",
     *         required=false,
     *         @OA\Schema(type="string", example="Banco")
     *     ),
     *     @OA\Parameter(
     *         name="filter_status",
     *         in="query",
     *         description="Filtrar por status: 'all', 'active' (ativas), 'inactive' (inativas)",
     *         required=false,
     *         @OA\Schema(type="string", enum={"all", "active", "inactive"}, example="active")
     *     ),
     *     @OA\Parameter(
     *         name="order",
     *         in="query",
     *         description="Ordenação (ex: name,-active)",
     *         required=false,
     *         @OA\Schema(type="string", example="name,-active")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lista de tipos retornada com sucesso",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="hasNext", type="boolean", example=true),
     *             @OA\Property(
     *                 property="items",
     *                 type="array",
     *                 @OA\Items(ref="#/components/schemas/ContractType")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Não autorizado"
     *     )
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min($request->get('pageSize', 20), 100);
        $search = $request->get('search');
        $filterStatus = $request->get('filter_status'); // 'all', 'active', 'inactive'

        $query = ContractType::query()->where('code', '!=', 'bizify');

        // Filtro de busca (nome ou código)
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('code', 'ilike', "%{$search}%");
            });
        }

        // Filtro de status (ativo/inativo)
        if ($filterStatus === 'active') {
            $query->where('active', true);
        } elseif ($filterStatus === 'inactive') {
            $query->where('active', false);
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
            // Ordenação padrão: nome
            $query->orderBy('name');
        }

        // Paginação PO-UI
        $page = (int) $request->get('page', 1);
        $types = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'hasNext' => $types->hasMorePages(),
            'items' => $types->items()
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/contract-types/{id}",
     *     summary="Busca tipo por ID",
     *     description="Retorna um tipo específico",
     *     tags={"Contract Types"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID do tipo",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Tipo encontrado",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", ref="#/components/schemas/ContractType")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Tipo não encontrado"
     *     )
     * )
     */
    public function show(int $id): JsonResponse
    {
        $type = ContractType::find($id);

        if (!$type) {
            return $this->notFoundResponse('Tipo de contrato não encontrado');
        }

        return response()->json([
            'success' => true,
            'data' => $type
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/contract-types",
     *     summary="Criar tipo de contrato",
     *     description="Cria um novo tipo de contrato",
     *     tags={"Contract Types"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name", "code"},
     *             @OA\Property(property="name", type="string", example="Banco de Horas Fixo"),
     *             @OA\Property(property="code", type="string", example="fixed_hours"),
     *             @OA\Property(property="description", type="string", nullable=true, example="Contrato com banco de horas fixo"),
     *             @OA\Property(property="active", type="boolean", example=true)
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Tipo criado com sucesso",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", ref="#/components/schemas/ContractType")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Dados inválidos"
     *     )
     * )
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|min:2',
            'code' => 'required|string|max:255|unique:contract_types,code',
            'description' => 'nullable|string|max:500',
            'active' => 'nullable|boolean',
        ]);

        $type = ContractType::create($validated);

        return response()->json([
            'success' => true,
            'data' => $type
        ], 201);
    }

    /**
     * @OA\Put(
     *     path="/api/v1/contract-types/{id}",
     *     summary="Atualizar tipo de contrato",
     *     description="Atualiza um tipo de contrato existente",
     *     tags={"Contract Types"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID do tipo",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string", example="Banco de Horas Fixo"),
     *             @OA\Property(property="code", type="string", example="fixed_hours"),
     *             @OA\Property(property="description", type="string", nullable=true, example="Contrato com banco de horas fixo"),
     *             @OA\Property(property="active", type="boolean", example=true)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Tipo atualizado com sucesso",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", ref="#/components/schemas/ContractType")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Tipo não encontrado"
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Dados inválidos"
     *     )
     * )
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $type = ContractType::find($id);

        if (!$type) {
            return $this->notFoundResponse('Tipo de contrato não encontrado');
        }

        // Verificar se está tentando alterar o código de um tipo de sistema
        if ($request->has('code') && $request->get('code') !== $type->code) {
            if (SystemDefaults::isProtectedContractType($type->code)) {
                return response()->json([
                    'code' => 'SYSTEM_TYPE_PROTECTED',
                    'type' => 'error',
                    'message' => 'Não é possível alterar o código de um tipo de contrato padrão do sistema',
                    'detailMessage' => 'Este tipo de contrato é essencial para o funcionamento do sistema e seu código não pode ser alterado'
                ], 422);
            }
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255|min:2',
            'code' => 'sometimes|string|max:255|unique:contract_types,code,' . $id,
            'description' => 'nullable|string|max:500',
            'active' => 'nullable|boolean',
        ]);

        $type->update($validated);
        $type->refresh();

        return response()->json([
            'success' => true,
            'data' => $type
        ]);
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/contract-types/{id}",
     *     summary="Excluir tipo de contrato",
     *     description="Exclui um tipo de contrato. Não permite excluir se houver projetos vinculados",
     *     tags={"Contract Types"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID do tipo",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Tipo excluído com sucesso",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Tipo excluído com sucesso")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Tipo não encontrado"
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Não é possível excluir (tem projetos vinculados)"
     *     )
     * )
     */
    public function destroy(int $id): JsonResponse
    {
        $type = ContractType::find($id);

        if (!$type) {
            return $this->notFoundResponse('Tipo de contrato não encontrado');
        }

        // Verificar se é um tipo de contrato padrão do sistema
        if (SystemDefaults::isProtectedContractType($type->code)) {
            return response()->json([
                'code' => 'SYSTEM_TYPE_PROTECTED',
                'type' => 'error',
                'message' => 'Não é possível excluir tipo de contrato padrão do sistema',
                'detailMessage' => 'Este tipo de contrato é essencial para o funcionamento do sistema e não pode ser excluído'
            ], 422);
        }

        // Verificar se tem projetos vinculados
        $projectsCount = $type->projects()->count();
        if ($projectsCount > 0) {
            return response()->json([
                'code' => 'HAS_PROJECTS',
                'type' => 'error',
                'message' => 'Não é possível excluir tipo de contrato com projetos vinculados',
                'detailMessage' => 'Existem ' . $projectsCount . ' projeto(s) vinculado(s) a este tipo'
            ], 422);
        }

        $type->delete();

        return response()->json([
            'success' => true,
            'message' => 'Tipo de contrato excluído com sucesso'
        ]);
    }
}
