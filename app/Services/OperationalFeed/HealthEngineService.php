<?php

namespace App\Services\OperationalFeed;

use App\Enums\FeedEventType;
use App\Enums\FeedSeverity;
use App\Enums\FeedSource;
use App\Models\Customer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Health Engine — varre customers e gera eventos no Operational Feed
 * espelhando a lógica de classificação do n8n original (CS Multi-Agent).
 *
 * Origem dos sinais:
 * - tickets últimos 90 dias (movidesk_tickets)
 * - saldo de horas (sold_hours - sum(effort_minutes/60) dos timesheets em 90 dias)
 *
 * Classificação (espelha n8n):
 *   tickets > 10 OR saldo < 0       => RISCO    (severity critical)
 *   tickets > 5  OR saldo < 20      => ATENCAO  (severity high)
 *   tickets == 0 AND saldo > 50     => EXPANSAO (severity info)
 *   default                         => NORMAL   (sem evento)
 *
 * Eventos individuais (independentes da classificação geral):
 * - hour_overrun  quando saldo < 0
 * - ticket_spike  quando tickets > 10
 *
 * Dedupe key diário garante idempotência: dois scans no mesmo dia não duplicam.
 */
class HealthEngineService
{
    public function __construct(protected OperationalFeedService $feed)
    {
    }

