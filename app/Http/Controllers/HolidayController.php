<?php

namespace App\Http\Controllers;

use App\Models\Holiday;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class HolidayController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Holiday::query();

        if ($request->filled('year')) {
            $query->whereYear('date', $request->year);
        }
        if ($request->filled('active')) {
            $query->where('active', filter_var($request->active, FILTER_VALIDATE_BOOLEAN));
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        $holidays = $query->orderBy('date')->get();
        return response()->json(['items' => $holidays]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date'  => 'required|date',
            'name'  => 'required|string|max:255',
            'type'  => 'nullable|in:national,state,municipal,optional',
            'state' => 'nullable|string|size:2',
            'active'=> 'nullable|boolean',
        ]);

        $holiday = Holiday::create($validated);
        return response()->json($holiday, 201);
    }

    public function update(Request $request, Holiday $holiday): JsonResponse
    {
        $validated = $request->validate([
            'date'  => 'sometimes|date',
            'name'  => 'sometimes|string|max:255',
            'type'  => 'sometimes|in:national,state,municipal,optional',
            'state' => 'nullable|string|size:2',
            'active'=> 'nullable|boolean',
        ]);

        $holiday->update($validated);
        return response()->json($holiday);
    }

    public function destroy(Holiday $holiday): JsonResponse
    {
        $holiday->delete();
        return response()->json(null, 204);
    }

    public function importFromApi(Request $request): JsonResponse
    {
        $year = (int) ($request->query('year') ?: now()->year);

        $response = Http::timeout(10)
            ->get("https://brasilapi.com.br/api/feriados/v1/{$year}");

        if (!$response->successful()) {
            return response()->json(['message' => 'Erro ao consultar a API de feriados.'], 502);
        }

        $imported = 0;
        $skipped  = 0;

        foreach ($response->json() as $item) {
            if (($item['type'] ?? '') !== 'national') {
                $skipped++;
                continue;
            }

            Holiday::updateOrCreate(
                ['date' => $item['date']],
                [
                    'name'   => $item['name'],
                    'type'   => 'national',
                    'state'  => null,
                    'active' => true,
                ]
            );

            $imported++;
        }

        $holidays = Holiday::whereYear('date', $year)
            ->where('type', 'national')
            ->orderBy('date')
            ->get();

        return response()->json([
            'message'  => "{$imported} feriados importados para {$year}.",
            'imported' => $imported,
            'skipped'  => $skipped,
            'items'    => $holidays,
        ]);
    }
}
