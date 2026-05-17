<?php

namespace App\Services;

/**
 * Risco operacional derivado por etapa.
 *
 * Regras simples (ver ADR 0006), sem persistência. Toda info usada já existe
 * no payload da etapa quando este service é chamado a partir do controller.
 *
 * Categorias (precedência alto > médio > baixo):
 *
 *   🔴 ALTO
 *     - derived_status = 'bloqueada' E days_since_activity > 7
 *     - prazo passou (expected_end_date < hoje) E não concluída
 *     - consumo > 110% (actual_hours / planned_hours > 1.1)
 *     - team_overrun_count > 0 (≥1 alocação estourada)
 *
 *   🟡 MÉDIO
 *     - prazo nos próximos 7 dias
 *     - consumo > 85%
 *     - sem movimentação > 5 dias
 *
 *   🟢 BAIXO — nenhuma das condições acima
 */
class ProjectStageRiskService
{
    public const LEVEL_LOW    = 'low';
    public const LEVEL_MEDIUM = 'medium';
    public const LEVEL_HIGH   = 'high';

    /**
     * @param array{
     *   derived_status?: string|null,
     *   expected_end_date?: string|null,
     *   days_since_activity?: int|null,
     *   planned_hours?: float|int|null,
     *   actual_hours?: float|int|null,
     *   team_overrun_count?: int|null,
     * } $input
     *
     * @return array{level: string, reasons: array<int, string>}
     */
    public static function compute(array $input): array
    {
        $reasons = [];
        $highHits = [];
        $mediumHits = [];

        $derived       = $input['derived_status']      ?? null;
        $expectedEnd   = $input['expected_end_date']   ?? null;
        $daysActivity  = $input['days_since_activity'] ?? null;
        $planned       = (float) ($input['planned_hours'] ?? 0);
        $actual        = (float) ($input['actual_hours']  ?? 0);
        $teamOverrun   = (int)   ($input['team_overrun_count'] ?? 0);

        // ── Alto risco ───────────────────────────────────────────────────────
        if ($derived === 'bloqueada' && $daysActivity !== null && $daysActivity > 7) {
            $highHits[] = sprintf('Bloqueada há %dd', $daysActivity);
        }

        if ($expectedEnd !== null && $derived !== 'concluida') {
            $end = \Carbon\Carbon::parse($expectedEnd)->startOfDay();
            $today = \Carbon\Carbon::now()->startOfDay();
            if ($end->lt($today)) {
                $daysLate = $today->diffInDays($end);
                $highHits[] = sprintf('Prazo vencido há %dd', $daysLate);
            }
        }

        if ($planned > 0 && $actual / $planned > 1.10) {
            $pct = (int) round(($actual / $planned) * 100);
            $highHits[] = sprintf('Consumo %d%% do planejado', $pct);
        }

        if ($teamOverrun > 0) {
            $highHits[] = sprintf(
                '%d aloca%s estourada%s',
                $teamOverrun,
                $teamOverrun === 1 ? 'ção' : 'ções',
                $teamOverrun === 1 ? '' : 's'
            );
        }

        // ── Médio risco ──────────────────────────────────────────────────────
        if ($expectedEnd !== null && $derived !== 'concluida') {
            $end = \Carbon\Carbon::parse($expectedEnd)->startOfDay();
            $today = \Carbon\Carbon::now()->startOfDay();
            if ($end->gte($today) && $today->diffInDays($end) <= 7) {
                $mediumHits[] = sprintf('Prazo em %dd', $today->diffInDays($end));
            }
        }

        if ($planned > 0) {
            $ratio = $actual / $planned;
            if ($ratio > 0.85 && $ratio <= 1.10) {
                $mediumHits[] = sprintf('Consumo %d%% do planejado', (int) round($ratio * 100));
            }
        }

        if ($daysActivity !== null && $daysActivity > 5 && $derived !== 'concluida') {
            // Não duplica se já entrou na regra alta de bloqueada >7d
            $alreadyHigh = $derived === 'bloqueada' && $daysActivity > 7;
            if (!$alreadyHigh) {
                $mediumHits[] = sprintf('Sem movimentação há %dd', $daysActivity);
            }
        }

        // ── Resolve nível dominante ──────────────────────────────────────────
        if (!empty($highHits)) {
            return ['level' => self::LEVEL_HIGH, 'reasons' => $highHits];
        }
        if (!empty($mediumHits)) {
            return ['level' => self::LEVEL_MEDIUM, 'reasons' => $mediumHits];
        }
        return ['level' => self::LEVEL_LOW, 'reasons' => []];
    }
}
