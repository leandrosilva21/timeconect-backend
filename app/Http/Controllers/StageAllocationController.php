<?php

namespace App\Http\Controllers;

use App\Models\ProjectStage;
use App\Models\StageAllocation;
use App\Models\Timesheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StageAllocationController extends Controller
{
    /**
     * Lista alocações da etapa + actual/remaining calculados.
     */
    public function index(ProjectStage $stage): JsonResponse
    {
        $items = StageAllocation::query()
            ->where('stage_allocations.stage_id', $stage->id)
            ->with('user:id,name,email,profile_photo_path')
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
                    'id'                 => $a->user->id,
                    'name'               => $a->user->name,
                    'email'              => $a->user->email,
                    'profile_photo_url'  => $a->user->profile_photo_path
                        ? rtrim(config('app.url'), '/') . '/storage/' . $a->user->profile_photo_path
                        : null,
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
}
