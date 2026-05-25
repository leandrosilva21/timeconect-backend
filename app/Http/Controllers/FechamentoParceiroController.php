<?php

namespace App\Http\Controllers;

use App\Exports\FechamentoParceiroExport;
use App\Mail\FechamentoParceiroMail;
use App\Models\Expense;
use App\Models\FechamentoParceiro;
use App\Models\Partner;
use App\Models\Timesheet;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Maatwebsite\Excel\Facades\Excel;

class FechamentoParceiroController extends Controller
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
        return trim((string) $ascii, '_') ?: 'parceiro';
    }

    /** Mensagem padrão (corpo) do e-mail de fechamento — editável na tela antes de enviar. */
    private function defaultMensagem(string $periodo): string
    {
        return "Segue em anexo o fechamento referente ao período de {$periodo}.\n\nEm caso de dúvidas ou divergências, por gentileza entrar em contato.";
    }

    // ─── Index ────────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $yearMonth = $request->query('year_month');

        $partners = Partner::whereRaw('"active" = true')
            ->orderBy('name')
            ->get(['id', 'name', 'pricing_type', 'hourly_rate']);

        $fechamentos = $yearMonth
            ? FechamentoParceiro::where('year_month', $yearMonth)
                ->with('closedByUser:id,name')
                ->get()
                ->keyBy('partner_id')
            : collect();

        $data = $partners->map(function ($partner) use ($fechamentos) {
            $f = $fechamentos->get($partner->id);
            return [
                'partner_id'     => $partner->id,
                'nome'           => $partner->name,
                'pricing_type'   => $partner->pricing_type,
                'hourly_rate'    => (float) ($partner->hourly_rate ?? 0),
                'status'         => $f?->status ?? 'sem_registro',
                'total_horas'    => (float) ($f?->total_horas ?? 0),
                'total_despesas' => (float) ($f?->total_despesas ?? 0),
                'total_a_pagar'  => (float) ($f?->total_a_pagar ?? 0),
                'closed_at'      => $f?->closed_at?->toISOString(),
                'closed_by_name' => $f?->closedByUser?->name,
            ];
        });

        return response()->json(['data' => $data]);
    }

    // ─── Consultores ─────────────────────────────────────────────────────────

    public function consultores(string $partnerId, string $yearMonth): JsonResponse
    {
        $fechamento = FechamentoParceiro::where('partner_id', $partnerId)
            ->where('year_month', $yearMonth)
            ->first();

        if ($fechamento?->isClosed() && $fechamento->snapshot_consultores) {
            return response()->json(['data' => $fechamento->snapshot_consultores, 'from_snapshot' => true]);
        }

        $partner = Partner::findOrFail($partnerId);
        $data    = $this->consultoresData($partner, $yearMonth);

        return response()->json(['data' => $data, 'from_snapshot' => false]);
    }

    // ─── Despesas ────────────────────────────────────────────────────────────

    public function despesas(string $partnerId, string $yearMonth): JsonResponse
    {
        $fechamento = FechamentoParceiro::where('partner_id', $partnerId)
            ->where('year_month', $yearMonth)
            ->first();

        if ($fechamento?->isClosed() && $fechamento->snapshot_despesas) {
            return response()->json(['data' => $fechamento->snapshot_despesas, 'from_snapshot' => true]);
        }

        $data = $this->despesasData((int) $partnerId, $yearMonth);
        return response()->json(['data' => $data, 'from_snapshot' => false]);
    }

    // ─── Fechar ──────────────────────────────────────────────────────────────

    public function fechar(Request $request, string $partnerId, string $yearMonth): JsonResponse
    {
        $fechamento = FechamentoParceiro::firstOrNew([
            'partner_id' => $partnerId,
            'year_month' => $yearMonth,
        ]);

        if ($fechamento->exists && $fechamento->isClosed()) {
            return response()->json(['message' => 'Fechamento já está encerrado.'], 422);
        }

        $partner     = Partner::findOrFail($partnerId);
        $consultores = $this->consultoresData($partner, $yearMonth);
        $despesas    = $this->despesasData((int) $partnerId, $yearMonth);

        $totalHoras    = round(collect($consultores)->sum('horas'), 2);
        $totalServicos = round(collect($consultores)->sum('total'), 2);
        $totalDespesas = round(collect($despesas)->sum('valor'), 2);

        $fechamento->fill([
            'status'               => 'closed',
            'snapshot_consultores' => $consultores,
            'snapshot_despesas'    => $despesas,
            'total_horas'          => $totalHoras,
            'total_despesas'       => $totalDespesas,
            'total_a_pagar'        => round($totalServicos + $totalDespesas, 2),
            'closed_at'            => now(),
            'closed_by'            => $request->user()->id,
            'notes'                => $request->input('notes'),
        ])->save();

        return response()->json(['message' => "Fechamento do parceiro para {$yearMonth} encerrado.", 'fechamento' => $fechamento]);
    }

    // ─── Reabrir ─────────────────────────────────────────────────────────────

    public function reabrir(Request $request, string $partnerId, string $yearMonth): JsonResponse
    {
        if (!$request->user()->isAdmin()) {
            return response()->json(['message' => 'Sem permissão para reabrir fechamentos.'], 403);
        }

        $fechamento = FechamentoParceiro::where('partner_id', $partnerId)
            ->where('year_month', $yearMonth)
            ->firstOrFail();

        $fechamento->update([
            'status'               => 'open',
            'closed_at'            => null,
            'closed_by'            => null,
            'snapshot_consultores' => null,
            'snapshot_despesas'    => null,
        ]);

        return response()->json(['message' => "Fechamento do parceiro reaberto para {$yearMonth}."]);
    }

    // ─── Helpers privados ────────────────────────────────────────────────────

    private function consultoresData(Partner $partner, string $yearMonth): array
    {
        [$from, $to] = $this->period($yearMonth);

        $users = User::where('partner_id', $partner->id)
            ->where('type', 'parceiro_admin')
            ->where('enabled', true)
            ->get();

        $isFixed      = $partner->pricing_type === Partner::PRICING_FIXED;
        // Valor hora do parceiro vigente na competência (legado intacto ao mudar o valor).
        $partnerRate  = (float) $partner->hourlyRateForCompetencia($yearMonth);

        $rows = [];
        foreach ($users as $user) {
            $minutos = Timesheet::where('user_id', $user->id)
                ->whereBetween('date', [$from, $to])
                ->whereNotIn('status', [Timesheet::STATUS_ADJUSTMENT_REQUESTED, Timesheet::STATUS_REJECTED, Timesheet::STATUS_CONFLICTED, Timesheet::STATUS_INTERNAL])
                ->whereNull('deleted_at')
                ->where('is_internal_action', false)
                ->sum('effort_minutes');

            $horas = round($minutos / 60, 2);

            if ($isFixed) {
                $valorHora = $partnerRate;
            } else {
                $valorHora = $this->effectiveHourlyRate(
                    (float) ($user->hourly_rate ?? 0),
                    $user->rate_type ?? 'hourly'
                );
            }

            $rows[] = [
                'user_id'              => $user->id,
                'nome'                 => $user->name,
                'horas'                => $horas,
                'rate_type'            => $user->rate_type ?? 'hourly',
                'valor_hora'           => $valorHora,
                'pricing_type_parceiro'=> $partner->pricing_type,
                'total'                => round($horas * $valorHora, 2),
            ];
        }

        return $rows;
    }

    private function despesasData(int $partnerId, string $yearMonth): array
    {
        [$from, $to] = $this->period($yearMonth);

        $userIds = User::where('partner_id', $partnerId)
            ->where('type', 'parceiro_admin')
            ->pluck('id');

        if ($userIds->isEmpty()) {
            return [];
        }

        $excludeStatuses = [Expense::STATUS_ADJUSTMENT_REQUESTED, Expense::STATUS_REJECTED];

        return Expense::with([
            'user:id,name',
            'project:id,name,code',
            'category:id,name',
        ])
            ->whereIn('user_id', $userIds)
            ->whereNotIn('status', $excludeStatuses)
            ->where('is_paid', false)
            ->whereBetween('expense_date', [$from, $to])
            ->get()
            ->map(fn ($e) => [
                'id'          => $e->id,
                'data'        => $e->expense_date->format('Y-m-d'),
                'descricao'   => $e->description,
                'categoria'   => $e->category->name ?? '—',
                'colaborador' => $e->user->name ?? '—',
                'projeto'     => $e->project->name ?? '—',
                'valor'       => (float) $e->amount,
                'status'      => $e->status,
            ])
            ->toArray();
    }

    // ─── Apontamentos ─────────────────────────────────────────────────────────

    public function apontamentos(string $partnerId, string $yearMonth): JsonResponse
    {
        return response()->json(['data' => $this->parceiroApontamentosRows($partnerId, $yearMonth)]);
    }

    /**
     * Linhas de apontamento dos consultores do parceiro no mês.
     * Reutilizada pela API (apontamentos) e pela geração de PDF/XLSX no envio de e-mail.
     *
     * @return array<int,array<string,mixed>>
     */
    private function parceiroApontamentosRows(string $partnerId, string $yearMonth): array
    {
        [$from, $to] = $this->period($yearMonth);

        $userIds = User::where('partner_id', $partnerId)
            ->where('type', 'parceiro_admin')
            ->where('enabled', true)
            ->pluck('id');

        if ($userIds->isEmpty()) {
            return [];
        }

        $excludeStatuses = [Timesheet::STATUS_ADJUSTMENT_REQUESTED, Timesheet::STATUS_REJECTED, Timesheet::STATUS_CONFLICTED, Timesheet::STATUS_INTERNAL];

        return Timesheet::with([
            'user:id,name',
            'project:id,name,code,contract_type_id,customer_id',
            'project.contractType:id,name,code',
            'project.customer:id,name',
        ])
            ->select('timesheets.*', 'movidesk_tickets.titulo as ticket_titulo', 'movidesk_tickets.solicitante as ticket_solicitante')
            ->leftJoin('movidesk_tickets', 'movidesk_tickets.ticket_id', '=', 'timesheets.ticket')
            ->whereIn('timesheets.user_id', $userIds)
            ->whereBetween('timesheets.date', [$from, $to])
            ->whereNotIn('timesheets.status', $excludeStatuses)
            ->whereNull('timesheets.deleted_at')
            ->orderBy('timesheets.user_id')
            ->orderBy('timesheets.date')
            ->get()
            ->map(function ($t) {
                $solicitanteRaw = $t->ticket_solicitante;
                if (is_string($solicitanteRaw)) $solicitanteRaw = json_decode($solicitanteRaw, true);
                $solicitante = is_array($solicitanteRaw) ? ($solicitanteRaw['name'] ?? null) : null;

                $ct = $t->project?->contractType;

                return [
                    'id'                   => $t->id,
                    'data'                 => $t->date->format('Y-m-d'),
                    'user_id'              => $t->user_id,
                    'consultor'            => $t->user->name ?? '—',
                    'cliente'              => $t->project->customer->name ?? '—',
                    'projeto'              => $t->project->name ?? '—',
                    'projeto_codigo'       => $t->project->code ?? '—',
                    'tipo_contrato_code'   => $ct?->code ?? 'outros',
                    'tipo_contrato_nome'   => $ct?->name ?? 'Outros',
                    'horas'                => round($t->effort_minutes / 60, 2),
                    'status'               => $t->status,
                    'ticket'               => $t->ticket,
                    'titulo'               => $t->ticket_titulo,
                    'solicitante'          => $solicitante,
                    'observacao'           => $t->observation,
                ];
            })
            ->toArray();
    }

    /**
     * Total a pagar do parceiro no mês: usa o snapshot do fechamento encerrado (total_a_pagar)
     * quando existir; senão calcula ao vivo (sum consultores.total + sum despesas.valor).
     */
    private function parceiroTotals(Partner $partner, string $yearMonth): float
    {
        $fechamento = FechamentoParceiro::where('partner_id', $partner->id)
            ->where('year_month', $yearMonth)
            ->first();

        if ($fechamento?->isClosed()) {
            return (float) $fechamento->total_a_pagar;
        }

        $consultores   = $this->consultoresData($partner, $yearMonth);
        $despesas      = $this->despesasData((int) $partner->id, $yearMonth);
        $totalServicos = round(collect($consultores)->sum('total'), 2);
        $totalDespesas = round(collect($despesas)->sum('valor'), 2);

        return round($totalServicos + $totalDespesas, 2);
    }

    /** E-mails dos parceiro_admin habilitados do parceiro. */
    private function parceiroAdminEmails(string $partnerId): array
    {
        // "Sempre recebe" = só o(s) ADMIN do parceiro (perfil "Parceiro ADM" = is_executive),
        // não todos os consultores vinculados (que também são type=parceiro_admin).
        return User::where('partner_id', $partnerId)
            ->where('type', 'parceiro_admin')
            ->where('is_executive', true)
            ->where('enabled', true)
            ->whereNotNull('email')
            ->pluck('email')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** Agrupa as linhas de apontamento por consultor, para o PDF. */
    private function buildPdfGroups(array $rows): array
    {
        $byConsultor = [];
        foreach ($rows as $r) {
            $byConsultor[$r['consultor'] ?? '—'][] = $r;
        }

        $grupos = [];
        foreach ($byConsultor as $consultor => $items) {
            $horas  = 0.0;
            $linhas = [];
            foreach ($items as $l) {
                $horas += (float) ($l['horas'] ?? 0);
                $linhas[] = [
                    'data'      => isset($l['data']) ? Carbon::parse($l['data'])->format('d/m/Y') : '',
                    'projeto'   => $l['projeto'] ?? '—',
                    'ticket'    => $l['ticket'] ?? '',
                    'horas_fmt' => $this->fmtHoras((float) ($l['horas'] ?? 0)),
                ];
            }
            $grupos[] = [
                'consultor' => $consultor,
                'linhas'    => $linhas,
                'horas_fmt' => $this->fmtHoras($horas),
            ];
        }

        return $grupos;
    }

    /**
     * Gera (PDF + XLSX) do fechamento do parceiro e grava em storage/app/fechamentos.
     *
     * @return array{
     *   pdf_rel:string, xlsx_rel:string, pdf_full:string, xlsx_full:string,
     *   pdf_name:string, xlsx_name:string, total_value:float
     * }
     */
    private function generateParceiroFiles(Partner $partner, string $yearMonth): array
    {
        $periodo    = $this->periodoExtenso($yearMonth);
        $rows       = $this->parceiroApontamentosRows((string) $partner->id, $yearMonth);
        $totalValue = $this->parceiroTotals($partner, $yearMonth);
        $totalHoras = round(collect($rows)->sum('horas'), 2);

        $safeName     = $this->sanitizeFilename($partner->name);
        $pdfFileName  = "Fechamento_{$yearMonth}_{$safeName}.pdf";
        $xlsxFileName = "Fechamento_{$yearMonth}_{$safeName}.xlsx";
        $dir          = 'fechamentos';
        $pdfRelPath   = "{$dir}/{$pdfFileName}";
        $xlsxRelPath  = "{$dir}/{$xlsxFileName}";
        $pdfFullPath  = storage_path("app/{$pdfRelPath}");
        $xlsxFullPath = storage_path("app/{$xlsxRelPath}");

        // Cria a pasta REAL onde os arquivos são gravados/anexados (storage/app/fechamentos).
        $dirFull = storage_path("app/{$dir}");
        if (!is_dir($dirFull)) {
            mkdir($dirFull, 0775, true);
        }

        // ── PDF ──
        $pdf = Pdf::loadView('pdf.fechamento-parceiro', [
            'parceiroName'  => $partner->name,
            'periodo'       => $periodo,
            'totalHorasFmt' => $this->fmtHoras($totalHoras),
            'valorTotal'    => $this->brl($totalValue),
            'grupos'        => $this->buildPdfGroups($rows),
        ])->setPaper('a4', 'portrait');
        file_put_contents($pdfFullPath, $pdf->output());

        // ── XLSX ──
        $export = new FechamentoParceiroExport($rows, $partner->name, $periodo, $totalValue);
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

    // ─── Prévia do e-mail (template real) com a mensagem editável ───────────────
    // Renderiza o MESMO template do envio, pra mostrar na tela e atualizar ao vivo
    // conforme o admin edita a mensagem. Retorna o HTML + a mensagem padrão (1ª carga).
    public function emailPreview(Request $request, string $partnerId, string $yearMonth): JsonResponse
    {
        $sender = $request->user();
        if (!$sender || !($sender->isAdmin() || $sender->isAdministrativo())) {
            return response()->json(['success' => false, 'message' => 'Sem permissão.'], 403);
        }
        $partner = Partner::find($partnerId);
        if (!$partner) {
            return response()->json(['success' => false, 'message' => 'Parceiro não encontrado.'], 404);
        }

        $periodo        = $this->periodoExtenso($yearMonth);
        $mensagemPadrao = $this->defaultMensagem($periodo);
        $mensagem       = trim((string) $request->input('mensagem'));
        $mensagem       = $mensagem !== '' ? $mensagem : $mensagemPadrao;

        $html = view('emails.fechamento.parceiro', [
            'parceiroName'    => $partner->name,
            'senderName'      => $sender->name,
            'periodo'         => $periodo,
            'valorTotal'      => $this->brl($this->parceiroTotals($partner, $yearMonth)),
            'withAttachments' => true,
            'mensagem'        => $mensagem,
        ])->render();

        // Prévia só: força o logo claro (escuro-colorido) a aparecer no card branco —
        // o swap de dark-mode do template trocaria pro logo branco (invisível aqui).
        $override = '<style>.erp-light{display:inline-block !important}.erp-dark{display:none !important}</style>';
        $html = str_ireplace('</head>', $override . '</head>', $html);

        return response()->json([
            'html'                  => $html,
            'mensagem_padrao'       => $mensagemPadrao,
            'parceiro_admin_emails' => $this->parceiroAdminEmails($partnerId),
            'fechamento_email'      => $partner->fechamento_email,
        ]);
    }

    // ─── Enviar fechamento por e-mail ───────────────────────────────────────────
    // Envia o fechamento do parceiro por e-mail, com detalhamento em anexos (PDF + XLSX).
    // De = conta autenticada (mail.from) com o NOME do usuário logado (sem Send As).
    // Reply-To = quem enviou + financeiro; CC = financeiro (+ admins quando o To é custom).
    public function enviarEmail(Request $request, string $partnerId, string $yearMonth): JsonResponse
    {
        $sender = $request->user();
        if (!$sender || !($sender->isAdmin() || $sender->isAdministrativo())) {
            return response()->json(['success' => false, 'message' => 'Sem permissão para enviar o fechamento.'], 403);
        }
        if (!$sender->email) {
            return response()->json(['success' => false, 'message' => 'Seu usuário não tem e-mail cadastrado para usar como remetente.'], 422);
        }

        $request->validate([
            'mensagem' => 'nullable|string', // corpo editável; vazio = mensagem padrão
            'emails'   => 'nullable|array',
            'emails.*' => 'email',
        ]);

        $partner = Partner::find($partnerId);
        if (!$partner) {
            return response()->json(['success' => false, 'message' => 'Parceiro não encontrado.'], 404);
        }

        $periodo      = $this->periodoExtenso($yearMonth);
        $financeiroCc = (string) (config('mail.financeiro_cc') ?? '');
        $mensagem     = trim((string) $request->input('mensagem')) ?: $this->defaultMensagem($periodo);

        // Destinatários: e-mails informados na tela (To) ou, se vazios, os parceiro_admin.
        $customEmails = $request->input('emails') ?: [];
        $to = !empty($customEmails) ? $customEmails : $this->parceiroAdminEmails($partnerId);

        // CC: quando o To é custom, os parceiro_admin entram em cópia; + financeiro. Único, não-vazio.
        $cc = array_values(array_unique(array_filter(array_merge(
            !empty($customEmails) ? $this->parceiroAdminEmails($partnerId) : [],
            [$financeiroCc]
        ))));

        // Não duplica em CC o que já está em To.
        $cc = array_values(array_diff($cc, $to));

        if (empty($to)) {
            return response()->json([
                'success' => false,
                'message' => 'Nenhum destinatário: informe e-mails ou cadastre administradores do parceiro.',
            ], 422);
        }

        $subject = 'Fechamento ' . $this->periodoMMAAAA($yearMonth) . ' | Relatório de Horas - ' . $partner->name;

        $files      = $this->generateParceiroFiles($partner, $yearMonth);
        $totalValue = $files['total_value'];

        // Envia COMO o remetente (App Password O365) quando configurado; senão, remetente padrão.
        $mc = \App\Services\SenderMailer::for(
            $sender,
            'smtp',
            (string) config('mail.from.address'),
            config('mail.fechamento_from_name', 'Fechamento ERPSERV'),
        );

        try {
            $mailable = new FechamentoParceiroMail(
                parceiroName:    $partner->name,
                senderName:      $sender->name,
                periodo:         $periodo,
                valorTotal:      $this->brl($totalValue),
                subjectLine:     $subject,
                pdfPath:         $files['pdf_full'],
                xlsxPath:        $files['xlsx_full'],
                pdfFileName:     $files['pdf_name'],
                xlsxFileName:    $files['xlsx_name'],
                senderEmail:     $sender->email,
                financeiroCc:    $financeiroCc ?: null,
                mensagem:        $mensagem,
                withAttachments: true,
                fromAddress:     $mc['from_address'],
                fromName:        $mc['from_name'],
            );
            Mail::mailer($mc['mailer'])->to($to)->cc($cc)->send($mailable);

            Log::info('Fechamento de parceiro enviado por e-mail', [
                'parceiro' => $partner->id, 'remetente' => $sender->id,
                'to' => $to, 'cc' => $cc, 'total' => $totalValue,
            ]);
        } catch (\Throwable $e) {
            Log::error('Falha ao enviar fechamento de parceiro por e-mail', [
                'parceiro' => $partner->id, 'remetente' => $sender->id, 'erro' => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'message' => 'Falha ao enviar o e-mail: ' . $e->getMessage()], 500);
        }

        $toLabel = implode(', ', $to);
        return response()->json([
            'success' => true,
            'message' => "Fechamento enviado para {$toLabel}" . (!empty($cc) ? ' (cópia: ' . implode(', ', $cc) . ')' : '') . '.',
        ]);
    }

    // ─── Download do Excel (XLSX) do fechamento ─────────────────────────────────
    // Mesmo XLSX que vai como anexo no e-mail, baixável direto pela tela do relatório.
    public function excel(Request $request, string $partnerId, string $yearMonth)
    {
        $sender = $request->user();
        if (!$sender || !($sender->isAdmin() || $sender->isAdministrativo())) {
            return response()->json(['success' => false, 'message' => 'Sem permissão.'], 403);
        }
        $partner = Partner::find($partnerId);
        if (!$partner) {
            return response()->json(['success' => false, 'message' => 'Parceiro não encontrado.'], 404);
        }

        $periodo    = $this->periodoExtenso($yearMonth);
        $rows       = $this->parceiroApontamentosRows((string) $partner->id, $yearMonth);
        $totalValue = $this->parceiroTotals($partner, $yearMonth);
        $export     = new FechamentoParceiroExport($rows, $partner->name, $periodo, $totalValue);
        $fileName   = "Fechamento_{$yearMonth}_" . $this->sanitizeFilename($partner->name) . ".xlsx";

        return Excel::download($export, $fileName);
    }

    // ─── Salvar e-mail de fechamento do parceiro ────────────────────────────────
    // Persiste o(s) destinatário(s) padrão (separados por vírgula) do fechamento.
    public function saveFechamentoEmail(Request $request, string $partnerId): JsonResponse
    {
        $sender = $request->user();
        if (!$sender || !($sender->isAdmin() || $sender->isAdministrativo())) {
            return response()->json(['success' => false, 'message' => 'Sem permissão.'], 403);
        }
        $partner = Partner::find($partnerId);
        if (!$partner) {
            return response()->json(['success' => false, 'message' => 'Parceiro não encontrado.'], 404);
        }

        $request->validate([
            'fechamento_email' => 'nullable|string',
        ]);

        $partner->update(['fechamento_email' => $request->input('fechamento_email')]);

        return response()->json([
            'success'          => true,
            'fechamento_email' => $partner->fechamento_email,
        ]);
    }
}
