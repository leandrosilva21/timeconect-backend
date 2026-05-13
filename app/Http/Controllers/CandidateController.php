<?php

namespace App\Http\Controllers;

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

            return $user->id;
        });

        return response()->json([
            'id'      => $userId,
            'message' => 'Perfil recebido com sucesso. Entraremos em contato.',
        ], 201);
    }
}
