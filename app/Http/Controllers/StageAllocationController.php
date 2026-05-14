<?php

namespace App\Http\Controllers;

use App\Models\ProjectStage;
use App\Models\StageAllocation;
use App\Models\StageDelivery;
use App\Models\Timesheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StageAllocationController extends Controller
{
    /**
     * Lista alocações da etapa + actual/remaining calculados.
     * Visão consultor: vê só sua própria alocação (ADR 0004).
     */
    public function index(ProjectStage $stage, Request $request): JsonResponse
    {
        $user = $request->user();
        $isConsultor = $user && method_exists($user, 'isConsultor') && $user->isConsultor();

        $items = StageAllocation::query()
            ->where('stage_allocations.stage_id', $stage->id)
            ->when($isConsultor, fn ($q) => $q->where('stage_allocations.user_id', $user->id))
            ->with('user:id,name,email')
            ->leftJoinSub(
                Timesheet::query()
                    ->selectRaw('user_id, stage_id, COALESCE(SUM(effort_minutes), 0) AS minutes_sum')
                    ->where('stage_id', $stage->id)
                    ->whereNull('deleted_at')
                    ->whereIn('status', [Timesheet::STATUS_APPROVED, Timesheet::STATUS_RELEASED])
                    ->groupBy('user_id', 'stage_id'),
                'ts',
                fn ($join) => $join->on('ts.user_id', '=', 'stage_allocations.user_id')
                                   ->on('ts.stage_id', '=', 'stage_allocations.stage_id')
            )
            ->selectRaw('stage_allocations.*, COALESCE(ts.minutes_sum, 0) / 60.0 AS actual_hours')
            ->orderBy('stage_allocations.id')
            ->get();

        $rows = $items->map(function ($a) {
            $planned   = (float) $a->planned_hours;
            $actual    = (float) $a->actual_hours;
            $remaining = round($planned - $actual, 2);

            return [
                'id'              => $a->id,
                'stage_id'        => $a->stage_id,
                'user_id'         => $a->user_id,
                'user'            => $a->user ? [
                    'id'    => $a->user->id,
                    'name'  => $a->user->name,
                    'email' => $a->user->email,
                ] : null,
                'planned_hours'   => $planned,
                'actual_hours'    => round($actual, 2),
                'remaining_hours' => $remaining,
                'health'          => self::computeHealth($planned, $actual),
            ];
        });

        $totalPlanned  = (float) $rows->sum('planned_hours');
        $totalActual   = (float) $rows->sum('actual_hours');
        $totalRemaining = round($totalPlanned - $totalActual, 2);

        return response()->json([
            'items' => $rows,
            'totals' => [
                'planned_hours'   => $totalPlanned,
                'actual_hours'    => round($totalActual, 2),
                'remaining_hours' => $totalRemaining,
                'overrun_count'   => $rows->where('health', 'estourado')->count(),
            ],
        ]);
    }

    public function store(Request $request, ProjectStage $stage): JsonResponse
    {
        $data = $request->validate([
            'user_id'       => 'required|integer|exists:users,id',
            'planned_hours' => 'required|numeric|min:0.5',
        ]);

        $existing = StageAllocation::where('stage_id', $stage->id)
            ->where('user_id', $data['user_id'])
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Consultor já alocado nesta etapa. Use PATCH pra atualizar horas.',
            ], 422);
        }

        $err = $this->guardStageCapacity($stage, (float) $data['planned_hours']);
        if ($err !== null) return $err;

        $allocation = StageAllocation::create([
            'stage_id'      => $stage->id,
            'user_id'       => $data['user_id'],
            'planned_hours' => $data['planned_hours'],
        ]);

        return response()->json($allocation->load('user:id,name,email'), 201);
    }

    public function update(Request $request, StageAllocation $allocation): JsonResponse
    {
        $data = $request->validate([
            'planned_hours' => 'required|numeric|min:0.5',
        ]);

        $delta = (float) $data['planned_hours'] - (float) $allocation->planned_hours;
        if ($delta > 0) {
            // Alocação ligada a atividade → valida contra delivery.hours_planned (Pilar B)
            if ($allocation->delivery_id) {
                $allocation->loadMissing('delivery');
                if ($allocation->delivery) {
                    $err = $this->guardActivityCapacity($allocation->delivery, $delta);
                    if ($err !== null) return $err;
                }
            } else {
                $allocation->loadMissing('stage');
                if ($allocation->stage) {
                    $err = $this->guardStageCapacity($allocation->stage, $delta);
                    if ($err !== null) return $err;
                }
            }
        }

        $allocation->update($data);

        return response()->json($allocation->fresh()->load('user:id,name,email'));
    }

    /**
     * Remove alocação. Timesheets ficam intactos (stage_id preservado no histórico).
     * Frontend deve mostrar confirm dialog quando houver actual > 0.
     */
    public function destroy(StageAllocation $allocation): JsonResponse
    {
        $allocation->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Bloqueia se SUM(allocations.planned_hours) + $delta > stage.planned_hours.
     */
    private function guardStageCapacity(ProjectStage $stage, float $delta): ?JsonResponse
    {
        $stagePlanned = (float) ($stage->hours_planned ?? 0);
        if ($stagePlanned <= 0) return null;

        $allocated = (float) StageAllocation::where('stage_id', $stage->id)->sum('planned_hours');
        $available = $stagePlanned - $allocated;

        if ($delta > $available + 0.001) {
            return response()->json([
                'message' => 'Sem saldo disponível. Verifique com o coordenador.',
                'detail'  => sprintf(
                    'Tentativa de alocar %.1fh na etapa. Saldo da etapa: %.1fh (planejadas %.1fh, alocadas %.1fh).',
                    $delta, $available, $stagePlanned, $allocated
                ),
            ], 422);
        }
        return null;
    }

    /**
     * Health por consultor:
     *   🟢 ok       — consumo ≤ 80%
     *   🟡 atencao  — 80% < consumo ≤ 100%
     *   🔴 estourado — consumo > 100%
     *   ⚪ desconhecido — planned 0
     */
    private static function computeHealth(float $planned, float $actual): string
    {
        if ($planned <= 0) return 'unknown';
        $pct = $actual / $planned;
        if ($pct > 1.0)  return 'estourado';
        if ($pct > 0.8)  return 'atencao';
        return 'ok';
    }

    // ─────────────────────────────────────────────────────────────────────
    // Activity-level (Pilar B do refactor 2026-05-15)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Lista alocações da atividade.
     * Visão consultor: vê só a própria.
     */
    public function indexForActivity(StageDelivery $delivery, Request $request): JsonResponse
    {
        $user = $request->user();
        $isConsultor = $user && method_exists($user, 'isConsultor') && $user->isConsultor();

        // actual_hours por (user, delivery) via timesheets.stage_delivery_id
        $items = StageAllocation::query()
            ->where('stage_allocations.delivery_id', $delivery->id)
            ->when($isConsultor, fn ($q) => $q->where('stage_allocations.user_id', $user->id))
            ->with('user:id,name,email')
            ->leftJoinSub(
                Timesheet::query()
                    ->selectRaw('user_id, stage_delivery_id, COALESCE(SUM(effort_minutes), 0) AS minutes_sum')
                    ->where('stage_delivery_id', $delivery->id)
                    ->whereNull('deleted_at')
                    ->whereIn('status', [Timesheet::STATUS_APPROVED, Timesheet::STATUS_RELEASED])
                    ->groupBy('user_id', 'stage_delivery_id'),
                'ts',
                fn ($join) => $join->on('ts.user_id', '=', 'stage_allocations.user_id')
                                   ->on('ts.stage_delivery_id', '=', 'stage_allocations.delivery_id')
            )
            ->selectRaw('stage_allocations.*, COALESCE(ts.minutes_sum, 0) / 60.0 AS actual_hours')
            ->orderBy('stage_allocations.id')
            ->get();

        $rows = $items->map(function ($a) {
            $planned   = (float) $a->planned_hours;
            $actual    = (float) $a->actual_hours;
            $remaining = round($planned - $actual, 2);

            return [
                'id'              => $a->id,
                'stage_id'        => $a->stage_id,
                'delivery_id'     => $a->delivery_id,
                'user_id'         => $a->user_id,
                'user'            => $a->user ? [
                    'id'    => $a->user->id,
                    'name'  => $a->user->name,
                    'email' => $a->user->email,
                ] : null,
                'planned_hours'   => $planned,
                'actual_hours'    => round($actual, 2),
                'remaining_hours' => $remaining,
                'health'          => self::computeHealth($planned, $actual),
            ];
        });

        $totalPlanned   = (float) $rows->sum('planned_hours');
        $totalActual    = (float) $rows->sum('actual_hours');
        $totalRemaining = round($totalPlanned - $totalActual, 2);

        return response()->json([
            'items' => $rows,
            'totals' => [
                'planned_hours'   => $totalPlanned,
                'actual_hours'    => round($totalActual, 2),
                'remaining_hours' => $totalRemaining,
                'overrun_count'   => $rows->where('health', 'estourado')->count(),
            ],
        ]);
    }

    public function storeForActivity(Request $request, StageDelivery $delivery): JsonResponse
    {
        $data = $request->validate([
            'user_id'       => 'required|integer|exists:users,id',
            'planned_hours' => 'required|numeric|min:0.5',
        ]);

        $existing = StageAllocation::where('delivery_id', $delivery->id)
            ->where('user_id', $data['user_id'])
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Consultor já alocado nesta atividade. Use PATCH pra atualizar horas.',
            ], 422);
        }

        $err = $this->guardActivityCapacity($delivery, (float) $data['planned_hours']);
        if ($err !== null) return $err;

        $allocation = StageAllocation::create([
            'stage_id'      => $delivery->stage_id,
            'delivery_id'   => $delivery->id,
            'user_id'       => $data['user_id'],
            'planned_hours' => $data['planned_hours'],
        ]);

        return response()->json($allocation->load('user:id,name,email'), 201);
    }

    /**
     * Bloqueia se SUM(allocations WHERE delivery_id=X) + $delta > delivery.hours_planned.
     */
    private function guardActivityCapacity(StageDelivery $delivery, float $delta): ?JsonResponse
    {
        $deliveryPlanned = (float) ($delivery->hours_planned ?? 0);
        if ($deliveryPlanned <= 0) return null;

        $allocated = (float) StageAllocation::where('delivery_id', $delivery->id)->sum('planned_hours');
        $available = $deliveryPlanned - $allocated;

        if ($delta > $available + 0.001) {
            return response()->json([
                'message' => 'Sem saldo disponível. Verifique com o coordenador.',
                'detail'  => sprintf(
                    'Tentativa de alocar %.1fh na atividade. Saldo da atividade: %.1fh (previstas %.1fh, alocadas %.1fh).',
                    $delta, $available, $deliveryPlanned, $allocated
                ),
            ], 422);
        }
        return null;
    }
}
