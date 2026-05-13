<?php

namespace App\Http\Controllers;

use App\Models\ProjectStage;
use App\Models\StageDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StageDeliveryController extends Controller
{
    public function index(ProjectStage $stage): JsonResponse
    {
        $deliveries = $stage->deliveries()
            ->with('responsible:id,name,email')
            ->withSum('timesheets as effort_minutes_sum', 'effort_minutes')
            ->orderBy('status')
            ->orderBy('order_index')
            ->get();

        return response()->json(['items' => $deliveries]);
    }

    public function show(StageDelivery $delivery): JsonResponse
    {
        $delivery->load(['responsible:id,name,email', 'stage:id,project_id,name']);

        return response()->json($delivery);
    }

    public function store(Request $request, ProjectStage $stage): JsonResponse
    {

        $data = $request->validate([
            'title'               => 'required|string|max:200',
            'description'         => 'nullable|string',
            'responsible_user_id' => 'nullable|exists:users,id',
            'hours_planned'       => 'nullable|numeric|min:0',
            'priority'            => ['nullable', Rule::in(StageDelivery::PRIORITIES)],
            'status'              => ['nullable', Rule::in(StageDelivery::STATUSES)],
            'due_date'            => 'nullable|date',
        ]);

        $data['stage_id'] = $stage->id;
        $data['order_index'] = (int) $stage->deliveries()
            ->where('status', $data['status'] ?? StageDelivery::STATUS_BACKLOG)
            ->max('order_index') + 1;

        $delivery = StageDelivery::create($data);

        return response()->json($delivery->load('responsible:id,name,email'), 201);
    }

    public function update(Request $request, StageDelivery $delivery): JsonResponse
    {

        $data = $request->validate([
            'title'               => 'sometimes|string|max:200',
            'description'         => 'nullable|string',
            'responsible_user_id' => 'nullable|exists:users,id',
            'hours_planned'       => 'sometimes|numeric|min:0',
            'priority'            => ['sometimes', Rule::in(StageDelivery::PRIORITIES)],
            'status'              => ['sometimes', Rule::in(StageDelivery::STATUSES)],
            'due_date'            => 'nullable|date',
        ]);

        $delivery->update($data);

        return response()->json($delivery->fresh()->load('responsible:id,name,email'));
    }

    public function destroy(StageDelivery $delivery): JsonResponse
    {
        $delivery->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Move uma entrega: muda status (coluna) e/ou reposiciona dentro da coluna.
     * Payload: { status: 'in_progress', order_index: 2, sibling_ids?: [4,5,7] }
     *
     * Se sibling_ids vier, reordena todas as entregas da nova coluna na ordem informada.
     */
    public function move(Request $request, StageDelivery $delivery): JsonResponse
    {

        $data = $request->validate([
            'status'        => ['required', Rule::in(StageDelivery::STATUSES)],
            'order_index'   => 'sometimes|integer|min:0',
            'sibling_ids'   => 'sometimes|array',
            'sibling_ids.*' => 'integer|exists:stage_deliveries,id',
        ]);

        DB::transaction(function () use ($data, $delivery) {
            $delivery->update([
                'status'      => $data['status'],
                'order_index' => $data['order_index'] ?? $delivery->order_index,
            ]);

            if (!empty($data['sibling_ids'])) {
                foreach ($data['sibling_ids'] as $index => $id) {
                    StageDelivery::where('id', $id)
                        ->where('stage_id', $delivery->stage_id)
                        ->update(['order_index' => $index]);
                }
            }
        });

        return response()->json($delivery->fresh());
    }
}
