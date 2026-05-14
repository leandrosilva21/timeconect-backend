<?php

namespace App\Console\Commands;

use App\Services\OperationalFeed\HealthEngineService;
use Illuminate\Console\Command;

class HealthScanCommand extends Command
{
    protected $signature = 'health:scan
                            {--days=90 : Janela de análise em dias (default 90)}';

    protected $description = 'Varre customers, classifica risco e publica eventos no Operational Feed.';

    public function handle(HealthEngineService $engine): int
    {
        $days = (int) $this->option('days');
        $since = now()->subDays($days);

        $this->info("Health Engine — janela de {$days} dias (desde {$since->toDateString()})");
        $start = microtime(true);

        $report = $engine->scanAll($since);

        $elapsed = round(microtime(true) - $start, 2);

        $this->newLine();
        $this->info("✅ Scan concluído em {$elapsed}s");
        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Customers escaneados',     $report['customers_scanned']],
                ['Em RISCO (critical)',      $report['customers_at_risk']],
                ['Oportunidade de expansão', $report['customers_expansion']],
                ['Feeds criados (eventos)',  $report['feeds_created']],
                ['Erros',                    $report['errors']],
            ]
        );

        return $report['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
