<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\Ai\AiInsightService;
use Illuminate\Console\Command;

class TestAiInsightCommand extends Command
{
    protected $signature = 'ai:test-insight
                            {customer_id : ID do customer alvo}
                            {--no-persist : Apenas chama a IA, não grava OperationalFeed (não disponível ainda; sempre persiste por design)}';

    protected $description = 'Smoke test: chama AiInsightService->generateForCustomer() para validar provider + integração end-to-end.';

    public function handle(AiInsightService $service): int
    {
        $customerId = (int) $this->argument('customer_id');
        $customer = Customer::find($customerId);

        if (! $customer) {
            $this->error("Customer id={$customerId} não encontrado.");
            return self::FAILURE;
        }

        $this->info("Provider: {$service->providerName()}");
        $this->info("Customer: {$customer->name} (id={$customer->id})");
        $this->line('Disparando geração de insight…');

        $start = microtime(true);
        $feed = $service->generateForCustomer($customer, [
            'note' => 'Smoke test executado via php artisan ai:test-insight',
            'timestamp' => now()->toIso8601String(),
        ]);
        $elapsed = round(microtime(true) - $start, 2);

        if (! $feed) {
            $this->error("Insight retornou null. Veja storage/logs/laravel.log para detalhes.");
            return self::FAILURE;
        }

        $this->newLine();
        $this->info("✅ Insight criado em {$elapsed}s (feed id={$feed->id})");
        $this->table(
            ['Campo', 'Valor'],
            [
                ['source',     $feed->source->value],
                ['event_type', $feed->event_type->value],
                ['severity',   $feed->severity->value],
                ['title',      $feed->title],
                ['provider',   $feed->metadata['provider'] ?? '-'],
                ['dedupe_key', $feed->metadata['dedupe_key'] ?? '-'],
                ['created_at', $feed->created_at?->toIso8601String()],
            ]
        );

        $this->newLine();
        $this->line('───── MENSAGEM IA ─────');
        $this->line($feed->message);
        $this->line('───────────────────────');

        return self::SUCCESS;
    }
}
