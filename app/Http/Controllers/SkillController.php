<?php

namespace App\Http\Controllers;

use App\Models\Skill;
use App\Models\SkillLevel;
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
}
