<?php

namespace App\Http\Controllers;

use App\Models\Partner;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PartnerReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $auth = $request->user();

        $isPartnerAdmin = $auth->type === 'parceiro_admin';
        $isAdmin = $auth->isAdmin() || $auth->isAdministrativo();
        if (!$isPartnerAdmin && !$isAdmin) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // parceiro_admin vê o próprio parceiro; admin (Visão Externa) escolhe via ?partner_id.
        $partnerId = $isPartnerAdmin ? $auth->partner_id : ($request->integer('partner_id') ?: null);
        $partner = $partnerId ? Partner::find($partnerId) : null;
        if (!$partner) {
            return response()->json([
                'partner'     => null,
                'kpis'        => ['total_hours' => 0, 'total_amount' => 0, 'consultants_count' => 0, 'active_consultants' => 0, 'avg_ticket' => 0],
                'consultants' => [],
                'error'       => $isPartnerAdmin
                    ? 'Usuário não vinculado a nenhum parceiro. Configure o partner_id no perfil do usuário.'
                    : 'Selecione um parceiro.',
            ], 200);
        }

        // Consultores deste parceiro
        $consultants = User::where('partner_id', $partner->id)
            ->select('id', 'name', 'hourly_rate', 'is_executive')
            ->get();

        $consultantIds = $consultants->pluck('id');

        // Timesheets aprovados dos consultores
        $query = DB::table('timesheets')
            ->whereIn('user_id', $consultantIds)
            ->whereIn('status', ['approved', 'pending'])
            ->whereNull('deleted_at');

        if ($request->filled('start_date')) {
            $query->whereDate('date', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->whereDate('date', '<=', $request->end_date);
        }
        if ($request->filled('project_id')) {
            $query->where('project_id', (int) $request->project_id);
        }
        if ($request->filled('contract_type_id')) {
            $query->join('projects', 'timesheets.project_id', '=', 'projects.id')
                  ->where('projects.contract_type_id', (int) $request->contract_type_id);
        }

        $minutesByUser = $query
            ->select('user_id', DB::raw('SUM(effort_minutes) as total_minutes'))
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $isFixed       = $partner->pricing_type === Partner::PRICING_FIXED;
        $partnerRate   = (float) ($partner->hourly_rate ?? 0);

        $consultantRows = [];
        $grandMinutes   = 0;
        $grandAmount    = 0.0;

        foreach ($consultants as $consultant) {
            $minutes = (int) ($minutesByUser[$consultant->id]->total_minutes ?? 0);
            $hours   = round($minutes / 60, 2);
            $rate    = $isFixed ? $partnerRate : (float) ($consultant->hourly_rate ?? 0);
            $amount  = round($hours * $rate, 2);

            $consultantRows[] = [
                'id'           => $consultant->id,
                'name'         => $consultant->name,
                'total_minutes'=> $minutes,
                'total_hours'  => $hours,
                'hourly_rate'  => $rate,
                'total_amount' => $amount,
                'is_admin'     => (bool) $consultant->is_executive,
            ];

            $grandMinutes += $minutes;
            $grandAmount  += $amount;
        }

        // Ordena por horas desc
        usort($consultantRows, fn($a, $b) => $b['total_minutes'] <=> $a['total_minutes']);

        $grandHours    = round($grandMinutes / 60, 2);
        $activeCount   = count(array_filter($consultantRows, fn($c) => $c['total_minutes'] > 0));
        $avgTicket     = $grandHours > 0 ? round($grandAmount / $grandHours, 2) : 0;

        return response()->json([
            'partner' => [
                'id'           => $partner->id,
                'name'         => $partner->name,
                'pricing_type' => $partner->pricing_type,
                'hourly_rate'  => $partner->hourly_rate,
            ],
            'kpis' => [
                'total_hours'        => $grandHours,
                'total_amount'       => round($grandAmount, 2),
                'consultants_count'  => count($consultantRows),
                'active_consultants' => $activeCount,
                'avg_ticket'         => $avgTicket,
            ],
            'consultants' => $consultantRows,
        ]);
    }
}
