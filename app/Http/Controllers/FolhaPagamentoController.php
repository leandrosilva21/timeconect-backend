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

    /** Sócios: linhas FIXAS, manuais e totalmente editáveis (destacadas), independentes de cadastro. */
    private const SOCIOS = [
        ['key' => 'ricardo_silva',      'nome' => 'RICARDO DE OLIVEIRA SILVA',           'cpf' => '313.017.868-61', 'matricula' => '46761', 'status' => 'Contratado', 'hm' => 'Mensalista'],
        ['key' => 'caio_maior',         'nome' => 'CAIO MAIOR GARCIA',                   'cpf' => '370.373.308-09', 'matricula' => '16383', 'status' => 'Contratado', 'hm' => 'Horista'],
        ['key' => 'ricardo_badawi',     'nome' => 'RICARDO BADAWI SANTOS',               'cpf' => '358.075.828-45', 'matricula' => '29653', 'status' => 'Contratado', 'hm' => 'Horista'],
        ['key' => 'leandro_silva',      'nome' => 'LEANDRO SANTOS E SILVA',              'cpf' => '328.265.748-09', 'matricula' => '1968',  'status' => 'Contratado', 'hm' => 'Horista'],
        ['key' => 'guilherme_junior',   'nome' => 'GUILHERME MATIAS DE OLIVEIRA JUNIOR', 'cpf' => '422.075.628-08', 'matricula' => '38046', 'status' => 'Contratado', 'hm' => 'Horista'],
        ['key' => 'daniel_albuquerque', 'nome' => 'DANIEL OLIVEIRA DE ALBUQUERQUE',      'cpf' => '003.701.572-90', 'matricula' => '16408', 'status' => 'Contratado', 'hm' => 'Horista'],
    ];

    private function normName(string $s): string
    {
        $s = preg_replace('/\s+/', ' ', mb_strtoupper(trim($s)));
        return strtr($s, ['Á'=>'A','À'=>'A','Â'=>'A','Ã'=>'A','É'=>'E','Ê'=>'E','Í'=>'I','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ú'=>'U','Ç'=>'C','Ü'=>'U']);
    }

    private function nameTokens(string $s): array
    {
        $stop = ['DE', 'DA', 'DO', 'DOS', 'DAS', 'E', 'DI'];
        return array_values(array_filter(explode(' ', $this->normName($s)), fn ($t) => strlen($t) >= 2 && !in_array($t, $stop, true)));
    }

    /** Usuário "é sócio" se primeiro e último token do nome batem com algum sócio fixo. */
    private function matchesSocio(string $userName): bool
    {
        $ut = $this->nameTokens($userName);
        if (count($ut) < 2) {
            return false;
        }
        foreach (self::SOCIOS as $s) {
            $st = $this->nameTokens($s['nome']);
            if (in_array($ut[0], $st, true) && in_array(end($ut), $st, true)) {
                return true;
            }
        }
        return false;
    }

    /** Linhas do grid: cooperados regulares + as linhas-sócio fixas (totalmente editáveis). */
    private function buildRows(string $yearMonth): array
    {
        $fc   = app(FechamentoConsultorController::class);
        $data = $fc->buildConsultoresData($yearMonth);
        $byUser = collect(array_merge($data['horistas'], $data['banco_horas'], $data['fixos']))->keyBy('user_id');

        $all   = FechamentoFolha::where('year_month', $yearMonth)->get();
        $folhaByUser  = $all->whereNotNull('user_id')->keyBy('user_id');
        $folhaBySocio = $all->whereNotNull('socio_key')->keyBy('socio_key');

        $rows = [];

        // ── Cooperados: TODO usuário (qualquer perfil exceto cliente) marcado cooperado.
        // Exclui os sócios (entram como linha própria). Produção/horas vêm do fechamento
        // do consultor quando houver apontamentos; senão 0 / 180.
        $cooperados = User::where('contract_type', 'cooperado')->where('enabled', true)
            ->whereNotIn('type', ['cliente'])->orderBy('name')->get();
        foreach ($cooperados as $u) {
            if ($this->matchesSocio($u->name)) {
                continue; // sócio aparece como linha-sócio editável
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

        // ── Linhas-sócio (fixas, totalmente editáveis, destacadas) ──
        foreach (self::SOCIOS as $s) {
            $f = $folhaBySocio[$s['key']] ?? null;

            $horas        = ($f && $f->horas_trabalhadas !== null) ? (float) $f->horas_trabalhadas : 180.0;
            $valorHora    = ($f && $f->valor_hora !== null) ? (float) $f->valor_hora : 0.0;
            $producao     = ($f && $f->producao !== null) ? (float) $f->producao : 0.0;
            $variavel     = $f ? (float) $f->variavel : 0.0;
            $reemb        = $f ? (float) $f->reemb : 0.0;
            $descontos    = $f ? (float) $f->descontos_diversos : 0.0;
            $adiantamento = $f ? (float) $f->adiantamento : 0.0;

            $totalRend    = round($producao + $variavel + $reemb, 2);
            $totalDebitos = round($descontos + $adiantamento, 2);

            $rows[] = [
                'row_key'            => 's:' . $s['key'],
                'is_socio'           => true,
                'inativo'            => false,
                'cancelado'          => $f ? (bool) $f->cancelado : false,
                'user_id'            => null,
                'socio_key'          => $s['key'],
                'cpf'                => $f?->cpf ?? $s['cpf'],
                'matricula'          => $f?->matricula ?? $s['matricula'],
                'status'             => $f?->status ?? $s['status'],
                'nome'               => $f?->nome ?? $s['nome'],
                'dias'               => $f ? (float) $f->dias_trabalhados : 0.0,
                'horas'              => $horas,
                'horas_apontamentos' => 0.0,
                'valor_hora'         => $valorHora,
                'producao'           => $producao,
                'variavel'           => $variavel,
                'reemb'              => $reemb,
                'descontos'          => $descontos,
                'adiantamento'       => $adiantamento,
                'horista_mensalista' => $f && $f->horista_mensalista ? $f->horista_mensalista : $s['hm'],
                'total_rend'         => $totalRend,
                'total_debitos'      => $totalDebitos,
                'liquido'            => round($totalRend - $totalDebitos, 2),
            ];
        }

        // ── Linhas manuais CUSTOM ("Nova linha editável"): socio_key fora da lista fixa ──
        $socioKeys = array_column(self::SOCIOS, 'key');
        foreach ($folhaBySocio as $key => $f) {
            if (in_array($key, $socioKeys, true)) {
                continue; // sócios fixos já tratados acima
            }
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

        // ── Cooperados DESATIVADOS com registro na folha do mês (linha inativa) ──
        // (Usuário ativo já entrou acima via buildConsultoresData; aqui pegamos os que
        // foram desativados mas têm linha salva no mês — para sinalizar e permitir cancelar.)
        $jaIncluidos = collect($rows)->whereNotNull('user_id')->pluck('user_id')->all();
        $inativos = User::where('contract_type', 'cooperado')
            ->where('enabled', false)
            ->whereIn('id', $folhaByUser->keys()->all())
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

    public function grid(Request $request, string $yearMonth): JsonResponse
    {
        if ($r = $this->guard($request)) {
            return $r;
        }
        return response()->json(['data' => $this->buildRows($yearMonth)]);
    }

    public function save(Request $request, string $yearMonth): JsonResponse
    {
        if ($r = $this->guard($request)) {
            return $r;
        }
        $request->validate([
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
            'entries.*.horista_mensalista'   => 'nullable|string|max:20',
        ]);

        $saved = 0;

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
                // Linha-sócio fixa OU linha manual custom: tudo manual (cpf/matrícula/nome/status/valor_hora/produção).
                FechamentoFolha::updateOrCreate(
                    ['socio_key' => $e['socio_key'], 'year_month' => $yearMonth],
                    array_merge($comum, [
                        'cpf'        => $e['cpf'] ?? null,
                        'matricula'  => $e['matricula'] ?? null,
                        'nome'       => $e['nome'] ?? null,
                        'status'     => $e['status'] ?? null,
                        'valor_hora' => $e['valor_hora'] ?? null,
                        'producao'   => $e['producao'] ?? null,
                    ])
                );
                $saved++;
            } elseif (!empty($e['user_id'])) {
                FechamentoFolha::updateOrCreate(
                    ['user_id' => $e['user_id'], 'year_month' => $yearMonth],
                    $comum
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
        FechamentoFolha::where('year_month', $yearMonth)->where('socio_key', $socioKey)->delete();
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
            'cancelado' => 'required|boolean',
        ]);

        $cancelado = $request->boolean('cancelado');
        if ($request->filled('socio_key')) {
            FechamentoFolha::updateOrCreate(
                ['socio_key' => $request->input('socio_key'), 'year_month' => $yearMonth],
                ['cancelado' => $cancelado]
            );
        } elseif ($request->filled('user_id')) {
            FechamentoFolha::updateOrCreate(
                ['user_id' => $request->integer('user_id'), 'year_month' => $yearMonth],
                ['cancelado' => $cancelado]
            );
        } else {
            return response()->json(['message' => 'Informe user_id ou socio_key.'], 422);
        }

        return response()->json(['cancelado' => $cancelado]);
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
