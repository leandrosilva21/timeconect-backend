<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectStage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProjectStageController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        $stages = $project->stages()
            ->with('responsible:id,name,email')
            ->withCount(['deliveries', 'deliveries as deliveries_done_count' => function ($q) {
                $q->where('status', \App\Models\StageDelivery::STATUS_DONE);
            }])
            ->withSum('deliveries as deliveries_hours_planned_sum', 'hours_planned')
            ->withSum(['deliveries as deliveries_hours_planned_done_sum' => function ($q) {
                $q->where('status', \App\Models\StageDelivery::STATUS_DONE);
            }], 'hours_planned')
            ->orderBy('order_index')
            ->get();

        // Progresso ponderado (earned value): Σ horas planejadas de entregas DONE / Σ horas planejadas TOTAL.
        // Fallback para deliveries_done / deliveries_count quando nenhuma entrega tem hours_planned.
        // Ver ADR 0002.
        $stages->each(function ($s) {
            $totalHours = (float) ($s->deliveries_hours_planned_sum ?? 0);
            $doneHours  = (float) ($s->deliveries_hours_planned_done_sum ?? 0);
            if ($totalHours > 0) {
                $s->progress_pct = round(($doneHours / $totalHours) * 100, 2);
            } elseif (($s->deliveries_count ?? 0) > 0) {
                $s->progress_pct = round((($s->deliveries_done_count ?? 0) / $s->deliveries_count) * 100, 2);
            } else {
                $s->progress_pct = 0.0;
            }
        });

        return response()->json(['items' => $stages]);
    }

    public function show(ProjectStage $stage): JsonResponse
    {
        $stage->load(['responsible:id,name,email', 'deliveries']);

        return response()->json($stage);
    }

    public function store(Request $request, Project $project): JsonResponse
    {

        $data = $request->validate([
            'name'                => 'required|string|max:100',
            'responsible_user_id' => 'nullable|exists:users,id',
            'hours_planned'       => 'nullable|numeric|min:0',
            'status'              => ['nullable', Rule::in(ProjectStage::STATUSES)],
            'expected_end_date'   => 'nullable|date',
        ]);

        $data['project_id'] = $project->id;
        $data['order_index'] = (int) $project->stages()->max('order_index') + 1;

        $stage = ProjectStage::create($data);

        return response()->json($stage->load('responsible:id,name,email'), 201);
    }

    public function update(Request $request, ProjectStage $stage): JsonResponse
    {

        $data = $request->validate([
            'name'                => 'sometimes|string|max:100',
            'responsible_user_id' => 'nullable|exists:users,id',
            'hours_planned'       => 'sometimes|numeric|min:0',
            'status'              => ['sometimes', Rule::in(ProjectStage::STATUSES)],
            'expected_end_date'   => 'nullable|date',
        ]);

        $stage->update($data);

        return response()->json($stage->fresh()->load('responsible:id,name,email'));
    }

    public function destroy(ProjectStage $stage): JsonResponse
    {
        $stage->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Reordena etapas de um projeto. Payload: { stage_ids: [3, 1, 2] }
     */
    public function reorder(Request $request, Project $project): JsonResponse
    {

        $data = $request->validate([
            'stage_ids'   => 'required|array|min:1',
            'stage_ids.*' => 'integer|exists:project_stages,id',
        ]);

        DB::transaction(function () use ($data, $project) {
            foreach ($data['stage_ids'] as $index => $id) {
                ProjectStage::where('id', $id)
                    ->where('project_id', $project->id)
                    ->update(['order_index' => $index]);
            }
        });

        return response()->json(['reordered' => true]);
    }
}
