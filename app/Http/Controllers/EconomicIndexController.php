<?php

namespace App\Http\Controllers;

use App\Services\EconomicIndexService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EconomicIndexController extends Controller
{
    public function __construct(private EconomicIndexService $service)
    {
    }

    /**
     * GET /economic-index?index_type=IPCA&start_date=2024-07-01&end_date=2025-06-30
     * Retorna o percentual acumulado do índice no período.
     */
    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'index_type' => 'required|string',
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
        ]);

        if (!EconomicIndexService::supports($validated['index_type'])) {
            return response()->json(['message' => 'Índice não suportado. Use IPCA ou IGP-M.'], 422);
        }

        try {
            $result = $this->service->accumulated(
                $validated['index_type'],
                Carbon::parse($validated['start_date']),
                Carbon::parse($validated['end_date']),
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json($result);
    }
}
