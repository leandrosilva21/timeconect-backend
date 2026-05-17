<?php

namespace App\Services;

use App\Models\Timesheet;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Capacidade global do consultor — soma de planned/actual em alocações ativas
 * (etapa não concluída e projeto operacional ativo).
 *
 * Pura, sem cache, sem persistência (ADR 0003 / 0006). Retorna sempre o estado
 * fresco do banco — overload é derivado.
 *
 * Default de capacidade: 40h/semana × 4 semanas = 160h. Pode ser sobrescrito
 * por `user.capacity_hours` (campo já existe no User).
 */
class UserCapacityService
{
    public const DEFAULT_CAPACITY_HOURS = 160.0;

    /**
     * Resumo de capacidade de um usuário.
     *
     * @return array{
     *   items: array<int, array{
     *     allocation_id: int,
     *     stage_id: int,
     *     stage_name: string,
     *     project_id: int,
     *     project_name: string,
     *     customer_name: ?string,
     *     planned_hours: float,
     *     actual_hours: float,
     *     remaining_hours: float,
     *     health: string,
     *   }>,
     *   totals: array{planned_hours: float, actual_hours: float, remaining_hours: float},
     *   capacity_hours: float,
     *   overload: bool,
     *   overload_reasons: array<int, string>
     * }
     */
    public static function summarize(int $userId, ?float $capacityHours = null): array
    {
        $capacity = $capacityHours ?? self::DEFAULT_CAPACITY_HOURS;

        // Soma de horas consumidas por (user_id, stage_id) — só approved+released
        $tsSum = DB::table('timesheets')
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->whereIn('status', [Timesheet::STATUS_APPROVED, Timesheet::STATUS_RELEASED])
            ->groupBy('stage_id')
            ->selectRaw('stage_id, COALESCE(SUM(effort_minutes), 0) / 60.0 AS actual_hours');

        $rows = DB::table('stage_allocations as a')
            ->join('project_stages as ps', 'ps.id', '=', 'a.stage_id')
            ->join('projects as p', 'p.id', '=', 'ps.project_id')
            ->leftJoin('customers as c', 'c.id', '=', 'p.customer_id')
            ->leftJoin('service_types as st', 'st.id', '=', 'p.service_type_id')
            ->leftJoinSub($tsSum, 'ts', fn ($j) => $j->on('ts.stage_id', '=', 'a.stage_id'))
            ->where('a.user_id', $userId)
            ->whereNull('ps.deleted_at')
            ->whereNull('p.deleted_at')
            ->where('ps.status', '!=', 'done')
            ->where(function ($q) {
                // Apenas projetos operacionais (sustentação fora — ADR 0004)
                $q->whereNull('st.name')
                  ->orWhere(function ($s) {
                      $s->whereRaw('LOWER(st.name) NOT LIKE ?', ['%sustenta%'])
                        ->whereRaw('LOWER(st.name) NOT LIKE ?', ['%cloud%'])
                        ->whereRaw('LOWER(st.name) NOT LIKE ?', ['%bizify%']);
                  });
            })
            ->selectRaw('
                a.id AS allocation_id,
                a.stage_id,
                ps.name AS stage_name,
                p.id AS project_id,
                p.name AS project_name,
                c.name AS customer_name,
                a.planned_hours,
                COALESCE(ts.actual_hours, 0) AS actual_hours
            ')
            ->orderBy('p.name')
            ->orderBy('ps.order_index')
            ->get();

        $items = [];
        $totalPlanned = 0.0;
        $totalActual = 0.0;
        $hasOverrun = false;

        foreach ($rows as $r) {
            $planned   = (float) $r->planned_hours;
            $actual    = (float) $r->actual_hours;
            $remaining = round($planned - $actual, 2);

            if ($remaining < 0) $hasOverrun = true;

            $items[] = [
                'allocation_id'   => (int) $r->allocation_id,
                'stage_id'        => (int) $r->stage_id,
                'stage_name'      => (string) $r->stage_name,
                'project_id'      => (int) $r->project_id,
                'project_name'    => (string) $r->project_name,
                'customer_name'   => $r->customer_name ? (string) $r->customer_name : null,
                'planned_hours'   => $planned,
                'actual_hours'    => round($actual, 2),
                'remaining_hours' => $remaining,
                'health'          => self::health($planned, $actual),
            ];

            $totalPlanned += $planned;
            $totalActual  += $actual;
        }

        $totalRemaining = round($totalPlanned - $totalActual, 2);

        $reasons = [];
        if ($totalPlanned > $capacity) {
            $reasons[] = sprintf('Planejado %.1fh excede capacidade %.1fh', $totalPlanned, $capacity);
        }
        if ($hasOverrun) {
            $reasons[] = 'Pelo menos uma alocação está estourada';
        }
        if ($totalRemaining < 0) {
            $reasons[] = sprintf('Consumo total %.1fh excede planejado %.1fh', $totalActual, $totalPlanned);
        }

        return [
            'items'            => $items,
            'totals'           => [
                'planned_hours'   => round($totalPlanned, 2),
                'actual_hours'    => round($totalActual, 2),
                'remaining_hours' => $totalRemaining,
            ],
            'capacity_hours'   => $capacity,
            'overload'         => !empty($reasons),
            'overload_reasons' => $reasons,
        ];
    }

    /**
     * Horas disponíveis num dia útil específico para o consultor.
     *
     * Default = 8h/dia útil. Subtrai a soma de `hours_planned` das atividades
     * do user cujo intervalo [planned_start_at, due_date] contém $date.
     *
     * Retorna negativo se sobrecarregado. Não checa se $date é dia útil — quem
     * chamar deve usar BusinessCalendarService::isBusinessDay() antes.
     */
    public static function dailyAvailable(int $userId, CarbonInterface $date, float $dailyHours = 8.0): float
    {
        $iso = $date->toDateString();

        $planned = (float) DB::table('stage_deliveries')
            ->whereNull('deleted_at')
            ->where('responsible_user_id', $userId)
            ->whereNotNull('planned_start_at')
            ->whereNotNull('due_date')
            ->whereDate('planned_start_at', '<=', $iso)
            ->whereDate('due_date', '>=', $iso)
            ->sum(DB::raw('COALESCE(hours_planned, 0) / NULLIF(
                GREATEST(DATE_PART(\'day\', due_date::timestamp - planned_start_at::timestamp) + 1, 1), 0
            )'));

        return round($dailyHours - $planned, 2);
    }

    /**
     * Resumo de capacidade num período (intervalo de datas).
     * Soma `dailyAvailable` em cada dia útil entre $fromIso e $toIso.
     * `overloaded` = qualquer dia útil com remaining < 0.
     */
    public static function periodSummary(int $userId, string $fromIso, string $toIso, float $dailyHours = 8.0): array
    {
        $from = Carbon::parse($fromIso);
        $to   = Carbon::parse($toIso);
        if ($to->lt($from)) {
            return ['capacity_hours' => 0.0, 'planned_hours' => 0.0, 'remaining_hours' => 0.0, 'overloaded' => false, 'days_overloaded' => 0];
        }

        $calendar = app(\App\Services\BusinessCalendarService::class);
        $capacity = 0.0;
        $remaining = 0.0;
        $daysOverloaded = 0;

        $cursor = $from->copy();
        while ($cursor->lte($to)) {
            if ($calendar->isBusinessDay($cursor)) {
                $capacity += $dailyHours;
                $r = self::dailyAvailable($userId, $cursor, $dailyHours);
                $remaining += $r;
                if ($r < 0) $daysOverloaded++;
            }
            $cursor->addDay();
        }

        $planned = round($capacity - $remaining, 2);
        return [
            'capacity_hours'   => round($capacity, 2),
            'planned_hours'    => $planned,
            'remaining_hours'  => round($remaining, 2),
            'overloaded'       => $daysOverloaded > 0,
            'days_overloaded'  => $daysOverloaded,
        ];
    }

    private static function health(float $planned, float $actual): string
    {
        if ($planned <= 0) return 'unknown';
        $pct = $actual / $planned;
        if ($pct > 1.0)  return 'estourado';
        if ($pct > 0.8)  return 'atencao';
        return 'ok';
    }
}
