<?php

namespace App\Http\Controllers;

use App\Models\ConsultantSkill;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConsultantSkillController extends Controller
{
    /**
     * Lista skills de um consultor, com skill e level carregados.
     */
    public function indexByConsultant(int $consultantId): JsonResponse
    {
        // Garante que o consultor existe (404 controlado)
        User::findOrFail($consultantId);

        $items = ConsultantSkill::with(['skill', 'level'])
            ->where('consultant_id', $consultantId)
            ->orderBy('id')
            ->get();

        return response()->json($items);
    }

    /**
     * Cria/atualiza skills de um consultor.
     * Aceita payload único (objeto) ou bulk (array em "items").
     * updateOrCreate(consultant_id+skill_id) evita duplicidade — sobrescreve nível.
     */
    public function storeForConsultant(Request $request, int $consultantId): JsonResponse
    {
        User::findOrFail($consultantId);

        $isBulk = $request->has('items');

        $rules = [
            'skill_id'         => 'required|exists:skills,id',
            'level_id'         => 'required|exists:skill_levels,id',
            'years_experience' => 'nullable|integer|min:0|max:100',
            'last_used_at'     => 'nullable|date',
            'source'           => 'nullable|in:forms_import,user_input,validated',
            'confidence'       => 'nullable|in:low,medium,high',
            'notes'            => 'nullable|string',
            'atuacao_types'    => 'nullable|array',
            'atuacao_types.*'  => 'string|max:60',
        ];

        if ($isBulk) {
            $payload = $request->validate([
                'items'              => 'required|array|min:1',
                'items.*.skill_id'         => $rules['skill_id'],
                'items.*.level_id'         => $rules['level_id'],
                'items.*.years_experience' => $rules['years_experience'],
                'items.*.last_used_at'     => $rules['last_used_at'],
                'items.*.source'           => $rules['source'],
                'items.*.confidence'       => $rules['confidence'],
                'items.*.notes'            => $rules['notes'],
                'items.*.atuacao_types'    => $rules['atuacao_types'],
            ]);
            $rows = $payload['items'];
        } else {
            $rows = [$request->validate($rules)];
        }

        $saved = DB::transaction(function () use ($rows, $consultantId) {
            $out = [];
            foreach ($rows as $row) {
                $out[] = ConsultantSkill::updateOrCreate(
                    ['consultant_id' => $consultantId, 'skill_id' => $row['skill_id']],
                    [
                        'level_id'         => $row['level_id'],
                        'years_experience' => $row['years_experience'] ?? null,
                        'last_used_at'     => $row['last_used_at']     ?? null,
                        'source'           => $row['source']           ?? 'user_input',
                        'confidence'       => $row['confidence']       ?? 'medium',
                        'notes'            => $row['notes']            ?? null,
                        'atuacao_types'    => $row['atuacao_types']    ?? null,
                    ]
                );
            }
            return $out;
        });

        return response()->json($isBulk ? $saved : $saved[0], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $cs = ConsultantSkill::findOrFail($id);

        $data = $request->validate([
            'level_id'         => 'sometimes|exists:skill_levels,id',
            'years_experience' => 'nullable|integer|min:0|max:100',
            'last_used_at'     => 'nullable|date',
            'source'           => 'sometimes|in:forms_import,user_input,validated',
            'confidence'       => 'sometimes|in:low,medium,high',
            'notes'            => 'nullable|string',
            'atuacao_types'    => 'nullable|array',
            'atuacao_types.*'  => 'string|max:60',
        ]);

        $cs->update($data);

        return response()->json($cs->fresh(['skill', 'level']));
    }

    /**
     * Perfil completo pra alimentar formulário (steps 1, 2, 5).
     */
    public function showProfile(int $consultantId): \Illuminate\Http\JsonResponse
    {
        $u = User::findOrFail($consultantId);
        return response()->json([
            'id'                       => $u->id,
            'name'                     => $u->name,
            'email'                    => $u->email,
            'type'                     => $u->type,
            'consultant_type'          => $u->consultant_type,
            'availability_status'      => $u->availability_status,
            'hours_available'          => $u->allocated_hours !== null && $u->capacity_hours !== null
                ? max(0, $u->capacity_hours - $u->allocated_hours)
                : null,
            'capacity_hours'           => $u->capacity_hours,
            'allocated_hours'          => $u->allocated_hours,
            'availability_start_date'  => optional($u->availability_start_date)->format('Y-m-d'),
            'relevant_projects'        => $u->relevant_projects,
            'segments'                 => $u->segments,
        ]);
    }

    /**
     * Atualiza campos de perfil (steps 1, 2, 5 do formulário).
     * Não toca em type/consultant_type (segurança — admin lida em outra tela).
     */
    public function updateProfile(\Illuminate\Http\Request $request, int $consultantId): \Illuminate\Http\JsonResponse
    {
        $u = User::findOrFail($consultantId);

        $data = $request->validate([
            'name'                    => 'sometimes|string|max:255',
            'email'                   => 'sometimes|email|max:255|unique:users,email,' . $consultantId,
            'availability_status'     => 'nullable|in:integral,parcial,indisponivel',
            'hours_available'         => 'nullable|integer|min:0|max:240',
            'availability_start_date' => 'nullable|date',
            'relevant_projects'       => 'nullable|string|max:5000',
            'segments'                => 'nullable|array',
            'segments.*'              => 'string|max:60',
        ]);

        // Mapear hours_available pra (capacity_hours - allocated_hours) — mantém allocated, ajusta capacity
        if (array_key_exists('hours_available', $data) && $data['hours_available'] !== null) {
            $allocated = (int) ($u->allocated_hours ?? 0);
            $data['capacity_hours'] = $allocated + $data['hours_available'];
            unset($data['hours_available']);
        } else {
            unset($data['hours_available']);
        }

        $u->update($data);

        return response()->json([
            'updated'              => true,
            'capacity_hours'       => $u->fresh()->capacity_hours,
            'availability_status'  => $u->fresh()->availability_status,
        ]);
    }
}
