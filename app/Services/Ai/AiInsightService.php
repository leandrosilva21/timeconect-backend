<?php

namespace App\Services\Ai;

use App\Enums\FeedEventType;
use App\Enums\FeedSeverity;
use App\Enums\FeedSource;
use App\Models\Customer;
use App\Models\OperationalFeed;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\OperationalFeed\OperationalFeedService;
use Illuminate\Support\Facades\Log;

class AiInsightService
{
    public function __construct(
        protected AiProvider $provider,
        protected OperationalFeedService $feed,
    ) {
    }

    /**
     * Gera um diagnóstico de IA para um Customer e registra como OperationalFeed.
     *
     * @param  array  $context  Payload pré-processado: tickets, saldo de horas, etc. Caller responsável pela coleta.
     */
    public function generateForCustomer(
        Customer $customer,
        array $context = [],
        FeedSeverity $severity = FeedSeverity::Info,
    ): ?OperationalFeed {
        $systemPrompt = <<<PROMPT
Você é um analista sênior de Customer Success da ERPSERV.
Analise os dados operacionais do cliente e devolva um diagnóstico estruturado em 4 seções (máx. 3 bullets cada):
- Diagnóstico: resumo da situação.
- Evidência: dados que comprovam.
- Impacto: consequência para o cliente.
- Direção: ações sugeridas.

Seja conciso, direto, em português brasileiro. Não invente dados que não estejam no contexto.
PROMPT;

        try {
            $text = $this->provider->generateInsight(
                prompt: "Cliente: {$customer->name}",
                context: $context,
                options: [
                    'system'      => $systemPrompt,
                    'max_tokens'  => 800,
                    'temperature' => 0.3,
                ],
            );
        } catch (\Throwable $e) {
            Log::error('🤖 [AiInsightService] Falha ao gerar insight', [
                'customer_id' => $customer->id,
                'provider'    => $this->provider->name(),
                'error'       => $e->getMessage(),
            ]);
            return null;
        }

        if (! $text) {
            return null;
        }

        return $this->feed->record(
            eventType: FeedEventType::AiSuggestion,
            severity: $severity,
            title: "Diagnóstico IA — {$customer->name}",
            message: $text,
            source: FeedSource::Ai,
            customer: $customer,
            metadata: [
                'provider'   => $this->provider->name(),
                'context'    => $context,
                'dedupe_key' => OperationalFeedService::dedupeKey(
                    'ai_customer',
                    (string) $customer->id,
                    now()->format('Y-m-d')
                ),
            ],
        );
    }

    public function providerName(): string
    {
        return $this->provider->name();
    }
}
