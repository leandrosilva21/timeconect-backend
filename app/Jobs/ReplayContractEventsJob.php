<?php

namespace App\Jobs;

use App\Events\ContractEventCreated;
use App\Listeners\ContractEventListener;
use App\Models\ContractEvent;
use App\Models\ContractFlowSnapshot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReplayContractEventsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries   = 1;
    public $timeout = 300;

    public function __construct(
        public readonly int $contractId,
        public readonly int $fromSequence = 0,
        public readonly int $batchSize    = 200,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        try {
            // Resetar ponteiros para permitir reprocessamento
            DB::table('contract_flow_snapshots')
                ->where('contract_id', $this->contractId)
                ->update([
                    'last_event_id'      => null,
                    'last_sequence'      => 0,
                    'version'            => DB::raw('version + 1'),
                    'replay_in_progress' => true,
                ]);

            Cache::forget(ContractEventListener::cacheKey($this->contractId));

            $listener  = app(ContractEventListener::class);
            $processed = 0;

            ContractEvent::where('contract_id', $this->contractId)
                ->where('sequence_number', '>', $this->fromSequence)
                ->orderBy('sequence_number')
                ->chunk($this->batchSize, function ($events) use ($listener, &$processed) {
                    foreach ($events as $event) {
                        $listener->handle(new ContractEventCreated($event));
                        $processed++;
                    }
                });

            Log::info('ReplayContractEvents: concluído', [
                'contract_id'   => $this->contractId,
                'from_sequence' => $this->fromSequence,
                'processed'     => $processed,
            ]);
        } finally {
            DB::table('contract_flow_snapshots')
                ->where('contract_id', $this->contractId)
                ->update(['replay_in_progress' => false]);
        }
    }
}
