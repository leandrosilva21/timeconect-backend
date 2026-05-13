<?php

namespace App\Http\Controllers;

use App\Models\CandidateProfile;
use App\Models\ConsultantSkill;
use App\Models\Skill;
use App\Models\SkillLevel;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CandidateController extends Controller
{
    /**
     * Lista pública de skills + níveis pra alimentar o form de candidato.
     */
    public function formData(): JsonResponse
    {
        return response()->json([
            'skills' => Skill::orderBy('category')->orderBy('name')->get(['id','name','category','type']),
            'levels' => SkillLevel::orderBy('weight')->get(['id','name','weight']),
        ]);
    }

    /**
     * Cadastro público de candidato: cria user + consultant_skills numa transação.
     * type='consultor', consultant_type='candidate', enabled=false, source='candidate_form'.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'                    => 'required|string|max:255',
            'email'                   => 'required|email|max:255|unique:users,email',
            'phone'                   => 'nullable|string|max:30',
            'linkedin_url'            => 'nullable|url|max:255',
            'city'                    => 'nullable|string|max:100',
            'state'                   => 'nullable|string|max:2',
            'protheus_years_experience' => 'nullable|integer|min:0|max:50',
            'protheus_modules'        => 'nullable|array',
            'protheus_modules.*'      => 'integer|exists:skills,id',
            'work_model'              => 'nullable|in:PJ,CLT,Freelancer,Hibrido',
            'hourly_rate'             => 'nullable|numeric|min:0|max:99999',
            'availability_status'     => 'nullable|in:integral,parcial,indisponivel',
            'hours_available'         => 'nullable|integer|min:0|max:240',
            'availability_start_date' => 'nullable|date',
            'relevant_projects'       => 'nullable|string|max:5000',
            'segments'                => 'nullable|array',
            'segments.*'              => 'string|max:60',
            'skills'                  => 'nullable|array',
            'skills.*.skill_id'       => 'required|integer|exists:skills,id',
            'skills.*.level_id'       => 'required|integer|exists:skill_levels,id',
            'skills.*.years_experience' => 'nullable|integer|min:0|max:50',
        ]);

        $userId = DB::transaction(function () use ($data) {
            $capacity = $data['hours_available'] ?? null;

            $user = User::create([
                'name'                      => $data['name'],
                'email'                     => $data['email'],
                'password'                  => Hash::make(Str::random(32)), // não usado, candidato não loga
                'type'                      => 'consultor',
                'consultant_type'           => 'candidate',
                'enabled'                   => false,
                'phone'                     => $data['phone']        ?? null,
                'linkedin_url'              => $data['linkedin_url'] ?? null,
                'city'                      => $data['city']         ?? null,
                'state'                     => $data['state']        ?? null,
                'work_model'                => $data['work_model']   ?? null,
                'hourly_rate'               => $data['hourly_rate']  ?? null,
                'rate_type'                 => 'hourly',
                'capacity_hours'            => $capacity !== null ? $capacity : 160,
                'allocated_hours'           => 0,
                'availability_status'       => $data['availability_status']     ?? null,
                'availability_start_date'   => $data['availability_start_date'] ?? null,
                'protheus_years_experience' => $data['protheus_years_experience'] ?? null,
                'relevant_projects'         => $data['relevant_projects'] ?? null,
                'segments'                  => $data['segments'] ?? null,
                'daily_hours'               => 8,
                'has_temporary_password'    => false,
                'is_executive'              => false,
                'can_timesheet_sustentacao' => false,
            ]);

            foreach (($data['skills'] ?? []) as $row) {
                ConsultantSkill::updateOrCreate(
                    ['consultant_id' => $user->id, 'skill_id' => $row['skill_id']],
                    [
                        'level_id'         => $row['level_id'],
                        'years_experience' => $row['years_experience'] ?? null,
                        'source'           => 'candidate_form',
                        'confidence'       => 'low',
                    ]
                );
            }

            // Cria o candidate_profile com status='new' pra entrar no Kanban
            CandidateProfile::create([
                'user_id' => $user->id,
                'status'  => 'new',
            ]);

            return $user->id;
        });

        return response()->json([
            'id'      => $userId,
            'message' => 'Perfil recebido com sucesso. Entraremos em contato.',
        ], 201);
    }

    /**
     * Lista candidatos (users.consultant_type='candidate') com perfil + score + top skills.
     * Filtros: ?status=approved, ?min_score=0.7, ?skill_id=N
     */
    public function index(\Illuminate\Http\Request $request): JsonResponse
    {
        $query = User::query()
            ->where('consultant_type', 'candidate')
            ->select('id','name','email','phone','city','state','work_model',
                     'capacity_hours','allocated_hours','availability_status',
                     'hourly_rate','protheus_years_experience');

        if ($skillId = $request->integer('skill_id')) {
            $query->whereIn('id', function ($q) use ($skillId) {
                $q->select('consultant_id')->from('consultant_skills')->where('skill_id', $skillId);
            });
        }

        $users = $query->get();
        $userIds = $users->pluck('id');

        // Profiles (LEFT JOIN — default 'new' se faltar)
        $profiles = CandidateProfile::whereIn('user_id', $userIds)->get()->keyBy('user_id');

        // Top 3 skills por candidato
        $topSkills = DB::table('consultant_skills as cs')
            ->join('skills as s', 's.id', '=', 'cs.skill_id')
            ->join('skill_levels as sl', 'sl.id', '=', 'cs.level_id')
            ->whereIn('cs.consultant_id', $userIds)
            ->orderByDesc('sl.weight')
            ->select('cs.consultant_id','s.name as skill','sl.name as level','sl.weight')
            ->get()
            ->groupBy('consultant_id')
            ->map(fn($coll) => $coll->take(3)->map(fn($r) => ['name' => $r->skill, 'level' => $r->level])->values());

        $statusFilter = $request->string('status')->toString();
        $minScore     = $request->filled('min_score') ? (float) $request->input('min_score') : null;

        $items = $users->map(function ($u) use ($profiles, $topSkills) {
            $p = $profiles->get($u->id);
            $cap = (int) ($u->capacity_hours ?? 160);
            $alc = (int) ($u->allocated_hours ?? 0);
            $avail = $cap > 0 ? max(0.0, min(1.0, ($cap - $alc) / $cap)) : 1.0;
            return [
                'id'                  => $u->id,
                'name'                => $u->name,
                'email'               => $u->email,
                'phone'               => $u->phone,
                'city'                => $u->city,
                'state'               => $u->state,
                'work_model'          => $u->work_model,
                'availability_status' => $u->availability_status,
                'availability'        => round($avail, 3),
                'hourly_rate'         => $u->hourly_rate,
                'protheus_years_experience' => $u->protheus_years_experience,
                'status'              => $p->status ?? 'new',
                'score_initial'       => $p->score_initial ?? null,
                'interest_level'      => $p->interest_level ?? null,
                'expected_rate'       => $p->expected_rate ?? null,
                'notes'               => $p->notes ?? null,
                'top_skills'          => $topSkills->get($u->id, collect())->all(),
            ];
        })
        ->filter(function ($c) use ($statusFilter, $minScore) {
            if ($statusFilter && $c['status'] !== $statusFilter) return false;
            if ($minScore !== null && ($c['score_initial'] ?? 0) < $minScore) return false;
            return true;
        })
        ->sortBy('name')
        ->values();

        return response()->json(['candidates' => $items, 'total' => $items->count()]);
    }

    /**
     * PATCH /candidates/{id}/status — muda status no Kanban.
     */
    public function updateStatus(\Illuminate\Http\Request $request, int $id): JsonResponse
    {
        $user = User::where('id', $id)->where('consultant_type', 'candidate')->firstOrFail();

        $data = $request->validate([
            'status' => 'required|in:' . implode(',', CandidateProfile::STATUSES),
        ]);

        $profile = CandidateProfile::firstOrNew(['user_id' => $user->id]);
        $profile->status = $data['status'];
        $profile->save();

        return response()->json([
            'id'     => $user->id,
            'status' => $profile->status,
        ]);
    }

    /**
     * PATCH /candidates/{id} — atualiza score, interest, rate, notes.
     */
    public function update(\Illuminate\Http\Request $request, int $id): JsonResponse
    {
        $user = User::where('id', $id)->where('consultant_type', 'candidate')->firstOrFail();

        $data = $request->validate([
            'score_initial'  => 'nullable|numeric|min:0|max:2',
            'interest_level' => 'nullable|in:alto,medio,baixo',
            'expected_rate'  => 'nullable|numeric|min:0|max:99999',
            'notes'          => 'nullable|string|max:5000',
        ]);

        $profile = CandidateProfile::firstOrNew(['user_id' => $user->id]);
        $profile->fill($data);
        if (!$profile->status) $profile->status = 'new';
        $profile->save();

        return response()->json([
            'id'             => $user->id,
            'status'         => $profile->status,
            'score_initial'  => $profile->score_initial,
            'interest_level' => $profile->interest_level,
            'expected_rate'  => $profile->expected_rate,
            'notes'          => $profile->notes,
        ]);
    }
}
