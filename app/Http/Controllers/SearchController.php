<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Skill;
use App\Models\SkillAlias;
use App\Models\User;
use App\Models\ConsultantSkill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    /**
     * Busca avançada com filtros estruturados — tabela completa pra tomada de decisão.
     *
     * Query params:
     *  q             — termo (skill name ou alias). Vazio = sem filtro de texto.
     *  type          — CSV: internal,candidate,partner
     *  min_level     — 1..4 (weight mínimo)
     *  availability  — CSV: full,partial (mapeado pra integral/parcial)
     *  skill_ids     — CSV de skill ids
     *  segment       — CSV de segmentos (Indústria, Agro, etc)
     *
     * Retorna 1 row por (user, skill) — pode duplicar o user se ele bater múltiplas skills.
     * Ordena: sl.weight DESC, availability (integral first), hourly_rate ASC, name ASC.
     *
     * LGPD: candidato com status != approved tem email/phone nulificados.
     */
    public function advanced(Request $request): JsonResponse
    {
        $q            = trim((string) $request->input('q', ''));
        $types        = array_values(array_filter(array_map('trim', explode(',', (string) $request->input('type', '')))));
        $minLevel     = (int) $request->input('min_level', 0);
        $availability = array_values(array_filter(array_map('trim', explode(',', (string) $request->input('availability', '')))));
        $skillIds     = array_values(array_filter(array_map('intval', explode(',', (string) $request->input('skill_ids', '')))));
        $segments     = array_values(array_filter(array_map('trim', explode(',', (string) $request->input('segment', '')))));

        // Pré-computa skill_ids que batem em name OR alias — evita LEFT JOIN duplicar rows e DISTINCT
        $matchedSkillIds = null;
        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
            $matchedSkillIds = DB::table('skills as s')
                ->leftJoin('skill_aliases as sa', 'sa.skill_id', '=', 's.id')
                ->where(function ($w) use ($like) {
                    $w->where('s.name', 'ILIKE', $like)->orWhere('sa.alias', 'ILIKE', $like);
                })
                ->distinct()
                ->pluck('s.id');
        }

        $query = DB::table('consultant_skills as cs')
            ->join('users as u', 'u.id', '=', 'cs.consultant_id')
            ->join('skills as s', 's.id', '=', 'cs.skill_id')
            ->join('skill_levels as sl', 'sl.id', '=', 'cs.level_id')
            ->leftJoin('candidate_profiles as cp', 'cp.user_id', '=', 'u.id')
            ->whereIn('u.type', ['consultor', 'parceiro_admin']);

        if ($matchedSkillIds !== null) {
            $query->whereIn('cs.skill_id', $matchedSkillIds);
        }

        if (!empty($types)) {
            $query->where(function ($w) use ($types) {
                if (in_array('internal', $types, true)) {
                    $w->orWhere(function ($x) {
                        $x->where('u.type', 'consultor')
                          ->where(function ($y) {
                              $y->whereNull('u.consultant_type')
                                ->orWhere('u.consultant_type', '!=', 'candidate');
                          });
                    });
                }
                if (in_array('candidate', $types, true)) {
                    $w->orWhere(function ($x) {
                        $x->where('u.type', 'consultor')->where('u.consultant_type', 'candidate');
                    });
                }
                if (in_array('partner', $types, true)) {
                    $w->orWhere('u.type', 'parceiro_admin');
                }
            });
        }

        if ($minLevel >= 1 && $minLevel <= 4) {
            $query->where('sl.weight', '>=', $minLevel);
        }

        if (!empty($availability)) {
            $map = ['full' => 'integral', 'partial' => 'parcial', 'integral' => 'integral', 'parcial' => 'parcial'];
            $vals = [];
            foreach ($availability as $a) {
                if (isset($map[$a])) $vals[] = $map[$a];
            }
            if (!empty($vals)) {
                $query->whereIn('u.availability_status', array_unique($vals));
            }
        }

        if (!empty($skillIds)) {
            $query->whereIn('cs.skill_id', $skillIds);
        }

        if (!empty($segments)) {
            $query->where(function ($w) use ($segments) {
                foreach ($segments as $seg) {
                    $w->orWhereJsonContains('u.segments', $seg);
                }
            });
        }

        $rows = $query
            ->select(
                'u.id', 'u.name', 'u.email', 'u.phone', 'u.type', 'u.consultant_type',
                'u.hourly_rate', 'u.availability_status', 'u.city', 'u.state',
                's.id as skill_id', 's.name as skill',
                'sl.name as level', 'sl.weight as level_weight',
                'cp.status as candidate_status'
            )
            ->orderByDesc('sl.weight')
            ->orderByRaw("CASE u.availability_status WHEN 'integral' THEN 1 WHEN 'parcial' THEN 2 WHEN 'indisponivel' THEN 3 ELSE 4 END")
            ->orderBy('u.hourly_rate')
            ->orderBy('u.name')
            ->limit(100)
            ->get();

        $data = $rows->map(function ($r) {
            $personType = $r->type === 'parceiro_admin'
                ? 'partner'
                : ($r->consultant_type === 'candidate' ? 'candidate' : 'internal');

            // LGPD: candidato com status != approved não expõe contato
            $hideContact = $personType === 'candidate'
                && $r->candidate_status !== null
                && $r->candidate_status !== 'approved';

            return [
                'id'               => (int) $r->id,
                'name'             => $r->name,
                'type'             => $personType,
                'email'            => $hideContact ? null : $r->email,
                'phone'            => $hideContact ? null : $r->phone,
                'hourly_rate'      => $r->hourly_rate !== null ? (float) $r->hourly_rate : null,
                'availability'     => $r->availability_status,
                'city'             => $r->city,
                'state'            => $r->state,
                'skill_id'         => (int) $r->skill_id,
                'skill'            => $r->skill,
                'level'            => $r->level,
                'level_weight'     => (int) $r->level_weight,
                'candidate_status' => $r->candidate_status,
                'contact_hidden'   => $hideContact,
            ];
        });

        return response()->json([
            'data'    => $data->values(),
            'total'   => $data->count(),
            'limited' => $data->count() === 100,
            'filters' => [
                'q'            => $q,
                'type'         => $types,
                'min_level'    => $minLevel,
                'availability' => $availability,
                'skill_ids'    => $skillIds,
                'segment'      => $segments,
            ],
        ]);
    }
}
