<?php

namespace App\Console\Commands;

use App\Models\OperationalFeed;
use App\Services\Bot\NotificationEngine;
use Illuminate\Console\Command;

class InboxBackfillFromFeedCommand extends Command
{
    protected $signature = 'inbox:backfill-from-feed
                            {--severity-min=high : Severidade mínima a entregar}
                            {--limit=200         : Máximo de feeds a processar}';

    protected $description = 'Reentrega feeds existentes para o Inbox (útil após instalação inicial do BOT).';

    public function handle(NotificationEngine $engine): int
    {
        $severityMin = $this->option('severity-min');
        $limit       = (int) $this->option('limit');

        $severityOrder = ['info' => 0, 'low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];
        $minLevel = $severityOrder[$severityMin] ?? 3;
        $allowed  = array_keys(array_filter($severityOrder, fn ($v) => $v >= $minLevel));

        $feeds = OperationalFeed::query()
            ->whereIn('severity', $allowed)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        $this->info("Backfill: {$feeds->count()} feeds com severity ≥ {$severityMin}");

        $totalDelivered = 0;
        $feeds->reverse()->each(function ($feed) use ($engine, &$totalDelivered) {
            $totalDelivered += $engine->routeFeedToInbox($feed);
        });

        $this->newLine();
        $this->info("✅ {$totalDelivered} mensagens entregues no inbox.");

        return self::SUCCESS;
    }
}
