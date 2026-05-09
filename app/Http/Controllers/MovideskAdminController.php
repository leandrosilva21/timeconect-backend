<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\MovideskOrganization;
use App\Models\Project;
use App\Models\SystemSetting;
use App\Models\Timesheet;
use App\Services\MovideskService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MovideskAdminController extends Controller
{
    /**
     * Retorna o status da integração Movidesk:
     * - data/hora da última sincronização
     * - total de timesheets importados via webhook
     */
    public function status(): JsonResponse
    {
        try {
            $lastSync = SystemSetting::get('movidesk_last_sync');

            $totalImported = Timesheet::where('origin', 'webhook')->count();

            $todayImported = Timesheet::where('origin', 'webhook')
                ->whereDate('created_at', today())
                ->count();

            return response()->json([
                'last_sync'        => $lastSync,
                'last_sync_human'  => $lastSync ? Carbon::parse($lastSync)->timezone('America/Sao_Paulo')->format('d/m/Y H:i') : null,
                'total_imported'   => $totalImported,
                'today_imported'   => $todayImported,
                'token_configured' => !empty(config('services.movidesk.token')),
            ]);
        } catch (\Throwable $e) {
            Log::error('[Movidesk] Erro ao carregar status: ' . $e->getMessage());
            return response()->json([
                'last_sync'        => null,
                'last_sync_human'  => null,
                'total_imported'   => 0,
                'today_imported'   => 0,
                'token_configured' => !empty(config('services.movidesk.token')),
                'error'            => $e->getMessage(),
            ]);
        }
    }

    /**
     * Dispara sincronização manual via service (output rico para debug).
     * Aceita parâmetro opcional `since` (ISO 8601).
     */
    public function sync(Request $request): JsonResponse
    {
        $sinceInput = $request->input('since');

        if (!config('services.movidesk.token')) {
            return response()->json([
                'success' => false,
                'message' => 'MOVIDESK_API_TOKEN não configurado no servidor.',
            ], 422);
        }

        // Resolver `since`
        // Manual sync usa janela de 2h para não estourar timeout HTTP (504).
        // O cron cobre janela de 48h em background sem timeout.
        if ($sinceInput) {
            $since = Carbon::parse($sinceInput);
        } else {
            $lastSync     = SystemSetting::get('movidesk_last_sync');
            $maxLookback  = now()->subHours(2); // limite para evitar 504 no HTTP

            if ($lastSync) {
                $fromLastSync = Carbon::parse($lastSync)->subMinutes(20);
                // Usa o mais antigo entre last_sync-20min e now-2h, mas não mais que 2h
                $since = $fromLastSync->lt($maxLookback) ? $maxLookback : $fromLastSync;
            } else {
                $since = $maxLookback;
            }
        }

        Log::info('🔧 [MOVIDESK ADMIN] Sync manual iniciado', [
            'since'        => $since->toIso8601String(),
            'triggered_by' => auth()->user()?->email,
        ]);

        try {
            $params = ['--since' => $since->toIso8601String()];
            Artisan::call('movidesk:sync', $params);
            $output = trim(Artisan::output());
        } catch (\Throwable $e) {
            Log::error('🚨 [MOVIDESK ADMIN] Erro no sync manual', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Erro ao executar sincronização: ' . $e->getMessage(),
            ], 500);
        }

        $lastSync = SystemSetting::get('movidesk_last_sync');

        return response()->json([
            'success'         => true,
            'message'         => 'Sincronização concluída.',
            'output'          => $output ?: "Sync concluído desde: {$since->timezone('America/Sao_Paulo')->format('d/m/Y H:i:s')}",
            'last_sync'       => $lastSync,
            'last_sync_human' => $lastSync
                ? Carbon::parse($lastSync)->timezone('America/Sao_Paulo')->format('d/m/Y H:i')
                : null,
            'today_imported'  => Timesheet::where('origin', 'webhook')->whereDate('created_at', today())->count(),
            'total_imported'  => Timesheet::where('origin', 'webhook')->count(),
        ]);
    }

    /**
     * Enfileira importação histórica do Movidesk desde uma data específica.
     * Roda em background (queue) para evitar timeout HTTP.
     * POST /api/v1/movidesk/history-import  { "since": "2026-04-01" }
     */
    public function historyImport(Request $request): JsonResponse
    {
        if (!config('services.movidesk.token')) {
            return response()->json(['success' => false, 'message' => 'MOVIDESK_API_TOKEN não configurado.'], 422);
        }

        $since = $request->input('since');
        if (!$since) {
            return response()->json(['success' => false, 'message' => 'Parâmetro "since" é obrigatório.'], 422);
        }

        try {
            $sinceCarbon = Carbon::parse($since)->startOfDay();
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Data inválida.'], 422);
        }

        \Illuminate\Support\Facades\Artisan::queue('movidesk:sync', [
            '--since' => $sinceCarbon->toIso8601String(),
        ]);

        Log::info('📥 [MOVIDESK] Importação histórica enfileirada', [
            'since'        => $sinceCarbon->toIso8601String(),
            'triggered_by' => auth()->user()?->email,
        ]);

        return response()->json([
            'success' => true,
            'message' => "Importação histórica enfileirada desde {$sinceCarbon->format('d/m/Y')}. Acompanhe o progresso nos logs.",
            'since'   => $sinceCarbon->toIso8601String(),
        ], 202);
    }

    /**
     * Diagnóstico: chama a API Movidesk e retorna a resposta bruta para debug.
     * GET /api/v1/movidesk/debug
     */
    public function debug(Request $request): JsonResponse
    {
        $token = config('services.movidesk.token');

        if (!$token) {
            return response()->json(['error' => 'Token não configurado'], 422);
        }

        $since = $request->input('since', now()->subHours(48)->utc()->format('Y-m-d\TH:i:s'));

        // Testa 3 formatos de filtro diferentes
        $tests = [];

        $formats = [
            'bare'     => "lastUpdate gt {$since}",
            'with_Z'   => "lastUpdate gt {$since}Z",
            'datetime' => "lastUpdate gt datetime'{$since}'",
        ];

        foreach ($formats as $name => $filter) {
            $url = 'https://api.movidesk.com/public/v1/tickets'
                . '?token=' . urlencode($token)
                . '&$filter=' . urlencode($filter)
                . '&$select=id,lastUpdate'
                . '&$top=5';

            try {
                $resp = Http::timeout(15)->get($url);
                $tests[$name] = [
                    'filter'  => $filter,
                    'url'     => $url,
                    'status'  => $resp->status(),
                    'body'    => $resp->body(),
                ];
            } catch (\Throwable $e) {
                $tests[$name] = ['error' => $e->getMessage()];
            }
        }

        // Busca o ticket mais recente com expand completo para ver estrutura real
        try {
            $urlFull = 'https://api.movidesk.com/public/v1/tickets'
                . '?token=' . urlencode($token)
                . '&$expand=clients,owner,actions($expand=timeAppointments)'
                . '&$top=1'
                . '&$orderby=lastUpdate desc';
            $resp = Http::timeout(20)->get($urlFull);
            $tests['latest_full'] = [
                'status' => $resp->status(),
                'body'   => $resp->json(),
            ];
        } catch (\Throwable $e) {
            $tests['latest_full'] = ['error' => $e->getMessage()];
        }

        return response()->json($tests);
    }

    /**
     * Diagnóstico do mapeamento org→cliente→projeto para o Movidesk.
     * GET /api/v1/movidesk/debug-orgs
     */
    public function debugOrgs(): JsonResponse
    {
        $orgs = MovideskOrganization::orderBy('name')->get()->map(function ($o) {
            $customer = $o->customer_id ? Customer::select('id', 'name', 'company_name')->find($o->customer_id) : null;

            // Projeto vinculado manualmente à org (prioridade)
            $linkedProject = $o->project_id ? Project::select('id', 'name', 'status')->find($o->project_id) : null;

            // Projeto de sustentação do cliente (fallback automático)
            $sustProject = null;
            if ($o->customer_id) {
                $sustProject = Project::where('customer_id', $o->customer_id)
                    ->join('service_types', 'service_types.id', '=', 'projects.service_type_id')
                    ->where(function ($q) {
                        $q->where('service_types.code', 'sustentacao')
                          ->orWhere('service_types.name', 'ilike', '%sustenta%');
                    })
                    ->select('projects.id', 'projects.name', 'projects.status',
                             'service_types.code as st_code', 'service_types.name as st_name')
                    ->first();
            }

            $resolvedProject = $linkedProject ?? $sustProject;

            return [
                'org_id'              => $o->id,
                'org_name'            => $o->name,
                'cnpj'                => $o->cnpj,
                'customer_id'         => $o->customer_id,
                'customer_name'       => $customer?->name ?? '(sem vínculo)',
                'linked_project_id'   => $o->project_id,
                'linked_project_name' => $linkedProject?->name,
                'sust_project_id'     => $sustProject?->id,
                'sust_project_name'   => $sustProject?->name,
                'project_id'          => $resolvedProject?->id,
                'project_name'        => $resolvedProject?->name ?? '(sem projeto)',
                'project_source'      => $linkedProject ? 'manual' : ($sustProject ? 'sustentacao' : null),
                'project_status'      => $resolvedProject?->status,
                'is_active_proj'      => $resolvedProject
                    ? !in_array($resolvedProject->status, ['cancelled', 'finished', 'paused'])
                    : null,
            ];
        });

        $defaultProjectId = SystemSetting::get('movidesk_default_project_id');
        $defaultProject   = $defaultProjectId ? Project::select('id', 'name')->find($defaultProjectId) : null;

        return response()->json([
            'default_project_id'   => $defaultProjectId,
            'default_project_name' => $defaultProject?->name ?? '(não configurado)',
            'orgs'                 => $orgs,
            'unlinked_count'       => $orgs->whereNull('customer_id')->count(),
            'no_project_count'     => $orgs->filter(fn($o) => is_null($o['project_id']))->count(),
        ]);
    }

    /**
     * Vincula manualmente uma org Movidesk a um cliente.
     * POST /api/v1/movidesk/link-org
     * Body: { "org_id": 1, "customer_id": 42 }
     */
    public function linkOrg(Request $request): JsonResponse
    {
        $key = (string) $request->header('X-Admin-Key', '');
        $expected = (string) config('app.admin_exec_key');
        if ($expected === '' || !hash_equals($expected, $key)) {
            return response()->json(['error' => 'Não autorizado'], 403);
        }

        $orgId      = $request->input('org_id');
        $customerId = $request->input('customer_id');

        if (!$orgId || !$customerId) {
            return response()->json(['error' => 'org_id e customer_id são obrigatórios'], 422);
        }

        $org = MovideskOrganization::find($orgId);
        if (!$org) {
            return response()->json(['error' => "Org #{$orgId} não encontrada"], 404);
        }

        $customer = Customer::find($customerId);
        if (!$customer) {
            return response()->json(['error' => "Cliente #{$customerId} não encontrado"], 404);
        }

        $org->customer_id = (int) $customerId;
        $org->project_id  = null; // limpa projeto ao revincultar cliente
        $org->save();

        return response()->json([
            'success'       => true,
            'org_name'      => $org->name,
            'customer_name' => $customer->name,
            'message'       => 'Vínculo salvo. Execute sync-orgs para reprocessar os tickets.',
        ]);
    }

    /**
     * Vincula manualmente uma org Movidesk a um projeto específico.
     * POST /api/v1/movidesk/link-org-project
     * Body: { "org_id": 1, "project_id": 42 }
     */
    public function linkOrgProject(Request $request): JsonResponse
    {
        $orgId     = $request->input('org_id');
        $projectId = $request->input('project_id'); // null = remover vínculo

        if (!$orgId) {
            return response()->json(['error' => 'org_id é obrigatório'], 422);
        }

        $org = MovideskOrganization::find($orgId);
        if (!$org) {
            return response()->json(['error' => "Org #{$orgId} não encontrada"], 404);
        }

        $project = null;
        if ($projectId) {
            $project = Project::find($projectId);
            if (!$project) {
                return response()->json(['error' => "Projeto #{$projectId} não encontrado"], 404);
            }
        }

        $org->project_id = $projectId ? (int) $projectId : null;
        $org->save();

        return response()->json([
            'success'      => true,
            'org_name'     => $org->name,
            'project_name' => $project?->name ?? null,
            'message'      => $project
                ? "Org vinculada ao projeto \"{$project->name}\"."
                : 'Vínculo de projeto removido — voltará a usar projeto de sustentação ou padrão.',
        ]);
    }
}
