<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Consulta índices econômicos na API do Banco Central (SGS) e calcula o
 * percentual acumulado de um período.
 *  - IPCA  → série 433
 *  - IGP-M → série 189
 * Doc: https://api.bcb.gov.br/dados/serie/bcdata.sgs.{codigo}/dados
 */
class EconomicIndexService
{
    /** Códigos das séries no SGS/BCB. Aceita aliases comuns. */
    private const SERIES = [
        'IPCA' => 433,
        'IGPM' => 189,
        'IGP-M' => 189,
    ];

    public static function supports(string $indexType): bool
    {
        return isset(self::SERIES[strtoupper(trim($indexType))]);
    }

    /** Normaliza p/ o rótulo canônico (IPCA | IGPM). */
    public static function canonical(string $indexType): string
    {
        return strtoupper(trim($indexType)) === 'IPCA' ? 'IPCA' : 'IGPM';
    }

    /**
     * Busca as variações mensais do índice no período e calcula o acumulado.
     *
     * @return array{index_type:string, percentual_total:float, meses_utilizados:int, meses:array, start:string, end:string}
     */
    public function accumulated(string $indexType, Carbon $start, Carbon $end): array
    {
        $serie = self::SERIES[strtoupper(trim($indexType))] ?? null;
        if ($serie === null) {
            throw new RuntimeException("Índice não suportado: {$indexType}. Use IPCA ou IGP-M.");
        }

        $resp = Http::timeout(20)->retry(2, 600)->acceptJson()->get(
            "https://api.bcb.gov.br/dados/serie/bcdata.sgs.{$serie}/dados",
            [
                'formato'     => 'json',
                'dataInicial' => $start->format('d/m/Y'),
                'dataFinal'   => $end->format('d/m/Y'),
            ],
        );

        if ($resp->failed()) {
            throw new RuntimeException('Falha ao consultar o Banco Central (BCB). Tente novamente.');
        }

        $data = $resp->json();
        if (!is_array($data)) {
            throw new RuntimeException('Resposta inesperada do Banco Central.');
        }

        $calc = $this->calculateAccumulatedIndex($data);

        return array_merge($calc, [
            'index_type' => self::canonical($indexType),
            'start'      => $start->toDateString(),
            'end'        => $end->toDateString(),
        ]);
    }

    /**
     * Acumula variações mensais: (1 + m1/100) * (1 + m2/100) * ... - 1.
     *
     * @param  array<int,array{data?:string,valor?:string|float}>  $data
     * @return array{percentual_total:float, meses_utilizados:int, meses:array}
     */
    public function calculateAccumulatedIndex(array $data): array
    {
        $fator = 1.0;
        $meses = [];

        foreach ($data as $row) {
            if (!isset($row['valor'])) {
                continue;
            }
            $valor = (float) str_replace(',', '.', (string) $row['valor']);
            $fator *= (1 + $valor / 100);
            $meses[] = [
                'mes'   => $row['data'] ?? null,
                'valor' => $valor,
            ];
        }

        return [
            'percentual_total' => round(($fator - 1) * 100, 4),
            'meses_utilizados' => count($meses),
            'meses'            => $meses,
        ];
    }
}
