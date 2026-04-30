<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * @OA\Tag(
 *     name="System Settings",
 *     description="Gerenciamento de configurações do sistema"
 * )
 */
class SystemSettingController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/v1/system-settings",
     *     summary="Listar todas as configurações do sistema",
     *     description="Retorna todas as configurações organizadas por grupo",
     *     tags={"System Settings"},
     *     security={{"sanctum":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Configurações retornadas com sucesso",
     *         @OA\JsonContent(
     *             @OA\Property(property="general", type="object",
     *                 @OA\Property(property="timesheet_retroactive_limit_days", type="integer", example=7)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Não autenticado"),
     *     @OA\Response(response=403, description="Sem permissão")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        try {
            Log::info('🎫 [SYSTEM SETTINGS] Listando configurações do sistema');
            $user = $request->user();

            // Verificar permissões
            if (!$user->isAdmin() && !$user->hasAccess('system_settings.view')) {
                return response()->json([
                    'code' => 'PERMISSION_DENIED',
                    'type' => 'error',
                    'message' => 'Você não tem permissão para visualizar configurações do sistema.',
                ], 403);
            }

            $settings = SystemSetting::all();

            $flat = [];
            foreach ($settings as $setting) {
                $flat[$setting->key] = $this->castValue($setting->value, $setting->type);
            }

            return response()->json($flat);
        } catch (\Exception $e) {
            Log::error('Erro ao listar configurações do sistema: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => $request->user()?->id
            ]);

            return response()->json([
                'code' => 'INTERNAL_ERROR',
                'type' => 'error',
                'message' => 'Erro ao listar configurações do sistema.',
                'detailMessage' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/v1/system-settings/{key}",
     *     summary="Obter uma configuração específica",
     *     description="Retorna o valor de uma configuração específica",
     *     tags={"System Settings"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="key",
     *         in="path",
     *         description="Chave da configuração",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Configuração encontrada",
     *         @OA\JsonContent(
     *             @OA\Property(property="key", type="string", example="timesheet_retroactive_limit_days"),
     *             @OA\Property(property="value", type="integer", example=7),
     *             @OA\Property(property="type", type="string", example="integer"),
     *             @OA\Property(property="description", type="string")
     *         )
     *     ),
     *     @OA\Response(response=404, description="Configuração não encontrada")
     * )
     */
    public function show(Request $request, string $key): JsonResponse
    {
        try {
            $user = $request->user();

            // Verificar permissões
            if (!$user->isAdmin() && !$user->hasAccess('system_settings.view')) {
                return response()->json([
                    'code' => 'PERMISSION_DENIED',
                    'type' => 'error',
                    'message' => 'Você não tem permissão para visualizar configurações do sistema.',
                ], 403);
            }

            $setting = SystemSetting::where('key', $key)->first();

            if (!$setting) {
                return response()->json([
                    'code' => 'NOT_FOUND',
                    'type' => 'error',
                    'message' => 'Configuração não encontrada.',
                ], 404);
            }

            return response()->json([
                'key' => $setting->key,
                'value' => $this->castValue($setting->value, $setting->type),
                'type' => $setting->type,
                'group' => $setting->group,
                'description' => $setting->description,
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao buscar configuração: ' . $e->getMessage(), [
                'exception' => $e,
                'key' => $key
            ]);

            return response()->json([
                'code' => 'INTERNAL_ERROR',
                'type' => 'error',
                'message' => 'Erro ao buscar configuração.',
                'detailMessage' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * @OA\Put(
     *     path="/api/v1/system-settings",
     *     summary="Atualizar configurações do sistema",
     *     description="Atualiza múltiplas configurações de uma vez",
     *     tags={"System Settings"},
     *     security={{"sanctum":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="timesheet_retroactive_limit_days", type="integer", example=7),
     *             @OA\Property(property="movidesk_default_customer_id", type="integer", nullable=true, example=1),
     *             @OA\Property(property="movidesk_default_project_id", type="integer", nullable=true, example=1)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Configurações atualizadas com sucesso",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Configurações atualizadas com sucesso")
     *         )
     *     ),
     *     @OA\Response(response=422, description="Erro de validação")
     * )
     */
    public function update(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            // Verificar permissões
            if (!$user->isAdmin() && !$user->hasAccess('system_settings.update')) {
                return response()->json([
                    'code' => 'PERMISSION_DENIED',
                    'type' => 'error',
                    'message' => 'Você não tem permissão para atualizar configurações do sistema.',
                ], 403);
            }

            // Validação
            $validator = Validator::make($request->all(), [
                'timesheet_retroactive_limit_days'      => 'nullable|integer|min:0|max:365',
                'movidesk_default_customer_id'          => 'nullable|integer|exists:customers,id',
                'movidesk_default_project_id'           => 'nullable|integer|exists:projects,id',
                'movidesk_default_user_id'              => 'nullable|integer|exists:users,id',
                'movidesk_sync_orgs_interval_minutes'   => 'nullable|integer|in:5,10,15,20,30,60',
                'movidesk_portal_sync_interval_minutes' => 'nullable|integer|in:5,10,15,20,30,60',
            ], [
                'timesheet_retroactive_limit_days.integer'         => 'O prazo deve ser um número inteiro.',
                'timesheet_retroactive_limit_days.min'             => 'O prazo não pode ser negativo.',
                'timesheet_retroactive_limit_days.max'             => 'O prazo não pode ser maior que 365 dias.',
                'movidesk_default_customer_id.integer'             => 'O cliente padrão deve ser um número inteiro.',
                'movidesk_default_customer_id.exists'              => 'O cliente selecionado não existe.',
                'movidesk_default_project_id.integer'              => 'O projeto padrão deve ser um número inteiro.',
                'movidesk_default_project_id.exists'               => 'O projeto selecionado não existe.',
                'movidesk_default_user_id.integer'                 => 'O usuário padrão deve ser um número inteiro.',
                'movidesk_default_user_id.exists'                  => 'O usuário selecionado não existe.',
                'movidesk_sync_orgs_interval_minutes.in'           => 'Intervalo inválido para sync-orgs. Use: 5, 10, 15, 20, 30 ou 60 minutos.',
                'movidesk_portal_sync_interval_minutes.in'         => 'Intervalo inválido para portal-sync. Use: 5, 10, 15, 20, 30 ou 60 minutos.',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'code' => 'VALIDATION_FAILED',
                    'type' => 'error',
                    'message' => 'Dados inválidos.',
                    'details' => $validator->errors(),
                ], 422);
            }

            $data = $validator->validated();

            // Atualizar configurações
            foreach ($data as $key => $value) {
                SystemSetting::set(
                    $key,
                    $value,
                    $this->getSettingType($key),
                    $this->getSettingGroup($key),
                    $this->getSettingDescription($key)
                );
            }

            return response()->json([
                'message' => 'Configurações atualizadas com sucesso.',
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao atualizar configurações: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => $request->user()?->id,
                'data' => $request->all()
            ]);

            return response()->json([
                'code' => 'INTERNAL_ERROR',
                'type' => 'error',
                'message' => 'Erro ao atualizar configurações.',
                'detailMessage' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Converter valor do tipo correto
     */
    private function castValue($value, string $type)
    {
        return match ($type) {
            'boolean' => (bool) $value,
            'integer' => (int) $value,
            'json' => json_decode($value, true),
            default => $value,
        };
    }

    /**
     * Obter tipo da configuração
     */
    private function getSettingType(string $key): string
    {
        return match ($key) {
            'timesheet_retroactive_limit_days'      => 'integer',
            'movidesk_default_customer_id'          => 'integer',
            'movidesk_default_project_id'           => 'integer',
            'movidesk_default_user_id'              => 'integer',
            'movidesk_sync_orgs_interval_minutes'   => 'integer',
            'movidesk_portal_sync_interval_minutes' => 'integer',
            default => 'string',
        };
    }

    /**
     * Obter grupo da configuração
     */
    private function getSettingGroup(string $key): string
    {
        return match ($key) {
            'timesheet_retroactive_limit_days'      => 'timesheets',
            'movidesk_default_customer_id'          => 'movidesk',
            'movidesk_default_project_id'           => 'movidesk',
            'movidesk_default_user_id'              => 'movidesk',
            'movidesk_sync_orgs_interval_minutes'   => 'movidesk',
            'movidesk_portal_sync_interval_minutes' => 'movidesk',
            default => 'general',
        };
    }

    /**
     * Obter descrição da configuração
     */
    private function getSettingDescription(string $key): string
    {
        return match ($key) {
            'timesheet_retroactive_limit_days'      => 'Quantidade de dias após a data do serviço que o consultor pode lançar horas',
            'movidesk_default_customer_id'          => 'ID do cliente padrão para integração com Movidesk',
            'movidesk_default_project_id'           => 'ID do projeto padrão para integração com Movidesk',
            'movidesk_default_user_id'              => 'ID do usuário padrão para integração com Movidesk',
            'movidesk_sync_orgs_interval_minutes'   => 'Intervalo em minutos para sincronização de organizações do Movidesk (5, 10, 15, 20, 30 ou 60)',
            'movidesk_portal_sync_interval_minutes' => 'Intervalo em minutos para sincronização do Portal de Sustentação do Movidesk (5, 10, 15, 20, 30 ou 60)',
            default => '',
        };
    }
}

