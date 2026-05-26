<?php

namespace App\Http\Controllers;

use App\Exports\FolhaPagamentoExport;
use App\Models\FechamentoFolha;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Excel as ExcelType;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Folha de pagamento (planilha de importação): grid editável por consultor/mês + geração
 * do .xls idêntico ao template. Colunas auto (CPF/Matrícula/Status/Nome/Valor Hora) vêm do
 * usuário; Produção e Horas vêm do fechamento/apontamentos; o restante é editável e salvo.
 */
class FolhaPagamentoController extends Controller
{
    private function guard(Request $request): ?JsonResponse
    {
        $u = $request->user();
        if (!$u || !($u->isAdmin() || $u->isAdministrativo())) {
            return response()->json(['message' => 'Sem permissão para a folha de pagamento.'], 403);
        }
        return null;
    }

    private const RAHO_PARTNER_NAME = 'Raho';

    /**
     * id do parceiro Raho — fechamento dele vai INDIVIDUALMENTE pra cooperativa.
     * Hardcoded por nome (decisão de negócio: caso específico do Raho).
     */
    private static function rahoPartnerId(): ?int
    {
        return \App\Models\Partner::where('name', self::RAHO_PARTNER_NAME)->value('id');
    }

    /** Linhas do grid: cooperados regulares + as linhas-sócio fixas (totalmente editáveis). */
    private function buildRows(string $yearMonth, string $empresa = 'erpserv'): array
    {
        $all = FechamentoFolha::where('year_month', $yearMonth)->where('empresa', $empresa)->get();

        // Bizify: folha 100% manual (lançamentos/importação de planilha) — colunas próprias,
        // sem cooperados/sócios/Raho. ERPSERV segue a lógica completa abaixo.
        if ($empresa === 'bizify') {
            return $this->buildBizifyRows($all);
        }

        $fc   = app(FechamentoConsultorController::class);
        $data = $fc->buildConsultoresData($yearMonth);
        $byUser = collect(array_merge($data['horistas'], $data['banco_horas'], $data['fixos']))->keyBy('user_id');

        $folhaByUser  = $all->whereNotNull('user_id')->keyBy('user_id');
        $folhaBySocio = $all->whereNotNull('socio_key')->keyBy('socio_key');

        // Parceiro "Raho": fechamento vai INDIVIDUALMENTE pra cooperativa — cada usuário
        // do Raho vira uma linha própria (azul, identificada, 100% editável), fora da
        // lista normal de cooperados (sem duplicar). Hardcoded por nome.
        $rahoId = self::rahoPartnerId();

        $rows = [];

        // ── Cooperados: TODO usuário (qualquer perfil exceto cliente) marcado cooperado.
        // Exclui os sócios (entram como linha própria). Produção/horas vêm do fechamento
        // do consultor quando houver apontamentos; senão 0 / 180.
        $cooperados = User::where('contract_type', 'cooperado')->where('enabled', true)
            ->whereNotIn('type', ['cliente'])->orderBy('name')->get();
        foreach ($cooperados as $u) {
            if ($rahoId && (int) $u->partner_id === $rahoId) {
                continue; // usuário do Raho entra na seção própria (azul), não como cooperado
            }
            $uid = $u->id;
            $c   = $byUser[$uid] ?? []; // dados do fechamento (se for consultor com apontamento)

            $f = $folhaByUser[$uid] ?? null;

            $apontHoras = round((float) ($c['horas_trabalhadas'] ?? 0), 2);
            $producao   = round((float) ($c['total'] ?? 0), 2);
            $horas      = 180.0; // horas FIXAS (não via apontamento); Produção segue do fechamento

            // Valor hora só p/ vínculo por hora; fixo/mensal NÃO traz valor.
            $valorHora = ($u && $u->rate_type === 'hourly') ? round((float) $u->hourly_rate, 4) : 0.0;

            $variavel     = $f ? (float) $f->variavel : 0.0;
            $reemb        = $f ? (float) $f->reemb : 0.0;
            $descontos    = $f ? (float) $f->descontos_diversos : 0.0;
            $adiantamento = $f ? (float) $f->adiantamento : 0.0;
            $hm           = $f && $f->horista_mensalista
                ? $f->horista_mensalista
                : (($c['consultant_type'] ?? $u->consultant_type) === 'horista' ? 'Horista' : 'Mensalista');

            $totalRend    = round($producao + $variavel + $reemb, 2);
            $totalDebitos = round($descontos + $adiantamento, 2);

            $rows[] = [
                'row_key'            => 'u:' . $uid,
                'is_socio'           => false,
                'inativo'            => false,
                'cancelado'          => $f ? (bool) $f->cancelado : false,
                'user_id'            => $uid,
                'socio_key'          => null,
                'cpf'                => $u?->cpf ?? '',
                'matricula'          => $u?->matricula ?? '',
                'status'             => $u?->payroll_status ?? '',
                'nome'               => $u?->full_name ?: ($u?->name ?? ($c['nome'] ?? '')),
                'dias'               => $f ? (float) $f->dias_trabalhados : 0.0,
                'horas'              => $horas,
                'horas_apontamentos' => $apontHoras,
                'valor_hora'         => $valorHora,
                'producao'           => $producao,
                'variavel'           => $variavel,
                'reemb'              => $reemb,
                'descontos'          => $descontos,
                'adiantamento'       => $adiantamento,
                'horista_mensalista' => $hm,
                'total_rend'         => $totalRend,
                'total_debitos'      => $totalDebitos,
                'liquido'            => round($totalRend - $totalDebitos, 2),
            ];
        }

        // ── Raho: cada usuário do parceiro vira linha própria (azul, identificada,
        //    100% editável). Keyed por user_id; valores editáveis vêm da folha salva,
        //    com defaults do cadastro/fechamento. Novo usuário do Raho → nova linha auto. ──
        if ($rahoId) {
            // Valores CALCULADOS do mês filtrado: vêm do fechamento do PARCEIRO (horas × taxa).
            $rahoPartner = \App\Models\Partner::find($rahoId);
            $rahoCalc = $rahoPartner
                ? collect(app(FechamentoParceiroController::class)->consultoresData($rahoPartner, $yearMonth))->keyBy('user_id')
                : collect();
            // Inclui desativados também (aparecem em "afastamento", ocultação manual).
            $rahoUsers = User::where('partner_id', $rahoId)->orderBy('name')->get();
            foreach ($rahoUsers as $u) {
                $uid  = $u->id;
                $f    = $folhaByUser[$uid] ?? null;
                $calc = $rahoCalc[$uid] ?? [];

                // Original (calculado do mês): horas/taxa/produção do fechamento do parceiro.
                $horasCalc     = round((float) ($calc['horas'] ?? 0), 2);
                $valorHoraCalc = round((float) ($calc['valor_hora'] ?? 0), 2);
                $producaoCalc  = round((float) ($calc['total'] ?? 0), 2);

                // Atual = salvo (editado) quando houver; senão o calculado (prefill).
                $horas        = ($f && $f->horas_trabalhadas !== null) ? (float) $f->horas_trabalhadas : $horasCalc;
                $valorHora    = ($f && $f->valor_hora !== null) ? (float) $f->valor_hora : $valorHoraCalc;
                $producao     = ($f && $f->producao !== null) ? (float) $f->producao : $producaoCalc;
                $variavel     = $f ? (float) $f->variavel : 0.0;
                $reemb        = $f ? (float) $f->reemb : 0.0;
                $descontos    = $f ? (float) $f->descontos_diversos : 0.0;
                $adiantamento = $f ? (float) $f->adiantamento : 0.0;

                $totalRend    = round($producao + $variavel + $reemb, 2);
                $totalDebitos = round($descontos + $adiantamento, 2);

                $rows[] = [
                    'row_key'            => 'u:' . $uid,
                    'is_socio'           => false, // identidade (cpf/nome/status) vem do usuário (read-only); VALORES editáveis via is_raho
                    'is_raho'            => true,
                    'partner_label'      => 'Raho',
                    'inativo'            => !$u->enabled, // desativado => "em afastamento"
                    'cancelado'          => $f ? (bool) $f->cancelado : false,
                    'user_id'            => $uid,
                    'socio_key'          => null,
                    'cpf'                => $u->cpf ?? '',
                    'matricula'          => $u->matricula ?? '',
                    'status'             => $u->payroll_status ?? '',
                    'nome'               => $u->full_name ?: $u->name,
                    'dias'               => $f ? (float) $f->dias_trabalhados : 0.0,
                    'horas'              => $horas,
                    'horas_apontamentos' => $horasCalc,
                    'valor_hora'         => $valorHora,
                    'producao'           => $producao,
                    // Valores ORIGINAIS calculados (p/ legenda "original" quando alterado).
                    'horas_calc'         => $horasCalc,
                    'valor_hora_calc'    => $valorHoraCalc,
                    'producao_calc'      => $producaoCalc,
                    'variavel'           => $variavel,
                    'reemb'              => $reemb,
                    'descontos'          => $descontos,
                    'adiantamento'       => $adiantamento,
                    'horista_mensalista' => $f && $f->horista_mensalista ? $f->horista_mensalista
                                          : ($u->consultant_type === 'horista' ? 'Horista' : 'Mensalista'),
                    'total_rend'         => $totalRend,
                    'total_debitos'      => $totalDebitos,
                    'liquido'            => round($totalRend - $totalDebitos, 2),
                ];
            }
        }

        // ── Linhas manuais ("Nova linha editável") — inclui os sócios (migrados p/ manual). ──
        foreach ($folhaBySocio as $key => $f) {
            $variavel     = (float) $f->variavel;
            $reemb        = (float) $f->reemb;
            $descontos    = (float) $f->descontos_diversos;
            $adiantamento = (float) $f->adiantamento;
            $producao     = $f->producao !== null ? (float) $f->producao : 0.0;
            $totalRend    = round($producao + $variavel + $reemb, 2);
            $totalDebitos = round($descontos + $adiantamento, 2);

            $rows[] = [
                'row_key'            => 's:' . $key,
                'is_socio'           => true,
                'inativo'            => false,
                'cancelado'          => (bool) $f->cancelado,
                'user_id'            => null,
                'socio_key'          => $key,
                'cpf'                => $f->cpf ?? '',
                'matricula'          => $f->matricula ?? '',
                'status'             => $f->status ?? '',
                'nome'               => $f->nome ?? '',
                'dias'               => (float) $f->dias_trabalhados,
                'horas'              => $f->horas_trabalhadas !== null ? (float) $f->horas_trabalhadas : 180.0,
                'horas_apontamentos' => 0.0,
                'valor_hora'         => $f->valor_hora !== null ? (float) $f->valor_hora : 0.0,
                'producao'           => $producao,
                'variavel'           => $variavel,
                'reemb'              => $reemb,
                'descontos'          => $descontos,
                'adiantamento'       => $adiantamento,
                'horista_mensalista' => $f->horista_mensalista ?: 'Horista',
                'total_rend'         => $totalRend,
                'total_debitos'      => $totalDebitos,
                'liquido'            => round($totalRend - $totalDebitos, 2),
            ];
        }

        // ── Cooperados DESATIVADOS (linha "em afastamento") ──
        // Usuário desativado continua aparecendo (afastamento, borda vermelha) até que
        // a ocultação seja feita manualmente (cancelar) quando não tiver participação.
        $jaIncluidos = collect($rows)->whereNotNull('user_id')->pluck('user_id')->all();
        $inativos = User::where('contract_type', 'cooperado')
            ->where('enabled', false)
            ->whereNotIn('id', $jaIncluidos)
            ->get();
        foreach ($inativos as $u) {
            $f = $folhaByUser[$u->id] ?? null;
            $valorHora    = ($u->rate_type === 'hourly') ? round((float) $u->hourly_rate, 4) : 0.0;
            $producao     = $f && $f->producao !== null ? (float) $f->producao : 0.0;
            $variavel     = $f ? (float) $f->variavel : 0.0;
            $reemb        = $f ? (float) $f->reemb : 0.0;
            $descontos    = $f ? (float) $f->descontos_diversos : 0.0;
            $adiantamento = $f ? (float) $f->adiantamento : 0.0;
            $totalRend    = round($producao + $variavel + $reemb, 2);
            $totalDebitos = round($descontos + $adiantamento, 2);

            $rows[] = [
                'row_key'            => 'u:' . $u->id,
                'is_socio'           => false,
                'inativo'            => true,
                'cancelado'          => $f ? (bool) $f->cancelado : false,
                'user_id'            => $u->id,
                'socio_key'          => null,
                'cpf'                => $u->cpf ?? '',
                'matricula'          => $u->matricula ?? '',
                'status'             => $u->payroll_status ?? '',
                'nome'               => $u->full_name ?: $u->name,
                'dias'               => $f ? (float) $f->dias_trabalhados : 0.0,
                'horas'              => $f && $f->horas_trabalhadas !== null ? (float) $f->horas_trabalhadas : 180.0,
                'horas_apontamentos' => 0.0,
                'valor_hora'         => $valorHora,
                'producao'           => $producao,
                'variavel'           => $variavel,
                'reemb'              => $reemb,
                'descontos'          => $descontos,
                'adiantamento'       => $adiantamento,
                'horista_mensalista' => $f && $f->horista_mensalista ? $f->horista_mensalista : 'Mensalista',
                'total_rend'         => $totalRend,
                'total_debitos'      => $totalDebitos,
                'liquido'            => round($totalRend - $totalDebitos, 2),
            ];
        }

        usort($rows, fn ($a, $b) => strcasecmp((string) $a['nome'], (string) $b['nome']));
        return $rows;
    }

