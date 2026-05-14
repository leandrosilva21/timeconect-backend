<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Services\Ai\AiInsightService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateAiInsightForCustomerJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;
    public int $timeout = 120;

    public function __construct(
        public readonly int $customerId,
        public readonly array $context = [],
    ) {
    }

    public function handle(AiInsightService $service): void
    {
        $customer = Customer::find($this->customerId);
        if (! $customer) {
            Log::warning('[AiInsightJob] customer não encontrado', ['customer_id' => $this->customerId]);
            return;
        }

        $feed = $service->generateForCustomer($customer, $this->context);

        if (! $feed) {
            Log::warning('[AiInsightJob] insight retornou null', [
                'customer_id' => $customer->id,
                'provider'    => $service->providerName(),
            ]);
            return;
        }

        Log::info('[AiInsightJob] insight registrado', [
            'customer_id' => $customer->id,
            'feed_id'     => $feed->id,
            'provider'    => $service->providerName(),
        ]);
    }
}
