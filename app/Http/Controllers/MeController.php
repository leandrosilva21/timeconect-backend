<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\StageActivityEvent;
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

    /**
     * Comentários onde o usuário corrente foi mencionado via `@[id:Name]`.
     *
     * Read tracking é client-side (localStorage `minutor.mentions_last_seen`).
     * Endpoint retorna últimas 50 mentions ordenadas decrescente.
     */
    public function mentions(Request $request): JsonResponse
    {
        $user = $request->user();

        // payload->mentioned_user_ids é jsonb array. @> consulta containment.
        $events = StageActivityEvent::query()
            ->where('type', StageActivityEvent::TYPE_COMMENT)
            ->whereRaw("payload->'mentioned_user_ids' @> ?::jsonb", [json_encode([$user->id])])
            ->with([
                'actor:id,name,email',
                'delivery:id,title,stage_id',
                'delivery.stage:id,name,project_id',
                'delivery.stage.project:id,name,customer_id',
            ])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $items = $events->map(function ($ev) {
            $delivery = $ev->delivery;
            $stage    = $delivery?->stage;
            $project  = $stage?->project;
            $payload  = $ev->payload ?? [];

            return [
                'id'           => $ev->id,
                'created_at'   => $ev->created_at?->toIso8601String(),
                'actor'        => $ev->actor ? [
                    'id'   => $ev->actor->id,
                    'name' => $ev->actor->name,
                ] : null,
                'text'         => $payload['text'] ?? null,
                'delivery_id'  => $delivery?->id,
                'delivery'     => $delivery?->title,
                'stage_id'     => $stage?->id,
                'stage'        => $stage?->name,
                'project_id'   => $project?->id,
                'project'      => $project?->name,
            ];
        });

        return response()->json([
            'items' => $items,
            'count' => $items->count(),
        ]);
    }
}