    /**
     * Linhas da aba BIZIFY: lançamentos manuais (avulsos, identidade da própria linha).
     * Colunas próprias: Produção, Variável, Aj Custo, Reemb, Adto (créditos) / Descontos,
     * Adiantamento (débitos). Totais calculados. socio_key = matrícula.
     */
    private function buildBizifyRows($all): array
    {
        $rows = [];
        foreach ($all->whereNotNull('socio_key') as $f) {
            $producao     = (float) ($f->producao ?? 0);
            $variavel     = (float) $f->variavel;
            $ajCusto      = (float) ($f->aj_custo ?? 0);
            $reemb        = (float) $f->reemb;
            $adto         = (float) ($f->adto ?? 0);
            $descontos    = (float) $f->descontos_diversos;
            $adiantamento = (float) $f->adiantamento;

            $totalCred = round($producao + $variavel + $ajCusto + $reemb + $adto, 2);
            $totalDeb  = round($descontos + $adiantamento, 2);

            $rows[] = [
                'row_key'        => 'b:' . $f->socio_key,
                'is_bizify'      => true,
                'is_socio'       => false,
                'is_raho'        => false,
                'inativo'        => false,
                'cancelado'      => (bool) $f->cancelado,
                'user_id'        => null,
                'socio_key'      => $f->socio_key,
                'matricula'      => $f->matricula ?? '',
                'nome'           => $f->nome ?? '',
                'status'         => $f->status ?? '', // Operação
                'producao'       => $producao,
                'variavel'       => $variavel,
                'aj_custo'       => $ajCusto,
                'reemb'          => $reemb,
                'adto'           => $adto,
                'descontos'      => $descontos,
                'adiantamento'   => $adiantamento,
                'total_creditos' => $totalCred,
                'total_debitos'  => $totalDeb,
                'liquido'        => round($totalCred - $totalDeb, 2),
            ];
        }
        usort($rows, fn ($a, $b) => strcasecmp((string) $a['nome'], (string) $b['nome']));
        return $rows;
    }

