<?php

namespace App\Http\Controllers;

use App\Models\Partner;
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
                'user:id,name,hourly_rate,rate_type,partner_id',
                'user.partner:id,pricing_type,hourly_rate',
                'project:id,name,hourly_rate,customer_id',
                'project.customer:id,name',
            ])
            ->whereBetween('date', [$from, $to])
            ->whereIn('status', ['approved', 'pending'])
            ->where('is_billable_only', false)
            ->where('is_internal_action', false)
            ->get();

        $costRateCache = [];
        $costRate = function ($user) use (&$costRateCache, $from, $yearMonth) {
            if (!$user) return 0.0;
            if (isset($costRateCache[$user->id])) return $costRateCache[$user->id];

            // Consultor vinculado a parceiro herda o valor/hora DO PARCEIRO na competência
            // quando o parceiro é de valor fixo. Se o parceiro for "por consultor"
            // (pricing_type 'variable'), usa o valor do próprio consultor (regra abaixo).
            if ($user->partner_id && $user->partner && $user->partner->pricing_type === Partner::PRICING_FIXED) {
                return $costRateCache[$user->id] = (float) $user->partner->hourlyRateForCompetencia($yearMonth);
            }

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

    /**
     * Rentabilidade POR CLIENTE no mês, cruzada com o RECEBIMENTO do Keruak por CNPJ.
     * O recebimento é sempre do mês SEGUINTE ao do apontamento (trabalha no mês M,
     * cobra/recebe no M+1): apontamentos de maio ↔ recebimento de junho no Keruak.
     */
    public function clientes(Request $request, string $yearMonth): JsonResponse
    {
        [$y, $m] = array_map('intval', explode('-', $yearMonth));
        $from = Carbon::create($y, $m, 1)->startOfMonth()->toDateString();
        $to   = Carbon::create($y, $m, 1)->endOfMonth()->toDateString();
        $recebMonth = Carbon::create($y, $m, 1)->addMonth()->format('Y-m'); // M+1

        $timesheets = Timesheet::with([
                'user:id,name,hourly_rate,rate_type,partner_id',
                'user.partner:id,pricing_type,hourly_rate',
                'project:id,name,hourly_rate,customer_id',
                'project.customer:id,name,cgc',
            ])
            ->whereBetween('date', [$from, $to])
            ->whereIn('status', ['approved', 'pending'])
            ->where('is_billable_only', false)
            ->where('is_internal_action', false)
            ->get();

        $costRateCache = [];
        $costRate = function ($user) use (&$costRateCache, $from, $yearMonth) {
            if (!$user) return 0.0;
            if (isset($costRateCache[$user->id])) return $costRateCache[$user->id];
            if ($user->partner_id && $user->partner && $user->partner->pricing_type === Partner::PRICING_FIXED) {
                return $costRateCache[$user->id] = (float) $user->partner->hourlyRateForCompetencia($yearMonth);
            }
            $hist = UserHourlyRateLog::effectiveValuesAt($user->id, $user, $from);
            $rate = (float) ($hist['hourly_rate'] ?? $user->hourly_rate ?? 0);
            $type = $hist['rate_type'] ?? $user->rate_type ?? 'hourly';
            $eff  = ($type === 'monthly' && $rate > 0) ? round($rate / 160, 4) : $rate;
            return $costRateCache[$user->id] = $eff;
        };

        // Agrega por cliente (com CNPJ p/ casar com o Keruak).
        $byCustomer = [];
        foreach ($timesheets as $ts) {
            if (!$ts->project || !$ts->project->customer) continue;
            $cid = $ts->project->customer_id;
            if (!isset($byCustomer[$cid])) {
                $byCustomer[$cid] = [
                    'customer_id' => $cid,
                    'cliente'     => $ts->project->customer->name ?? '—',
                    'cnpj'        => preg_replace('/\D/', '', (string) ($ts->project->customer->cgc ?? '')),
                    'horas'       => 0.0,
                    'receita'     => 0.0,
                    'custo'       => 0.0,
                ];
            }
            $horas = round($ts->effort_minutes / 60, 4);
            $byCustomer[$cid]['horas']   += $horas;
            $byCustomer[$cid]['receita'] += $horas * (float) ($ts->project->hourly_rate ?? 0);
            $byCustomer[$cid]['custo']   += $horas * $costRate($ts->user);
        }

        $keruak = app(\App\Services\KeruakRentabilidadeService::class)->recebido();

        $rows = [];
        $usados = [];
        foreach ($byCustomer as $g) {
            $cnpj = $g['cnpj'];
            $recebido = ($cnpj && isset($keruak[$cnpj]['receb'][$recebMonth])) ? (float) $keruak[$cnpj]['receb'][$recebMonth] : 0.0;
            if ($cnpj) $usados[$cnpj] = true;

            $horas = round($g['horas'], 2);
            $receita = round($g['receita'], 2);
            $custo = round($g['custo'], 2);
            $margem = round($receita - $custo, 2);
            $margemReal = round($recebido - $custo, 2);
            $rows[] = [
                'customer_id'     => $g['customer_id'],
                'cliente'         => $g['cliente'],
                'cnpj'            => $cnpj,
                'horas'           => $horas,
                'receita'         => $receita,
                'custo'           => $custo,
                'margem'          => $margem,
                'margem_pct'      => $receita > 0 ? round($margem / $receita * 100, 1) : null,
                'recebido'        => round($recebido, 2),
                'margem_real'     => $margemReal,
                'margem_real_pct' => $recebido > 0 ? round($margemReal / $recebido * 100, 1) : null,
                'no_minutor'      => true,
            ];
        }

        // Clientes com recebimento no M+1 mas SEM apontamento Minutor no mês M.
        foreach ($keruak as $cnpj => $info) {
            if (isset($usados[$cnpj])) continue;
            $recebido = (float) ($info['receb'][$recebMonth] ?? 0);
            if ($recebido <= 0) continue;
            $rows[] = [
                'customer_id'     => null,
                'cliente'         => $info['name'] ?: '(fora do Minutor)',
                'cnpj'            => $cnpj,
                'horas'           => 0.0,
                'receita'         => 0.0,
                'custo'           => 0.0,
                'margem'          => 0.0,
                'margem_pct'      => null,
                'recebido'        => round($recebido, 2),
                'margem_real'     => round($recebido, 2),
                'margem_real_pct' => 100.0,
                'no_minutor'      => false,
            ];
        }

        usort($rows, fn ($a, $b) => strcasecmp($a['cliente'], $b['cliente']));

        return response()->json(['data' => ['rows' => $rows, 'receb_month' => $recebMonth]]);
    }
}
