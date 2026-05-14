<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\StageDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    /**
     * Cards atribuídos ao usuário corrente, agrupados em 4 seções:
     *   - overdue:        prazo passou e não está concluída
     *   - waiting_client: status = waiting_client
     *   - in_progress:    status = in_progress ou review
     *   - backlog:        status = backlog
     *
     * Só considera entregas de projetos operacionais (sustentação fora — ADR 0004).
     */
    public function cards(Request $request): JsonResponse
    {
        $user = $request->user();

        $deliveries = StageDelivery::query()
            ->where('responsible_user_id', $user->id)
            ->whereHas('stage.project', function ($q) {
                $q->where(function ($w) {
                    $w->whereDoesntHave('serviceType')
                      ->orWhereHas('serviceType', function ($s) {
                          $s->whereRaw('LOWER(name) NOT LIKE ?', ['%sustenta%'])
                            ->whereRaw('LOWER(name) NOT LIKE ?', ['%cloud%'])
                            ->whereRaw('LOWER(name) NOT LIKE ?', ['%bizify%']);
                      });
                });
            })
            ->with([
                'stage:id,project_id,name',
                'stage.project:id,name,customer_id',
                'stage.project.customer:id,name',
            ])
            ->orderByRaw('due_date ASC NULLS LAST')
            ->orderByRaw("CASE priority WHEN 'high' THEN 0 WHEN 'medium' THEN 1 WHEN 'low' THEN 2 ELSE 3 END")
            ->get();

        $today = now()->startOfDay();

        $sections = [
            'overdue'        => [],
            'waiting_client' => [],
            'in_progress'    => [],
            'backlog'        => [],
        ];

        foreach ($deliveries as $d) {
            $card = [
                'id'             => $d->id,
                'title'          => $d->title,
                'priority'       => $d->priority,
                'status'         => $d->status,
                'due_date'       => $d->due_date?->toDateString(),
                'hours_planned'  => (float) $d->hours_planned,
                'stage_id'       => $d->stage_id,
                'stage_name'     => $d->stage?->name,
                'project_id'     => $d->stage?->project_id,
                'project_name'   => $d->stage?->project?->name,
                'customer_name'  => $d->stage?->project?->customer?->name,
            ];

            // overdue tem prioridade sobre status atual
            if (
                $d->status !== StageDelivery::STATUS_DONE
                && $d->due_date
                && $d->due_date->startOfDay()->lt($today)
            ) {
                $sections['overdue'][] = $card;
                continue;
            }

            if ($d->status === StageDelivery::STATUS_WAITING_CLIENT) {
                $sections['waiting_client'][] = $card;
            } elseif (
                $d->status === StageDelivery::STATUS_IN_PROGRESS
                || $d->status === StageDelivery::STATUS_REVIEW
            ) {
                $sections['in_progress'][] = $card;
            } elseif ($d->status === StageDelivery::STATUS_BACKLOG) {
                $sections['backlog'][] = $card;
            }
            // status = done não entra em nenhuma seção
        }

        return response()->json([
            'sections' => $sections,
            'totals'   => [
                'overdue'        => count($sections['overdue']),
                'waiting_client' => count($sections['waiting_client']),
                'in_progress'    => count($sections['in_progress']),
                'backlog'        => count($sections['backlog']),
            ],
        ]);
    }
}
