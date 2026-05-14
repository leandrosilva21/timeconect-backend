<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Skill;
use App\Models\User;
use App\Models\ConsultantSkill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    /**
     * Busca global: pessoas, skills, projetos.
     * Ordena pessoas: skill-match primeiro, depois nome-match.
     */
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json([
                'q'        => $q,
                'people'   => [],
                'skills'   => [],
                'projects' => [],
            ]);
        }

        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';

        // Skills que batem por nome (case-insensitive)
        $matchingSkills = Skill::where('name', 'ILIKE', $like)
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'name', 'category']);

        // Pessoas com skill-match (têm pelo menos uma das skills que bateu)
        $skillMatchUserIds = $matchingSkills->isNotEmpty()
            ? ConsultantSkill::whereIn('skill_id', $matchingSkills->pluck('id'))
                ->distinct('consultant_id')
                ->pluck('consultant_id')
            : collect();

        $skillMatchPeople = User::whereIn('id', $skillMatchUserIds)
            ->whereIn('type', ['consultor', 'parceiro_admin'])
            ->select('id', 'name', 'email', 'consultant_type', 'type')
            ->orderBy('name')
            ->limit(15)
            ->get();

        // Pessoas que batem por nome ou email (excluindo as já no skill-match)
        $nameMatchPeople = User::where(function ($q2) use ($like) {
                $q2->where('name', 'ILIKE', $like)->orWhere('email', 'ILIKE', $like);
            })
            ->whereIn('type', ['consultor', 'parceiro_admin'])
            ->whereNotIn('id', $skillMatchPeople->pluck('id'))
            ->select('id', 'name', 'email', 'consultant_type', 'type')
            ->orderBy('name')
            ->limit(15)
            ->get();

        $people = $skillMatchPeople->concat($nameMatchPeople);

        // Pra cada pessoa, achar a skill principal (maior weight)
        $peopleIds = $people->pluck('id');
        $topSkillByUser = $peopleIds->isNotEmpty()
            ? ConsultantSkill::with('skill:id,name', 'level:id,name,weight')
                ->whereIn('consultant_id', $peopleIds)
                ->get()
                ->groupBy('consultant_id')
                ->map(fn($coll) => $coll->sortByDesc(fn($cs) => optional($cs->level)->weight ?? 0)->first())
            : collect();

        // Projetos: por code ou name
        $projects = Project::where(function ($q2) use ($like) {
                $q2->where('name', 'ILIKE', $like)->orWhere('code', 'ILIKE', $like);
            })
            ->orderBy('code')
            ->limit(10)
            ->get(['id', 'name', 'code']);

        $skillMatchIdSet = $skillMatchPeople->pluck('id')->flip();

        return response()->json([
            'q'      => $q,
            'people' => $people->map(function ($u) use ($topSkillByUser, $skillMatchIdSet) {
                $top = $topSkillByUser->get($u->id);
                $personType = $u->type === 'parceiro_admin'
                    ? 'Parceiro'
                    : ($u->consultant_type === 'candidate'
                        ? 'Candidato'
                        : 'Consultor');
                return [
                    'id'               => $u->id,
                    'name'             => $u->name,
                    'email'            => $u->email,
                    'type'             => $personType,
                    'matched_by_skill' => $skillMatchIdSet->has($u->id),
                    'main_skill'       => $top && $top->skill ? $top->skill->name : null,
                    'main_skill_level' => $top && $top->level ? $top->level->name : null,
                ];
            })->values(),
            'skills' => $matchingSkills->map(fn($s) => [
                'id'       => (int) $s->id,
                'name'     => $s->name,
                'category' => $s->category,
            ])->values(),
            'projects' => $projects->map(fn($p) => [
                'id'   => (int) $p->id,
                'name' => $p->name,
                'code' => $p->code,
            ])->values(),
        ]);
    }
}
