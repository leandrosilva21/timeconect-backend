<?php

namespace App\Http\Controllers;

use App\Exports\FechamentoConsultorExport;
use App\Exports\FechamentoConsultorListExport;
use App\Mail\FechamentoConsultorMail;
use App\Models\FechamentoConsultorEmail;
use App\Models\Expense;
use App\Models\Timesheet;
use App\Models\User;
use App\Models\UserHourlyRateLog;
use App\Services\HourBankService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

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
            ? round($hourlyRate / 160, 4)
            : $hourlyRate;
    }

    private const MESES = ['', 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

    /** "Maio de 2026" */
    private function periodoExtenso(string $yearMonth): string
    {
        [$year, $month] = array_map('intval', explode('-', $yearMonth));
        $nome = ($month >= 1 && $month <= 12) ? self::MESES[$month] : $yearMonth;
        return "{$nome} de {$year}";
    }

    /** "05/2026" (MM/AAAA) — usado no assunto do e-mail. */
    private function periodoMMAAAA(string $yearMonth): string
    {
        [$year, $month] = array_map('intval', explode('-', $yearMonth));
        return sprintf('%02d/%04d', $month, $year);
    }

    private function brl(float $value): string
    {
        return 'R$ ' . number_format($value, 2, ',', '.');
    }

    /** Formata horas decimais como HHhMM (ex.: 12.5 -> "12h30"). */
    private function fmtHoras(float $h): string
    {
        // Horas em DECIMAL 2 casas (pt-BR) — total bate com horas × taxa.
        return number_format($h, 2, ',', '.');
    }

    /** Remove acentos/espaços/barras de um nome para uso em filename. */
    private function sanitizeFilename(string $name): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $name);
        if ($ascii === false) {
            $ascii = $name;
        }
        $ascii = preg_replace('/[^A-Za-z0-9]+/', '_', $ascii);
        return trim((string) $ascii, '_') ?: 'consultor';
    }

    /**
     * Linhas de apontamento do consultor no mês — mesma forma do endpoint apontamentos().
     * Usada tanto pela API quanto pela geração de PDF/XLSX no envio de e-mail.
     */
    private function buildApontamentosRows(string $userId, string $from, string $to, ?User $user = null): array
    {
        $excludeStatuses = [Timesheet::STATUS_ADJUSTMENT_REQUESTED, Timesheet::STATUS_REJECTED, Timesheet::STATUS_CONFLICTED, Timesheet::STATUS_INTERNAL];

        $user          = $user ?: User::find($userId, ['id', 'name', 'hourly_rate', 'rate_type', 'consultant_type']);
        $isBancoHoras  = $user?->consultant_type === 'banco_de_horas';
        $hist          = UserHourlyRateLog::effectiveValuesAt((int) $userId, $user, $from);
        $effectiveRate = $this->effectiveHourlyRate(
            (float) ($hist['hourly_rate'] ?? $user?->hourly_rate ?? 0),
            $hist['rate_type'] ?? $user?->rate_type ?? 'hourly'
        );

        $rows = Timesheet::with([
            'project:id,name,code,contract_type_id,customer_id',
            'project.contractType:id,name,code',
            'project.customer:id,name',
        ])
            ->select('timesheets.*', 'movidesk_tickets.titulo as ticket_titulo', 'movidesk_tickets.solicitante as ticket_solicitante')
            ->leftJoin('movidesk_tickets', 'movidesk_tickets.ticket_id', '=', 'timesheets.ticket')
            ->where('timesheets.user_id', $userId)
            ->whereBetween('timesheets.date', [$from, $to])
            ->whereNotIn('timesheets.status', $excludeStatuses)
            ->where('timesheets.is_billable_only', false)
            ->where('timesheets.is_internal_action', false)
            ->whereNull('timesheets.deleted_at')
            ->orderBy('timesheets.date')
            ->get()
            ->map(function ($t) use ($effectiveRate, $isBancoHoras) {
                $solicitanteRaw = $t->ticket_solicitante;
                if (is_string($solicitanteRaw)) $solicitanteRaw = json_decode($solicitanteRaw, true);
                $solicitante = is_array($solicitanteRaw) ? ($solicitanteRaw['name'] ?? null) : null;

                $baseHoras = $t->effort_minutes / 60;
                $pct       = $t->consultant_extra_pct ? (float) $t->consultant_extra_pct : null;
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
            })
            ->toArray();

        return ['rows' => $rows, 'effective_rate' => $effectiveRate, 'is_banco_horas' => $isBancoHoras];
    }

    /**
     * Total a pagar de UM consultor no mês — mesma regra do index(), por tipo.
     * Retorna ['total' => float, 'horas_a_pagar' => float, 'horas_trabalhadas' => float].
     */
    private function computeConsultantClosing(User $user, string $yearMonth): array
    {
        [$from, $to]    = $this->period($yearMonth);
        [$year, $month] = array_map('intval', explode('-', $yearMonth));

        $excludeStatuses = [Timesheet::STATUS_ADJUSTMENT_REQUESTED, Timesheet::STATUS_REJECTED, Timesheet::STATUS_CONFLICTED, Timesheet::STATUS_INTERNAL];

        $totalMinutes = (int) Timesheet::whereBetween('date', [$from, $to])
            ->whereNotIn('status', $excludeStatuses)
            ->whereNull('deleted_at')
            ->where('is_billable_only', false)
            ->where('is_internal_action', false)
            ->where('user_id', $user->id)
            ->sum('effort_minutes');

        $extraTimesheets = Timesheet::whereBetween('date', [$from, $to])
            ->whereNotIn('status', $excludeStatuses)
            ->whereNull('deleted_at')
            ->where('is_billable_only', false)
            ->where('is_internal_action', false)
            ->whereNotNull('consultant_extra_pct')
            ->where('user_id', $user->id)
            ->get(['effort_minutes', 'consultant_extra_pct']);

        $hist          = UserHourlyRateLog::effectiveValuesAt($user->id, $user, $from);
        $hourlyRate    = (float) ($hist['hourly_rate'] ?? 0);
        $rateType      = $hist['rate_type'] ?? 'hourly';
        $effectiveRate = $this->effectiveHourlyRate($hourlyRate, $rateType);
        $horasTrabalhadas = round($totalMinutes / 60, 2);

        $hourBankService  = app(HourBankService::class);
        $workingDaysFull  = $hourBankService->calculateWorkingDays($year, $month);
        $totalWorkingDays = $workingDaysFull['working_days'];

        $startDate = $user->bank_hours_start_date
            ? $user->bank_hours_start_date->format('Y-m-d')
            : null;
        $startIsInMonth = $startDate
            && Carbon::parse($startDate)->year  === $year
            && Carbon::parse($startDate)->month === $month;

        if ($startDate && Carbon::parse($startDate)->format('Y-m') > $yearMonth) {
            $ratio = 0; // começou depois da competência → sem horas garantidas nesse mês
        } elseif ($startIsInMonth) {
            $workingDaysPeriod = $hourBankService->calculateWorkingDays($year, $month, $startDate);
            $ratio = $totalWorkingDays > 0 ? round($workingDaysPeriod['working_days'] / $totalWorkingDays, 6) : 1;
        } else {
            $ratio = 1;
        }

        $extrasConsultant = round(
            $extraTimesheets->sum(fn ($t) => ($t->effort_minutes / 60) * $effectiveRate * ((float) $t->consultant_extra_pct / 100)),
            2
        );

        switch ($user->consultant_type) {
            case 'horista':
                $guaranteedHours    = (float) ($user->guaranteed_hours ?? 0);
                $guaranteedProrated = $guaranteedHours > 0 ? round($guaranteedHours * $ratio, 2) : 0;
                $horasMinimas       = $guaranteedProrated > 0 ? max($horasTrabalhadas, $guaranteedProrated) : $horasTrabalhadas;
                return [
                    'total'             => round($horasMinimas * $effectiveRate + $extrasConsultant, 2),
                    'horas_a_pagar'     => $horasMinimas,
                    'horas_trabalhadas' => $horasTrabalhadas,
                    'effective_rate'    => $effectiveRate,
                    'taxa_label'        => 'Valor/Hora',
                    'taxa_value'        => $effectiveRate,
                ];

            case 'banco_de_horas':
                $extraHoursForBank = round(
                    $extraTimesheets->sum(fn ($t) => ($t->effort_minutes / 60) * ((float) $t->consultant_extra_pct / 100)),
                    2
                );
                $calc = $hourBankService->calculateMonth(
                    $user->id, $year, $month,
                    (float) ($user->daily_hours ?? 8.0),
                    $startDate, $extraHoursForBank
                );
                $valorHoraExtra = $hourlyRate > 0 ? round($hourlyRate / 160, 4) : 0;
                $horasExtras    = $calc['paid_hours'];
                $totalExtra     = round($horasExtras * $valorHoraExtra, 2);
                return [
                    'total'             => round($hourlyRate + $totalExtra, 2),
                    'horas_a_pagar'     => $horasExtras,
                    'horas_trabalhadas' => round($calc['worked_hours'] ?? $horasTrabalhadas, 2),
                    'effective_rate'    => $valorHoraExtra,
                    'taxa_label'        => 'Salário Mensal',
                    'taxa_value'        => $hourlyRate,
                ];

            case 'fixo':
                $salarioProportional = round($hourlyRate * $ratio, 2);
                return [
                    'total'             => round($salarioProportional + $extrasConsultant, 2),
                    'horas_a_pagar'     => $horasTrabalhadas,
                    'horas_trabalhadas' => $horasTrabalhadas,
                    'effective_rate'    => $effectiveRate,
                    'taxa_label'        => 'Salário Mensal',
                    'taxa_value'        => $hourlyRate,
                ];

            default:
                return [
                    'total'             => round($horasTrabalhadas * $effectiveRate + $extrasConsultant, 2),
                    'horas_a_pagar'     => $horasTrabalhadas,
                    'horas_trabalhadas' => $horasTrabalhadas,
                    'effective_rate'    => $effectiveRate,
                    'taxa_label'        => 'Valor/Hora',
                    'taxa_value'        => $effectiveRate,
                ];
        }
    }

    /** Despesas do consultor encaminhadas ao fechamento (pagar_no_fechamento=true) no período. */
    private function despesasConsultor(int $userId, string $yearMonth): array
    {
        [$from, $to] = $this->period($yearMonth);

        // Encaminhadas ao fechamento (a pagar) OU já pagas (avulso) — paga aparece com a
        // data do pagamento e NÃO entra no saldo; o saldo a pagar no fechamento = as não-pagas.
        return Expense::with(['project:id,name,code,customer_id', 'project.customer:id,name', 'category:id,name', 'paidByUser:id,name'])
            ->where('user_id', $userId)
            ->where(fn ($q) => $q->where('pagar_no_fechamento', true)->orWhere('is_paid', true))
            ->whereNotIn('status', [Expense::STATUS_ADJUSTMENT_REQUESTED, Expense::STATUS_REJECTED])
            ->whereBetween('expense_date', [$from, $to])
            ->orderBy('expense_date')
            ->get()
            ->map(fn ($e) => [
                'id'           => $e->id,
                'data'         => $e->expense_date->format('Y-m-d'),
                'descricao'    => $e->description,
                'categoria'    => $e->category->name ?? '—',
                'cliente'      => $e->project?->customer?->name ?? '—',
                'projeto'      => $e->project->name ?? '—',
                'valor'        => (float) $e->amount,
                'is_paid'      => (bool) $e->is_paid,            // true = paga avulso (fora do fechamento)
                'paid_at'      => $e->paid_at?->toISOString(),
                'paid_by_name' => $e->paidByUser->name ?? null,
            ])
            ->toArray();
    }

    /**
     * Fechamento do consultor + despesas encaminhadas + total geral.
     * total_servico = horas/serviço; total_despesas = despesas NÃO pagas avulso
     * (paga avulso aparece no relatório mas não soma); total_geral = serviço + despesa.
     */
    private function closingComDespesas(User $user, string $yearMonth): array
    {
        $closing  = $this->computeConsultantClosing($user, $yearMonth);
        $despesas = $this->despesasConsultor($user->id, $yearMonth);

        $totalServico  = (float) ($closing['total'] ?? 0);
        $totalDespesas = round(collect($despesas)->where('is_paid', false)->sum('valor'), 2);

        $closing['total_servico']  = round($totalServico, 2);
        $closing['despesas']       = $despesas;
        $closing['total_despesas'] = $totalDespesas;
        $closing['total_geral']    = round($totalServico + $totalDespesas, 2);

        return $closing;
    }

    /** GET /fechamento-consultor/{id}/{ym}/despesas — lançamentos de despesa encaminhados ao fechamento. */
    public function despesas(string $userId, string $yearMonth): JsonResponse
    {
        return response()->json(['data' => $this->despesasConsultor((int) $userId, $yearMonth)]);
    }

    /** Agrupa as linhas por tipo de contrato → cliente, para o PDF. */
    private function buildPdfGroups(array $rows): array
    {
        $byTipo = [];
        foreach ($rows as $r) {
            $byTipo[$r['tipo_contrato_nome'] ?? 'Outros'][] = $r;
        }

        $grupos = [];
        foreach ($byTipo as $tipo => $items) {
            $byCliente   = [];
            $horasTipo   = 0.0;
            foreach ($items as $r) {
                $byCliente[$r['cliente'] ?? '—'][] = $r;
                $horasTipo += (float) ($r['horas'] ?? 0);
            }

            $clientes = [];
            foreach ($byCliente as $cliente => $linhasCliente) {
                $horasCliente = 0.0;
                $linhas = [];
                foreach ($linhasCliente as $l) {
                    $horasCliente += (float) ($l['horas'] ?? 0);
                    $descricao = $l['observacao']
                        ? trim(preg_replace('/\s+/', ' ', strip_tags((string) $l['observacao'])))
                        : '';
                    $linhas[] = [
                        'data'      => isset($l['data']) ? Carbon::parse($l['data'])->format('d/m/Y') : '',
                        'projeto'   => $l['projeto'] ?? '—',
                        'ticket'    => $l['ticket'] ?? '',
                        'descricao' => $descricao,
                        'horas_fmt' => $this->fmtHoras((float) ($l['horas'] ?? 0)),
                    ];
                }
                $clientes[] = [
                    'nome'      => $cliente,
                    'linhas'    => $linhas,
                    'horas_fmt' => $this->fmtHoras($horasCliente),
                ];
            }

            $grupos[] = [
                'tipo'      => $tipo,
                'clientes'  => $clientes,
                'horas_fmt' => $this->fmtHoras($horasTipo),
            ];
        }

        return $grupos;
    }

    /** Message-ID determinístico-por-envio da thread de fechamento. */
    private function buildMessageId(int|string $consultantId, string $yearMonth): string
    {
        return 'fech-' . $consultantId . '-' . $yearMonth . '-' . Str::uuid()->toString() . '@minutor.com.br';
    }

    /** Chave da thread usada no header X-Minutor-Fechamento-Id. */
    private function threadKey(int|string $consultantId, string $yearMonth): string
    {
        return "{$consultantId}:{$yearMonth}";
    }

    /**
     * Raiz canônica da thread de um consultor+período: a 1ª linha (por id) que tenha
     * message_id não-nulo. É o Message-ID que todas as demais mensagens (reenvios e
     * continuações) referenciam via In-Reply-To/References para threadar no Outlook/Gmail.
     * Retorna null se ainda não houver nenhuma mensagem com message_id (thread inexistente).
     */
    private function threadRoot(int|string $consultantId, string $yearMonth, string $mode = 'ambos'): ?FechamentoConsultorEmail
    {
        return FechamentoConsultorEmail::where('consultant_user_id', $consultantId)
            ->where('year_month', $yearMonth)
            ->whereNotNull('message_id')
            // Despesa e serviço NÃO compartilham thread (assuntos distintos).
            ->when($mode === 'despesa', fn ($q) => $q->where('subject', 'like', 'Despesas%'))
            ->when($mode !== 'despesa', fn ($q) => $q->where('subject', 'not like', 'Despesas%'))
            ->orderBy('id')
            ->first();
    }

    /**
     * Gera (PDF + XLSX) do fechamento do consultor e grava em storage/app/fechamentos.
     * Reutilizado pelo envio original e pelas continuações que pedem anexo atualizado.
     *
     * @return array{
     *   pdf_rel:string, xlsx_rel:string, pdf_full:string, xlsx_full:string,
     *   pdf_name:string, xlsx_name:string, total_value:float
     * }
     */
    /**
     * View-data ÚNICA do relatório do consultor — usada PELA TELA (endpoint reportHtml)
     * E pelo PDF/e-mail (generateFechamentoFiles), garantindo que sejam IDÊNTICOS.
     * Espelha o antigo buildReport() do front: resumo por tipo (BH/Fixo/Horista),
     * apontamentos por tipo→cliente (8 colunas), despesas, ajustes e total.
     */
    private function buildConsultorReportView(int $userId, string $yearMonth, string $mode = 'ambos'): array
    {
        [$from, $to] = $this->period($yearMonth);
        $consultor = collect($this->buildConsultoresData($yearMonth))
            ->only(['horistas', 'banco_horas', 'fixos'])
            ->flatMap(fn ($g) => $g)
            ->firstWhere('user_id', $userId) ?? [];

        $user     = User::find($userId);
        $apont    = $this->buildApontamentosRows((string) $userId, $from, $to, $user);
        $rows     = $apont['rows'];
        $despesas = $this->despesasConsultor($userId, $yearMonth);

        $servTotal = round((float) ($consultor['total'] ?? 0), 2);
        $despTot   = round((float) ($consultor['total_despesas'] ?? 0), 2);

        $soDespesa = $mode === 'despesa';
        $soServico = $mode === 'servicos';
        $baseValor = $soServico ? $servTotal : ($soDespesa ? $despTot : $servTotal + $despTot);

        $desconto     = round((float) ($consultor['desconto'] ?? 0), 2);
        $adiantamento = round((float) ($consultor['adiantamento'] ?? 0), 2);
        $adicional    = round((float) ($consultor['adicional'] ?? 0), 2);
        $temAjustes   = !$soDespesa && ($desconto != 0 || $adiantamento != 0 || $adicional != 0);
        $recebimento  = round($baseValor - $desconto - $adiantamento + $adicional, 2);

        // ── Resumo (cards) — espelha o summaryExtra por tipo do buildReport ──
        $rate4 = fn ($v) => 'R$ ' . number_format((float) $v, ((float) $v == floor((float) $v)) ? 2 : 4, ',', '.');
        $cards = [];
        if ($soDespesa) {
            $cards[] = ['label' => 'Despesas (fechamento)', 'value' => $this->brl($despTot), 'color' => '#7c3aed'];
        } else {
            $totalHoras = round(collect($rows)->sum('horas'), 2);
            $cards[] = ['label' => 'Total Horas', 'value' => $this->fmtHoras($totalHoras)];
            if (array_key_exists('fixed_salary', $consultor)) {            // Banco de Horas
                $cards[] = ['label' => 'H Úteis Disponib.', 'value' => $this->fmtHoras((float) ($consultor['expected_hours'] ?? 0))];
                $cards[] = ['label' => 'Base Mensal', 'value' => $this->brl((float) ($consultor['fixed_salary'] ?? 0))];
                $cards[] = ['label' => 'Taxa/h (÷160)', 'value' => $rate4($consultor['valor_hora_extra'] ?? 0)];
                $cards[] = ['label' => 'Saldo Acumulado', 'value' => $this->fmtHoras((float) ($consultor['accumulated_balance'] ?? 0))];
                $cards[] = ['label' => 'H Extras', 'value' => ($consultor['horas_extras'] ?? 0) > 0 ? $this->fmtHoras((float) $consultor['horas_extras']) : '—'];
            } elseif (array_key_exists('salario_mensal', $consultor)) {    // Fixo
                $cards[] = ['label' => 'Salário Mensal', 'value' => $this->brl((float) ($consultor['salario_mensal'] ?? 0))];
            } else {                                                        // Horista
                $hasGuaranteed = ($consultor['guaranteed_prorated'] ?? 0) > 0 && ($consultor['horas_a_pagar'] ?? 0) > ($consultor['horas_trabalhadas'] ?? 0);
                if ($hasGuaranteed) {
                    $cards[] = ['label' => 'H Garantidas (mín)', 'value' => $this->fmtHoras((float) $consultor['guaranteed_prorated']), 'color' => '#d97706'];
                    $cards[] = ['label' => 'H a Pagar', 'value' => $this->fmtHoras((float) $consultor['horas_a_pagar'])];
                }
                $cards[] = ['label' => 'Taxa/h', 'value' => $this->brl((float) ($consultor['effective_rate'] ?? 0))];
            }
            $cards[] = ['label' => 'Total Serviços', 'value' => $this->brl($servTotal), 'color' => '#7c3aed'];
        }

        // ── Apontamentos: tipo → cliente → linhas (8 colunas, espelha a tela) ──
        $fmtTime = fn ($t) => !$t ? '—' : (str_contains((string) $t, 'T') ? substr((string) $t, 11, 5) : substr((string) $t, 0, 5));
        $grupos = [];
        if (!$soDespesa) {
            $byTipo = [];
            foreach ($rows as $r) { $byTipo[$r['tipo_contrato_nome'] ?? 'Outros'][] = $r; }
            foreach ($byTipo as $tipo => $items) {
                $byCliente = []; $horasTipo = 0.0;
                foreach ($items as $r) { $byCliente[$r['cliente'] ?? '—'][] = $r; $horasTipo += (float) ($r['horas'] ?? 0); }
                $clientes = [];
                foreach ($byCliente as $cli => $linhasCli) {
                    $horasCli = 0.0; $linhas = [];
                    foreach ($linhasCli as $l) {
                        $horasCli += (float) ($l['horas'] ?? 0);
                        $extra = '';
                        if (!empty($l['consultant_extra_pct'])) {
                            $extra = ($l['valor_extra'] ?? null) !== null
                                ? '+' . $l['consultant_extra_pct'] . '% (' . $this->brl((float) $l['valor_extra']) . ')'
                                : '+' . $l['consultant_extra_pct'] . '% base ' . $this->fmtHoras((float) ($l['horas_base'] ?? $l['horas']));
                        }
                        $linhas[] = [
                            'data'    => isset($l['data']) ? Carbon::parse($l['data'])->format('d/m/Y') : '',
                            'cliente' => $l['cliente'] ?? '—',
                            'codigo'  => $l['projeto_codigo'] ?? '',
                            'projeto' => $l['projeto'] ?? '—',
                            'ticket'  => $l['ticket'] ?: '—',
                            'titulo'  => $l['titulo'] ?: '—',
                            'inicio'  => $fmtTime($l['start_time'] ?? null),
                            'fim'     => $fmtTime($l['end_time'] ?? null),
                            'horas'   => $this->fmtHoras((float) ($l['horas'] ?? 0)),
                            'extra'   => $extra,
                        ];
                    }
                    $clientes[] = ['nome' => $cli, 'horas_fmt' => $this->fmtHoras($horasCli), 'linhas' => $linhas];
                }
                $grupos[] = ['tipo' => $tipo, 'horas_fmt' => $this->fmtHoras($horasTipo), 'clientes' => $clientes];
            }
        }

        // Logo: Bizify quando o consultor é Bizify (espelha o Blade legado), senão ERPSERV.
        $logoBizify  = public_path('logo-bizify.png');
        $logoErp     = public_path('logo-erpserv.png');
        $logoPath    = ($user && $user->is_bizify && is_file($logoBizify)) ? $logoBizify : $logoErp;
        $logoDataUri = is_file($logoPath) ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath)) : '';

        $despesaSaldo = round(collect($despesas)->where('is_paid', false)->sum('valor'), 2);
        $totalValor   = $temAjustes ? $recebimento : $baseValor;

        return [
            'consultantName'  => $consultor['nome'] ?? ($user->name ?? ''),
            'periodo'         => $this->periodoExtenso($yearMonth),
            'mode'            => $mode,
            'logoDataUri'     => $logoDataUri,
            'cards'           => $cards,
            'grupos'          => $grupos,
            'despesas'        => $despesas,
            'temDespesas'     => !empty($despesas) && !$soServico,
            'despesaSaldoFmt' => $this->brl($despesaSaldo),
            'temAjustes'      => $temAjustes,
            'servTotalFmt'    => $this->brl($servTotal),
            'despTot'         => $despTot,
            'despTotFmt'      => $this->brl($despTot),
            'descontoFmt'     => $this->brl($desconto),
            'descontoDesc'    => $consultor['desconto_desc'] ?? null,
            'adiantamentoFmt' => $this->brl($adiantamento),
            'adicionalFmt'    => $this->brl($adicional),
            'adicionalDesc'   => $consultor['adicional_desc'] ?? null,
            'baseValorFmt'    => $this->brl($baseValor),
            'recebimentoFmt'  => $this->brl($recebimento),
            'totalValorFmt'   => $this->brl($totalValor),
        ];
    }

    /** GET /fechamento-consultor/{userId}/{yearMonth}/report-html?mode= — HTML do relatório (mesma fonte do PDF). */
    public function reportHtml(Request $request, string $userId, string $yearMonth): JsonResponse
    {
        $mode = $request->query('mode', 'ambos');
        $html = view('pdf.fechamento-consultor', $this->buildConsultorReportView((int) $userId, $yearMonth, $mode))->render();
        return response()->json(['html' => $html]);
    }

    private function generateFechamentoFiles(User $consultant, string $yearMonth, string $mode = 'ambos'): array
    {
        [$from, $to]   = $this->period($yearMonth);
        $periodo       = $this->periodoExtenso($yearMonth);

        $closing       = $this->closingComDespesas($consultant, $yearMonth);
        $totalServico  = (float) $closing['total_servico'];
        $totalDespesas = (float) $closing['total_despesas'];
        $apont         = $this->buildApontamentosRows($consultant->id, $from, $to, $consultant);
        $rows          = $apont['rows'];
        $effectiveRate = (float) $apont['effective_rate'];

        $soDespesa  = $mode === 'despesa';
        $soServico  = $mode === 'servicos';
        $totalValue = $soDespesa ? $totalDespesas : ($soServico ? $totalServico : (float) $closing['total_geral']);

        // Ajustes manuais (desconto/adiantamento/adicional) — entram no Recebimento final.
        $ajuste        = \App\Models\FechamentoConsultorAjuste::where('user_id', $consultant->id)
            ->where('year_month', $yearMonth)->first();
        $desconto      = round((float) ($ajuste->desconto ?? 0), 2);
        $adiantamento  = round((float) ($ajuste->adiantamento ?? 0), 2);
        $adicional     = round((float) ($ajuste->adicional ?? 0), 2);
        $temAjustes    = ($desconto != 0 || $adiantamento != 0 || $adicional != 0);
        // Recebimento = valor base do relatório (conforme mode) − desconto − adiantamento + adicional.
        $recebimento   = round($totalValue - $desconto - $adiantamento + $adicional, 2);

        $prefix       = $soDespesa ? 'Despesas' : 'Fechamento';
        $safeName     = $this->sanitizeFilename($consultant->name);
        $pdfFileName  = "{$prefix}_{$yearMonth}_{$safeName}.pdf";
        $xlsxFileName = "{$prefix}_{$yearMonth}_{$safeName}.xlsx";
        $dir          = 'fechamentos';
        $pdfRelPath   = "{$dir}/{$pdfFileName}";
        $xlsxRelPath  = "{$dir}/{$xlsxFileName}";
        $pdfFullPath  = storage_path("app/{$pdfRelPath}");
        $xlsxFullPath = storage_path("app/{$xlsxRelPath}");

        // Cria a pasta REAL onde os arquivos são gravados/anexados (storage/app/fechamentos).
        // No Laravel 11+ o disco 'local' aponta pra storage/app/private, então gravamos via storage_path() direto.
        $dirFull = storage_path("app/{$dir}");
        if (!is_dir($dirFull)) {
            mkdir($dirFull, 0775, true);
        }

        // ── PDF ── (MESMA fonte/Blade do relatório da tela → tela = e-mail idênticos)
        $pdf = Pdf::loadView('pdf.fechamento-consultor', $this->buildConsultorReportView($consultant->id, $yearMonth, $mode))
            ->setPaper('a4', 'portrait')->setOption(['defaultMediaType' => 'print']);
        file_put_contents($pdfFullPath, $pdf->output());

        // ── XLSX ──
        $export = $soDespesa
            ? new \App\Exports\FechamentoConsultorDespesaExport($closing['despesas'], $consultant->name, $periodo)
            : new FechamentoConsultorExport($rows, $effectiveRate, $consultant->name, $periodo, $totalValue);
        file_put_contents($xlsxFullPath, Excel::raw($export, \Maatwebsite\Excel\Excel::XLSX));

        return [
            'pdf_rel'     => $pdfRelPath,
            'xlsx_rel'    => $xlsxRelPath,
            'pdf_full'    => $pdfFullPath,
            'xlsx_full'   => $xlsxFullPath,
            'pdf_name'    => $pdfFileName,
            'xlsx_name'   => $xlsxFileName,
            'total_value' => $totalValue,
        ];
    }


    /** Mensagem padrão (corpo) do e-mail de fechamento — editável na tela antes de enviar. */
    private function defaultMensagem(string $periodo, string $yearMonth, string $mode = 'ambos'): string
    {
        $doc = $mode === 'despesa' ? 'a apuração das despesas' : 'o fechamento';
        // Recebimento sempre no dia 20 do mês seguinte à competência.
        $dataRecebimento = \Carbon\Carbon::parse($yearMonth . '-01')->addMonth()->day(20)->format('d/m/Y');
        return "Este e-mail é apenas para informar que o seu recebimento será no dia {$dataRecebimento}.\n\n"
            . "Segue em anexo {$doc} referente ao período de {$periodo}.\n\n"
            . "ATENÇÃO: Para garantir o bom andamento dos processos financeiros, pedimos que, ao receber o fechamento, revise todas as informações com atenção e informe imediatamente ao departamento financeiro se houver necessidade de ajustes. Para consultores que emitem notas fiscais pedimos que as notas sejam enviadas com antecedência, para evitar impacto no fluxo do financeiro.";
    }

    // ─── Enviar fechamento por e-mail ───────────────────────────────────────────
    // Envia o fechamento do consultor por e-mail, com detalhamento em anexos (PDF + XLSX).
    // De = conta autenticada (mail.from) com o NOME do usuário logado (sem Send As).
    // Reply-To = financeiro; CC = financeiro; To = consultor. O corpo é minimalista.
    public function enviarEmail(Request $request, string $userId, string $yearMonth): JsonResponse
    {
        $sender = $request->user();
        if (!$sender || !($sender->isAdmin() || $sender->isAdministrativo())) {
            return response()->json(['success' => false, 'message' => 'Sem permissão para enviar o fechamento.'], 403);
        }
        if (!$sender->email) {
            return response()->json(['success' => false, 'message' => 'Seu usuário não tem e-mail cadastrado para usar como remetente.'], 422);
        }

        // 'html' aceito por retrocompatibilidade do front, mas ignorado.
        $request->validate([
            'html'     => 'nullable|string',
            'subject'  => 'nullable|string',
            'mensagem' => 'nullable|string', // corpo editável; vazio = mensagem padrão
            'mode'     => 'nullable|string|in:servicos,despesa,ambos',
        ]);
        $mode = $request->input('mode', 'ambos');

        $consultant = User::find($userId);
        if (!$consultant) {
            return response()->json(['success' => false, 'message' => 'Consultor não encontrado.'], 404);
        }
        if (!$consultant->email) {
            return response()->json(['success' => false, 'message' => 'Consultor sem e-mail cadastrado.'], 422);
        }

        $periodo      = $this->periodoExtenso($yearMonth);
        $financeiroCc = (string) (config('mail.financeiro_cc') ?? '');
        // Corpo editável (texto livre); vazio = mensagem padrão.
        $mensagem     = trim((string) $request->input('mensagem')) ?: $this->defaultMensagem($periodo, $yearMonth, $mode);

        // Message-ID determinístico DESTA mensagem (sempre persistido) + chave da thread.
        $messageId    = $this->buildMessageId($consultant->id, $yearMonth);
        $threadKey    = $this->threadKey($consultant->id, $yearMonth);

        // Uma única thread por (consultor, período). Se já existe uma raiz canônica,
        // este "envio" é na verdade um REENVIO: threada na raiz (mesmo assunto + In-Reply-To),
        // em vez de virar um e-mail novo. Sem raiz = primeiro envio (cria a thread).
        $root         = $this->threadRoot($consultant->id, $yearMonth, $mode);
        $isResend     = $root !== null;

        if ($isResend) {
            // Reenvio: MESMO assunto da raiz (sem "Re:") + In-Reply-To/References da raiz.
            $subject  = $root->subject;
            $inReplyTo = $root->message_id;
        } else {
            // Primeiro envio: assunto gerado, raiz da thread (sem pai).
            $subject  = $request->input('subject')
                ?: ($mode === 'despesa'
                    ? 'Despesas ' . $this->periodoMMAAAA($yearMonth) . ' | Reembolso - ' . $consultant->name
                    : 'Fechamento ' . $this->periodoMMAAAA($yearMonth) . ' | Relatório de Horas - ' . $consultant->name);
            $inReplyTo = null;
        }

        // Gera anexos (PDF + XLSX) — reutilizável (mesma geração das continuações).
        $files        = $this->generateFechamentoFiles($consultant, $yearMonth, $mode);
        $totalValue   = $files['total_value'];

        $log = new FechamentoConsultorEmail([
            'sender_user_id'     => $sender->id,
            'consultant_user_id' => $consultant->id,
            'year_month'         => $yearMonth,
            'to_email'           => $consultant->email,
            'cc_email'           => $financeiroCc ?: null,
            'subject'            => $subject,
            'message_id'         => $messageId,
            'in_reply_to'        => $inReplyTo,
            'is_continuation'    => false,
            'total_value'        => $totalValue,
        ]);

        // Envia COMO o remetente (App Password O365) quando configurado; senão, remetente padrão.
        $mc = \App\Services\SenderMailer::for(
            $sender,
            'smtp',
            (string) config('mail.from.address'),
            config('mail.fechamento_from_name', 'Fechamento ERPSERV'),
        );

        try {
            // ── E-mail ──
            $mailable = new FechamentoConsultorMail(
                consultantName: $consultant->name,
                senderName:     $sender->name,
                periodo:        $periodo,
                valorTotal:     $this->brl($totalValue),
                financeiroCc:   $financeiroCc,
                subjectLine:    $subject,
                pdfPath:        $files['pdf_full'],
                xlsxPath:       $files['xlsx_full'],
                pdfFileName:    $files['pdf_name'],
                xlsxFileName:   $files['xlsx_name'],
                messageId:      $messageId,
                references:     $inReplyTo,
                inReplyTo:      $inReplyTo,
                threadKey:      $threadKey,
                withAttachments: true,
                senderEmail:    $sender->email, // Reply-To = quem enviou (trata a resposta no Outlook)
                mensagem:       $mensagem,
                fromAddress:    $mc['from_address'],
                fromName:       $mc['from_name'],
                mode:           $mode,
                isBizify:       (bool) $consultant->is_bizify,
            );

            // Microsoft Graph (Send As do remetente) quando configurado; senão, SMTP atual.
            if (\App\Services\GraphMailer::enabled() && filled($sender->email)) {
                \App\Services\GraphMailer::sendAs(
                    $sender->email,
                    [$consultant->email],
                    array_filter([$financeiroCc]),
                    $subject,
                    $mailable->render(),
                    [$files['pdf_full'], $files['xlsx_full']],
                );
            } else {
                Mail::mailer($mc['mailer'])->to($consultant->email)->send($mailable);
            }

            $log->fill([
                'pdf_path'          => $files['pdf_rel'],
                'xlsx_path'         => $files['xlsx_rel'],
                'status'            => FechamentoConsultorEmail::STATUS_ENVIADO,
                'provider_response' => null,
                'sent_at'           => now(),
            ])->save();

            \App\Models\FechamentoSendStatus::marcarEnviado(
                \App\Models\FechamentoSendStatus::TIPO_CONSULTOR, (int) $consultant->id, $yearMonth, (int) $sender->id,
            );

            Log::info('Fechamento de consultor enviado por e-mail', [
                'consultor' => $consultant->id, 'remetente' => $sender->id,
                'pdf' => $files['pdf_rel'], 'xlsx' => $files['xlsx_rel'], 'total' => $totalValue,
            ]);
        } catch (\Throwable $e) {
            $log->fill([
                'pdf_path'          => is_file($files['pdf_full']) ? $files['pdf_rel'] : null,
                'xlsx_path'         => is_file($files['xlsx_full']) ? $files['xlsx_rel'] : null,
                'status'            => FechamentoConsultorEmail::STATUS_FALHOU,
                'provider_response' => $e->getMessage(),
                'sent_at'           => null,
            ])->save();

            Log::error('Falha ao enviar fechamento de consultor por e-mail', [
                'consultor' => $consultant->id, 'remetente' => $sender->id, 'erro' => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'message' => 'Falha ao enviar o e-mail: ' . $e->getMessage()], 500);
        }

        return response()->json([
            'success' => true,
            'message' => "Fechamento enviado para {$consultant->email}" . ($financeiroCc ? " (cópia: {$financeiroCc})" : '') . '.',
        ]);
    }

    // ─── Limpar status de envio ─────────────────────────────────────────────────
    // Reseta o "enviado" do consultor/mês (volta a "não enviado"). NÃO apaga a thread
    // de e-mails (fechamento_consultor_emails), só o indicador de status.
    public function limparEnvio(Request $request, string $userId, string $yearMonth): JsonResponse
    {
        $sender = $request->user();
        if (!$sender || !($sender->isAdmin() || $sender->isAdministrativo())) {
            return response()->json(['success' => false, 'message' => 'Sem permissão.'], 403);
        }

        \App\Models\FechamentoSendStatus::limpar(
            \App\Models\FechamentoSendStatus::TIPO_CONSULTOR, (int) $userId, $yearMonth,
        );

        return response()->json(['success' => true, 'message' => 'Status de envio limpo.']);
    }

    // ─── Download do Excel (XLSX) do fechamento ─────────────────────────────────
    // Mesmo XLSX que vai como anexo no e-mail, baixável direto pela tela do relatório.
    public function excel(Request $request, string $userId, string $yearMonth)
    {
        $sender = $request->user();
        if (!$sender || !($sender->isAdmin() || $sender->isAdministrativo())) {
            return response()->json(['success' => false, 'message' => 'Sem permissão.'], 403);
        }
        $consultant = User::find($userId);
        if (!$consultant) {
            return response()->json(['success' => false, 'message' => 'Consultor não encontrado.'], 404);
        }

        [$from, $to] = $this->period($yearMonth);
        $periodo  = $this->periodoExtenso($yearMonth);
        $mode     = $request->query('mode', 'ambos');
        $closing  = $this->closingComDespesas($consultant, $yearMonth);

        // Relatório SÓ de despesas → planilha de despesas.
        if ($mode === 'despesa') {
            $export   = new \App\Exports\FechamentoConsultorDespesaExport($closing['despesas'], $consultant->name, $periodo);
            $fileName = "Despesas_{$yearMonth}_" . $this->sanitizeFilename($consultant->name) . ".xlsx";
            return Excel::download($export, $fileName);
        }

        $apont    = $this->buildApontamentosRows($consultant->id, $from, $to, $consultant);
        $total    = $mode === 'servicos' ? (float) $closing['total_servico'] : (float) $closing['total_geral'];
        $export   = new FechamentoConsultorExport(
            $apont['rows'], (float) $apont['effective_rate'], $consultant->name, $periodo, $total
        );
        $fileName = "Fechamento_{$yearMonth}_" . $this->sanitizeFilename($consultant->name) . ".xlsx";

        return Excel::download($export, $fileName);
    }

    // ─── Prévia do e-mail (template real) com a mensagem editável ───────────────
    // Renderiza o MESMO template do envio, pra mostrar na tela e atualizar ao vivo
    // conforme o admin edita a mensagem. Retorna o HTML + a mensagem padrão (1ª carga).
    public function emailPreview(Request $request, string $userId, string $yearMonth): JsonResponse
    {
        $sender = $request->user();
        if (!$sender || !($sender->isAdmin() || $sender->isAdministrativo())) {
            return response()->json(['success' => false, 'message' => 'Sem permissão.'], 403);
        }
        $consultant = User::find($userId);
        if (!$consultant) {
            return response()->json(['success' => false, 'message' => 'Consultor não encontrado.'], 404);
        }

        $periodo        = $this->periodoExtenso($yearMonth);
        $mode           = $request->input('mode', 'ambos');
        $closing        = $this->closingComDespesas($consultant, $yearMonth);
        $valorPreview   = $mode === 'despesa'
            ? (float) $closing['total_despesas']
            : ($mode === 'servicos' ? (float) $closing['total_servico'] : (float) $closing['total_geral']);
        $mensagemPadrao = $this->defaultMensagem($periodo, $yearMonth, $mode);
        $mensagem       = trim((string) $request->input('mensagem'));
        $mensagem       = $mensagem !== '' ? $mensagem : $mensagemPadrao;

        $html = view('emails.fechamento.consultor', [
            'consultantName'  => $consultant->name,
            'senderName'      => $sender->name,
            'periodo'         => $periodo,
            'mode'            => $mode,
            'valorTotal'      => $this->brl($valorPreview),
            'financeiroCc'    => (string) (config('mail.financeiro_cc') ?? ''),
            'bodyText'        => null,
            'isContinuation'  => false,
            'withAttachments' => true,
            'mensagem'        => $mensagem,
            'isBizify'        => (bool) $consultant->is_bizify,
        ])->render();

        // Prévia só: força o logo claro (escuro-colorido) a aparecer no card branco —
        // o swap de dark-mode do template trocaria pro logo branco (invisível aqui).
        $override = '<style>.erp-light{display:inline-block !important}.erp-dark{display:none !important}</style>';
        $html = str_ireplace('</head>', $override . '</head>', $html);

        return response()->json(['html' => $html, 'mensagem_padrao' => $mensagemPadrao]);
    }

    // ─── Thread (conversa do fechamento) ────────────────────────────────────────
    // Lista todas as mensagens de um consultor no período — saída (outbound: original +
    // continuações) E entrada (inbound: respostas lidas via Graph) — em ordem cronológica.
    public function thread(Request $request, string $userId, string $yearMonth): JsonResponse
    {
        $sender = $request->user();
        if (!$sender || !($sender->isAdmin() || $sender->isAdministrativo())) {
            return response()->json(['success' => false, 'message' => 'Sem permissão.'], 403);
        }

        return response()->json([
            'data'          => $this->threadMessages($userId, $yearMonth),
            'graph_enabled' => app(\App\Services\GraphMailService::class)->isConfigured(),
        ]);
    }

    // ─── Atualizar / puxar respostas agora (botão "Atualizar" da conversa) ───────
    // Dispara a leitura da caixa do noreply (Graph) na hora e devolve a thread já atualizada.
    // Em prod o scheduler já roda de 5 em 5 min; este endpoint é o "puxar agora".
    public function syncInbox(Request $request, string $userId, string $yearMonth): JsonResponse
    {
        $sender = $request->user();
        if (!$sender || !($sender->isAdmin() || $sender->isAdministrativo())) {
            return response()->json(['success' => false, 'message' => 'Sem permissão.'], 403);
        }

        $graph    = app(\App\Services\GraphMailService::class);
        $enabled  = $graph->isConfigured();
        $imported = 0;
        if ($enabled) {
            try {
                \Illuminate\Support\Facades\Artisan::call('fechamento:poll-inbox');
                if (preg_match('/importadas:\s*(\d+)/i', \Illuminate\Support\Facades\Artisan::output(), $mm)) {
                    $imported = (int) $mm[1];
                }
            } catch (\Throwable $e) {
                Log::warning('syncInbox: falha ao puxar respostas', ['erro' => $e->getMessage()]);
            }
        }

        return response()->json([
            'data'          => $this->threadMessages($userId, $yearMonth),
            'graph_enabled' => $enabled,
            'imported'      => $imported,
        ]);
    }

    /** Mensagens da thread (saída + entrada) em ordem cronológica — payload compartilhado. */
    private function threadMessages(string $userId, string $yearMonth): \Illuminate\Support\Collection
    {
        return FechamentoConsultorEmail::with('sender:id,name')
            ->where('consultant_user_id', $userId)
            ->where('year_month', $yearMonth)
            ->orderByRaw('COALESCE(sent_at, received_at, created_at) ASC') // cronológico (in + out)
            ->orderBy('id')
            ->get()
            ->map(function (FechamentoConsultorEmail $m) {
                $isInbound = $m->direction === FechamentoConsultorEmail::DIRECTION_INBOUND;
                $at        = $m->sent_at ?: $m->received_at;
                return [
                    'id'              => $m->id,
                    'direction'       => $m->direction ?: FechamentoConsultorEmail::DIRECTION_OUTBOUND,
                    'is_inbound'      => $isInbound,
                    'subject'         => $m->subject,
                    'body'            => $m->body,
                    'is_continuation' => (bool) $m->is_continuation,
                    'has_attachments' => (bool) ($m->pdf_path || $m->xlsx_path),
                    // Inbound = remetente é o consultor (from_email); outbound = usuário logado.
                    'sender_name'     => $isInbound ? ($m->from_email ?: 'Consultor') : $m->sender?->name,
                    'from_email'      => $m->from_email,
                    'to_email'        => $m->to_email,
                    'cc_email'        => $m->cc_email,
                    'status'          => $m->status,
                    'sent_at'         => optional($m->sent_at)->toIso8601String(),
                    'received_at'     => optional($m->received_at)->toIso8601String(),
                    'at'              => optional($at)->toIso8601String(),
                ];
            })
            ->values();
    }

    // ─── Continuar a conversa (resposta na mesma thread) ────────────────────────
    // Envia um follow-up THREADED (Re: + In-Reply-To/References da 1ª mensagem).
    // From/CC iguais ao original (plano B). Anexar fechamento atualizado é opcional.
    public function continuar(Request $request, string $userId, string $yearMonth): JsonResponse
    {
        $sender = $request->user();
        if (!$sender || !($sender->isAdmin() || $sender->isAdministrativo())) {
            return response()->json(['success' => false, 'message' => 'Sem permissão para responder o fechamento.'], 403);
        }
        if (!$sender->email) {
            return response()->json(['success' => false, 'message' => 'Seu usuário não tem e-mail cadastrado para usar como remetente.'], 422);
        }

        $validated = $request->validate([
            'body'              => 'required|string',
            'attach_fechamento' => 'nullable|boolean',
        ]);
        $bodyText        = (string) $validated['body'];
        $attachFechamento = (bool) ($validated['attach_fechamento'] ?? false);

        $consultant = User::find($userId);
        if (!$consultant) {
            return response()->json(['success' => false, 'message' => 'Consultor não encontrado.'], 404);
        }
        if (!$consultant->email) {
            return response()->json(['success' => false, 'message' => 'Consultor sem e-mail cadastrado.'], 422);
        }

        // Raiz canônica da thread (1ª linha com message_id). É nela que a continuação
        // se pendura via In-Reply-To/References, garantindo UMA conversa no Outlook/Gmail.
        $root = $this->threadRoot($userId, $yearMonth);

        // Fallback gracioso p/ logs antigos sem message_id: usa o 1º envio original.
        $original = $root ?: FechamentoConsultorEmail::where('consultant_user_id', $userId)
            ->where('year_month', $yearMonth)
            ->where('is_continuation', false)
            ->orderBy('sent_at')
            ->orderBy('id')
            ->first();

        if (!$original) {
            return response()->json([
                'success' => false,
                'message' => 'Envie o fechamento original antes de continuar a conversa.',
            ], 422);
        }

        $periodo      = $this->periodoExtenso($yearMonth);
        $parentMsgId  = $original->message_id; // raiz; pode ser null em logs antigos (fallback)
        $threadKey    = $this->threadKey($consultant->id, $yearMonth);
        $messageId    = $this->buildMessageId($consultant->id, $yearMonth);
        $financeiroCc = (string) ($original->cc_email ?? config('mail.financeiro_cc') ?? '');
        $subject      = Str::startsWith($original->subject, 'Re: ')
            ? $original->subject
            : 'Re: ' . $original->subject;

        // Anexos só se pedido — regenera (reusa a geração do envio original).
        $files = null;
        if ($attachFechamento) {
            $files = $this->generateFechamentoFiles($consultant, $yearMonth);
        }
        $totalValue = $files['total_value'] ?? (float) $original->total_value;

        $log = new FechamentoConsultorEmail([
            'sender_user_id'     => $sender->id,
            'consultant_user_id' => $consultant->id,
            'year_month'         => $yearMonth,
            'to_email'           => $consultant->email,
            'cc_email'           => $financeiroCc ?: null,
            'subject'            => $subject,
            'message_id'         => $messageId,
            'in_reply_to'        => $parentMsgId,
            'body'               => $bodyText,
            'is_continuation'    => true,
            'total_value'        => $totalValue,
        ]);

        // Mesma identidade do envio original: COMO o remetente (App Password) quando configurado.
        $mc = \App\Services\SenderMailer::for(
            $sender,
            'smtp',
            (string) config('mail.from.address'),
            config('mail.fechamento_from_name', 'Fechamento ERPSERV'),
        );

        try {
            $mailable = new FechamentoConsultorMail(
                consultantName: $consultant->name,
                senderName:     $sender->name,
                periodo:        $periodo,
                valorTotal:     $this->brl($totalValue),
                financeiroCc:   $financeiroCc,
                subjectLine:    $subject,
                pdfPath:        $files['pdf_full']  ?? '',
                xlsxPath:       $files['xlsx_full'] ?? '',
                pdfFileName:    $files['pdf_name']  ?? '',
                xlsxFileName:   $files['xlsx_name'] ?? '',
                messageId:      $messageId,
                references:     $parentMsgId,
                inReplyTo:      $parentMsgId,
                threadKey:      $threadKey,
                bodyText:       $bodyText,
                isContinuation: true,
                withAttachments: $attachFechamento,
                fromAddress:    $mc['from_address'],
                fromName:       $mc['from_name'],
                isBizify:       (bool) $consultant->is_bizify,
            );

            // Microsoft Graph (Send As do remetente) quando configurado; senão, SMTP atual.
            if (\App\Services\GraphMailer::enabled() && filled($sender->email)) {
                $attachmentPaths = ($attachFechamento && $files)
                    ? [$files['pdf_full'], $files['xlsx_full']]
                    : [];
                \App\Services\GraphMailer::sendAs(
                    $sender->email,
                    [$consultant->email],
                    array_filter([$financeiroCc]),
                    $subject,
                    $mailable->render(),
                    $attachmentPaths,
                );
            } else {
                Mail::mailer($mc['mailer'])->to($consultant->email)->send($mailable);
            }

            $log->fill([
                'pdf_path'          => $attachFechamento && $files ? $files['pdf_rel']  : null,
                'xlsx_path'         => $attachFechamento && $files ? $files['xlsx_rel'] : null,
                'status'            => FechamentoConsultorEmail::STATUS_ENVIADO,
                'provider_response' => null,
                'sent_at'           => now(),
            ])->save();

            Log::info('Continuação de fechamento de consultor enviada', [
                'consultor' => $consultant->id, 'remetente' => $sender->id,
                'in_reply_to' => $parentMsgId, 'message_id' => $messageId, 'anexo' => $attachFechamento,
            ]);
        } catch (\Throwable $e) {
            $log->fill([
                'status'            => FechamentoConsultorEmail::STATUS_FALHOU,
                'provider_response' => $e->getMessage(),
                'sent_at'           => null,
            ])->save();

            Log::error('Falha ao enviar continuação de fechamento de consultor', [
                'consultor' => $consultant->id, 'remetente' => $sender->id, 'erro' => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'message' => 'Falha ao enviar a resposta: ' . $e->getMessage()], 500);
        }

        return response()->json([
            'success' => true,
            'message' => "Resposta enviada para {$consultant->email}" . ($financeiroCc ? " (cópia: {$financeiroCc})" : '') . '.',
        ]);
    }

    // ─── Index ────────────────────────────────────────────────────────────────

    public function index(string $yearMonth): JsonResponse
    {
        return response()->json(['data' => $this->buildConsultoresData($yearMonth)]);
    }

    // ─── Meu fechamento (próprio usuário) ─────────────────────────────────────
    /**
     * GET /my-closing/{yearMonth}
     * Devolve, para o PRÓPRIO usuário autenticado, o MESMO recebimento que o admin vê
     * na tela de fechamento — refletindo ajustes (desconto/adiantamento/adicional + motivos)
     * e o recebimento final. Cada usuário só enxerga o próprio dado (sem vazamento).
     *
     * Identificação consultor × parceiro:
     *  - PARCEIRO: usuário é o ADMIN do parceiro → type='parceiro_admin' E is_executive=true
     *    (mesmo critério de parceiroAdminEmails do FechamentoParceiroController; é quem
     *    recebe o valor agregado do parceiro). Reusa o cálculo do controller de parceiro.
     *  - CONSULTOR: qualquer outro com movimento no fechamento. Um consultor com partner_id
     *    (ex.: Raho) NÃO é parceiro_admin/is_executive → cai no fluxo de consultor, onde de
     *    fato aparece em buildConsultoresData. Prioriza o fluxo de consultor.
     *
     * Payload normalizado:
     *  { tipo, total_servico, total_despesas, total_base, desconto, desconto_desc,
     *    adiantamento, adicional, adicional_desc, recebimento }
     *  com total_base = total_servico + total_despesas
     *  e  recebimento = total_base − desconto − adiantamento + adicional.
     */
    public function myClosing(Request $request, string $yearMonth): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Não autenticado.'], 401);
        }

        // ── PARCEIRO (admin do parceiro): só o parceiro_admin executivo do parceiro ──
        if ($user->isParceiroAdmin() && $user->is_executive && $user->partner_id) {
            $partner = \App\Models\Partner::find($user->partner_id);
            if ($partner) {
                $parceiroCtrl = app(FechamentoParceiroController::class);
                // Reusa a MESMA listagem do admin e isola a linha deste parceiro,
                // garantindo total_a_pagar + ajustes + recebimento idênticos ao fechamento.
                $listReq  = Request::create('', 'GET', ['year_month' => $yearMonth]);
                $listReq->setUserResolver(fn () => $user);
                $rows     = $parceiroCtrl->index($listReq)->getData(true)['data'] ?? [];
                $row      = collect($rows)->firstWhere('partner_id', $partner->id);

                $totalBase     = round((float) ($row['total_a_pagar'] ?? 0), 2);
                $totalDespesas = round((float) ($row['total_despesas'] ?? 0), 2);
                $desconto      = round((float) ($row['desconto'] ?? 0), 2);
                $adiantamento  = round((float) ($row['adiantamento'] ?? 0), 2);
                $adicional     = round((float) ($row['adicional'] ?? 0), 2);

                return response()->json(['data' => [
                    'tipo'           => 'parceiro',
                    'total_servico'  => round($totalBase - $totalDespesas, 2),
                    'total_despesas' => $totalDespesas,
                    'total_base'     => $totalBase,
                    'desconto'       => $desconto,
                    'desconto_desc'  => $row['desconto_desc'] ?? null,
                    'adiantamento'   => $adiantamento,
                    'adicional'      => $adicional,
                    'adicional_desc' => $row['adicional_desc'] ?? null,
                    'recebimento'    => round((float) ($row['recebimento'] ?? ($totalBase - $desconto - $adiantamento + $adicional)), 2),
                ]]);
            }
        }

        // ── CONSULTOR: acha a própria linha em buildConsultoresData (horistas+banco+fixos) ──
        $data = $this->buildConsultoresData($yearMonth);
        $row  = collect($data['horistas'])
            ->merge($data['banco_horas'])
            ->merge($data['fixos'])
            ->firstWhere('user_id', $user->id);

        if ($row) {
            $totalServico  = round((float) ($row['total'] ?? 0), 2);
            $totalDespesas = round((float) ($row['total_despesas'] ?? 0), 2);
            $desconto      = round((float) ($row['desconto'] ?? 0), 2);
            $adiantamento  = round((float) ($row['adiantamento'] ?? 0), 2);
            $adicional     = round((float) ($row['adicional'] ?? 0), 2);

            return response()->json(['data' => [
                'tipo'           => 'consultor',
                'total_servico'  => $totalServico,
                'total_despesas' => $totalDespesas,
                'total_base'     => round($totalServico + $totalDespesas, 2),
                'desconto'       => $desconto,
                'desconto_desc'  => $row['desconto_desc'] ?? null,
                'adiantamento'   => $adiantamento,
                'adicional'      => $adicional,
                'adicional_desc' => $row['adicional_desc'] ?? null,
                'recebimento'    => round((float) ($row['recebimento'] ?? ($totalServico + $totalDespesas - $desconto - $adiantamento + $adicional)), 2),
            ]]);
        }

        // ── Sem movimento no fechamento: zeros + eventuais ajustes manuais salvos ──
        $ajuste        = \App\Models\FechamentoConsultorAjuste::where('user_id', $user->id)
            ->where('year_month', $yearMonth)->first();
        $desconto      = round((float) ($ajuste->desconto ?? 0), 2);
        $adiantamento  = round((float) ($ajuste->adiantamento ?? 0), 2);
        $adicional     = round((float) ($ajuste->adicional ?? 0), 2);

        return response()->json(['data' => [
            'tipo'           => 'consultor',
            'total_servico'  => 0.0,
            'total_despesas' => 0.0,
            'total_base'     => 0.0,
            'desconto'       => $desconto,
            'desconto_desc'  => $ajuste->desconto_desc ?? null,
            'adiantamento'   => $adiantamento,
            'adicional'      => $adicional,
            'adicional_desc' => $ajuste->adicional_desc ?? null,
            'recebimento'    => round(0 - $desconto - $adiantamento + $adicional, 2),
        ]]);
    }

    /**
     * POST /fechamento-consultor/{userId}/{yearMonth}/ajustes
     * Salva (upsert) os ajustes manuais de um consultor no mês:
     * desconto / adiantamento / adicional (+ descritivos de desconto e adicional).
     * Recebimento = serviços + despesas − desconto − adiantamento + adicional.
     */
    public function salvarAjustes(Request $request, string $userId, string $yearMonth): JsonResponse
    {
        $sender = $request->user();
        if (!$sender || !($sender->isAdmin() || $sender->isAdministrativo())) {
            return response()->json(['success' => false, 'message' => 'Sem permissão.'], 403);
        }

        $data = $request->validate([
            'desconto'       => 'nullable|numeric',
            'desconto_desc'  => 'nullable|string',
            'adiantamento'   => 'nullable|numeric',
            'adicional'      => 'nullable|numeric',
            'adicional_desc' => 'nullable|string',
        ]);

        $consultant = User::find($userId);
        if (!$consultant) {
            return response()->json(['success' => false, 'message' => 'Consultor não encontrado.'], 404);
        }

        $ajuste = \App\Models\FechamentoConsultorAjuste::updateOrCreate(
            ['user_id' => (int) $userId, 'year_month' => $yearMonth],
            [
                'desconto'       => round((float) ($data['desconto'] ?? 0), 2),
                'desconto_desc'  => $data['desconto_desc'] ?? null,
                'adiantamento'   => round((float) ($data['adiantamento'] ?? 0), 2),
                'adicional'      => round((float) ($data['adicional'] ?? 0), 2),
                'adicional_desc' => $data['adicional_desc'] ?? null,
            ]
        );

        // Recalcula o recebimento (serviços + despesas − desconto − adiantamento + adicional).
        $closing       = $this->closingComDespesas($consultant, $yearMonth);
        $totalServico  = (float) ($closing['total_servico'] ?? 0);
        $totalDespesas = (float) ($closing['total_despesas'] ?? 0);
        $recebimento   = round(
            $totalServico + $totalDespesas
            - (float) $ajuste->desconto
            - (float) $ajuste->adiantamento
            + (float) $ajuste->adicional,
            2
        );

        return response()->json([
            'success'     => true,
            'ajuste'      => $ajuste,
            'recebimento' => $recebimento,
        ]);
    }

    /** Computa horistas/banco_horas/fixos + totais (compartilhado por index, exportExcel e folha). */
    public function buildConsultoresData(string $yearMonth): array
    {
        [$from, $to]     = $this->period($yearMonth);
        [$year, $month]  = array_map('intval', explode('-', $yearMonth));

        $users = User::where('enabled', true)
            ->whereNotIn('type', ['parceiro_admin', 'cliente'])
            ->whereNotNull('consultant_type')
            // Exclui quem começou DEPOIS da competência (não pertence a esse mês).
            ->where(function ($q) use ($to) {
                $q->whereNull('bank_hours_start_date')
                  ->orWhereDate('bank_hours_start_date', '<=', $to);
            })
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'type', 'consultant_type', 'contract_type', 'partner_id', 'hourly_rate', 'rate_type', 'daily_hours', 'bank_hours_start_date', 'guaranteed_hours', 'is_bizify']);

        $excludeStatuses = [Timesheet::STATUS_ADJUSTMENT_REQUESTED, Timesheet::STATUS_REJECTED, Timesheet::STATUS_CONFLICTED, Timesheet::STATUS_INTERNAL];

        $hoursByUser = Timesheet::whereBetween('date', [$from, $to])
            ->whereNotIn('status', $excludeStatuses)
            ->whereNull('deleted_at')
            ->where('is_billable_only', false)
            ->where('is_internal_action', false)
            ->whereIn('user_id', $users->pluck('id'))
            ->selectRaw('user_id, SUM(effort_minutes) as total_minutes')
            ->groupBy('user_id')
            ->pluck('total_minutes', 'user_id');

        // Per-timesheet extras (consultant_extra_pct) — only where set
        $extraTimesheetsByUser = Timesheet::whereBetween('date', [$from, $to])
            ->whereNotIn('status', $excludeStatuses)
            ->whereNull('deleted_at')
            ->where('is_billable_only', false)
            ->where('is_internal_action', false)
            ->whereNotNull('consultant_extra_pct')
            ->whereIn('user_id', $users->pluck('id'))
            ->select('user_id', 'effort_minutes', 'consultant_extra_pct')
            ->get()
            ->groupBy('user_id');

        // Despesas encaminhadas ao fechamento (pagar_no_fechamento), não pagas avulso — somadas por consultor.
        $despesasByUser = Expense::whereIn('user_id', $users->pluck('id'))
            ->where('pagar_no_fechamento', true)
            ->where('is_paid', false)
            ->whereNotIn('status', [Expense::STATUS_ADJUSTMENT_REQUESTED, Expense::STATUS_REJECTED])
            ->whereBetween('expense_date', [$from, $to])
            ->selectRaw('user_id, SUM(amount) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        $hourBankService = app(HourBankService::class);

        // Dias úteis do mês cheio — calculado uma vez, compartilhado por todos
        $workingDaysFull = $hourBankService->calculateWorkingDays($year, $month);
        $totalWorkingDays = $workingDaysFull['working_days'];

        $horistas   = [];
        $bancoHoras = [];
        $fixos      = [];

        // Status de envio do fechamento por consultor (último envio: quando + por quem).
        $envioMap = \App\Models\FechamentoSendStatus::mapFor(
            \App\Models\FechamentoSendStatus::TIPO_CONSULTOR, $yearMonth, $users->pluck('id')->all(),
        );

        // Notas fiscais PJ (NFS-e + Nota de débito) por consultor no mês.
        $notasMap = \App\Models\FechamentoNota::where('notable_type', \App\Models\User::class)
            ->where('year_month', $yearMonth)
            ->whereIn('notable_id', $users->pluck('id'))
            ->get()
            ->keyBy('notable_id');

        // Ajustes manuais (desconto/adiantamento/adicional) por consultor no mês.
        $ajustesMap = \App\Models\FechamentoConsultorAjuste::where('year_month', $yearMonth)
            ->whereIn('user_id', $users->pluck('id'))
            ->get()
            ->keyBy('user_id');

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

            $ajuste            = $ajustesMap->get($user->id);
            $descontoAjuste    = round((float) ($ajuste->desconto ?? 0), 2);
            $adiantamentoAjuste= round((float) ($ajuste->adiantamento ?? 0), 2);
            $adicionalAjuste   = round((float) ($ajuste->adicional ?? 0), 2);

            $base = [
                'user_id'           => $user->id,
                'nome'              => $user->name,
                'email'             => $user->email,
                'type'              => $user->type,
                'consultant_type'   => $user->consultant_type,
                'contract_type'     => $user->contract_type,
                'is_bizify'         => (bool) $user->is_bizify,
                'horas_trabalhadas' => $horasTrabalhadas,
                'valor_hora'        => $hourlyRate,
                'rate_type'         => $rateType,
                'effective_rate'    => $effectiveRate,
                'total_despesas'    => round((float) ($despesasByUser[$user->id] ?? 0), 2),
                'desconto'          => $descontoAjuste,
                'desconto_desc'     => $ajuste->desconto_desc ?? null,
                'adiantamento'      => $adiantamentoAjuste,
                'adicional'         => $adicionalAjuste,
                'adicional_desc'    => $ajuste->adicional_desc ?? null,
                'envio_em'          => $envioMap[$user->id]['envio_em'] ?? null,
                'envio_por'         => $envioMap[$user->id]['envio_por'] ?? null,
                // Notas fiscais só para PJ AVULSO (sem parceiro). Consultor de parceiro PJ
                // não anexa individualmente — quem anexa é o parceiro (via o admin do parceiro).
                'notas'             => ($user->contract_type === 'pj' && $user->partner_id === null)
                    ? (optional($notasMap->get($user->id))->toRowPayload() ?? \App\Models\FechamentoNota::emptyRowPayload())
                    : null,
            ];

            // Proporcionalidade: se data_inicio cai no mês atual
            $startDate = $user->bank_hours_start_date
                ? $user->bank_hours_start_date->format('Y-m-d')
                : null;

            $startIsInMonth = $startDate
                && Carbon::parse($startDate)->year  === $year
                && Carbon::parse($startDate)->month === $month;

            if ($startDate && Carbon::parse($startDate)->format('Y-m') > $yearMonth) {
                $periodDays = 0;
                $ratio      = 0; // começou depois da competência
            } elseif ($startIsInMonth) {
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
                    // Taxa hora extra = hourly_rate ÷ 160
                    $fixedSalary      = $hourlyRate;
                    $valorHoraExtra   = $hourlyRate > 0 ? round($hourlyRate / 160, 4) : 0;
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

        // Recebimento = total (serviços) + despesas − desconto − adiantamento + adicional.
        $addRecebimento = function (array $c): array {
            $c['recebimento'] = round(
                (float) ($c['total'] ?? 0)
                + (float) ($c['total_despesas'] ?? 0)
                - (float) ($c['desconto'] ?? 0)
                - (float) ($c['adiantamento'] ?? 0)
                + (float) ($c['adicional'] ?? 0),
                2
            );
            return $c;
        };
        $horistas   = array_map($addRecebimento, $horistas);
        $bancoHoras = array_map($addRecebimento, $bancoHoras);
        $fixos      = array_map($addRecebimento, $fixos);

        // Separar Bizify: quem for is_bizify=true sai dos cards/totais da ERPSERV e vai para um
        // bloco próprio "bizify", com os MESMOS campos e cálculo (aba Bizify no front).
        $partition = function (array $rows): array {
            $erp = array_values(array_filter($rows, fn ($c) => empty($c['is_bizify'])));
            $biz = array_values(array_filter($rows, fn ($c) => !empty($c['is_bizify'])));
            return [$erp, $biz];
        };
        [$horistasErp, $horistasBiz] = $partition($horistas);
        [$bancoErp, $bancoBiz]       = $partition($bancoHoras);
        [$fixosErp, $fixosBiz]       = $partition($fixos);

        $buildTotais = fn (array $h, array $b, array $f): array => [
            'total_horistas'    => round(collect($h)->sum('total'), 2),
            'total_banco_horas' => round(collect($b)->sum('total'), 2),
            'total_fixos'       => round(collect($f)->sum('total'), 2),
            'total_despesas'    => round(
                collect($h)->sum('total_despesas') +
                collect($b)->sum('total_despesas') +
                collect($f)->sum('total_despesas'),
                2
            ),
            'total_geral'       => round(
                collect($h)->sum('total') +
                collect($b)->sum('total') +
                collect($f)->sum('total'),
                2
            ),
        ];

        return [
            'horistas'    => $horistasErp,
            'banco_horas' => $bancoErp,
            'fixos'       => $fixosErp,
            'totais'      => $buildTotais($horistasErp, $bancoErp, $fixosErp),
            'bizify'      => [
                'horistas'    => $horistasBiz,
                'banco_horas' => $bancoBiz,
                'fixos'       => $fixosBiz,
                'totais'      => $buildTotais($horistasBiz, $bancoBiz, $fixosBiz),
            ],
        ];
    }

    /**
     * Export Excel da lista de consultores do fechamento, com filtro opcional por
     * tipo de contrato (cooperado|clt|pj) via ?contract_type=. Mantém o mesmo cálculo do index.
     */
    public function exportExcel(Request $request, string $yearMonth)
    {
        $data = $this->buildConsultoresData($yearMonth);

        $vinculoLabel = [
            'horista'        => 'Horista',
            'banco_de_horas' => 'Banco de Horas',
            'fixo'           => 'Fixo',
        ];
        $contratoLabel = ['cooperado' => 'Cooperado', 'clt' => 'CLT', 'pj' => 'PJ'];

        $filter = $request->query('contract_type'); // null = todos
        $rows = [];
        foreach (['horistas', 'banco_horas', 'fixos'] as $bucket) {
            foreach ($data[$bucket] as $c) {
                if ($filter && ($c['contract_type'] ?? null) !== $filter) {
                    continue;
                }
                $rows[] = [
                    'consultor'      => $c['nome'],
                    'email'          => $c['email'],
                    'tipo_vinculo'   => $vinculoLabel[$c['consultant_type']] ?? $c['consultant_type'],
                    'tipo_contrato'  => $contratoLabel[$c['contract_type'] ?? ''] ?? '—',
                    'horas'          => $c['horas_a_pagar'] ?? $c['horas_trabalhadas'] ?? 0,
                    'total'          => $c['total'] ?? 0,
                ];
            }
        }
        usort($rows, fn ($a, $b) => strcasecmp($a['consultor'], $b['consultor']));

        $sufixo   = $filter ? '_' . strtoupper($filter) : '';
        $fileName = "Fechamento_Consultores_{$yearMonth}{$sufixo}.xlsx";

        return Excel::download(new FechamentoConsultorListExport($rows), $fileName);
    }

    // ─── Apontamentos ─────────────────────────────────────────────────────────

    public function apontamentos(string $userId, string $yearMonth): JsonResponse
    {
        [$from, $to] = $this->period($yearMonth);
        $apont = $this->buildApontamentosRows($userId, $from, $to);

        return response()->json(['data' => $apont['rows']]);
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

        // Respeita a vigência do valor hora (consultor/coordenador) na competência.
        $histExtra      = UserHourlyRateLog::effectiveValuesAt($user->id, $user, sprintf('%04d-%02d-01', $year, $month));
        $fixedSalary    = (float) ($histExtra['hourly_rate'] ?? $user->hourly_rate ?? 0);
        $valorHoraExtra = $fixedSalary > 0 ? round($fixedSalary / 160, 4) : 0;
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