    public function grid(Request $request, string $yearMonth): JsonResponse
    {
        if ($r = $this->guard($request)) {
            return $r;
        }
        $empresa = $request->query('empresa') === 'bizify' ? 'bizify' : 'erpserv';
        return response()->json(['data' => $this->buildRows($yearMonth, $empresa)]);
    }

    public function save(Request $request, string $yearMonth): JsonResponse
    {
        if ($r = $this->guard($request)) {
            return $r;
        }
        $request->validate([
            'empresa'                        => 'nullable|string|in:erpserv,bizify',
            'entries'                        => 'required|array',
            'entries.*.user_id'              => 'nullable|integer|exists:users,id',
            'entries.*.socio_key'            => 'nullable|string|max:60',
            'entries.*.cpf'                  => 'nullable|string|max:20',
            'entries.*.matricula'            => 'nullable|string|max:30',
            'entries.*.nome'                 => 'nullable|string|max:255',
            'entries.*.status'               => 'nullable|string|max:40',
            'entries.*.valor_hora'           => 'nullable|numeric',
            'entries.*.producao'             => 'nullable|numeric',
            'entries.*.dias_trabalhados'     => 'nullable|numeric',
            'entries.*.horas_trabalhadas'    => 'nullable|numeric',
            'entries.*.variavel'             => 'nullable|numeric',
            'entries.*.reemb'                => 'nullable|numeric',
            'entries.*.descontos_diversos'   => 'nullable|numeric',
            'entries.*.adiantamento'         => 'nullable|numeric',
            'entries.*.aj_custo'             => 'nullable|numeric',
            'entries.*.adto'                 => 'nullable|numeric',
            'entries.*.horista_mensalista'   => 'nullable|string|max:20',
        ]);

        $empresa = $request->input('empresa') === 'bizify' ? 'bizify' : 'erpserv';
        $saved = 0;

        // Usuários do Raho = linhas 100% editáveis (salvam cpf/nome/status/valor_hora/produção também).
        $rahoId      = self::rahoPartnerId();
        $rahoUserIds = $rahoId ? User::where('partner_id', $rahoId)->pluck('id')->map(fn ($i) => (int) $i)->all() : [];

        foreach ($request->input('entries') as $e) {
            $comum = [
                'dias_trabalhados'   => $e['dias_trabalhados'] ?? 0,
                'horas_trabalhadas'  => $e['horas_trabalhadas'] ?? null,
                'variavel'           => $e['variavel'] ?? 0,
                'reemb'              => $e['reemb'] ?? 0,
                'descontos_diversos' => $e['descontos_diversos'] ?? 0,
                'adiantamento'       => $e['adiantamento'] ?? 0,
                'horista_mensalista' => $e['horista_mensalista'] ?? null,
            ];

            if (!empty($e['socio_key'])) {
                // Linha-sócio/manual (ERPSERV) OU lançamento manual da Bizify: tudo manual.
                FechamentoFolha::updateOrCreate(
                    ['socio_key' => $e['socio_key'], 'year_month' => $yearMonth, 'empresa' => $empresa],
                    array_merge($comum, [
                        'cpf'        => $e['cpf'] ?? null,
                        'matricula'  => $e['matricula'] ?? null,
                        'nome'       => $e['nome'] ?? null,
                        'status'     => $e['status'] ?? null,
                        'valor_hora' => $e['valor_hora'] ?? null,
                        'producao'   => $e['producao'] ?? null,
                        'aj_custo'   => $e['aj_custo'] ?? null,
                        'adto'       => $e['adto'] ?? null,
                    ])
                );
                $saved++;
            } elseif (!empty($e['user_id'])) {
                // Raho: VALORES editáveis (valor_hora/produção) salvos; cpf/nome/status seguem do cadastro do usuário.
                $extra = in_array((int) $e['user_id'], $rahoUserIds, true) ? [
                    'valor_hora' => $e['valor_hora'] ?? null,
                    'producao'   => $e['producao'] ?? null,
                ] : [];
                FechamentoFolha::updateOrCreate(
                    ['user_id' => $e['user_id'], 'year_month' => $yearMonth, 'empresa' => 'erpserv'],
                    array_merge($comum, $extra)
                );
                $saved++;
            }
        }

        return response()->json(['saved' => $saved]);
    }

