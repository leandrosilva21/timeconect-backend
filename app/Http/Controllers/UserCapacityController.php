<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\UserCapacityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserCapacityController extends Controller
{
    /**
     * GET /users/{user}/capacity — capacidade global do consultor cross-projetos.
     * Lista alocações ativas (etapa não concluída, projeto operacional) com
     * planned/actual/remaining e totais. `overload` é derivado.
     */
    public function show(User $user): JsonResponse
    {
        $capacity = $user->capacity_hours !== null
            ? (float) $user->capacity_hours
            : UserCapacityService::DEFAULT_CAPACITY_HOURS;

        $summary = UserCapacityService::summarize($user->id, $capacity);

        return response()->json(array_merge(
            ['user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email]],
            $summary,
        ));
    }

    /**
     * GET /users/capacity — lista de todos os consultores com flag de sobrecarga.
     * Ordenada por overload primeiro, depois por consumo % decrescente.
     */
    public function index(Request $request): JsonResponse
    {
        $users = User::query()
            ->where('type', 'consultor')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'capacity_hours']);

        $items = $users->map(function (User $u) {
            $capacity = $u->capacity_hours !== null
                ? (float) $u->capacity_hours
                : UserCapacityService::DEFAULT_CAPACITY_HOURS;

            $summary = UserCapacityService::summarize($u->id, $capacity);

            $usagePct = $capacity > 0
                ? round($summary['totals']['planned_hours'] / $capacity * 100, 1)
                : 0.0;

            return [
                'user' => [
                    'id'    => $u->id,
                    'name'  => $u->name,
                    'email' => $u->email,
                ],
                'capacity_hours'   => $capacity,
                'planned_hours'    => $summary['totals']['planned_hours'],
                'actual_hours'     => $summary['totals']['actual_hours'],
                'remaining_hours'  => $summary['totals']['remaining_hours'],
                'usage_pct'        => $usagePct,
                'allocation_count' => count($summary['items']),
                'overload'         => $summary['overload'],
                'overload_reasons' => $summary['overload_reasons'],
            ];
        });

        $sorted = $items
            ->sortByDesc(fn ($i) => [$i['overload'] ? 1 : 0, $i['usage_pct']])
            ->values();

        return response()->json([
            'items'   => $sorted,
            'summary' => [
                'overloaded_count' => $sorted->where('overload', true)->count(),
                'total_count'      => $sorted->count(),
            ],
        ]);
    }
}
