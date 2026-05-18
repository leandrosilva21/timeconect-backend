<?php

namespace App\Services;

use App\Models\User;

/**
 * Extrai user_ids mencionados em texto de chat.
 *
 * Suporta 2 sintaxes:
 *  1. Canônica: `@[123:Nome Completo]` — gerada pelo mention picker do FE
 *  2. Plain-text fallback: `@Nome Completo` — captura quando user digita à mão.
 *     Match com greedy longest-prefix contra User.name (case-insensitive).
 *
 * Plain-text fallback evita falso positivo via:
 *  - Caractere @ precedido de espaço, início, quebra de linha ou pontuação (não email)
 *  - Match mais longo primeiro: "Ricardo Oliveira Silva" antes de "Ricardo Oliveira"
 *  - Limite de 4 palavras após @ pra não devorar a frase
 */
class MentionParser
{
    /**
     * @param array<int,User>|\Illuminate\Support\Collection<int,User> $candidateUsers
     *        Lista pra resolver @<Nome>. Vazio = busca global em User.
     * @return array<int> user_ids únicos mencionados
     */
    public static function extract(string $text, $candidateUsers = []): array
    {
        $ids = [];

        // 1. Token canônico
        preg_match_all('/@\[(\d+):([^\]]+)\]/', $text, $canonical);
        foreach (array_unique($canonical[1] ?? []) as $id) {
            $ids[(int) $id] = true;
        }

        // 2. Remove tokens canônicos do texto pra não double-match no fallback
        $cleaned = preg_replace('/@\[(\d+):([^\]]+)\]/', '', $text);

        // 3. Fallback plain-text
        // Match `@<Nome>` precedido de início/whitespace/pontuação (não email).
        // Captura até 4 palavras (max ~60 chars) — suficiente pra nomes longos.
        preg_match_all('/(?:^|[\s,;:.!?\(\[])@([A-Za-zÀ-ÿ][A-Za-zÀ-ÿ\.\-\']*(?:\s+[A-Za-zÀ-ÿ][A-Za-zÀ-ÿ\.\-\']*){0,3})/u', $cleaned, $plain);

        if (!empty($plain[1])) {
            $users = $candidateUsers instanceof \Illuminate\Support\Collection
                ? $candidateUsers
                : collect($candidateUsers);

            // Se vazio, faz busca global limitada
            if ($users->isEmpty()) {
                $users = User::query()->select('id', 'name')->get();
            }

            foreach ($plain[1] as $candidate) {
                $userId = self::resolveByName($candidate, $users);
                if ($userId) $ids[$userId] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * Match longest-prefix do candidato contra a lista de users.
     * "Ricardo Oliveira Silva" tenta nessa ordem:
     *   "Ricardo Oliveira Silva" → "Ricardo Oliveira" → "Ricardo"
     *
     * 3 níveis de match (do mais estrito pro mais tolerante):
     *  1. Exato (normalizado: lowercase + sem acentos)
     *  2. Fuzzy: similar_text >= 90% (tolera typos pequenos tipo "OIiveira" vs "Oliveira")
     *  3. Prefixo único: SE só houver 1 user cujo nome começa com o candidato
     */
    private static function resolveByName(string $candidate, $users): ?int
    {
        $candidate = trim($candidate);
        $words = preg_split('/\s+/', $candidate);

        for ($len = count($words); $len >= 1; $len--) {
            $prefix = implode(' ', array_slice($words, 0, $len));
            $prefixNorm = self::normalize($prefix);

            // 1. Match exato
            foreach ($users as $u) {
                if (self::normalize($u->name) === $prefixNorm) {
                    return (int) $u->id;
                }
            }

            // 2. Fuzzy match (similar_text >= 90%)
            $bestId = null;
            $bestScore = 0;
            foreach ($users as $u) {
                $uNorm = self::normalize($u->name);
                similar_text($prefixNorm, $uNorm, $score);
                if ($score >= 90 && $score > $bestScore) {
                    $bestScore = $score;
                    $bestId = (int) $u->id;
                }
            }
            if ($bestId) return $bestId;

            // 3. Prefixo único — só dispara se EXATAMENTE 1 user matchear
            // Evita falso positivo (se houver vários "Pedro", não pega nenhum)
            $matches = [];
            foreach ($users as $u) {
                $uNorm = self::normalize($u->name);
                if (str_starts_with($uNorm, $prefixNorm . ' ') || $uNorm === $prefixNorm) {
                    $matches[] = (int) $u->id;
                }
            }
            if (count($matches) === 1) return $matches[0];
        }
        return null;
    }

    /** Normaliza pra match case-insensitive + sem acentos. */
    private static function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        $s = preg_replace('/\s+/u', ' ', $s);
        return iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    }
}
