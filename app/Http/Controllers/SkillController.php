<?php

namespace App\Http\Controllers;

use App\Models\Skill;
use App\Models\SkillLevel;
use App\Models\ConsultantSkill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SkillController extends Controller
{
    /**
     * Lista todas as skills, opcionalmente filtradas por category, type ou parent_id.
     * Inclui também os níveis disponíveis na resposta (útil pra preencher selects).
     */
    public function index(Request $request): JsonResponse
    {
        $query = Skill::query();

        if ($request->filled('category')) {
            $query->where('category', $request->string('category'));
        }
        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }
        if ($request->filled('parent_id')) {
            $query->where('parent_id', $request->integer('parent_id'));
        }

        $skills = $query->orderBy('category')->orderBy('name')->get();
        $levels = SkillLevel::orderBy('weight')->get();

        return response()->json([
            'skills' => $skills,
            'levels' => $levels,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'      => 'required|string|max:120',
            'category'  => 'required|string|max:80',
            'parent_id' => 'nullable|exists:skills,id',
            'type'      => 'nullable|in:module,technology,process',
        ]);

        $skill = Skill::create($data);

        return response()->json($skill, 201);
    }

    /**
     * Lista consultores que têm a skill no nível requerido (ou acima).
     * Útil pra alocação a partir da tela de gaps.
     */
    public function holders(int $id, Request $request): JsonResponse
    {
        $skill = Skill::findOrFail($id);

        $minWeight = null;
        if ($request->filled('min_level_id')) {
            $minLevel = SkillLevel::findOrFail($request->integer('min_level_id'));
            $minWeight = (int) $minLevel->weight;
        }

        $query = ConsultantSkill::with(['level:id,name,weight', 'consultant:id,name,email,consultant_type'])
            ->where('skill_id', $id);

        if ($minWeight !== null) {
            $query->whereHas('level', fn($q) => $q->where('weight', '>=', $minWeight));
        }

        $rows = $query->get()
            ->filter(fn($cs) => $cs->consultant !== null)
            ->sortByDesc(fn($cs) => $cs->level?->weight ?? 0)
            ->values();

        return response()->json([
            'skill' => [
                'id'       => (int) $skill->id,
                'name'     => $skill->name,
                'category' => $skill->category,
            ],
            'min_weight' => $minWeight,
            'total'      => $rows->count(),
            'holders'    => $rows->map(fn($cs) => [
                'consultant_id'   => (int) $cs->consultant_id,
                'name'            => $cs->consultant->name,
                'email'           => $cs->consultant->email,
                'consultant_type' => $cs->consultant->consultant_type,
                'level'           => $cs->level?->name,
                'level_weight'    => (int) ($cs->level?->weight ?? 0),
            ])->values(),
        ]);
    }
}
