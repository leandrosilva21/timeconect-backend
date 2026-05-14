<?php

namespace App\Services\Bot;

use App\Models\BotConfig;
use App\Models\Message;
use Illuminate\Support\Carbon;

/**
 * AntiNoiseService — fundação de cooldown/dedupe/agrupamento.
 *
 * IMPORTANTE: nesta versão o método `shouldDeliver` sempre retorna true.
 * O hook está plugado no NotificationEngine para que, quando o motor
 * real for ativado, a entrega passe pelo filtro sem alterar contrato.
 *
 * Próxima fase: implementar regras reais usando `cooldown_minutes`
 * e `dedupe_window_minutes` do `bot_configs` + `cooldown_minutes` do
 * agent / regra.
 */
class AntiNoiseService
{
    public function shouldDeliver(string $dedupeKey, ?int $customerId = null): bool
    {
        // STUB: passa tudo. Quando ativado, irá:
        // 1. consultar bot_configs.dedupe_window_minutes
        // 2. checar `messages` recentes com mesma metadata.dedupe_key
        // 3. retornar false se janela ainda ativa
        return true;
    }

    public function nextAllowedAt(string $dedupeKey, ?int $customerId = null): ?Carbon
    {
        $config = BotConfig::singleton();
        $window = (int) ($config->dedupe_window_minutes ?? 60);

        $last = Message::query()
            ->whereJsonContains('metadata->dedupe_key', $dedupeKey)
            ->latest('created_at')
            ->first();

        if (! $last) {
            return null;
        }

        return $last->created_at->copy()->addMinutes($window);
    }
}
