<?php

namespace App\Console\Commands;

use App\Enums\FeedEventType;
use App\Jobs\GenerateAiInsightForCustomerJob;
use App\Models\OperationalFeed;
use Illuminate\Console\Command;

class AiScanCustomersAtRiskCommand extends Command
{
    protected $signature = 'ai:scan-customers-at-risk
                            {--days=7  : Janela em dias para considerar customers com eventos recentes de risco/atencao (default 7)}
                            {--max=20  : Máximo de customers a processar (proteção de custo)}
                            {--sync    : Roda síncrono (sem queue) — útil em dev local}';

    protected $description = 'Identifica customers com eventos recentes de risco e dispara AiInsightService (job) para cada.';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $max  = (int) $this->option('max');
        $sync = (bool) $this->option('sync');

        $this->info("Procurando customers com eventos críticos/altos nos últimos {$days} dias…");

        $customerIds = OperationalFeed::query()
            ->whereNotNull('customer_id')
            ->whereIn('severity', ['critical', 'high'])
            ->whereIn('event_type', [
                FeedEventType::ChurnRisk->value,
                FeedEventType::HourOverrun->value,
                FeedEventType::TicketSpike->value,
                FeedEventType::SlaBreach->value,
            ])
            ->where('created_at', '>=', now()->subDays($days))
            ->groupBy('customer_id')
            ->orderByRaw('count(*) DESC')
            ->limit($max)
            ->pluck('customer_id')
            ->all();

        $count = count($customerIds);
        $this->info("Encontrados {$count} customers em risco. Disparando jobs…");

        if ($count === 0) {
            return self::SUCCESS;
        }

        foreach ($customerIds as $id) {
            // Anexa contexto leve com as métricas agregadas dos últimos 7 dias
            $context = $this->buildContext($id, $days);
            $job = new GenerateAiInsightForCustomerJob((int) $id, $context);

            if ($sync) {
                $job->handle(app(\App\Services\Ai\AiInsightService::class));
                $this->line("  → customer {$id} processado sync");
            } else {
                dispatch($job);
                $this->line("  → customer {$id} enfileirado");
            }
        }

        $this->newLine();
        $this->info("✅ {$count} jobs " . ($sync ? 'executados' : 'enfileirados') . ".");

        return self::SUCCESS;
    }

    /**
     * Constrói contexto numérico dos últimos N dias com os feeds do customer.
     * Mantém pequeno propositalmente — IA não precisa do payload inteiro.
     */
    private function buildContext(int $customerId, int $days): array
    {
        $since = now()->subDays($days);

        $feeds = OperationalFeed::query()
            ->where('customer_id', $customerId)
            ->where('created_at', '>=', $since)
            ->get(['event_type', 'severity', 'metadata', 'created_at']);

        $byType = [];
        foreach ($feeds as $f) {
            $type = $f->event_type->value;
            $byType[$type] = ($byType[$type] ?? 0) + 1;
        }

        // Últimas 3 metadados para contexto (com balance/tickets se houver)
        $recent = $feeds->take(3)->map(fn ($f) => [
            'event_type' => $f->event_type->value,
            'severity'   => $f->severity->value,
            'created_at' => $f->created_at?->toIso8601String(),
            'metadata'   => $f->metadata,
        ])->all();

        return [
            'window_days'     => $days,
            'events_by_type'  => $byType,
            'recent_events'   => $recent,
        ];
    }
}
