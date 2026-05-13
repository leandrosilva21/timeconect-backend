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
}
