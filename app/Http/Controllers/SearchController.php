<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Skill;
use App\Models\SkillAlias;
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
        $needle = mb_strtolower($q);

        // Skills que batem por NOME, ALIAS (skill_aliases) ou CATEGORIA — case-insensitive.
        // Estratégia: 1ª query pega ids matched; 2ª carrega com aliases pra ranking em PHP.
        $matchedSkillIds = \Illuminate\Support\Facades\DB::table('skills as s')
            ->leftJoin('skill_aliases as sa', 'sa.skill_id', '=', 's.id')
            ->where(function ($w) use ($like) {
                $w->where('s.name', 'ILIKE', $like)
                  ->orWhere('sa.alias', 'ILIKE', $like)
                  ->orWhere('s.category', 'ILIKE', $like);
            })
            ->distinct()
            ->pluck('s.id');

        $skillsWithAliases = $matchedSkillIds->isNotEmpty()
            ? Skill::with('aliases:id,skill_id,alias')
                ->whereIn('id', $matchedSkillIds)
                ->get(['id', 'name', 'category'])
            : collect();

        // Ranking: nome=1, alias=2, categoria=3 (mesma lógica da spec)
        $matchingSkills = $skillsWithAliases->map(function ($s) use ($needle) {
            $matchedAlias = null;
            $rank = 3;
            if (mb_stripos($s->name, $needle) !== false) {
                $rank = 1;
            } else {
                $alias = $s->aliases->first(fn($a) => mb_stripos($a->alias, $needle) !== false);
                if ($alias) {
                    $rank = 2;
                    $matchedAlias = $alias->alias;
                }
            }
            return (object) [
                'id'             => $s->id,
                'name'           => $s->name,
                'category'       => $s->category,
                'matched_alias'  => $matchedAlias,
                '_rank'          => $rank,
            ];
        })
        ->sortBy(['_rank', 'name'])
        ->take(10)
        ->values();

        // Pessoas com skill-match (têm pelo menos uma das skills matched)
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

        // Pra cada pessoa: prioriza a skill MATCHED (pra dar contexto à busca)
        // Fallback: skill com maior weight overall
        $peopleIds = $people->pluck('id');
        $matchedSkillIdSet = $matchingSkills->pluck('id');
        $allUserSkills = $peopleIds->isNotEmpty()
            ? ConsultantSkill::with('skill:id,name', 'level:id,name,weight')
                ->whereIn('consultant_id', $peopleIds)
                ->get()
                ->groupBy('consultant_id')
            : collect();

        $topSkillByUser = $allUserSkills->map(function ($coll) use ($matchedSkillIdSet) {
            // Preferir uma skill que esteja entre as matched
            $matched = $coll->filter(fn($cs) => $matchedSkillIdSet->contains($cs->skill_id))
                ->sortByDesc(fn($cs) => optional($cs->level)->weight ?? 0)
                ->first();
            if ($matched) return $matched;
            // Fallback: overall top
            return $coll->sortByDesc(fn($cs) => optional($cs->level)->weight ?? 0)->first();
        });

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
