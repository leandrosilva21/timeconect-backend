<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Scopes\HideAusterFrozenScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AusterIndicatorsController extends Controller
{
    private const AUSTER_CUSTOMER_ID = HideAusterFrozenScope::AUSTER_CUSTOMER_ID;

    private function denyIfNotAllowed(): ?JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Não autenticado'], 401);
        }
        if ($user->isAdmin()) return null;
        // Cliente da Auster vê os próprios indicadores
        if ($user->type === 'cliente' && (int) $user->customer_id === self::AUSTER_CUSTOMER_ID) {
            return null;
        }
        return response()->json(['message' => 'Acesso negado'], 403);
    }

    /**
     * Lista apenas os projetos históricos da Auster (subprojetos com start_date
     * anterior a 2025-05-01) — mesma regra de `isAusterFrozen()`. Projetos
     * recentemente desvinculados (parent virou NULL) não entram aqui.
     */
    public function projects(Request $request): JsonResponse
    {
        if ($deny = $this->denyIfNotAllowed()) return $deny;

        $rows = Project::withoutGlobalScope(HideAusterFrozenScope::class)
            ->with(['contractType'])
            ->where('customer_id', self::AUSTER_CUSTOMER_ID)
            ->whereNotNull('parent_project_id')
            ->whereNotNull('start_date')
            ->where('start_date', '<', HideAusterFrozenScope::FREEZE_DATE)
            ->whereHas('contractType', function ($q) {
                $q->where('code', 'closed')->orWhereRaw('LOWER(TRIM(name)) = ?', ['fechado']);
            })
            ->orderBy('start_date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $ids = $rows->pluck('id')->all();

        $loggedMap = DB::table('timesheets')
            ->whereIn('project_id', $ids)
            ->where('status', '!=', 'rejected')
            ->selectRaw('project_id, COALESCE(SUM(effort_minutes), 0) AS minutes')
            ->groupBy('project_id')
            ->pluck('minutes', 'project_id');

        $items = $rows->map(function (Project $p) use ($loggedMap) {
            $loggedHours   = round(((int) ($loggedMap[$p->id] ?? 0)) / 60, 2);
            $initial       = (float) ($p->initial_hours_consumed ?? 0);
            $consumed      = round($loggedHours + $initial, 2);
            $sold          = (float) ($p->sold_hours ?? 0);
            $balance       = round($sold - $consumed, 2);

            return [
                'id'              => $p->id,
                'code'            => $p->code,
                'name'            => $p->name,
                'start_date'      => optional($p->start_date)->format('Y-m-d'),
                'status'          => $p->status,
                'status_display'  => $p->status_display,
                'contract_type'   => optional($p->contractType)->name,
                'parent_project_id' => $p->parent_project_id,
                'sold_hours'      => $sold,
                'consumed_hours'  => $consumed,
                'balance_hours'   => $balance,
            ];
        });

        $totalSold     = round($items->sum('sold_hours'), 2);
        $totalConsumed = round($items->sum('consumed_hours'), 2);
        $totalProjects = $items->count();
        // Todos os projetos retornados são subprojetos Auster com start_date < 2025-05-01,
        // ou seja, históricos/fechados por definição. Contamos 100% como concluídos
        // independente do status individual (legado: alguns ficaram como 'started').
        $finished      = $totalProjects;
        $finishedPct   = $totalProjects > 0 ? 100.0 : 0.0;

        return response()->json([
            'items'   => $items,
            'summary' => [
                'total_projects'        => $totalProjects,
                'total_sold_hours'      => $totalSold,
                'total_consumed_hours'  => $totalConsumed,
                'finished_projects'     => $finished,
                'finished_percentage'   => $finishedPct,
            ],
        ]);
    }

    /**
     * Top N projetos da Auster por horas consumidas.
     * Aceita ?limit=5|10|20|all (default: 10).
     */
    public function topConsumed(Request $request): JsonResponse
    {
        if ($deny = $this->denyIfNotAllowed()) return $deny;

        $limitParam = (string) $request->get('limit', '10');

        $rows = Project::withoutGlobalScope(HideAusterFrozenScope::class)
            ->with(['contractType'])
            ->where('customer_id', self::AUSTER_CUSTOMER_ID)
            ->whereNotNull('parent_project_id')
            ->whereNotNull('start_date')
            ->where('start_date', '<', HideAusterFrozenScope::FREEZE_DATE)
            ->whereHas('contractType', function ($q) {
                $q->where('code', 'closed')->orWhereRaw('LOWER(TRIM(name)) = ?', ['fechado']);
            })
            ->get();

        $ids = $rows->pluck('id')->all();

        $loggedMap = DB::table('timesheets')
            ->whereIn('project_id', $ids)
            ->where('status', '!=', 'rejected')
            ->selectRaw('project_id, COALESCE(SUM(effort_minutes), 0) AS minutes')
            ->groupBy('project_id')
            ->pluck('minutes', 'project_id');

        $items = $rows
            ->map(function (Project $p) use ($loggedMap) {
                $loggedHours   = round(((int) ($loggedMap[$p->id] ?? 0)) / 60, 2);
                $initial       = (float) ($p->initial_hours_consumed ?? 0);
                $consumed      = round($loggedHours + $initial, 2);

                return [
                    'id'             => $p->id,
                    'code'           => $p->code,
                    'name'           => $p->name,
                    'start_date'     => optional($p->start_date)->format('Y-m-d'),
                    'status'         => $p->status,
                    'status_display' => $p->status_display,
                    'contract_type'  => optional($p->contractType)->name,
                    'sold_hours'     => (float) ($p->sold_hours ?? 0),
                    'consumed_hours' => $consumed,
                ];
            })
            ->sortByDesc('consumed_hours')
            ->values();

        if ($limitParam !== 'all') {
            $n = max(1, (int) $limitParam);
            $items = $items->take($n);
        }

        return response()->json([
            'items' => $items->values(),
            'limit' => $limitParam,
        ]);
    }
}
