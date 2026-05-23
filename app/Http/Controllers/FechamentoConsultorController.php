<?php

namespace App\Http\Controllers;

use App\Exports\FechamentoConsultorExport;
use App\Mail\FechamentoConsultorMail;
use App\Models\FechamentoConsultorEmail;
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
            ? round($hourlyRate / 180, 4)
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
        $totalMins = abs((int) round($h * 60));
        $hrs  = intdiv($totalMins, 60);
        $mins = $totalMins % 60;
        return sprintf('%dh%02d', $hrs, $mins);
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

        if ($startIsInMonth) {
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
                $valorHoraExtra = $hourlyRate > 0 ? round($hourlyRate / 180, 4) : 0;
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
    private function threadRoot(int|string $consultantId, string $yearMonth): ?FechamentoConsultorEmail
    {
        return FechamentoConsultorEmail::where('consultant_user_id', $consultantId)
            ->where('year_month', $yearMonth)
            ->whereNotNull('message_id')
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
    private function generateFechamentoFiles(User $consultant, string $yearMonth): array
    {
        [$from, $to]   = $this->period($yearMonth);
        $periodo       = $this->periodoExtenso($yearMonth);

        $closing       = $this->computeConsultantClosing($consultant, $yearMonth);
        $totalValue    = (float) $closing['total'];
        $apont         = $this->buildApontamentosRows($consultant->id, $from, $to, $consultant);
        $rows          = $apont['rows'];
        $effectiveRate = (float) $apont['effective_rate'];

        $safeName     = $this->sanitizeFilename($consultant->name);
        $pdfFileName  = "Fechamento_{$yearMonth}_{$safeName}.pdf";
        $xlsxFileName = "Fechamento_{$yearMonth}_{$safeName}.xlsx";
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

        // ── PDF ──
        $pdf = Pdf::loadView('pdf.fechamento-consultor', [
            'consultantName' => $consultant->name,
            'periodo'        => $periodo,
            'totalHorasFmt'  => $this->fmtHoras((float) $closing['horas_a_pagar']),
            'taxaLabel'      => $closing['taxa_label'],
            'taxaFmt'        => $this->brl((float) $closing['taxa_value']),
            'valorTotal'     => $this->brl($totalValue),
            'grupos'         => $this->buildPdfGroups($rows),
        ])->setPaper('a4', 'portrait');
        file_put_contents($pdfFullPath, $pdf->output());

        // ── XLSX ──
        $export = new FechamentoConsultorExport($rows, $effectiveRate, $consultant->name, $periodo, $totalValue);
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
    private function defaultMensagem(string $periodo): string
    {
        return "Segue em anexo o fechamento referente ao período de {$periodo}.\n\nEm caso de dúvidas ou divergências, por gentileza entrar em contato.";
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
        ]);

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
        $mensagem     = trim((string) $request->input('mensagem')) ?: $this->defaultMensagem($periodo);

        // Message-ID determinístico DESTA mensagem (sempre persistido) + chave da thread.
        $messageId    = $this->buildMessageId($consultant->id, $yearMonth);
        $threadKey    = $this->threadKey($consultant->id, $yearMonth);

        // Uma única thread por (consultor, período). Se já existe uma raiz canônica,
        // este "envio" é na verdade um REENVIO: threada na raiz (mesmo assunto + In-Reply-To),
        // em vez de virar um e-mail novo. Sem raiz = primeiro envio (cria a thread).
        $root         = $this->threadRoot($consultant->id, $yearMonth);
        $isResend     = $root !== null;

        if ($isResend) {
            // Reenvio: MESMO assunto da raiz (sem "Re:") + In-Reply-To/References da raiz.
            $subject  = $root->subject;
            $inReplyTo = $root->message_id;
        } else {
            // Primeiro envio: assunto gerado, raiz da thread (sem pai).
            $subject  = $request->input('subject')
                ?: 'Fechamento ' . $this->periodoMMAAAA($yearMonth) . ' | Relatório de Horas - ' . $consultant->name;
            $inReplyTo = null;
        }

        // Gera anexos (PDF + XLSX) — reutilizável (mesma geração das continuações).
        $files        = $this->generateFechamentoFiles($consultant, $yearMonth);
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
            );
            Mail::to($consultant->email)->send($mailable);

            $log->fill([
                'pdf_path'          => $files['pdf_rel'],
                'xlsx_path'         => $files['xlsx_rel'],
                'status'            => FechamentoConsultorEmail::STATUS_ENVIADO,
                'provider_response' => null,
                'sent_at'           => now(),
            ])->save();

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
        $closing  = $this->computeConsultantClosing($consultant, $yearMonth);
        $apont    = $this->buildApontamentosRows($consultant->id, $from, $to, $consultant);
        $export   = new FechamentoConsultorExport(
            $apont['rows'], (float) $apont['effective_rate'], $consultant->name, $periodo, (float) $closing['total']
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
        $closing        = $this->computeConsultantClosing($consultant, $yearMonth);
        $mensagemPadrao = $this->defaultMensagem($periodo);
        $mensagem       = trim((string) $request->input('mensagem'));
        $mensagem       = $mensagem !== '' ? $mensagem : $mensagemPadrao;

        $html = view('emails.fechamento.consultor', [
            'consultantName'  => $consultant->name,
            'senderName'      => $sender->name,
            'periodo'         => $periodo,
            'valorTotal'      => $this->brl((float) $closing['total']),
            'financeiroCc'    => (string) (config('mail.financeiro_cc') ?? ''),
            'bodyText'        => null,
            'isContinuation'  => false,
            'withAttachments' => true,
            'mensagem'        => $mensagem,
        ])->render();

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
            );
            Mail::to($consultant->email)->send($mailable);

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
        [$from, $to]     = $this->period($yearMonth);
        [$year, $month]  = array_map('intval', explode('-', $yearMonth));

        $users = User::where('enabled', true)
            ->whereNotIn('type', ['parceiro_admin', 'cliente'])
            ->whereNotNull('consultant_type')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'type', 'consultant_type', 'hourly_rate', 'rate_type', 'daily_hours', 'bank_hours_start_date', 'guaranteed_hours']);

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
