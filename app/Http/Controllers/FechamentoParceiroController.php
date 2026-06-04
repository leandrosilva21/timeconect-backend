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
        return trim((string) $ascii, '_') ?: 'parceiro';
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

    // ─── Index ────────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $yearMonth = $request->query('year_month');

        $partners = Partner::whereRaw('"active" = true')
            ->orderBy('name')
            ->get(['id', 'name', 'pricing_type', 'hourly_rate', 'contract_type']);

        $fechamentos = $yearMonth
            ? FechamentoParceiro::where('year_month', $yearMonth)
                ->with('closedByUser:id,name')
                ->get()
                ->keyBy('partner_id')
            : collect();

        $envioMap = \App\Models\FechamentoSendStatus::mapFor(
            \App\Models\FechamentoSendStatus::TIPO_PARCEIRO, $yearMonth, $partners->pluck('id')->all(),
        );

        // Notas fiscais PJ (NFS-e + Nota de débito) por parceiro no mês.
        $notasMap = $yearMonth
            ? \App\Models\FechamentoNota::where('notable_type', Partner::class)
                ->where('year_month', $yearMonth)
                ->whereIn('notable_id', $partners->pluck('id'))
                ->get()->keyBy('notable_id')
            : collect();

        // Ajustes manuais (desconto/adiantamento/adicional) por parceiro no mês.
        $ajustesMap = $yearMonth
            ? \App\Models\FechamentoParceiroAjuste::where('year_month', $yearMonth)
                ->get()->keyBy('partner_id')
            : collect();

        $data = $partners->map(function ($partner) use ($fechamentos, $envioMap, $notasMap, $ajustesMap, $yearMonth) {
            $f = $fechamentos->get($partner->id);

            $ajuste       = $ajustesMap->get($partner->id);
            $desconto     = round((float) ($ajuste->desconto ?? 0), 2);
            $adiantamento = round((float) ($ajuste->adiantamento ?? 0), 2);
            $adicional    = round((float) ($ajuste->adicional ?? 0), 2);

            // Quebra serviços × despesas. O pagamento de SERVIÇOS (mão de obra) é sem despesas —
            // usado no Relatório de Pagamentos, onde despesas (reembolso) não entram no valor.
            $servicos = 0.0; $despesasReais = 0.0;
            if ($yearMonth) {
                if ($f?->isClosed()) {
                    $servicos      = round(collect($f->snapshot_consultores ?? [])->sum('total'), 2);
                    $despesasReais = round((float) ($f->total_despesas ?? 0), 2);
                } else {
                    $servicos      = round(collect($this->consultoresData($partner, $yearMonth))->sum('total'), 2);
                    $despesasReais = round(collect($this->despesasData((int) $partner->id, $yearMonth))->where('is_paid', false)->sum('valor'), 2);
                }
            }

            // Total a pagar = base (serviços + despesas), SEM ajustes. Recebimento = base − desconto − adiantamento + adicional.
            // No snapshot fechado o total_a_pagar gravado JÁ inclui os ajustes (= recebimento); reconstrói a base p/ exibição.
            if ($f?->isClosed()) {
                $recebimento = round((float) ($f->total_a_pagar ?? 0), 2);
                $totalAPagar = round($recebimento + $desconto + $adiantamento - $adicional, 2);
            } else {
                $totalAPagar = round($servicos + $despesasReais, 2);
                $recebimento = round($totalAPagar - $desconto - $adiantamento + $adicional, 2);
            }
            $recebimentoSemDespesas = round($recebimento - $despesasReais, 2);

            return [
                'partner_id'     => $partner->id,
                'nome'           => $partner->name,
                'contract_type'  => $partner->contract_type,
                'notas'          => $partner->contract_type === 'pj'
                    ? (optional($notasMap->get($partner->id))->toRowPayload() ?? \App\Models\FechamentoNota::emptyRowPayload())
                    : null,
                'pricing_type'   => $partner->pricing_type,
                'hourly_rate'    => (float) ($partner->hourly_rate ?? 0),
                'status'         => $f?->status ?? 'sem_registro',
                'total_horas'    => (float) ($f?->total_horas ?? 0),
                'total_despesas' => (float) ($f?->total_despesas ?? 0),
                'total_servicos' => $servicos,
                'total_a_pagar'  => round($totalAPagar, 2),
                'closed_at'      => $f?->closed_at?->toISOString(),
                'closed_by_name' => $f?->closedByUser?->name,
                'envio_em'       => $envioMap[$partner->id]['envio_em'] ?? null,
                'envio_por'      => $envioMap[$partner->id]['envio_por'] ?? null,
                // Ajustes do recebimento.
                'desconto'       => $desconto,
                'desconto_desc'  => $ajuste->desconto_desc ?? null,
                'adiantamento'   => $adiantamento,
                'adicional'      => $adicional,
                'adicional_desc' => $ajuste->adicional_desc ?? null,
                'recebimento'    => $recebimento,
                'recebimento_sem_despesas' => $recebimentoSemDespesas,
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

        // Ajustes manuais do recebimento entram no total a pagar gravado no snapshot.
        $ajuste        = \App\Models\FechamentoParceiroAjuste::where('partner_id', $partnerId)
            ->where('year_month', $yearMonth)->first();
        $desconto      = round((float) ($ajuste->desconto ?? 0), 2);
        $adiantamento  = round((float) ($ajuste->adiantamento ?? 0), 2);
        $adicional     = round((float) ($ajuste->adicional ?? 0), 2);

        $fechamento->fill([
            'status'               => 'closed',
            'snapshot_consultores' => $consultores,
            'snapshot_despesas'    => $despesas,
            'total_horas'          => $totalHoras,
            'total_despesas'       => $totalDespesas,
            'total_a_pagar'        => round($totalServicos + $totalDespesas - $desconto - $adiantamento + $adicional, 2),
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

    public function consultoresData(Partner $partner, string $yearMonth): array
    {
        [$from, $to] = $this->period($yearMonth);

        $users = User::where('partner_id', $partner->id)            ->where('enabled', true)
            ->get();

        $isFixed      = $partner->pricing_type === Partner::PRICING_FIXED;
        // Valor hora do parceiro vigente na competência (legado intacto ao mudar o valor).
        $partnerRate  = (float) $partner->hourlyRateForCompetencia($yearMonth);

        $rows = [];
        foreach ($users as $user) {
            $minutos = Timesheet::where('user_id', $user->id)
                ->whereBetween('date', [$from, $to])
                ->whereNotIn('status', [Timesheet::STATUS_ADJUSTMENT_REQUESTED, Timesheet::STATUS_REJECTED, Timesheet::STATUS_CONFLICTED, Timesheet::STATUS_INTERNAL, Timesheet::STATUS_LATE])
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

            // Parceiro ADM (is_executive) também fatura as horas dele quando tem
            // apontamento — pago igual à equipe do parceiro (regra 2026-06-02).
            $isParceiroAdm = (bool) $user->is_executive;

            $rows[] = [
                'user_id'              => $user->id,
                'nome'                 => $user->name,
                'horas'                => $horas,
                'rate_type'            => $user->rate_type ?? 'hourly',
                'valor_hora'           => $valorHora,
                'pricing_type_parceiro'=> $partner->pricing_type,
                'is_parceiro_adm'      => $isParceiroAdm,
                'total'                => round($horas * $valorHora, 2),
            ];
        }

        return $rows;
    }

    public function despesasData(int $partnerId, string $yearMonth): array
    {
        [$from, $to] = $this->period($yearMonth);

        $userIds = User::where('partner_id', $partnerId)            ->pluck('id');

        if ($userIds->isEmpty()) {
            return [];
        }

        $excludeStatuses = [Expense::STATUS_ADJUSTMENT_REQUESTED, Expense::STATUS_REJECTED];

        // Inclui pagas e não-pagas. Para o parceiro, is_paid=true significa "paga
        // antecipadamente" (fora do fechamento, via Pagamento de Despesas) — aparece
        // no relatório com indicador, mas NÃO entra no total a pagar do fechamento.
        return Expense::with([
            'user:id,name',
            'project:id,name,code,customer_id',
            'project.customer:id,name',
            'category:id,name',
            'paidByUser:id,name',
        ])
            ->whereIn('user_id', $userIds)
            ->whereNotIn('status', $excludeStatuses)
            ->whereBetween('expense_date', [$from, $to])
            ->get()
            ->map(fn ($e) => [
                'id'           => $e->id,
                'data'         => $e->expense_date->format('Y-m-d'),
                'descricao'    => $e->description,
                'categoria'    => $e->category->name ?? '—',
                'colaborador'  => $e->user->name ?? '—',
                'cliente'      => $e->project?->customer?->name ?? '—',
                'projeto'      => $e->project->name ?? '—',
                'valor'        => (float) $e->amount,
                'status'       => $e->status,
                'is_paid'      => (bool) $e->is_paid,           // true = paga antecipadamente (fora do fechamento)
                'paid_at'      => $e->paid_at?->toISOString(),
                'paid_by_name' => $e->paidByUser->name ?? null,
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

        $userIds = User::where('partner_id', $partnerId)            ->where('enabled', true)
            ->pluck('id');

        if ($userIds->isEmpty()) {
            return [];
        }

        $excludeStatuses = [Timesheet::STATUS_ADJUSTMENT_REQUESTED, Timesheet::STATUS_REJECTED, Timesheet::STATUS_CONFLICTED, Timesheet::STATUS_INTERNAL, Timesheet::STATUS_LATE];

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
        // Antecipadas (is_paid=true) já foram pagas fora do fechamento → fora do total.
        $totalDespesas = round(collect($despesas)->where('is_paid', false)->sum('valor'), 2);

        return round($totalServicos + $totalDespesas, 2);
    }

    /** E-mails dos parceiro_admin habilitados do parceiro. */
    private function parceiroAdminEmails(string $partnerId): array
    {
        // "Sempre recebe" = só o(s) ADMIN do parceiro (perfil "Parceiro ADM" = is_executive),
        // não todos os consultores vinculados (que também são type=parceiro_admin).
        return User::where('partner_id', $partnerId)            ->where('is_executive', true)
            ->where('enabled', true)
            ->whereNotNull('email')
            ->pluck('email')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** Agrupa as linhas de apontamento por consultor, para o PDF. */
    private function buildPdfGroups(array $rows, array $calcByUser = []): array
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
            // Taxa/hora e total do consultor (do consultoresData) — exibidos no cabeçalho do grupo.
            // Strings montadas em PHP (sem @if no Blade) — evita erro de parse na view.
            $uid       = $items[0]['user_id'] ?? null;
            $calc      = ($uid !== null && isset($calcByUser[$uid])) ? $calcByUser[$uid] : null;
            $valorHora = $calc ? (float) ($calc['valor_hora'] ?? 0) : 0.0;
            $totalCons = $calc ? (float) ($calc['total'] ?? 0) : round($horas * $valorHora, 2);
            $horasFmt  = $this->fmtHoras($horas);
            $vhFmt     = $valorHora > 0 ? $this->brl($valorHora) : null;
            $totFmt    = $this->brl($totalCons);
            $grupos[] = [
                'consultor'      => $consultor,
                'linhas'         => $linhas,
                'horas_fmt'      => $horasFmt,
                'valor_hora_fmt' => $vhFmt,
                'total_fmt'      => $totFmt,
                'header_line'    => $consultor . ' — ' . $horasFmt . ($vhFmt ? ' · Taxa ' . $vhFmt . '/h' : '') . ' · ' . $totFmt,
                'subtotal_line'  => 'Subtotal ' . $consultor . ': ' . $horasFmt . ($vhFmt ? ' × ' . $vhFmt : '') . ' = ' . $totFmt,
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
    /**
     * View-data ÚNICA do relatório do parceiro — usada PELA TELA (endpoint reportHtml)
     * E pelo PDF/e-mail (generateParceiroFiles), garantindo que sejam IDÊNTICOS.
     */
    private function buildParceiroReportView(Partner $partner, string $yearMonth, string $mode = 'ambos'): array
    {
        $periodo    = $this->periodoExtenso($yearMonth);
        $rows       = $this->parceiroApontamentosRows((string) $partner->id, $yearMonth);
        $totalAll   = $this->parceiroTotals($partner, $yearMonth);
        $totalHoras = round(collect($rows)->sum('horas'), 2);

        $soDespesa = $mode === 'despesa';
        $soServico = $mode === 'servicos';

        $despesasAll     = $this->despesasData((int) $partner->id, $yearMonth);
        $despesas        = array_values(array_filter($despesasAll, fn ($d) => !$d['is_paid']));
        $despesasAntecip = array_values(array_filter($despesasAll, fn ($d) => $d['is_paid']));
        $totalDespesas   = round(collect($despesas)->sum('valor'), 2);
        $totalServicos   = round($totalAll - $totalDespesas, 2);
        $totalValue      = $soDespesa ? $totalDespesas : ($soServico ? $totalServicos : $totalAll);

        // Taxa/hora por consultor (consultoresData) — alimenta o cabeçalho de cada grupo
        // e o card "Taxa/Hora" do resumo (quando o parceiro tem uma única taxa).
        $calc      = collect($this->consultoresData($partner, $yearMonth))->keyBy('user_id')->all();
        $ratesUnis = collect($calc)->pluck('valor_hora')->filter(fn ($v) => (float) $v > 0)->map(fn ($v) => round((float) $v, 4))->unique()->values();
        $taxaHoraFmt = $ratesUnis->count() === 1 ? $this->brl((float) $ratesUnis[0]) : ($ratesUnis->count() > 1 ? 'por consultor' : '—');

        // Logo ERPSERV embutido (base64) — mesmo modelo do relatório de consultor/cliente.
        $logoFile    = public_path('logo-erpserv.png');
        $logoDataUri = is_file($logoFile) ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($logoFile)) : '';

        return [
            'parceiroName'     => $partner->name,
            'periodo'          => $periodo,
            'logoDataUri'      => $logoDataUri,
            'mode'             => $mode,
            'totalHorasFmt'    => $this->fmtHoras($totalHoras),
            'taxaHoraFmt'      => $taxaHoraFmt,
            'valorTotal'       => $this->brl($totalValue),
            'grupos'           => $soDespesa ? [] : $this->buildPdfGroups($rows, $calc),
            'despesas'         => $soServico ? [] : $despesas,
            'despesasAntecip'  => $soServico ? [] : $despesasAntecip,
            'totalServicosFmt' => $this->brl($totalServicos),
            'totalDespesasFmt' => $this->brl($totalDespesas),
            'brl'              => fn ($v) => $this->brl((float) $v),
        ];
    }

    /** GET /fechamento-parceiro/{partnerId}/{yearMonth}/report-html?mode= — HTML do relatório (mesma fonte do PDF). */
    public function reportHtml(Request $request, string $partnerId, string $yearMonth): JsonResponse
    {
        $partner = Partner::findOrFail($partnerId);
        $mode    = $request->query('mode', 'ambos');
        $html    = view('pdf.fechamento-parceiro', $this->buildParceiroReportView($partner, $yearMonth, $mode))->render();
        return response()->json(['html' => $html]);
    }

    private function generateParceiroFiles(Partner $partner, string $yearMonth, string $mode = 'ambos'): array
    {
        $periodo    = $this->periodoExtenso($yearMonth);
        $rows       = $this->parceiroApontamentosRows((string) $partner->id, $yearMonth);
        $totalAll   = $this->parceiroTotals($partner, $yearMonth); // serviços + despesas
        $totalHoras = round(collect($rows)->sum('horas'), 2);

        $soDespesa = $mode === 'despesa';
        $soServico = $mode === 'servicos';
        $prefix    = $soDespesa ? 'Despesas' : 'Fechamento';
        $safeName     = $this->sanitizeFilename($partner->name);
        $pdfFileName  = "{$prefix}_{$yearMonth}_{$safeName}.pdf";
        $xlsxFileName = "{$prefix}_{$yearMonth}_{$safeName}.xlsx";
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

        // ── Despesas (pagas junto no fechamento). Antecipadas (is_paid) aparecem
        //    com indicador mas NÃO entram no total. ──
        $despesasAll       = $this->despesasData((int) $partner->id, $yearMonth);
        $despesas          = array_values(array_filter($despesasAll, fn ($d) => !$d['is_paid']));
        $despesasAntecip   = array_values(array_filter($despesasAll, fn ($d) => $d['is_paid']));
        $totalDespesas     = round(collect($despesas)->sum('valor'), 2);
        $totalServicos     = round($totalAll - $totalDespesas, 2);
        $totalValue        = $soDespesa ? $totalDespesas : ($soServico ? $totalServicos : $totalAll);

        // ── PDF ── (MESMA fonte/Blade do relatório da tela → tela = e-mail idênticos)
        $pdf = Pdf::loadView('pdf.fechamento-parceiro', $this->buildParceiroReportView($partner, $yearMonth, $mode))
            ->setPaper('a4', 'portrait')->setOption(['defaultMediaType' => 'print']);
        file_put_contents($pdfFullPath, $pdf->output());

        // ── XLSX ──
        $export = $soDespesa
            ? new \App\Exports\FechamentoConsultorDespesaExport($despesasAll, $partner->name, $periodo)
            : new FechamentoParceiroExport($rows, $partner->name, $periodo, $totalValue);
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
        $mode           = $request->input('mode', 'ambos');
        $totalAll       = $this->parceiroTotals($partner, $yearMonth); // serviços + despesas
        $totalDesp      = round(collect($this->despesasData((int) $partner->id, $yearMonth))->where('is_paid', false)->sum('valor'), 2);
        $valorPreview   = $mode === 'despesa' ? $totalDesp : ($mode === 'servicos' ? $totalAll - $totalDesp : $totalAll);
        $mensagemPadrao = $this->defaultMensagem($periodo, $yearMonth, $mode);
        $mensagem       = trim((string) $request->input('mensagem'));
        $mensagem       = $mensagem !== '' ? $mensagem : $mensagemPadrao;

        $html = view('emails.fechamento.parceiro', [
            'parceiroName'    => $partner->name,
            'senderName'      => $sender->name,
            'periodo'         => $periodo,
            'mode'            => $mode,
            'valorTotal'      => $this->brl($valorPreview),
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
            'mode'     => 'nullable|string|in:servicos,despesa,ambos',
        ]);
        $mode = $request->input('mode', 'ambos');

        $partner = Partner::find($partnerId);
        if (!$partner) {
            return response()->json(['success' => false, 'message' => 'Parceiro não encontrado.'], 404);
        }

        $periodo      = $this->periodoExtenso($yearMonth);
        $financeiroCc = (string) (config('mail.financeiro_cc') ?? '');
        $mensagem     = trim((string) $request->input('mensagem')) ?: $this->defaultMensagem($periodo, $yearMonth, $mode);

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

        $subject = $mode === 'despesa'
            ? 'Despesas ' . $this->periodoMMAAAA($yearMonth) . ' | Reembolso - ' . $partner->name
            : 'Fechamento ' . $this->periodoMMAAAA($yearMonth) . ' | Relatório de Horas - ' . $partner->name;

        $files      = $this->generateParceiroFiles($partner, $yearMonth, $mode);
        $totalValue = $files['total_value'];

        // Modelo de e-mail do cadastro (por tipo de contrato do parceiro). Só quando
        // NÃO houve corpo manual; cai no default acima se não houver modelo ativo.
        if (trim((string) $request->input('mensagem')) === '') {
            $svc  = app(\App\Services\FechamentoEmailTemplateService::class);
            $vars = ['nome' => $partner->name, 'periodo' => $periodo, 'valor' => $this->brl($totalValue)];
            if ($partner->contract_type === 'pj') {
                $vars['data_nota'] = $svc->dataEnvioNotaPj($yearMonth);
            }
            $tpl = $svc->resolve('parceiro', $partner->contract_type, $vars);
            if ($tpl) {
                $mensagem = $tpl['body'];
                if ($tpl['subject'] !== '') $subject = $tpl['subject'];
            }
        }

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
                mode:            $mode,
            );

            // Microsoft Graph (Send As do remetente) quando configurado; senão, SMTP atual.
            if (\App\Services\GraphMailer::enabled() && filled($sender->email)) {
                \App\Services\GraphMailer::sendAs(
                    $sender->email,
                    $to,
                    $cc,
                    $subject,
                    $mailable->render(),
                    [$files['pdf_full'], $files['xlsx_full']],
                );
            } else {
                Mail::mailer($mc['mailer'])->to($to)->cc($cc)->send($mailable);
            }

            \App\Models\FechamentoSendStatus::marcarEnviado(
                \App\Models\FechamentoSendStatus::TIPO_PARCEIRO, (int) $partner->id, $yearMonth, (int) $sender->id,
            );

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

    // ─── Limpar status de envio ─────────────────────────────────────────────────
    public function limparEnvio(Request $request, string $partnerId, string $yearMonth): JsonResponse
    {
        $sender = $request->user();
        if (!$sender || !($sender->isAdmin() || $sender->isAdministrativo())) {
            return response()->json(['success' => false, 'message' => 'Sem permissão.'], 403);
        }

        \App\Models\FechamentoSendStatus::limpar(
            \App\Models\FechamentoSendStatus::TIPO_PARCEIRO, (int) $partnerId, $yearMonth,
        );

        return response()->json(['success' => true, 'message' => 'Status de envio limpo.']);
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
        $mode       = $request->query('mode', 'ambos');

        // Relatório SÓ de despesas → planilha de despesas.
        if ($mode === 'despesa') {
            $export   = new \App\Exports\FechamentoConsultorDespesaExport($this->despesasData((int) $partner->id, $yearMonth), $partner->name, $periodo);
            $fileName = "Despesas_{$yearMonth}_" . $this->sanitizeFilename($partner->name) . ".xlsx";
            return Excel::download($export, $fileName);
        }

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

    // ─── Ajustes do recebimento ──────────────────────────────────────────────────
    /**
     * POST /fechamento-parceiro/{partnerId}/{yearMonth}/ajustes
     * Salva (upsert) os ajustes manuais de um parceiro no mês:
     * desconto / adiantamento / adicional (+ descritivos de desconto e adicional).
     * Recebimento = serviços + despesas − desconto − adiantamento + adicional.
     */
    public function salvarAjustes(Request $request, string $partnerId, string $yearMonth): JsonResponse
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

        $partner = Partner::find($partnerId);
        if (!$partner) {
            return response()->json(['success' => false, 'message' => 'Parceiro não encontrado.'], 404);
        }

        $ajuste = \App\Models\FechamentoParceiroAjuste::updateOrCreate(
            ['partner_id' => (int) $partnerId, 'year_month' => $yearMonth],
            [
                'desconto'       => round((float) ($data['desconto'] ?? 0), 2),
                'desconto_desc'  => $data['desconto_desc'] ?? null,
                'adiantamento'   => round((float) ($data['adiantamento'] ?? 0), 2),
                'adicional'      => round((float) ($data['adicional'] ?? 0), 2),
                'adicional_desc' => $data['adicional_desc'] ?? null,
            ]
        );

        // Recalcula o recebimento (serviços + despesas − desconto − adiantamento + adicional).
        $totalAPagar = $this->parceiroTotals($partner, $yearMonth);
        $recebimento = round(
            $totalAPagar
            - (float) $ajuste->desconto
            - (float) $ajuste->adiantamento
            + (float) $ajuste->adicional,
            2
        );

        return response()->json([
            'success'       => true,
            'ajuste'        => $ajuste,
            'total_a_pagar' => round($totalAPagar, 2),
            'recebimento'   => $recebimento,
        ]);
    }
}
