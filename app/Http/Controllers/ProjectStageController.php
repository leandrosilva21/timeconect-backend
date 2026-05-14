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
            ->withCount([
                'deliveries',
                'deliveries as deliveries_done_count' => function ($q) {
                    $q->where('status', \App\Models\StageDelivery::STATUS_DONE);
                },
                'deliveries as deliveries_in_progress_count' => function ($q) {
                    $q->where('status', \App\Models\StageDelivery::STATUS_IN_PROGRESS);
                },
                'deliveries as deliveries_waiting_client_count' => function ($q) {
                    $q->where('status', \App\Models\StageDelivery::STATUS_WAITING_CLIENT);
                },
                'deliveries as deliveries_review_count' => function ($q) {
                    $q->where('status', \App\Models\StageDelivery::STATUS_REVIEW);
                },
            ])
            ->withSum('deliveries as deliveries_hours_planned_sum', 'hours_planned')
            ->withSum(['deliveries as deliveries_hours_planned_done_sum' => function ($q) {
                $q->where('status', \App\Models\StageDelivery::STATUS_DONE);
            }], 'hours_planned')
            ->orderBy('order_index')
            ->get();

        // Pré-busca overrun por etapa em 1 query agregada (evita N+1)
        $overrunByStage = $this->computeTeamOverrunByStage($stages->pluck('id')->all());

        $stages->each(function ($s) use ($overrunByStage) {
            // Earned value (ADR 0002)
            $totalHours = (float) ($s->deliveries_hours_planned_sum ?? 0);
            $doneHours  = (float) ($s->deliveries_hours_planned_done_sum ?? 0);
            if ($totalHours > 0) {
                $s->progress_pct = round(($doneHours / $totalHours) * 100, 2);
            } elseif (($s->deliveries_count ?? 0) > 0) {
                $s->progress_pct = round((($s->deliveries_done_count ?? 0) / $s->deliveries_count) * 100, 2);
            } else {
                $s->progress_pct = 0.0;
            }

            // Status macro derivado das entregas. Briefing:
            // tudo concluído → concluida; ≥1 aguardando cliente → bloqueada;
            // ≥1 em homologação → homologacao; ≥1 em execução → execucao;
            // resto → planejamento.
            $total = (int) ($s->deliveries_count ?? 0);
            $done  = (int) ($s->deliveries_done_count ?? 0);

            if ($total === 0) {
                $s->derived_status = 'planejamento';
            } elseif ($total === $done) {
                $s->derived_status = 'concluida';
            } elseif (($s->deliveries_waiting_client_count ?? 0) > 0) {
                $s->derived_status = 'bloqueada';
            } elseif (($s->deliveries_review_count ?? 0) > 0) {
                $s->derived_status = 'homologacao';
            } elseif (($s->deliveries_in_progress_count ?? 0) > 0) {
                $s->derived_status = 'execucao';
            } else {
                $s->derived_status = 'planejamento';
            }

            // 4º dot: equipe (red se ≥1 alocação estourada).
            $s->team_overrun_count = (int) ($overrunByStage[$s->id] ?? 0);
        });

        return response()->json(['items' => $stages]);
    }

    /**
     * Conta quantas alocações têm actual > planned por etapa.
     * Conta apenas timesheets approved + released (consistente com ADR 0003).
     * Retorna map [stage_id => overrun_count].
     */
    private function computeTeamOverrunByStage(array $stageIds): array
    {
        if (empty($stageIds)) return [];

        // Subquery: actual por (stage_id, user_id) — apenas approved/released, não soft-deletado
        $tsSum = DB::table('timesheets')
            ->whereIn('stage_id', $stageIds)
            ->whereNull('deleted_at')
            ->whereIn('status', [\App\Models\Timesheet::STATUS_APPROVED, \App\Models\Timesheet::STATUS_RELEASED])
            ->groupBy('user_id', 'stage_id')
            ->selectRaw('user_id, stage_id, COALESCE(SUM(effort_minutes), 0) / 60.0 AS actual_hours');

        // Join allocations com a soma, filtra estouradas, agrupa por etapa
        $rows = DB::table('stage_allocations as a')
            ->leftJoinSub($tsSum, 'ts', function ($join) {
                $join->on('ts.user_id', '=', 'a.user_id')
                    ->on('ts.stage_id', '=', 'a.stage_id');
            })
            ->whereIn('a.stage_id', $stageIds)
            ->whereRaw('COALESCE(ts.actual_hours, 0) > a.planned_hours')
            ->groupBy('a.stage_id')
            ->selectRaw('a.stage_id, COUNT(*) AS overrun_count')
            ->get();

        return $rows->pluck('overrun_count', 'stage_id')
            ->mapWithKeys(fn ($v, $k) => [(int) $k => (int) $v])
            ->toArray();
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

        // Bloqueia se a nova etapa fizer SUM(stages.planned) > project.sold_hours
        $project->loadMissing('serviceType');
        if ($project->isOperational() && isset($data['hours_planned']) && $data['hours_planned'] > 0) {
            $err = $this->guardProjectCapacity($project, (float) $data['hours_planned']);
            if ($err !== null) return $err;
        }

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

        // Se está aumentando hours_planned em projeto operacional, valida saldo
        $stage->loadMissing('project.serviceType');
        if (
            $stage->project?->isOperational()
            && isset($data['hours_planned'])
            && (float) $data['hours_planned'] > (float) $stage->hours_planned
        ) {
            $delta = (float) $data['hours_planned'] - (float) $stage->hours_planned;
            $err = $this->guardProjectCapacity($stage->project, $delta);
            if ($err !== null) return $err;
        }

        $stage->update($data);

        return response()->json($stage->fresh()->load('responsible:id,name,email'));
    }

    /**
     * Bloqueia se SUM(stages.planned_hours) + $delta > project.sold_hours.
     * Retorna 422 com mensagem padrão; ou null se está OK.
     */
    private function guardProjectCapacity(Project $project, float $delta): ?JsonResponse
    {
        $sold      = (float) ($project->sold_hours ?? 0);
        $allocated = (float) $project->stages()->sum('hours_planned');
        $available = $sold - $allocated;

        if ($delta > $available + 0.001) {
            return response()->json([
                'message' => 'Sem saldo disponível. Verifique com o coordenador.',
                'detail'  => sprintf(
                    'Tentativa de alocar %.1fh. Saldo do projeto: %.1fh (vendidas %.1fh, alocadas %.1fh).',
                    $delta, $available, $sold, $allocated
                ),
            ], 422);
        }
        return null;
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
