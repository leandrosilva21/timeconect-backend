<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Relatório de Pagamentos — unifica os pagamentos de CONSULTORES (fechamento de consultores,
 * incl. Bizify) e PARCEIROS (fechamento de parceiros) num só lugar, com tipo (consultor/parceiro),
 * vínculo (horista/banco de horas/fixo) e tipo de contratação (cooperado/clt/pj) para filtrar no FE.
 */
class RelatorioPagamentoController extends Controller
{
    public function pagamentos(Request $request, string $yearMonth): JsonResponse
    {
        $vinculoLabel  = ['horista' => 'Horista', 'banco_de_horas' => 'Banco de Horas', 'fixo' => 'Fixo'];
        $contratoLabel = ['cooperado' => 'Cooperado', 'clt' => 'CLT', 'pj' => 'PJ'];

        $rows = [];

        // ── Consultores (ERPSERV + Bizify) ──
        $cons = app(FechamentoConsultorController::class)->buildConsultoresData($yearMonth);
        $collectConsultores = function (array $src, string $empresa) use (&$rows, $vinculoLabel, $contratoLabel) {
            foreach (['horistas', 'banco_horas', 'fixos'] as $bucket) {
                foreach ($src[$bucket] ?? [] as $c) {
                    $rows[] = [
                        'tipo'                => 'consultor',
                        'empresa'             => $empresa,
                        'nome'                => $c['nome'] ?? '',
                        'vinculo'             => $c['consultant_type'] ?? null,
                        'vinculo_label'       => $vinculoLabel[$c['consultant_type'] ?? ''] ?? ($c['consultant_type'] ?? '—'),
                        'contract_type'       => $c['contract_type'] ?? null,
                        'contract_type_label' => $contratoLabel[$c['contract_type'] ?? ''] ?? '—',
                        'valor'               => round((float) ($c['recebimento'] ?? $c['total'] ?? 0), 2),
                        'envio_em'            => $c['envio_em'] ?? null,
                    ];
                }
            }
        };
        $collectConsultores($cons, 'ERPSERV');
        if (!empty($cons['bizify'])) {
            $collectConsultores($cons['bizify'], 'Bizify');
        }

        // ── Parceiros ──
        $partReq = new Request();
        $partReq->merge(['year_month' => $yearMonth]);
        $partResp = app(FechamentoParceiroController::class)->index($partReq);
        $partners = json_decode($partResp->getContent(), true)['data'] ?? [];
        foreach ($partners as $p) {
            $rows[] = [
                'tipo'                => 'parceiro',
                'empresa'             => 'Parceiro',
                'nome'                => $p['nome'] ?? '',
                'vinculo'             => null,
                'vinculo_label'       => '—',
                'contract_type'       => $p['contract_type'] ?? null,
                'contract_type_label' => $contratoLabel[$p['contract_type'] ?? ''] ?? '—',
                'valor'               => round((float) ($p['recebimento'] ?? $p['total_a_pagar'] ?? 0), 2),
                'envio_em'            => $p['envio_em'] ?? null,
            ];
        }

        usort($rows, fn ($a, $b) => strcasecmp($a['nome'], $b['nome']));

        return response()->json(['data' => ['rows' => $rows]]);
    }
}
