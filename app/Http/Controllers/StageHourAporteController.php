<?php

namespace App\Http\Controllers;

use App\Models\ProjectStage;
use App\Models\StageDelivery;
use App\Models\StageHourAporte;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Aportes operacionais de horas na etapa (ADR 0004).
 *
 * Registro imutável + atualização atômica de stage.hours_planned. Não há
 * UPDATE/DELETE de aporte — extorno futuro seria novo registro negativo.
 */
class StageHourAporteController extends Controller
{
    public function index(ProjectStage $stage): JsonResponse
    {
        $items = $stage->aportes()->with('user:id,name,email')->get();

        $total = (float) $items->sum('hours');

        return response()->json([
            'items' => $items,
            'totals' => [
                'count' => $items->count(),
                'hours' => round($total, 2),
            ],
        ]);
    }

    /**
     * @deprecated 2026-05-15 — aporte stage-level virou legado. ADR 0007 moveu
     * a unidade de execução pra atividade (stage_delivery). Use o novo endpoint
     * POST /activities/{delivery}/aportes (storeForActivity).
     *
     * Continuamos aceitando aqui pra compat de chamadas antigas, mas a UI nova
     * (side panel da atividade) já usa o endpoint da atividade.
     */
    public function store(Request $request, ProjectStage $stage): JsonResponse
    {
        \Log::warning('[deprecated] POST /stages/{id}/aportes — use POST /activities/{id}/aportes (ADR 0007)', [
            'stage_id' => $stage->id,
            'user_id'  => Auth::id(),
            'route'    => $request->path(),
        ]);

        $data = $request->validate([
            'hours'  => 'required|numeric|not_in:0',
            'reason' => 'required|string|min:5|max:500',
        ], [
            'hours.required'  => 'Informe a quantidade de horas.',
            'hours.not_in'    => 'Aporte com 0h não faz sentido.',
            'reason.required' => 'Justificativa obrigatória.',
            'reason.min'      => 'Justificativa precisa ter pelo menos 5 caracteres.',
        ]);

        $hours = (float) $data['hours'];

        // Validação de saldo do projeto — apenas pra aportes positivos
        $stage->loadMissing('project.serviceType');
        $project = $stage->project;

        if ($project && $project->isOperational() && $hours > 0) {
            $pool      = $project->cronogramaPoolHours();
            $allocated = (float) $project->stages()->sum('hours_planned');
            $available = $pool - $allocated;

            if ($hours > $available + 0.001) {
                return response()->json([
                    'message' => 'Sem saldo disponível. Verifique com o coordenador.',
                    'detail'  => sprintf(
                        'Aporte de %.1fh excede o saldo liberado à gestão. Disponível: %.1fh (liberadas %.1fh, alocadas %.1fh).',
                        $hours, $available, $pool, $allocated
                    ),
                ], 422);
            }
        }

        // Pra aporte negativo (extorno futuro), validar que stage.hours_planned não fica < 0
        if ($hours < 0) {
            $newStagePlanned = (float) $stage->hours_planned + $hours;
            if ($newStagePlanned < 0) {
                return response()->json([
                    'message' => 'Extorno excede o planejado da etapa.',
                    'detail'  => sprintf(
                        'Etapa tem %.1fh planejadas. Extorno de %.1fh deixaria %.1fh.',
                        $stage->hours_planned, abs($hours), $newStagePlanned
                    ),
                ], 422);
            }
        }

        $aporte = DB::transaction(function () use ($stage, $hours, $data) {
            $aporte = StageHourAporte::create([
                'stage_id' => $stage->id,
                'user_id'  => Auth::id(),
                'hours'    => $hours,
                'reason'   => trim($data['reason']),
            ]);

            // Atualiza horas planejadas da etapa atomicamente
            $stage->increment('hours_planned', $hours);

            return $aporte;
        });

        return response()->json($aporte->load('user:id,name,email'), 201);
    }

    /**
     * Aporte no nível da atividade (Pilar C do refactor 2026-05-15).
     *
     * Diferenças vs stage-level:
     * - delivery_id setado no aporte
     * - Incrementa `stage_deliveries.hours_planned` (não stage)
     * - Saldo do projeto = sold − SUM(stage_deliveries.hours_planned)
     */
    public function storeForActivity(Request $request, StageDelivery $delivery): JsonResponse
    {
        $data = $request->validate([
            'hours'  => 'required|numeric|not_in:0',
            'reason' => 'required|string|min:5|max:500',
        ], [
            'hours.required'  => 'Informe a quantidade de horas.',
            'hours.not_in'    => 'Aporte com 0h não faz sentido.',
            'reason.required' => 'Justificativa obrigatória.',
            'reason.min'      => 'Justificativa precisa ter pelo menos 5 caracteres.',
        ]);

        $hours = (float) $data['hours'];

        $delivery->loadMissing('stage.project.serviceType');
        $stage   = $delivery->stage;
        $project = $stage?->project;

        if ($project && $project->isOperational() && $hours > 0) {
            $pool = $project->cronogramaPoolHours();
            // Saldo agora considera SUM dos deliveries (Pilar D do refactor):
            // pool − SUM(stage_deliveries.hours_planned) >= hours
            $allocated = (float) DB::table('stage_deliveries as sd')
                ->join('project_stages as ps', 'ps.id', '=', 'sd.stage_id')
                ->where('ps.project_id', $project->id)
                ->whereNull('sd.deleted_at')
                ->whereNull('ps.deleted_at')
                ->sum('sd.hours_planned');
            $available = $pool - $allocated;

            if ($hours > $available + 0.001) {
                return response()->json([
                    'message' => 'Sem saldo disponível. Verifique com o coordenador.',
                    'detail'  => sprintf(
                        'Aporte de %.1fh excede o saldo liberado à gestão. Disponível: %.1fh (liberadas %.1fh, alocadas em atividades %.1fh).',
                        $hours, $available, $pool, $allocated
                    ),
                ], 422);
            }
        }

        if ($hours < 0) {
            $newPlanned = (float) $delivery->hours_planned + $hours;
            if ($newPlanned < 0) {
                return response()->json([
                    'message' => 'Extorno excede o planejado da atividade.',
                    'detail'  => sprintf(
                        'Atividade tem %.1fh planejadas. Extorno de %.1fh deixaria %.1fh.',
                        $delivery->hours_planned, abs($hours), $newPlanned
                    ),
                ], 422);
            }
        }

        $aporte = DB::transaction(function () use ($delivery, $hours, $data) {
            $aporte = StageHourAporte::create([
                'stage_id'    => $delivery->stage_id,
                'delivery_id' => $delivery->id,
                'user_id'     => Auth::id(),
                'hours'       => $hours,
                'reason'      => trim($data['reason']),
            ]);

            // Atualiza atividade atomicamente (não a etapa)
            $delivery->increment('hours_planned', $hours);

            return $aporte;
        });

        return response()->json($aporte->load('user:id,name,email'), 201);
    }

    /**
     * Lista aportes da atividade.
     */
    public function indexForActivity(StageDelivery $delivery): JsonResponse
    {
        $items = StageHourAporte::query()
            ->where('delivery_id', $delivery->id)
            ->with('user:id,name,email')
            ->orderByDesc('created_at')
            ->get();

        $total = (float) $items->sum('hours');

        return response()->json([
            'items' => $items,
            'totals' => [
                'count' => $items->count(),
                'hours' => round($total, 2),
            ],
        ]);
    }
}
