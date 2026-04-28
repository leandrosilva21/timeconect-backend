<?php

namespace App\Http\Controllers;

use App\Models\Timesheet;
use App\Models\User;
use App\Models\UserHourlyRateLog;
use App\Services\HourBankService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class FechamentoConsultorController extends Controller
{
    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function period(string $yearMonth): array
    {
        $from = "{$yearMonth}-01";
        $to   = Carbon::parse($from)->endOfMonth()->toDateString();
        return [$from, $to];
    }

    private function effectiveHourlyRate(float $hourlyRate, string $rateType): float
    {
        return ($rateType === 'monthly' && $hourlyRate > 0)
            ? round($hourlyRate / 180, 4)
            : $hourlyRate;
    }

    // ─── Index ────────────────────────────────────────────────────────────────

    public function index(string $yearMonth): JsonResponse
    {
        [$from, $to]     = $this->period($yearMonth);
        [$year, $month]  = array_map('intval', explode('-', $yearMonth));

        $users = User::where('enabled', true)
            ->whereNotIn('type', ['parceiro_admin', 'cliente'])
            ->whereNotNull('consultant_type')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'type', 'consultant_type', 'hourly_rate', 'rate_type', 'daily_hours', 'bank_hours_start_date', 'guaranteed_hours']);

        $excludeStatuses = [Timesheet::STATUS_ADJUSTMENT_REQUESTED, Timesheet::STATUS_REJECTED];

        $hoursByUser = Timesheet::whereBetween('date', [$from, $to])
            ->whereNotIn('status', $excludeStatuses)
            ->whereNull('deleted_at')
            ->where('is_billable_only', false)
            ->whereIn('user_id', $users->pluck('id'))
            ->selectRaw('user_id, SUM(effort_minutes) as total_minutes')
            ->groupBy('user_id')
            ->pluck('total_minutes', 'user_id');

        // Per-timesheet extras (consultant_extra_pct) — only where set
        $extraTimesheetsByUser = Timesheet::whereBetween('date', [$from, $to])
            ->whereNotIn('status', $excludeStatuses)
            ->whereNull('deleted_at')
            ->where('is_billable_only', false)
            ->whereNotNull('consultant_extra_pct')
            ->whereIn('user_id', $users->pluck('id'))
            ->select('user_id', 'effort_minutes', 'consultant_extra_pct')
            ->get()
            ->groupBy('user_id');

        $hourBankService = app(HourBankService::class);

        // Dias úteis do mês cheio — calculado uma vez, compartilhado por todos
        $workingDaysFull = $hourBankService->calculateWorkingDays($year, $month);
        $totalWorkingDays = $workingDaysFull['working_days'];

        $horistas   = [];
        $bancoHoras = [];
        $fixos      = [];

        foreach ($users as $user) {
            $hist             = UserHourlyRateLog::effectiveValuesAt($user->id, $user, $from);
            $hourlyRate       = (float) ($hist['hourly_rate'] ?? 0);
            $rateType         = $hist['rate_type'] ?? 'hourly';
            $effectiveRate    = $this->effectiveHourlyRate($hourlyRate, $rateType);
            $horasTrabalhadas = round((int) ($hoursByUser[$user->id] ?? 0) / 60, 2);

            $extrasConsultant = round(
                ($extraTimesheetsByUser->get($user->id, collect()))
                    ->sum(fn ($t) => ($t->effort_minutes / 60) * $effectiveRate * ((float) $t->consultant_extra_pct / 100)),
                2
            );

            $base = [
                'user_id'           => $user->id,
                'nome'              => $user->name,
                'email'             => $user->email,
                'type'              => $user->type,
                'consultant_type'   => $user->consultant_type,
                'horas_trabalhadas' => $horasTrabalhadas,
                'valor_hora'        => $hourlyRate,
                'rate_type'         => $rateType,
                'effective_rate'    => $effectiveRate,
            ];

            // Proporcionalidade: se data_inicio cai no mês atual
            $startDate = $user->bank_hours_start_date
                ? $user->bank_hours_start_date->format('Y-m-d')
                : null;

            $startIsInMonth = $startDate
                && Carbon::parse($startDate)->year  === $year
                && Carbon::parse($startDate)->month === $month;

            if ($startIsInMonth) {
                $workingDaysPeriod = $hourBankService->calculateWorkingDays($year, $month, $startDate);
                $periodDays        = $workingDaysPeriod['working_days'];
                $ratio             = $totalWorkingDays > 0 ? round($periodDays / $totalWorkingDays, 6) : 1;
            } else {
                $periodDays = $totalWorkingDays;
                $ratio      = 1;
            }

            switch ($user->consultant_type) {
                case 'horista':
                    $guaranteedHours         = (float) ($user->guaranteed_hours ?? 0);
                    $guaranteedProrated      = $guaranteedHours > 0 ? round($guaranteedHours * $ratio, 2) : 0;
                    $horasMinimas            = $guaranteedProrated > 0
                        ? max($horasTrabalhadas, $guaranteedProrated)
                        : $horasTrabalhadas;
                    $horistas[] = array_merge($base, [
                        'guaranteed_hours'   => $guaranteedHours,
                        'guaranteed_prorated'=> $guaranteedProrated,
                        'proporcional'       => $startIsInMonth,
                        'ratio'              => $ratio,
                        'dias_uteis_periodo' => $periodDays,
                        'dias_uteis_cheio'   => $totalWorkingDays,
                        'data_inicio'        => $startDate,
                        'horas_a_pagar'      => $horasMinimas,
                        'total_extras'       => $extrasConsultant,
                        'total'              => round($horasMinimas * $effectiveRate + $extrasConsultant, 2),
                    ]);
                    break;

                case 'banco_de_horas':
                    $startDate = $user->bank_hours_start_date
                        ? $user->bank_hours_start_date->format('Y-m-d')
                        : null;
                    // Para banco de horas: consultant_extra_pct infla as horas (não gera valor monetário extra)
                    $extraHoursForBank = round(
                        ($extraTimesheetsByUser->get($user->id, collect()))
                            ->sum(fn ($t) => ($t->effort_minutes / 60) * ((float) $t->consultant_extra_pct / 100)),
                        2
                    );
                    $calc = $hourBankService->calculateMonth(
                        $user->id,
                        $year,
                        $month,
                        (float) ($user->daily_hours ?? 8.0),
                        $startDate,
                        $extraHoursForBank
                    );
                    // Regra: hourly_rate = salário mensal fixo (sempre pago)
                    // Horas extras = accumulated_balance > 0 (paid_hours do HourBankService)
                    // Taxa hora extra = hourly_rate ÷ 180
                    $fixedSalary      = $hourlyRate;
                    $valorHoraExtra   = $hourlyRate > 0 ? round($hourlyRate / 180, 4) : 0;
                    $horasExtras      = $calc['paid_hours']; // accumulated > 0, senão 0
                    $totalExtra       = round($horasExtras * $valorHoraExtra, 2);
                    $total            = round($fixedSalary + $totalExtra, 2); // sem extrasConsultant: já virou horas no banco

                    $bancoHoras[] = array_merge($base, [
                        'horas_trabalhadas'   => $calc['worked_hours'], // inclui inflação do consultant_extra_pct
                        'daily_hours'         => (float) ($user->daily_hours ?? 8.0),
                        'working_days'        => $calc['working_days'],
                        'expected_hours'      => $calc['expected_hours'],
                        'month_balance'       => $calc['month_balance'],
                        'previous_balance'    => $calc['previous_balance'],
                        'accumulated_balance' => $calc['accumulated_balance'],
                        'paid_hours'          => $calc['paid_hours'],
                        'final_balance'       => $calc['final_balance'],
                        'fixed_salary'        => $fixedSalary,
                        'valor_hora_extra'    => $valorHoraExtra,
                        'horas_extras'        => $horasExtras,
                        'total_extra'         => $totalExtra,
                        'horas_a_pagar'       => $horasExtras,
                        'total_extras'        => 0,
                        'total'               => $total,
                    ]);
                    break;

                case 'fixo':
                    // hourly_rate = salário mensal; proporcional se entrou no meio do mês
                    $salarioProportional = round($hourlyRate * $ratio, 2);
                    $fixos[] = array_merge($base, [
                        'horas_a_pagar'      => $horasTrabalhadas,
                        'salario_mensal'     => $hourlyRate,
                        'proporcional'       => $startIsInMonth,
                        'ratio'              => $ratio,
                        'dias_uteis_periodo' => $periodDays,
                        'dias_uteis_cheio'   => $totalWorkingDays,
                        'data_inicio'        => $startDate,
                        'total_extras'       => $extrasConsultant,
                        'total'              => round($salarioProportional + $extrasConsultant, 2),
                    ]);
                    break;
            }
        }

        return response()->json([
            'data' => [
                'horistas'    => $horistas,
                'banco_horas' => $bancoHoras,
                'fixos'       => $fixos,
                'totais' => [
                    'total_horistas'    => round(collect($horistas)->sum('total'), 2),
                    'total_banco_horas' => round(collect($bancoHoras)->sum('total'), 2),
                    'total_fixos'       => round(collect($fixos)->sum('total'), 2),
                    'total_geral'       => round(
                        collect($horistas)->sum('total') +
                        collect($bancoHoras)->sum('total') +
                        collect($fixos)->sum('total'),
                        2
                    ),
                ],
            ],
        ]);
    }

    // ─── Apontamentos ─────────────────────────────────────────────────────────

    public function apontamentos(string $userId, string $yearMonth): JsonResponse
    {
        [$from, $to] = $this->period($yearMonth);

        $excludeStatuses = [Timesheet::STATUS_ADJUSTMENT_REQUESTED, Timesheet::STATUS_REJECTED];

        $user          = User::find($userId, ['id', 'name', 'hourly_rate', 'rate_type', 'consultant_type']);
        $isBancoHoras  = $user?->consultant_type === 'banco_de_horas';
        $hist          = UserHourlyRateLog::effectiveValuesAt((int) $userId, $user, $from);
        $effectiveRate = $this->effectiveHourlyRate(
            (float) ($hist['hourly_rate'] ?? $user?->hourly_rate ?? 0),
            $hist['rate_type'] ?? $user?->rate_type ?? 'hourly'
        );

        $rows = Timesheet::with([
            'project:id,name,code,contract_type_id',
            'project.contractType:id,name,code',
            'project.customer:id,name',
        ])
            ->select('timesheets.*', 'movidesk_tickets.titulo as ticket_titulo', 'movidesk_tickets.solicitante as ticket_solicitante')
            ->leftJoin('movidesk_tickets', 'movidesk_tickets.ticket_id', '=', 'timesheets.ticket')
            ->where('timesheets.user_id', $userId)
            ->whereBetween('timesheets.date', [$from, $to])
            ->whereNotIn('timesheets.status', $excludeStatuses)
            ->where('timesheets.is_billable_only', false)
            ->whereNull('timesheets.deleted_at')
            ->orderBy('timesheets.date')
            ->get()
            ->map(function ($t) use ($effectiveRate, $isBancoHoras) {
                $solicitanteRaw = $t->ticket_solicitante;
                if (is_string($solicitanteRaw)) $solicitanteRaw = json_decode($solicitanteRaw, true);
                $solicitante = is_array($solicitanteRaw) ? ($solicitanteRaw['name'] ?? null) : null;

                $baseHoras = $t->effort_minutes / 60;
                $pct       = $t->consultant_extra_pct ? (float) $t->consultant_extra_pct : null;
                // Banco de horas: pct infla as horas creditadas; horista/fixo: pct gera valor monetário
                $horasEfetivas = ($isBancoHoras && $pct)
                    ? round($baseHoras * (1 + $pct / 100), 2)
                    : round($baseHoras, 2);

                return [
                    'id'                   => $t->id,
                    'data'                 => $t->date->format('Y-m-d'),
                    'start_time'           => $t->start_time,
                    'end_time'             => $t->end_time,
                    'projeto'              => $t->project->name ?? '—',
                    'projeto_codigo'       => $t->project->code ?? '—',
                    'cliente'              => $t->project->customer->name ?? '—',
                    'tipo_contrato_code'   => $t->project?->contractType?->code ?? 'outros',
                    'tipo_contrato_nome'   => $t->project?->contractType?->name ?? 'Outros',
                    'horas'                => $horasEfetivas,
                    'horas_base'           => round($baseHoras, 2),
                    'status'               => $t->status,
                    'ticket'               => $t->ticket,
                    'titulo'               => $t->ticket_titulo,
                    'solicitante'          => $solicitante,
                    'observacao'           => $t->observation,
                    'consultant_extra_pct' => $pct,
                    'valor_extra'          => (!$isBancoHoras && $pct)
                        ? round($baseHoras * $effectiveRate * ($pct / 100), 2)
                        : null,
                ];
            });

        return response()->json(['data' => $rows]);
    }

    // ─── Banco de Horas Detalhado ─────────────────────────────────────────────

    public function bancoHoras(string $userId, string $yearMonth): JsonResponse
    {
        [$year, $month] = array_map('intval', explode('-', $yearMonth));

        $user = User::findOrFail($userId);

        $startDate = $user->bank_hours_start_date
            ? $user->bank_hours_start_date->format('Y-m-d')
            : null;

        $calc = app(HourBankService::class)->calculateMonth(
            $user->id,
            $year,
            $month,
            (float) ($user->daily_hours ?? 8.0),
            $startDate
        );

        $fixedSalary    = (float) ($user->hourly_rate ?? 0);
        $valorHoraExtra = $fixedSalary > 0 ? round($fixedSalary / 180, 4) : 0;
        $horasExtras    = $calc['paid_hours'];
        $totalExtra     = round($horasExtras * $valorHoraExtra, 2);

        return response()->json([
            'data' => array_merge($calc, [
                'fixed_salary'     => $fixedSalary,
                'valor_hora_extra' => $valorHoraExtra,
                'horas_extras'     => $horasExtras,
                'total_extra'      => $totalExtra,
                'total'            => round($fixedSalary + $totalExtra, 2),
            ]),
        ]);
    }
}
