<?php

namespace App\Http\Controllers;

use App\Models\StageDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeliveryEventController extends Controller
{
    /**
     * Timeline da entrega — eventos automáticos (status, reassign, completion, hours).
     * Mais recentes primeiro. Suporta paginação simples via `limit`.
     */
    public function index(StageDelivery $delivery, Request $request): JsonResponse
    {

        $limit = min((int) $request->get('limit', 50), 200);

        $events = $delivery->events()
            ->with('actor:id,name,email')
            ->limit($limit)
            ->get();

        return response()->json(['items' => $events]);
    }
}
