<?php

namespace App\Http\Controllers;

use App\Enums\PresenceStatus;
use App\Services\Inbox\PresenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PresenceController extends Controller
{
    public function __construct(protected PresenceService $service)
    {
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => 'nullable|string|in:online,away,offline',
        ]);

        $status = isset($data['status']) ? PresenceStatus::from($data['status']) : PresenceStatus::Online;
        $presence = $this->service->heartbeat($request->user(), $status);

        return response()->json([
            'status'       => $presence->status->value,
            'last_seen_at' => $presence->last_seen_at?->toIso8601String(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $userIds = $request->query('user_ids');
        $ids = is_string($userIds) ? array_filter(array_map('intval', explode(',', $userIds))) : [];

        return response()->json(['data' => $this->service->snapshot($ids)]);
    }
}
