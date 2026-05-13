<?php

namespace App\Http\Controllers;

use App\Models\ConsultantSkill;
use App\Models\CriticalSkill;
use App\Models\Project;
use App\Models\ProjectRequiredSkill;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GapController extends Controller
{
    /**
     * Gaps críticos de um consultor.
     * Retorna critical_skills onde o consultor:
     *  - missing: não tem registro em consultant_skills, OU
     *  - below:   tem registro mas level.weight < min_level.weight
     */
    public function consultantGaps(int $consultantId): JsonResponse
    {
        User::findOrFail($consultantId);

        $rows = DB::table('critical_skills as cs')
            ->join('skills as sk', 'sk.id', '=', 'cs.skill_id')
            ->join('skill_levels as req', 'req.id', '=', 'cs.min_level_id')
            ->leftJoin('consultant_skills as ucs', function ($join) use ($consultantId) {
                $join->on('ucs.skill_id', '=', 'cs.skill_id')
                     ->where('ucs.consultant_id', '=', $consultantId);
            })
            ->leftJoin('skill_levels as actual', 'actual.id', '=', 'ucs.level_id')
            ->where(function ($q) {
                $q->whereNull('actual.weight')
                  ->orWhereColumn('actual.weight', '<', 'req.weight');
            })
            ->select([
                'cs.id as critical_skill_id',
                'cs.context',
                'sk.id as skill_id',
                'sk.name as skill_name',
                'sk.category as skill_category',
                'req.id as required_level_id',
                'req.name as required_level_name',
                'req.weight as required_level_weight',
                'actual.id as actual_level_id',
                'actual.name as actual_level_name',
                'actual.weight as actual_level_weight',
            ])
            ->orderBy('sk.category')
            ->orderBy('sk.name')
            ->get();

        $gaps = $rows->map(fn($r) => [
            'skill' => [
                'id'       => (int) $r->skill_id,
                'name'     => $r->skill_name,
                'category' => $r->skill_category,
            ],
            'context'        => $r->context,
            'required_level' => [
                'id'     => (int) $r->required_level_id,
                'name'   => $r->required_level_name,
                'weight' => (int) $r->required_level_weight,
            ],
            'actual_level' => $r->actual_level_id ? [
                'id'     => (int) $r->actual_level_id,
                'name'   => $r->actual_level_name,
                'weight' => (int) $r->actual_level_weight,
            ] : null,
            'type' => $r->actual_level_id === null ? 'missing' : 'below',
        ]);

        return response()->json([
            'consultant_id' => $consultantId,
            'total'         => $gaps->count(),
            'gaps'          => $gaps,
        ]);
    }

    /**
     * Cobertura de skills de um projeto.
     */
    public function projectGaps(int $projectId): JsonResponse
    {
        $project = Project::findOrFail($projectId);

        $consultantIds = DB::table('project_consultants')
            ->where('project_id', $projectId)
            ->pluck('user_id');

        $consultantsById = User::whereIn('id', $consultantIds)
            ->pluck('name', 'id');

        $required = ProjectRequiredSkill::with(['skill:id,name,category', 'minLevel:id,name,weight'])
            ->where('project_id', $projectId)
            ->get();

        $skillIds = $required->pluck('skill_id');
        $consultantSkills = ConsultantSkill::with('level:id,name,weight')
            ->whereIn('consultant_id', $consultantIds)
            ->whereIn('skill_id', $skillIds)
            ->get()
            ->groupBy(['skill_id', 'consultant_id']);

        $skills = $required->map(function ($prs) use ($consultantIds, $consultantsById, $consultantSkills) {
            $reqWeight = $prs->minLevel->weight;
            $skillBucket = $consultantSkills->get($prs->skill_id, collect());

            $covering = [];
            $missing  = [];
            foreach ($consultantIds as $cid) {
                $name = $consultantsById->get($cid);
                $bucket = $skillBucket->get($cid);
                $cs = $bucket ? $bucket->first() : null;
                if ($cs && $cs->level && $cs->level->weight >= $reqWeight) {
                    $covering[] = [
                        'consultant_id' => (int) $cid,
                        'name'          => $name,
                        'level'         => $cs->level->name,
                    ];
                } else {
                    $missing[] = [
                        'consultant_id' => (int) $cid,
                        'name'          => $name,
                        'actual_level'  => $cs && $cs->level ? $cs->level->name : null,
                        'type'          => $cs ? 'below' : 'missing',
                    ];
                }
            }

            return [
                'skill' => [
                    'id'       => (int) $prs->skill->id,
                    'name'     => $prs->skill->name,
                    'category' => $prs->skill->category,
                ],
                'required_level' => [
                    'id'     => (int) $prs->minLevel->id,
                    'name'   => $prs->minLevel->name,
                    'weight' => (int) $prs->minLevel->weight,
                ],
                'consultants_total'    => $consultantIds->count(),
                'consultants_covering' => count($covering),
                'covering'             => $covering,
                'missing'              => $missing,
            ];
        });

        return response()->json([
            'project' => [
                'id'                => $project->id,
                'name'              => $project->name,
                'consultants_count' => $consultantIds->count(),
            ],
            'required_skills' => $skills,
        ]);
    }

    public function storeCriticalSkill(Request $request): JsonResponse
    {
        $data = $request->validate([
            'skill_id'     => 'required|exists:skills,id',
            'min_level_id' => 'required|exists:skill_levels,id',
            'context'      => 'nullable|string|max:120',
        ]);

        $cs = CriticalSkill::updateOrCreate(
            ['skill_id' => $data['skill_id'], 'context' => $data['context'] ?? null],
            ['min_level_id' => $data['min_level_id']]
        );

        return response()->json($cs->load(['skill', 'minLevel']), 201);
    }

    public function storeProjectRequiredSkill(Request $request, int $projectId): JsonResponse
    {
        Project::findOrFail($projectId);

        $data = $request->validate([
            'skill_id'     => 'required|exists:skills,id',
            'min_level_id' => 'required|exists:skill_levels,id',
        ]);

        $prs = ProjectRequiredSkill::updateOrCreate(
            ['project_id' => $projectId, 'skill_id' => $data['skill_id']],
            ['min_level_id' => $data['min_level_id']]
        );

        return response()->json($prs->load(['skill', 'minLevel']), 201);
    }

    /**
     * Top 10 consultores recomendados para um projeto.
     *
     * Score por skill (base):
     *   actual >= required → 1.0
     *   actual <  required → actual/required
     *   sem skill          → -0.5
     * Bonus por skill (over-qualification):
     *   actual > required  → (actual - required) * 0.1
     *   senão              → 0
     * Disponibilidade:
     *   (capacity_hours - allocated_hours) / capacity_hours  (clamp [0,1])
     * Score final:
     *   (base_avg + bonus_avg) * disponibilidade
     *
     * Filtros:
     *  - exclui já alocados ao projeto
     *  - score mínimo 0.3
     *  - candidates ocultados se houver internal com score >= 0.8
     */
    public function recommendations(int $projectId): JsonResponse
    {
        $project = Project::findOrFail($projectId);

        $required = DB::table('project_required_skills as prs')
            ->join('skills as s', 's.id', '=', 'prs.skill_id')
            ->join('skill_levels as sl', 'sl.id', '=', 'prs.min_level_id')
            ->where('prs.project_id', $projectId)
            ->select('prs.skill_id', 's.name as skill_name', 's.category as skill_category',
                     'sl.id as level_id', 'sl.name as level_name', 'sl.weight as req_weight')
            ->get();

        if ($required->isEmpty()) {
            return response()->json([
                'project'         => ['id' => $project->id, 'name' => $project->name],
                'recommendations' => [],
                'message'         => 'Projeto não tem skills exigidas cadastradas.',
            ]);
        }

        $skillIds   = $required->pluck('skill_id');
        $reqByGroup = $required->keyBy('skill_id');

        // Exclui consultores já alocados ao projeto
        $allocated = DB::table('project_consultants')->where('project_id', $projectId)->pluck('user_id');

        $eligible = User::whereIn('type', ['consultor', 'parceiro_admin'])
            ->whereNotIn('id', $allocated)
            ->select('id', 'name', 'type', 'consultant_type', 'enabled', 'capacity_hours', 'allocated_hours')
            ->get();

        // Pré-carrega skills relevantes de todos os elegíveis
        $skillsByUser = ConsultantSkill::with('level:id,name,weight')
            ->whereIn('consultant_id', $eligible->pluck('id'))
            ->whereIn('skill_id', $skillIds)
            ->get()
            ->groupBy('consultant_id');

        $recommendations = $eligible->map(function ($u) use ($reqByGroup, $skillsByUser) {
            $type = $u->type === 'parceiro_admin'
                ? 'partner'
                : ($u->consultant_type === 'candidate' ? 'candidate' : 'internal');

            $userSkills = $skillsByUser->get($u->id, collect())->keyBy('skill_id');

            $baseScores  = [];
            $bonusScores = [];
            $gaps        = [];
            $matched     = 0;
            foreach ($reqByGroup as $skillId => $r) {
                $cs = $userSkills->get($skillId);
                $actual = $cs?->level?->weight;
                if ($actual === null) {
                    $baseScores[]  = -0.5;
                    $bonusScores[] = 0.0;
                    $gaps[] = [
                        'skill'          => ['id' => (int) $skillId, 'name' => $r->skill_name, 'category' => $r->skill_category],
                        'required_level' => $r->level_name,
                        'actual_level'   => null,
                        'type'           => 'missing',
                    ];
                } elseif ($actual >= $r->req_weight) {
                    $baseScores[]  = 1.0;
                    $bonusScores[] = $actual > $r->req_weight ? round(($actual - $r->req_weight) * 0.1, 4) : 0.0;
                    $matched++;
                } else {
                    $baseScores[]  = round($actual / $r->req_weight, 4);
                    $bonusScores[] = 0.0;
                    $gaps[] = [
                        'skill'          => ['id' => (int) $skillId, 'name' => $r->skill_name, 'category' => $r->skill_category],
                        'required_level' => $r->level_name,
                        'actual_level'   => $cs->level->name,
                        'type'           => 'below',
                    ];
                }
            }
            $n        = max(count($baseScores), 1);
            $baseAvg  = array_sum($baseScores)  / $n;
            $bonusAvg = array_sum($bonusScores) / $n;

            $capacity  = (int) ($u->capacity_hours  ?? 160);
            $allocated = (int) ($u->allocated_hours ?? 0);
            if ($capacity <= 0) {
                $availability = 1.0;
            } else {
                $availability = ($capacity - $allocated) / $capacity;
            }
            $availability = max(0.0, min(1.0, $availability));

            $finalScore = round(($baseAvg + $bonusAvg) * $availability, 3);

            $coverage = $finalScore >= 0.9 ? 'Completo'
                      : ($finalScore >= 0.7 ? 'Parcial' : 'Insuficiente');

            return [
                'consultant_id'  => (int) $u->id,
                'name'           => $u->name,
                'score'          => $finalScore,
                'base_score'     => round($baseAvg, 3),
                'bonus'          => round($bonusAvg, 3),
                'availability'   => round($availability, 3),
                'capacity_hours' => $capacity,
                'allocated_hours'=> $allocated,
                'coverage'       => $coverage,
                'skills_match'   => $matched,
                'skills_total'   => $reqByGroup->count(),
                'type'           => $type,
                'gaps'           => $gaps,
            ];
        });

        // Sort: score DESC, type priority (internal=0, partner=1, candidate=2), name ASC
        $typePri = ['internal' => 0, 'partner' => 1, 'candidate' => 2];
        $sorted = $recommendations->sort(function ($a, $b) use ($typePri) {
            if ($a['score'] !== $b['score']) return $b['score'] <=> $a['score'];
            $pa = $typePri[$a['type']] ?? 99;
            $pb = $typePri[$b['type']] ?? 99;
            if ($pa !== $pb) return $pa <=> $pb;
            return strcmp($a['name'], $b['name']);
        });

        // Filtro 1: score mínimo 0.3 (descarta fits muito ruins)
        $sorted = $sorted->filter(fn($r) => $r['score'] >= 0.3);

        // Filtro 2: oculta candidates se já existe internal com score >= 0.8
        $hasStrongInternal = $sorted->contains(fn($r) => $r['type'] === 'internal' && $r['score'] >= 0.8);
        if ($hasStrongInternal) {
            $sorted = $sorted->filter(fn($r) => $r['type'] !== 'candidate');
        }

        $sorted = $sorted->take(10)->values();

        return response()->json([
            'project'         => ['id' => $project->id, 'name' => $project->name],
            'required_count'  => $reqByGroup->count(),
            'recommendations' => $sorted,
        ]);
    }

    /**
     * Aloca um consultor no projeto (insere em project_consultants).
     * Idempotente — se já alocado, retorna 200 com flag already=true.
     */
    public function allocate(Request $request, int $projectId): JsonResponse
    {
        Project::findOrFail($projectId);

        $data = $request->validate([
            'consultant_id' => 'required|exists:users,id',
            'score'         => 'nullable|numeric',
            'risk_flag'     => 'nullable|boolean',
            'risk_reason'   => 'nullable|string|max:1000',
            'with_caveat'   => 'nullable|boolean', // mantido pra compatibilidade
        ]);

        $exists = DB::table('project_consultants')
            ->where('project_id', $projectId)
            ->where('user_id', $data['consultant_id'])
            ->exists();

        if ($exists) {
            return response()->json(['allocated' => true, 'already' => true]);
        }

        // Auto-flag: score < 0.9 ou with_caveat explícito vira risk_flag=true
        $autoFlag = isset($data['score']) && $data['score'] < 0.9;
        $riskFlag = (bool) ($data['risk_flag'] ?? ($data['with_caveat'] ?? $autoFlag));

        DB::table('project_consultants')->insert([
            'project_id'             => $projectId,
            'user_id'                => $data['consultant_id'],
            'allow_manual_timesheet' => false,
            'risk_flag'              => $riskFlag,
            'risk_reason'            => $data['risk_reason'] ?? null,
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);

        return response()->json([
            'allocated'   => true,
            'risk_flag'   => $riskFlag,
            'risk_reason' => $data['risk_reason'] ?? null,
        ], 201);
    }
}
