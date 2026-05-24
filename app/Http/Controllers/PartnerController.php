<?php

namespace App\Http\Controllers;

use App\Models\Partner;
use App\Models\PartnerHourlyRateLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class PartnerController extends Controller
{
    /** GET /partners */
    public function index(Request $request): JsonResponse
    {
        $query = Partner::query();

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->get('search') . '%');
        }

        if ($request->filled('active')) {
            $query->where('active', filter_var($request->get('active'), FILTER_VALIDATE_BOOLEAN));
        }

        $pageSize = min((int) $request->get('pageSize', 20), 200);

        if ($pageSize === -1) {
            $items = $query->orderBy('name')->get();
            return response()->json(['items' => $items, 'hasNext' => false]);
        }

        $paginator = $query->orderBy('name')->paginate($pageSize);

        return response()->json([
            'items'   => $paginator->items(),
            'hasNext' => $paginator->hasMorePages(),
            'total'   => $paginator->total(),
        ]);
    }

    /** GET /partners/{partner} */
    public function show(Partner $partner): JsonResponse
    {
        return response()->json($partner);
    }

    /** POST /partners */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'         => 'required|string|max:255',
            'document'     => 'nullable|string|max:20',
            'email'        => 'nullable|email|max:255',
            'phone'        => 'nullable|string|max:20',
            'active'       => 'boolean',
            'pricing_type' => 'required|in:fixed,variable',
            'hourly_rate'  => 'nullable|numeric|min:0|max:999999.99',
        ]);

        $partner = Partner::create($data);

        return response()->json($partner, 201);
    }

    /** PUT /partners/{partner} */
    public function update(Request $request, Partner $partner): JsonResponse
    {
        $data = $request->validate([
            'name'         => 'sometimes|required|string|max:255',
            'document'     => 'nullable|string|max:20',
            'email'        => 'nullable|email|max:255',
            'phone'        => 'nullable|string|max:20',
            'active'       => 'boolean',
            'pricing_type' => 'sometimes|required|in:fixed,variable',
            'hourly_rate'  => 'nullable|numeric|min:0|max:999999.99',
            'hourly_rate_effective_from' => 'nullable|date',
        ]);

        $effectiveFrom = $data['hourly_rate_effective_from'] ?? null;
        unset($data['hourly_rate_effective_from']);

        $oldRate = $partner->hourly_rate;
        $partner->update($data);

        // Registra a vigência do novo valor hora (a partir do mês escolhido). Legado intacto.
        if (array_key_exists('hourly_rate', $data) && (float) $partner->hourly_rate !== (float) $oldRate) {
            PartnerHourlyRateLog::create([
                'partner_id'      => $partner->id,
                'changed_by'      => Auth::id(),
                'old_hourly_rate' => $oldRate,
                'new_hourly_rate' => $partner->hourly_rate,
                'effective_from'  => $effectiveFrom ? Carbon::parse($effectiveFrom)->startOfMonth()->toDateString() : null,
                'reason'          => $request->input('rate_change_reason'),
            ]);
        }

        return response()->json($partner);
    }

    /** DELETE /partners/{partner} */
    public function destroy(Partner $partner): JsonResponse
    {
        $partner->delete();

        return response()->json(null, 204);
    }

    /** GET /partners/{partner}/hourly-rate-history — histórico de alterações do valor hora. */
    public function getHourlyRateHistory(Request $request, Partner $partner): JsonResponse
    {
        $pageSize = min((int) $request->get('pageSize', 50), 200);
        $logs = $partner->hourlyRateLogs()->with('changedBy:id,name,email')->paginate($pageSize);

        $items = collect($logs->items())->map(function ($l) {
            $l->changed_by_user = $l->changedBy;
            unset($l->changedBy);
            return $l;
        });

        return response()->json([
            'hasNext' => $logs->hasMorePages(),
            'items'   => $items,
        ]);
    }
}
