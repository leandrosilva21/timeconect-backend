<?php

namespace App\Http\Controllers;

use App\Enums\FeedEventType;
use App\Enums\FeedSeverity;
use App\Enums\FeedSource;
use App\Http\Resources\OperationalFeedResource;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\OperationalFeed;
use App\Models\Project;
use App\Services\OperationalFeed\OperationalFeedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OperationalFeedController extends Controller
{
    public function __construct(protected OperationalFeedService $service)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'customer_id' => 'nullable|integer|exists:customers,id',
            'contract_id' => 'nullable|integer|exists:contracts,id',
            'project_id'  => 'nullable|integer|exists:projects,id',
            'severity'    => 'nullable|string',
            'source'      => 'nullable|string',
            'event_type'  => 'nullable|string',
            'per_page'    => 'nullable|integer|min:1|max:100',
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);
        unset($validated['per_page']);

        $paginated = $this->service->list($validated, $perPage);

        return OperationalFeedResource::collection($paginated);
    }

    public function show(int $id): OperationalFeedResource
    {
        $feed = OperationalFeed::with([
            'customer:id,name',
            'contract:id',
            'project:id,name',
            'creator:id,name',
        ])->findOrFail($id);

        return new OperationalFeedResource($feed);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => 'nullable|integer|exists:customers,id',
            'contract_id' => 'nullable|integer|exists:contracts,id',
            'project_id'  => 'nullable|integer|exists:projects,id',
            'event_type'  => 'required|string',
            'severity'    => 'required|string',
            'title'       => 'required|string|max:180',
            'message'     => 'required|string',
            'metadata'    => 'nullable|array',
        ]);

        try {
            $eventType = FeedEventType::from($data['event_type']);
            $severity  = FeedSeverity::from($data['severity']);
        } catch (\ValueError $e) {
            return response()->json([
                'message' => 'event_type ou severity inválido.',
                'error'   => $e->getMessage(),
            ], 422);
        }

        $feed = $this->service->record(
            eventType: $eventType,
            severity: $severity,
            title: $data['title'],
            message: $data['message'],
            source: FeedSource::Manual,
            customer: isset($data['customer_id']) ? Customer::find($data['customer_id']) : null,
            contract: isset($data['contract_id']) ? Contract::find($data['contract_id']) : null,
            project: isset($data['project_id']) ? Project::find($data['project_id']) : null,
            metadata: $data['metadata'] ?? [],
            createdBy: $request->user()?->id,
        );

        return (new OperationalFeedResource($feed))
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(int $id): JsonResponse
    {
        $feed = OperationalFeed::findOrFail($id);
        $feed->delete();

        return response()->json(['deleted' => true]);
    }
}
