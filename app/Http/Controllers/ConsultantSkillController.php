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
        ]);

        $cs->update($data);

        return response()->json($cs->fresh(['skill', 'level']));
    }
}