    /** Remove uma linha manual (socio_key) do mês. Sócios fixos voltam com defaults; custom somem. */
    public function deleteRow(Request $request, string $yearMonth, string $socioKey): JsonResponse
    {
        if ($r = $this->guard($request)) {
            return $r;
        }
        $empresa = $request->query('empresa') === 'bizify' ? 'bizify' : 'erpserv';
        FechamentoFolha::where('year_month', $yearMonth)->where('socio_key', $socioKey)->where('empresa', $empresa)->delete();
        return response()->json(['deleted' => true]);
    }

    /** Cancela/reativa uma linha do mês (move para/da aba "Canceladas"). */
    public function cancelRow(Request $request, string $yearMonth): JsonResponse
    {
        if ($r = $this->guard($request)) {
            return $r;
        }
        $request->validate([
            'user_id'   => 'nullable|integer|exists:users,id',
            'socio_key' => 'nullable|string|max:60',
            'empresa'   => 'nullable|string|in:erpserv,bizify',
            'cancelado' => 'required|boolean',
        ]);

        $empresa   = $request->input('empresa') === 'bizify' ? 'bizify' : 'erpserv';
        $cancelado = $request->boolean('cancelado');
        if ($request->filled('socio_key')) {
            FechamentoFolha::updateOrCreate(
                ['socio_key' => $request->input('socio_key'), 'year_month' => $yearMonth, 'empresa' => $empresa],
                ['cancelado' => $cancelado]
            );
        } elseif ($request->filled('user_id')) {
            FechamentoFolha::updateOrCreate(
                ['user_id' => $request->integer('user_id'), 'year_month' => $yearMonth, 'empresa' => 'erpserv'],
                ['cancelado' => $cancelado]
            );
        } else {
            return response()->json(['message' => 'Informe user_id ou socio_key.'], 422);
        }

        return response()->json(['cancelado' => $cancelado]);
    }