    public function scanAll(?Carbon $since = null): array
    {
        $since ??= now()->subDays(90);

        $stats = $this->collectStats($since);

        $report = [
            'window_days'        => (int) $since->diffInDays(now()),
            'customers_scanned'  => 0,
            'customers_at_risk'  => 0,
            'customers_expansion'=> 0,
            'feeds_created'      => 0,
            'errors'             => 0,
        ];

        $today = now()->format('Y-m-d');

        foreach ($stats as $row) {
            try {
                $customer = Customer::find($row['customer_id']);
                if (! $customer) {
                    continue;
                }

                $report['customers_scanned']++;
                $classification = $this->classify($row);

                if ($classification === 'RISCO')    $report['customers_at_risk']++;
                if ($classification === 'EXPANSAO') $report['customers_expansion']++;

                $report['feeds_created'] += $this->recordEvents($customer, $row, $classification, $today);
            } catch (\Throwable $e) {
                $report['errors']++;
                Log::error('[HealthEngine] falha ao processar customer', [
                    'customer_id' => $row['customer_id'] ?? null,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        return $report;
    }

    /**
     * Faz 3 queries em batch (tickets, projects, timesheets) e retorna um array
     * de stats por customer. Bem mais barato que N+1.
     *
     * @return array<array{customer_id:int, tickets:int, contracted:float, consumed:float, balance:float}>
     */
    protected function collectStats(Carbon $since): array
    {
        $sinceStr = $since->toDateTimeString();

        $ticketCounts = DB::table('movidesk_tickets')
            ->where('created_date', '>=', $sinceStr)
            ->whereNotNull('customer_id')
            ->select('customer_id', DB::raw('count(*) as total'))
            ->groupBy('customer_id')
            ->pluck('total', 'customer_id');

        $contracted = DB::table('projects')
            ->whereNull('deleted_at')
            ->select('customer_id', DB::raw('COALESCE(SUM(sold_hours), 0) as hours'))
            ->groupBy('customer_id')
            ->pluck('hours', 'customer_id');

        $consumedMinutes = DB::table('timesheets as t')
            ->join('projects as p', 'p.id', '=', 't.project_id')
            ->whereNull('t.deleted_at')
            ->where('t.date', '>=', $since->toDateString())
            ->where('t.status', '!=', 'rejected')
            ->select('p.customer_id', DB::raw('COALESCE(SUM(t.effort_minutes), 0) as minutes'))
            ->groupBy('p.customer_id')
            ->pluck('minutes', 'p.customer_id');

        // União das chaves (customer_id) que aparecem em qualquer uma das 3 fontes
        $customerIds = collect($ticketCounts->keys())
            ->merge($contracted->keys())
            ->merge($consumedMinutes->keys())
            ->unique()
            ->values();

        $out = [];
        foreach ($customerIds as $id) {
            $tickets   = (int) ($ticketCounts[$id] ?? 0);
            $hours     = (float) ($contracted[$id] ?? 0);
            $consumed  = round(((float) ($consumedMinutes[$id] ?? 0)) / 60, 2);
            $balance   = round($hours - $consumed, 2);

            $out[] = [
                'customer_id' => (int) $id,
                'tickets'     => $tickets,
                'contracted'  => $hours,
                'consumed'    => $consumed,
                'balance'     => $balance,
            ];
        }

        return $out;
    }

    protected function classify(array $row): string
    {
        if ($row['tickets'] > 10 || $row['balance'] < 0) {
            return 'RISCO';
        }
        if ($row['tickets'] > 5 || $row['balance'] < 20) {
            return 'ATENCAO';
        }
        if ($row['tickets'] === 0 && $row['balance'] > 50) {
            return 'EXPANSAO';
        }
        return 'NORMAL';
    }

    /**
     * Grava feeds para um customer. Cada tipo tem seu próprio dedupe_key diário.
     */
    protected function recordEvents(Customer $customer, array $row, string $classification, string $today): int
    {
        $created = 0;

        if ($row['balance'] < 0) {
            $this->feed->record(
                eventType: FeedEventType::HourOverrun,
                severity: $row['balance'] < -20 ? FeedSeverity::High : FeedSeverity::Medium,
                title: "Estouro de horas — {$customer->name}",
                message: sprintf(
                    'Saldo de horas: %.1fh (contratadas %.1fh, consumidas %.1fh em 90 dias).',
                    $row['balance'],
                    $row['contracted'],
                    $row['consumed']
                ),
                source: FeedSource::HealthEngine,
                customer: $customer,
                metadata: [
                    'balance'    => $row['balance'],
                    'contracted' => $row['contracted'],
                    'consumed'   => $row['consumed'],
                    'dedupe_key' => OperationalFeedService::dedupeKey('hour_overrun', (string) $customer->id, $today),
                ],
            );
            $created++;
        }

        if ($row['tickets'] > 10) {
            $this->feed->record(
                eventType: FeedEventType::TicketSpike,
                severity: $row['tickets'] > 15 ? FeedSeverity::Critical : FeedSeverity::High,
                title: "Aumento de tickets — {$customer->name}",
                message: "{$row['tickets']} tickets nos últimos 90 dias (limiar 10).",
                source: FeedSource::HealthEngine,
                customer: $customer,
                metadata: [
                    'tickets'    => $row['tickets'],
                    'dedupe_key' => OperationalFeedService::dedupeKey('ticket_spike', (string) $customer->id, $today),
                ],
            );
            $created++;
        }

        if ($classification === 'RISCO') {
            $this->feed->record(
                eventType: FeedEventType::ChurnRisk,
                severity: FeedSeverity::Critical,
                title: "Risco de churn — {$customer->name}",
                message: sprintf(
                    'Cliente classificado como RISCO: %d tickets em 90 dias + saldo de %.1fh.',
                    $row['tickets'],
                    $row['balance']
                ),
                source: FeedSource::HealthEngine,
                customer: $customer,
                metadata: [
                    'tickets'        => $row['tickets'],
                    'balance'        => $row['balance'],
                    'classification' => 'RISCO',
                    'dedupe_key'     => OperationalFeedService::dedupeKey('churn_risk', (string) $customer->id, $today),
                ],
            );
            $created++;
        }

        if ($classification === 'EXPANSAO') {
            $this->feed->record(
                eventType: FeedEventType::ExpansionOpportunity,
                severity: FeedSeverity::Info,
                title: "Oportunidade de expansão — {$customer->name}",
                message: sprintf(
                    'Cliente sem chamados nos últimos 90 dias e com folga de %.1fh. Potencial de novos projetos.',
                    $row['balance']
                ),
                source: FeedSource::HealthEngine,
                customer: $customer,
                metadata: [
                    'tickets'        => $row['tickets'],
                    'balance'        => $row['balance'],
                    'classification' => 'EXPANSAO',
                    'dedupe_key'     => OperationalFeedService::dedupeKey('expansion', (string) $customer->id, $today),
                ],
            );
            $created++;
        }

        return $created;
    }
}
