<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\HourContribution;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * @OA\Tag(
 *     name="Hour Contributions",
 *     description="Gerenciamento de Aportes de Horas"
 * )
 */
class HourContributionController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/v1/projects/{project}/hour-contributions",
     *     tags={"Hour Contributions"},
     *     summary="Listar aportes de horas de um projeto",
     *     description="Lista todos os aportes de horas adicionados ao projeto",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="project",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer", example=1),
     *         description="ID do projeto"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lista de aportes",
     *         @OA\JsonContent(
     *             @OA\Property(property="hasNext", type="boolean", example=false),
     *             @OA\Property(
     *                 property="items",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer"),
     *                     @OA\Property(property="project_id", type="integer"),
     *                     @OA\Property(property="contributed_hours", type="integer"),
     *                     @OA\Property(property="hourly_rate", type="number"),
     *                     @OA\Property(property="description", type="string"),
     *                     @OA\Property(property="contributed_at", type="string", format="date-time"),
     *                     @OA\Property(property="contributed_by_user", type="object")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=404, description="Projeto não encontrado"),
     *     @OA\Response(response=401, description="Não autenticado")
     * )
     */
    public function index(Project $project): JsonResponse
    {
        $contributions = $project->hourContributions()
            ->with('contributedBy:id,name,email')
            ->get();
        
        // Adicionar campo total_value calculado
        $contributions->transform(function ($contribution) {
            $contribution->total_value = $contribution->getTotalValue();
            return $contribution;
        });
        
        return response()->json([
            'hasNext' => false,
            'items' => $contributions
        ]);
    }
    
    /**
     * @OA\Post(
     *     path="/api/v1/projects/{project}/hour-contributions",
     *     tags={"Hour Contributions"},
     *     summary="Criar novo aporte de horas",
     *     description="Adiciona um novo aporte de horas ao projeto",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="project",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer", example=1),
     *         description="ID do projeto"
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"contributed_hours", "hourly_rate"},
     *             @OA\Property(property="contributed_hours", type="integer", example=20, description="Quantidade de horas"),
     *             @OA\Property(property="hourly_rate", type="number", example=180.00, description="Valor da hora"),
     *             @OA\Property(property="description", type="string", example="Aporte adicional", description="Descrição/motivo"),
     *             @OA\Property(property="contributed_at", type="string", format="date", example="2026-02-12", description="Data do aporte")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Aporte criado com sucesso",
     *         @OA\JsonContent(
     *             @OA\Property(property="id", type="integer"),
     *             @OA\Property(property="project_id", type="integer"),
     *             @OA\Property(property="contributed_hours", type="integer"),
     *             @OA\Property(property="hourly_rate", type="number"),
     *             @OA\Property(property="description", type="string"),
     *             @OA\Property(property="contributed_at", type="string")
     *         )
     *     ),
     *     @OA\Response(response=422, description="Dados de validação inválidos"),
     *     @OA\Response(response=404, description="Projeto não encontrado"),
     *     @OA\Response(response=401, description="Não autenticado")
     * )
     */
    public function store(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'contributed_hours' => 'required|numeric|min:0.01|max:999999',
            'hourly_rate' => 'required|numeric|min:0.01|max:9999.99',
            'description' => 'nullable|string|max:1000',
            'motivo' => 'nullable|in:aporte,excedentes,absorvidas',
            'contributed_at' => 'nullable|date',
        ], [
            'contributed_hours.required' => 'A quantidade de horas é obrigatória',
            'contributed_hours.min' => 'A quantidade de horas deve ser maior que zero',
            'contributed_hours.max' => 'A quantidade de horas não pode exceder 999.999',
            'hourly_rate.required' => 'O valor da hora é obrigatório',
            'hourly_rate.min' => 'O valor da hora deve ser maior que zero',
            'hourly_rate.max' => 'O valor da hora não pode exceder R$ 9.999,99',
            'description.max' => 'A descrição não pode ter mais de 1000 caracteres',
            'contributed_at.date' => 'Data do aporte inválida',
        ]);
        
        $contribution = $project->hourContributions()->create([
            ...$validated,
            'motivo' => $validated['motivo'] ?? 'aporte',
            'contributed_by' => $request->user()->id,
            'contributed_at' => $validated['contributed_at'] ?? now(),
        ]);
        
        $contribution->load('contributedBy:id,name,email');
        $contribution->total_value = $contribution->getTotalValue();
        
        return response()->json($contribution, 201);
    }
    
    /**
     * @OA\Put(
     *     path="/api/v1/projects/{project}/hour-contributions/{contribution}",
     *     tags={"Hour Contributions"},
     *     summary="Atualizar aporte de horas",
     *     description="Atualiza os dados de um aporte existente",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="project",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer", example=1),
     *         description="ID do projeto"
     *     ),
     *     @OA\Parameter(
     *         name="contribution",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer", example=1),
     *         description="ID do aporte"
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="contributed_hours", type="integer", example=25),
     *             @OA\Property(property="hourly_rate", type="number", example=200.00),
     *             @OA\Property(property="description", type="string", example="Aporte atualizado"),
     *             @OA\Property(property="contributed_at", type="string", format="date", example="2026-02-12")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Aporte atualizado com sucesso"
     *     ),
     *     @OA\Response(response=422, description="Dados de validação inválidos"),
     *     @OA\Response(response=404, description="Aporte ou projeto não encontrado"),
     *     @OA\Response(response=401, description="Não autenticado")
     * )
     */
    public function update(Request $request, Project $project, HourContribution $contribution): JsonResponse
    {
        // Verificar se o aporte pertence ao projeto
        if ($contribution->project_id !== $project->id) {
            return response()->json([
                'code' => 'INVALID_CONTRIBUTION',
                'type' => 'error',
                'message' => 'Aporte não encontrado',
                'detailMessage' => 'Este aporte não pertence ao projeto especificado'
            ], 404);
        }
        
        $validated = $request->validate([
            'contributed_hours' => 'sometimes|numeric|min:0.01|max:999999',
            'hourly_rate' => 'sometimes|numeric|min:0.01|max:9999.99',
            'description' => 'nullable|string|max:1000',
            'motivo' => 'sometimes|in:aporte,excedentes,absorvidas',
            'contributed_at' => 'sometimes|date',
        ], [
            'contributed_hours.min' => 'A quantidade de horas deve ser pelo menos 1',
            'contributed_hours.max' => 'A quantidade de horas não pode exceder 999.999',
            'hourly_rate.min' => 'O valor da hora deve ser maior que zero',
            'hourly_rate.max' => 'O valor da hora não pode exceder R$ 9.999,99',
            'description.max' => 'A descrição não pode ter mais de 1000 caracteres',
            'contributed_at.date' => 'Data do aporte inválida',
        ]);
        
        $contribution->update($validated);
        $contribution->load('contributedBy:id,name,email');
        $contribution->total_value = $contribution->getTotalValue();
        
        return response()->json($contribution);
    }
    
    /**
     * @OA\Delete(
     *     path="/api/v1/projects/{project}/hour-contributions/{contribution}",
     *     tags={"Hour Contributions"},
     *     summary="Excluir aporte de horas",
     *     description="Remove um aporte de horas do projeto (soft delete)",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="project",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer", example=1),
     *         description="ID do projeto"
     *     ),
     *     @OA\Parameter(
     *         name="contribution",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer", example=1),
     *         description="ID do aporte"
     *     ),
     *     @OA\Response(response=204, description="Aporte excluído com sucesso"),
     *     @OA\Response(response=404, description="Aporte ou projeto não encontrado"),
     *     @OA\Response(response=401, description="Não autenticado")
     * )
     */
    public function destroy(Project $project, HourContribution $contribution): JsonResponse
    {
        // Verificar se o aporte pertence ao projeto
        if ($contribution->project_id !== $project->id) {
            return response()->json([
                'code' => 'INVALID_CONTRIBUTION',
                'type' => 'error',
                'message' => 'Aporte não encontrado',
                'detailMessage' => 'Este aporte não pertence ao projeto especificado'
            ], 404);
        }
        
        $contribution->delete();
        
        return response()->json(null, 204);
    }
}
