<?php

namespace App\Services\OperationalFeed;

use App\Enums\FeedEventType;
use App\Enums\FeedSeverity;
use App\Enums\FeedSource;
use App\Events\OperationalFeedCreated;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\OperationalFeed;
use App\Models\Project;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class OperationalFeedService
{
    public function record(
        FeedEventType $eventType,
        FeedSeverity $severity,
        string $title,
        string $message,
        FeedSource $source = FeedSource::System,
        ?Customer $customer = null,
        ?Contract $contract = null,
        ?Project $project = null,
        array $metadata = [],
        ?int $createdBy = null,
    ): OperationalFeed {
        $dedupeKey = $metadata['dedupe_key'] ?? null;

        if ($dedupeKey) {
            $existing = OperationalFeed::query()
                ->where('event_type', $eventType->value)
                ->where('metadata->dedupe_key', $dedupeKey)
                ->where('created_at', '>=', now()->subHours(24))
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        $resolvedCustomerId = $customer?->id
            ?? ($contract?->customer_id ?? null)
            ?? ($project?->customer_id ?? null);

        $feed = DB::transaction(function () use (
            $eventType,
            $severity,
            $title,
            $message,
            $source,
            $resolvedCustomerId,
            $contract,
            $project,
            $metadata,
            $createdBy
        ) {
            return OperationalFeed::create([
                'customer_id' => $resolvedCustomerId,
                'contract_id' => $contract?->id,
                'project_id'  => $project?->id,
                'source'      => $source,
                'event_type'  => $eventType,
                'severity'    => $severity,
                'title'       => $title,
                'message'     => $message,
                'metadata'    => $metadata ?: null,
                'created_by'  => $createdBy,
            ]);
        });

        event(new OperationalFeedCreated($feed));

        return $feed;
    }

    public function list(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $q = OperationalFeed::query()
            ->with(['customer:id,name', 'contract:id', 'project:id,name', 'creator:id,name'])
            ->recent();

        if (!empty($filters['customer_id'])) {
            $q->forCustomer((int) $filters['customer_id']);
        }
        if (!empty($filters['contract_id'])) {
            $q->forContract((int) $filters['contract_id']);
        }
        if (!empty($filters['project_id'])) {
            $q->forProject((int) $filters['project_id']);
        }
        if (!empty($filters['severity'])) {
            $q->bySeverity(is_array($filters['severity']) ? $filters['severity'] : explode(',', $filters['severity']));
        }
        if (!empty($filters['source'])) {
            $q->bySource(is_array($filters['source']) ? $filters['source'] : explode(',', $filters['source']));
        }
        if (!empty($filters['event_type'])) {
            $values = is_array($filters['event_type']) ? $filters['event_type'] : explode(',', $filters['event_type']);
            $q->whereIn('event_type', $values);
        }

        return $q->paginate(min($perPage, 100));
    }

    public static function dedupeKey(string ...$parts): string
    {
        return sha1(implode('|', $parts));
    }
}