    /**
     * Importa a planilha da Bizify (xls/xlsx/csv) p/ o mês: MESCLA por matrícula
     * (updateOrCreate — não apaga linhas fora do arquivo). Totais (colunas vermelhas
     * na planilha) são calculados, não importados.
     */
    public function importBizify(Request $request, string $yearMonth): JsonResponse
    {
        if ($r = $this->guard($request)) {
            return $r;
        }
        $request->validate([
            'file' => 'required|file|mimes:xls,xlsx,csv,txt|max:8192',
        ]);

        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($request->file('file')->getRealPath())->getActiveSheet();
        $data  = $sheet->toArray(null, true, false, false);
        if (count($data) < 2) {
            return response()->json(['message' => 'Planilha vazia.'], 422);
        }

        // Mapeia colunas pelo cabeçalho (normalizado: minúsculo, sem acento).
        $norm = function ($s) {
            $s = mb_strtolower(trim((string) $s));
            return strtr($s, ['á'=>'a','â'=>'a','ã'=>'a','à'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c']);
        };
        $header = array_map($norm, $data[0]);
        $col    = fn ($name) => array_search($name, $header, true);

        $cMat  = $col('matricula');
        $cNome = $col('nome');
        $cOper = $col('operacao');
        $cProd = $col('producao');
        $cVar  = $col('variavel');
        $cAjc  = $col('aj custo');
        $cReem = $col('reemb');
        $cAdto = $col('adto');
        $cDesc = $col('descontos');
        $cAdia = $col('adiantamento');

        if ($cMat === false || $cNome === false) {
            return response()->json(['message' => 'Cabeçalho inválido: faltam colunas Matricula/Nome.'], 422);
        }

        $num = function ($v) {
            if ($v === null || $v === '') return 0.0;
            if (is_numeric($v)) return (float) $v;
            $v = str_replace(['.', ' '], '', (string) $v); // pt-BR "8.910,00"
            $v = str_replace(',', '.', $v);
            return is_numeric($v) ? (float) $v : 0.0;
        };

        $imported = 0;
        foreach (array_slice($data, 1) as $row) {
            $mat = trim((string) ($row[$cMat] ?? ''));
            if ($mat === '' || !preg_match('/^\d+$/', $mat)) {
                continue; // pula totais / legenda / linhas sem matrícula numérica
            }
            FechamentoFolha::updateOrCreate(
                ['year_month' => $yearMonth, 'empresa' => 'bizify', 'socio_key' => $mat],
                [
                    'matricula'          => $mat,
                    'nome'               => $cNome !== false ? trim((string) ($row[$cNome] ?? '')) : null,
                    'status'             => $cOper !== false ? trim((string) ($row[$cOper] ?? '')) : null,
                    'producao'           => $cProd !== false ? $num($row[$cProd] ?? 0) : 0,
                    'variavel'           => $cVar  !== false ? $num($row[$cVar]  ?? 0) : 0,
                    'aj_custo'           => $cAjc  !== false ? $num($row[$cAjc]  ?? 0) : 0,
                    'reemb'              => $cReem !== false ? $num($row[$cReem] ?? 0) : 0,
                    'adto'               => $cAdto !== false ? $num($row[$cAdto] ?? 0) : 0,
                    'descontos_diversos' => $cDesc !== false ? $num($row[$cDesc] ?? 0) : 0,
                    'adiantamento'       => $cAdia !== false ? $num($row[$cAdia] ?? 0) : 0,
                ]
            );
            $imported++;
        }

        return response()->json(['imported' => $imported]);
    }

    public function export(Request $request, string $yearMonth)
    {
        if ($r = $this->guard($request)) {
            return $r;
        }
        [$year, $month] = explode('-', $yearMonth);
        $fileName = "{$month}_{$year}_M_ERPSERV CONSULTORIA DE SISTEMAS LTDA.xls";

        // O .xls não leva linhas canceladas (elas estão na aba "Canceladas").
        $rows = array_values(array_filter($this->buildRows($yearMonth), fn ($r) => empty($r['cancelado'])));

        return Excel::download(new FolhaPagamentoExport($rows), $fileName, ExcelType::XLS);
    }
}
