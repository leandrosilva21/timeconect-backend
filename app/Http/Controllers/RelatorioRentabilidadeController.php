<?php

namespace App\Http\Controllers;

use App\Models\Timesheet;
use App\Models\UserHourlyRateLog;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;

/**
 * Relatório de Rentabilidade — por consultor × projeto, no mês:
 *   receita = horas apontadas × valor/hora DO PROJETO (o que o projeto fatura/hora)
 *   custo   = horas apontadas × valor/hora DO CONSULTOR (efetivo; mensalista = salário ÷ 160)
 *   margem  = receita − custo ; margem% = margem / receita.
 */
class RelatorioRentabilidadeController extends Controller
{
    public function rentabilidade(Request $request, string $yearMonth): JsonResponse
    {
        [$y, $m] = array_map('intval', explode('-', $yearMonth));
        $from = Carbon::create($y, $m, 1)->startOfMonth()->toDateString();
        $to   = Carbon::create($y, $m, 1)->endOfMonth()->toDateString();

        $timesheets = Timesheet::with([
                'user:id,name,hourly_rate,rate_type',
                'project:id,name,hourly_rate,customer_id',
                'project.customer:id,name',
            ])
            ->whereBetween('date', [$from, $to])
            ->whereIn('status', ['approved', 'pending'])
            ->where('is_billable_only', false)
            ->where('is_internal_action', false)
            ->get();

        $costRateCache = [];
        $costRate = function ($user) use (&$costRateCache, $from) {
            if (!$user) return 0.0;
            if (isset($costRateCache[$user->id])) return $costRateCache[$user->id];
            $hist = UserHourlyRateLog::effectiveValuesAt($user->id, $user, $from);
            $rate = (float) ($hist['hourly_rate'] ?? $user->hourly_rate ?? 0);
            $type = $hist['rate_type'] ?? $user->rate_type ?? 'hourly';
            $eff  = ($type === 'monthly' && $rate > 0) ? round($rate / 160, 4) : $rate;
            return $costRateCache[$user->id] = $eff;
        };

        $groups = [];
        foreach ($timesheets as $ts) {
            if (!$ts->project) continue;
            $key = $ts->user_id . ':' . $ts->project_id;
            $horas    = round($ts->effort_minutes / 60, 4);
            $rateProj = (float) ($ts->project->hourly_rate ?? 0);
            $rateCons = $costRate($ts->user);

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'user_id'              => $ts->user_id,
                    'consultor'            => $ts->user->name ?? '—',
                    'project_id'           => $ts->project_id,
                    'projeto'              => $ts->project->name ?? '—',
                    'cliente'              => $ts->project->customer->name ?? '—',
                    'valor_hora_projeto'   => round($rateProj, 2),
                    'valor_hora_consultor' => round($rateCons, 2),
                    'horas'                => 0.0,
                    'receita'              => 0.0,
                    'custo'                => 0.0,
                ];
            }
            $groups[$key]['horas']   += $horas;
            $groups[$key]['receita'] += $horas * $rateProj;
            $groups[$key]['custo']   += $horas * $rateCons;
        }

        $rows = array_map(function ($g) {
            $g['horas']   = round($g['horas'], 2);
            $g['receita'] = round($g['receita'], 2);
            $g['custo']   = round($g['custo'], 2);
            $g['margem']  = round($g['receita'] - $g['custo'], 2);
            $g['margem_pct'] = $g['receita'] > 0 ? round($g['margem'] / $g['receita'] * 100, 1) : null;
            return $g;
        }, array_values($groups));

        usort($rows, fn ($a, $b) => strcasecmp($a['consultor'], $b['consultor']) ?: strcasecmp($a['projeto'], $b['projeto']));

        return response()->json(['data' => ['rows' => $rows]]);
    }
}
