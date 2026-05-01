<?php

namespace App\Jobs;

use App\Models\ContractEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CleanupContractEventsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries   = 1;
    public $timeout = 300;

    public function __construct(public readonly int $keepDays = 180) {}

    public function handle(): void
    {
        $deleted = ContractEvent::where('created_at', '<', now()->subDays($this->keepDays))->delete();

        if ($deleted > 0) {
            Log::info('CleanupContractEvents: eventos antigos removidos', [
                'deleted'   => $deleted,
                'keep_days' => $this->keepDays,
            ]);
        }
    }
}
