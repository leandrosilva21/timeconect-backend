<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreConsultantGroupRequest;
use App\Http\Requests\UpdateConsultantGroupRequest;
use App\Models\ConsultantGroup;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * @OA\Tag(
 *     name="Consultant Groups",
 *     description="Gerenciamento de grupos de consultores"
 * )
 */
class ConsultantGroupController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/v1/consultant-groups",
     *     summary="Listar grupos de consultores",
     *     description="Retorna a lista paginada de grupos de consultores com suporte a filtros e ordenação",
     *     tags={"Consultant Groups"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         description="Número da página",
     *         required=false,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Parameter(
     *         name="pageSize",
     *         in="query",
     *         description="Quantidade de itens por página",
     *         required=false,
     *         @OA\Schema(type="integer", example=20)
     *     ),
     *     @OA\Parameter(
     *         name="order",
     *         in="query",
     *         description="Ordenação (ex: name,-created_at)",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="name",
     *         in="query",
     *         description="Filtrar por nome (busca parcial)",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="active",
     *         in="query",
     *         description="Filtrar por status ativo (true/false)",
     *         required=false,
     *         @OA\Schema(type="boolean")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lista de grupos retornada com sucesso",
     *         @OA\JsonContent(
     *             @OA\Property(property="hasNext", type="boolean", example=true),
     *             @OA\Property(property="items", type="array", @OA\Items(
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string", example="Equipe Alpha"),
     *                 @OA\Property(property="description", type="string", example="Consultores sêniores"),
     *                 @OA\Property(property="active", type="boolean", example=true),
     *                 @OA\Property(property="consultants_count", type="integer", example=5),
     *                 @OA\Property(property="consultants", type="array", @OA\Items(type="object")),
     *                 @OA\Property(property="created_at", type="string", format="date-time"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time")
     *             ))
     *         )
     *     ),
     *     @OA\Response(response=401, description="Não autenticado"),
     *     @OA\Response(response=403, description="Sem permissão")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            // Verificar permissões
            if (!$user->isAdmin() && !$user->hasAccess('consultant_groups.view')) {
                return response()->json([
                    'code' => 'PERMISSION_DENIED',
                    'type' => 'error',
                    'message' => 'Você não tem permissão para visualizar grupos de consultores.',
                ], 403);
            }

            $pageSize = $request->get('pageSize', 20);
            $query = ConsultantGroup::with(['consultants', 'creator']);

            // Filtros
            if ($request->has('name') && $request->get('name') !== '') {
                $query->where('name', 'ilike', '%' . $request->get('name') . '%');
            }

            if ($request->has('active')) {
                $active = filter_var($request->get('active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($active !== null) {
                    $query->where('active', $active);
                }
            }

            // Ordenação (padrão PO-UI)
            if ($request->has('order')) {
                $orderFields = explode(',', $request->get('order'));
                foreach ($orderFields as $field) {
                    $direction = 'asc';
                    if (strpos($field, '-') === 0) {
                        $direction = 'desc';
                        $field = substr($field, 1);
                    }
                    $query->orderBy($field, $direction);
                }
            } else {
                $query->orderBy('name', 'asc');
            }

            // Paginação
            $paginator = $query->paginate($pageSize);

            // Adicionar contagem de consultores
            $items = $paginator->items();
            foreach ($items as $item) {
                $item->consultants_count = $item->consultants->count();
            }

            return response()->json([
                'hasNext' => $paginator->hasMorePages(),
                'items' => $items,
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao listar grupos de consultores: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => $request->user()?->id
            ]);

            return response()->json([
                'code' => 'INTERNAL_ERROR',
                'type' => 'error',
                'message' => 'Erro ao listar grupos de consultores.',
                'detailMessage' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/consultant-groups",
     *     summary="Criar novo grupo de consultores",
     *     description="Cria um novo grupo de consultores no sistema",
     *     tags={"Consultant Groups"},
     *     security={{"sanctum":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name", "consultant_ids"},
     *             @OA\Property(property="name", type="string", example="Equipe Alpha"),
     *             @OA\Property(property="description", type="string", example="Consultores sêniores especializados em desenvolvimento"),
     *             @OA\Property(property="active", type="boolean", example=true),
     *             @OA\Property(property="consultant_ids", type="array", @OA\Items(type="integer"), example={1,2,3})
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Grupo criado com sucesso",
     *         @OA\JsonContent(
     *             @OA\Property(property="id", type="integer"),
     *             @OA\Property(property="name", type="string"),
     *             @OA\Property(property="description", type="string"),
     *             @OA\Property(property="active", type="boolean"),
     *             @OA\Property(property="consultants", type="array", @OA\Items(type="object"))
     *         )
     *     ),
     *     @OA\Response(response=400, description="Dados inválidos"),
     *     @OA\Response(response=401, description="Não autenticado"),
     *     @OA\Response(response=403, description="Sem permissão"),
     *     @OA\Response(response=422, description="Erro de validação")
     * )
     */
    public function store(StoreConsultantGroupRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();

            $validated = $request->validated();
            $consultantIds = $validated['consultant_ids'] ?? [];
            unset($validated['consultant_ids']);

            // Verificar se os usuários são realmente consultores
            $consultants = User::whereIn('type', ['consultor', 'parceiro_admin'])->whereIn('id', $consultantIds)->get();

            if ($consultants->count() !== count($consultantIds)) {
                return response()->json([
                    'code' => 'INVALID_CONSULTANTS',
                    'type' => 'error',
                    'message' => 'Um ou mais usuários selecionados não possuem a permissão Consultant.',
                ], 422);
            }

            // Criar o grupo
            $group = ConsultantGroup::create([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'active' => $validated['active'] ?? true,
                'created_by' => $request->user()->id,
            ]);

            // Vincular consultores
            $group->consultants()->attach($consultantIds);

            // Recarregar relações
            $group->load(['consultants', 'creator']);
            $group->consultants_count = $group->consultants->count();

            DB::commit();

            return response()->json($group, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erro ao criar grupo de consultores: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => $request->user()?->id,
                'data' => $request->all()
            ]);

            return response()->json([
                'code' => 'INTERNAL_ERROR',
                'type' => 'error',
                'message' => 'Erro ao criar grupo de consultores.',
                'detailMessage' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/v1/consultant-groups/{id}",
     *     summary="Obter detalhes de um grupo",
     *     description="Retorna os detalhes de um grupo de consultores específico",
     *     tags={"Consultant Groups"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID do grupo",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Detalhes do grupo",
     *         @OA\JsonContent(
     *             @OA\Property(property="id", type="integer"),
     *             @OA\Property(property="name", type="string"),
     *             @OA\Property(property="description", type="string"),
     *             @OA\Property(property="active", type="boolean"),
     *             @OA\Property(property="consultants", type="array", @OA\Items(type="object")),
     *             @OA\Property(property="creator", type="object")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Não autenticado"),
     *     @OA\Response(response=403, description="Sem permissão"),
     *     @OA\Response(response=404, description="Grupo não encontrado")
     * )
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $user = $request->user();

            // Verificar permissões
            if (!$user->isAdmin() && !$user->hasAccess('consultant_groups.view')) {
                return response()->json([
                    'code' => 'PERMISSION_DENIED',
                    'type' => 'error',
                    'message' => 'Você não tem permissão para visualizar grupos de consultores.',
                ], 403);
            }

            $group = ConsultantGroup::with(['consultants', 'creator'])->find($id);

            if (!$group) {
                return response()->json([
                    'code' => 'NOT_FOUND',
                    'type' => 'error',
                    'message' => 'Grupo de consultores não encontrado.',
                ], 404);
            }

            $group->consultants_count = $group->consultants->count();

            return response()->json($group);
        } catch (\Exception $e) {
            Log::error('Erro ao buscar grupo de consultores: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => $request->user()?->id,
                'group_id' => $id
            ]);

            return response()->json([
                'code' => 'INTERNAL_ERROR',
                'type' => 'error',
                'message' => 'Erro ao buscar grupo de consultores.',
                'detailMessage' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * @OA\Put(
     *     path="/api/v1/consultant-groups/{id}",
     *     summary="Atualizar grupo de consultores",
     *     description="Atualiza os dados de um grupo de consultores",
     *     tags={"Consultant Groups"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID do grupo",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string"),
     *             @OA\Property(property="description", type="string"),
     *             @OA\Property(property="active", type="boolean"),
     *             @OA\Property(property="consultant_ids", type="array", @OA\Items(type="integer"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Grupo atualizado com sucesso",
     *         @OA\JsonContent(
     *             @OA\Property(property="id", type="integer"),
     *             @OA\Property(property="name", type="string"),
     *             @OA\Property(property="consultants", type="array", @OA\Items(type="object"))
     *         )
     *     ),
     *     @OA\Response(response=400, description="Dados inválidos"),
     *     @OA\Response(response=401, description="Não autenticado"),
     *     @OA\Response(response=403, description="Sem permissão"),
     *     @OA\Response(response=404, description="Grupo não encontrado"),
     *     @OA\Response(response=422, description="Erro de validação")
     * )
     */
    public function update(UpdateConsultantGroupRequest $request, int $id): JsonResponse
    {
        try {
            DB::beginTransaction();

            $group = ConsultantGroup::find($id);

            if (!$group) {
                return response()->json([
                    'code' => 'NOT_FOUND',
                    'type' => 'error',
                    'message' => 'Grupo de consultores não encontrado.',
                ], 404);
            }

            $validated = $request->validated();
            $consultantIds = $validated['consultant_ids'] ?? null;
            unset($validated['consultant_ids']);

            // Atualizar dados básicos
            if (!empty($validated)) {
                $group->update($validated);
            }

            // Atualizar consultores se fornecido
            if ($consultantIds !== null) {
                // Verificar se os usuários são realmente consultores
                $consultants = User::whereIn('type', ['consultor', 'parceiro_admin'])->whereIn('id', $consultantIds)->get();

                if ($consultants->count() !== count($consultantIds)) {
                    DB::rollBack();
                    return response()->json([
                        'code' => 'INVALID_CONSULTANTS',
                        'type' => 'error',
                        'message' => 'Um ou mais usuários selecionados não possuem a permissão Consultant.',
                    ], 422);
                }

                // Verificar se há pelo menos um consultor
                if (count($consultantIds) === 0) {
                    DB::rollBack();
                    return response()->json([
                        'code' => 'VALIDATION_FAILED',
                        'type' => 'error',
                        'message' => 'O grupo deve ter pelo menos um consultor.',
                    ], 422);
                }

                $group->consultants()->sync($consultantIds);
            }

            // Recarregar relações
            $group->load(['consultants', 'creator']);
            $group->consultants_count = $group->consultants->count();

            DB::commit();

            return response()->json($group);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erro ao atualizar grupo de consultores: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => $request->user()?->id,
                'group_id' => $id,
                'data' => $request->all()
            ]);

            return response()->json([
                'code' => 'INTERNAL_ERROR',
                'type' => 'error',
                'message' => 'Erro ao atualizar grupo de consultores.',
                'detailMessage' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/consultant-groups/{id}",
     *     summary="Excluir grupo de consultores",
     *     description="Remove um grupo de consultores do sistema (soft delete)",
     *     tags={"Consultant Groups"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID do grupo",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=204,
     *         description="Grupo excluído com sucesso"
     *     ),
     *     @OA\Response(response=401, description="Não autenticado"),
     *     @OA\Response(response=403, description="Sem permissão"),
     *     @OA\Response(response=404, description="Grupo não encontrado")
     * )
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $user = $request->user();

            // Verificar permissões: groups.manage (cadastrada em PermissionService)
            // ou consultant_groups.delete (legado).
            if (!$user->isAdmin()
                && !$user->hasAccess('groups.manage')
                && !$user->hasAccess('consultant_groups.delete')
            ) {
                return response()->json([
                    'code' => 'PERMISSION_DENIED',
                    'type' => 'error',
                    'message' => 'Você não tem permissão para excluir grupos de consultores.',
                ], 403);
            }

            $group = ConsultantGroup::find($id);

            if (!$group) {
                return response()->json([
                    'code' => 'NOT_FOUND',
                    'type' => 'error',
                    'message' => 'Grupo de consultores não encontrado.',
                ], 404);
            }

            $group->delete();

            return response()->json(null, 204);
        } catch (\Exception $e) {
            Log::error('Erro ao excluir grupo de consultores: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => $request->user()?->id,
                'group_id' => $id
            ]);

            return response()->json([
                'code' => 'INTERNAL_ERROR',
                'type' => 'error',
                'message' => 'Erro ao excluir grupo de consultores.',
                'detailMessage' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/v1/consultant-groups/available-consultants",
     *     summary="Listar consultores disponíveis",
     *     description="Retorna lista de usuários com permissão Consultant",
     *     tags={"Consultant Groups"},
     *     security={{"sanctum":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Lista de consultores disponíveis",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(
     *                 @OA\Property(property="id", type="integer"),
     *                 @OA\Property(property="name", type="string"),
     *                 @OA\Property(property="email", type="string")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Não autenticado"),
     *     @OA\Response(response=403, description="Sem permissão")
     * )
     */
    public function availableConsultants(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            // Verificar permissões
            if (!$user->isAdmin() && !$user->hasAccess('consultant_groups.view')) {
                return response()->json([
                    'code' => 'PERMISSION_DENIED',
                    'type' => 'error',
                    'message' => 'Você não tem permissão para visualizar consultores.',
                ], 403);
            }

            $consultants = User::whereIn('type', ['consultor', 'parceiro_admin'])
            ->where('enabled', true)
            ->select('id', 'name', 'email')
            ->orderBy('name')
            ->get();

            return response()->json(['items' => $consultants]);
        } catch (\Exception $e) {
            Log::error('Erro ao listar consultores disponíveis: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => $request->user()?->id
            ]);

            return response()->json([
                'code' => 'INTERNAL_ERROR',
                'type' => 'error',
                'message' => 'Erro ao listar consultores disponíveis.',
                'detailMessage' => $e->getMessage(),
            ], 500);
        }
    }
}

