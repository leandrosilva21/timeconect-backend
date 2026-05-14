<?php

namespace App\Http\Controllers;

use App\Models\ProjectStage;
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

    public function store(Request $request, ProjectStage $stage): JsonResponse
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

        // Validação de saldo do projeto — apenas pra aportes positivos
        $stage->loadMissing('project.serviceType');
        $project = $stage->project;

        if ($project && $project->isOperational() && $hours > 0) {
            $sold      = (float) ($project->sold_hours ?? 0);
            $allocated = (float) $project->stages()->sum('hours_planned');
            $available = $sold - $allocated;

            if ($hours > $available + 0.001) {
                return response()->json([
                    'message' => 'Sem saldo disponível. Verifique com o coordenador.',
                    'detail'  => sprintf(
                        'Aporte de %.1fh excede o saldo do projeto. Disponível: %.1fh (vendidas %.1fh, alocadas %.1fh).',
                        $hours, $available, $sold, $allocated
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
}
