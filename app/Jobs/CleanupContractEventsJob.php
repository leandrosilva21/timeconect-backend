<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CleanupContractEventsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $queue   = 'low';
    public $tries   = 1;
    public $timeout = 300;

    public function __construct(public readonly int $keepDays = 180) {}

    public function handle(): void
    {
        $cutoff = now()->subDays($this->keepDays)->toDateTimeString();

        // Arquivar antes de deletar — preserva histórico sem FK constraints
        $archived = DB::statement("
            INSERT INTO contract_events_archive
                (id, contract_id, sequence_number, event_type, field,
                 from_value, to_value, triggered_by, meta, created_at, updated_at)
            SELECT id, contract_id, sequence_number, event_type, field,
                   from_value, to_value, triggered_by, meta, created_at, updated_at
            FROM contract_events
            WHERE created_at < ?
            ON CONFLICT (id) DO NOTHING
        ", [$cutoff]);

        $deleted = DB::table('contract_events')->where('created_at', '<', $cutoff)->delete();

        if ($deleted > 0) {
            Log::info('CleanupContractEvents: arquivados e removidos', [
                'archived'  => $deleted,
                'deleted'   => $deleted,
                'keep_days' => $this->keepDays,
                'cutoff'    => $cutoff,
            ]);
        }
    }
}
