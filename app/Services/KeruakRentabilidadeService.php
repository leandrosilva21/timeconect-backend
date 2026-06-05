<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Integração com o BI de Rentabilidade do Keruak ("RECEBIMENTO POR CLIENTE").
 * O endpoint retorna uma TABELA HTML (não JSON) com: Razão, CNPJ, AnoMesEmissao,
 * Valor Recebido, Mês-Ano Recebimento, Empresa, Observação. Parseamos e
 * agregamos por CNPJ × mês de recebimento (somando o valor recebido).
 */
class KeruakRentabilidadeService
{
    private const DEFAULT_URL = 'https://app.keruak.com/cgi-bin/Relatorios/powerbi?id=ZXJwc2VydmNvO0ZBOTJBNTE0LTE0RkUtRUYxMS05Q0QxLTAyMkJFMEVBOEFDOQ==';
    private const CACHE_KEY    = 'keruak:recebido-map';

    /**
     * @return array<string, array{name: string, receb: array<string, float>}>
     *   indexado por CNPJ (só dígitos); receb = ['YYYY-MM' => valor somado].
     */
    public function recebido(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget(self::CACHE_KEY);
        }

        return Cache::remember(self::CACHE_KEY, now()->addHours(3), function () {
            $url = SystemSetting::get('keruak_rentabilidade_url') ?: self::DEFAULT_URL;
            try {
                $resp = Http::timeout(60)->get($url);
                if (!$resp->successful()) {
                    Log::warning('[KERUAK] resposta não-ok', ['status' => $resp->status()]);
                    return [];
                }
                return $this->parse($resp->body());
            } catch (\Throwable $e) {
                Log::warning('[KERUAK] falha ao buscar recebimentos', ['error' => $e->getMessage()]);
                return [];
            }
        });
    }

    private function parse(string $html): array
    {
        if (!preg_match('/<tbody>(.*)<\/tbody>/s', $html, $mb)) {
            return [];
        }
        preg_match_all('/<tr>(.*?)<\/tr>/s', $mb[1], $rows);

        $out = [];
        foreach ($rows[1] as $r) {
            preg_match_all('/<td>(.*?)<\/td>/s', $r, $c);
            $cells = $c[1] ?? [];
            if (count($cells) < 5) {
                continue;
            }
            $name = trim(html_entity_decode(strip_tags($cells[0])));
            $cnpj = preg_replace('/\D/', '', $cells[1]);
            if (!$cnpj) {
                continue;
            }
            // "4.457,87" -> 4457.87
            $valor = (float) str_replace(',', '.', str_replace('.', '', trim(strip_tags($cells[3]))));
            $recebRaw = trim(strip_tags($cells[4])); // "MM-YYYY"
            if (!preg_match('/^(\d{2})-(\d{4})$/', $recebRaw, $mm)) {
                continue;
            }
            $ym = $mm[2] . '-' . $mm[1];

            if (!isset($out[$cnpj])) {
                $out[$cnpj] = ['name' => $name, 'receb' => []];
            }
            $out[$cnpj]['receb'][$ym] = round(($out[$cnpj]['receb'][$ym] ?? 0) + $valor, 2);
        }

        return $out;
    }
}
