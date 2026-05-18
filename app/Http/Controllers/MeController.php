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
     * Agrega 4 fontes em uma lista única ordenada por data:
     *  - stage_activity_events (timeline operacional de atividade — payload->mentioned_user_ids)
     *  - project_message_mentions (chat de projeto)
     *  - contract_request_message_mentions (chat de requisição)
     *  - contract_message_mentions (chat de contrato)
     *
     * Cada item carrega contexto pra deep link no FE (project_id, request_id, contract_id, delivery_id).
     */
    public function mentions(Request $request): JsonResponse
    {
        $user = $request->user();
        $items = collect();

        // Cliente NUNCA vê chat de projeto (regra cards) — pula 2 fontes ligadas a projeto
        $isClient = $user->isCliente();

        // 1) Stage activity events (timeline operacional — chat por atividade dentro de projeto)
        // Cliente nunca tem visão de atividade → pula
        if ($isClient) {
            // pula bloco
        } else {
        $events = StageActivityEvent::query()
            ->where('type', StageActivityEvent::TYPE_COMMENT)
            ->whereRaw("payload->'mentioned_user_ids' @> ?::jsonb", [json_encode([$user->id])])
            ->with([
                'actor:id,name',
                'delivery:id,title,stage_id',
                'delivery.stage:id,name,project_id',
                'delivery.stage.project:id,name',
            ])
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();
        foreach ($events as $ev) {
            $items->push([
                'source'      => 'activity',
                'id'          => "act-{$ev->id}",
                'created_at'  => $ev->created_at?->toIso8601String(),
                'actor'       => $ev->actor ? ['id' => $ev->actor->id, 'name' => $ev->actor->name] : null,
                'text'        => $ev->payload['text'] ?? null,
                'project_id'  => $ev->delivery?->stage?->project?->id,
                'project'     => $ev->delivery?->stage?->project?->name,
                'delivery_id' => $ev->delivery?->id,
                'delivery'    => $ev->delivery?->title,
            ]);
        }
        } // close non-client guard

        // 2) Chat de projeto — cliente nunca recebe
        if (!$isClient) {
        $projectMentions = \App\Models\ProjectMessageMention::query()
            ->where('mentioned_user_id', $user->id)
            ->with([
                'message:id,project_id,user_id,message,created_at',
                'message.author:id,name',
                'message.project:id,name',
            ])
            ->whereHas('message')
            ->orderByDesc('id')
            ->limit(100)
            ->get();
        foreach ($projectMentions as $m) {
            $items->push([
                'source'     => 'project_chat',
                'id'         => "pm-{$m->id}",
                'created_at' => $m->message?->created_at?->toIso8601String(),
                'actor'      => $m->message?->author ? ['id' => $m->message->author->id, 'name' => $m->message->author->name] : null,
                'text'       => $m->message?->message,
                'project_id' => $m->message?->project?->id,
                'project'    => $m->message?->project?->name,
            ]);
        }
        } // close non-client guard

        // 3) Chat de requisição — cliente vê SÓ até req_decided_at (regra ADR cards)
        $reqMentionsQuery = \App\Models\ContractRequestMessageMention::query()
            ->where('mentioned_user_id', $user->id)
            ->with([
                'message:id,contract_request_id,user_id,message,created_at',
                'message.author:id,name',
                'message.request:id,customer_id,req_decided_at,project_name,linked_project_id',
                'message.request.customer:id,name',
            ])
            ->whereHas('message');

        if ($isClient) {
            $reqMentionsQuery->whereHas('message.request', function ($q) {
                $q->whereNull('req_decided_at');
            });
        }

        $reqMentions = $reqMentionsQuery->orderByDesc('id')->limit(100)->get();
        foreach ($reqMentions as $m) {
            $req = $m->message?->request;
            $items->push([
                'source'      => 'request_chat',
                'id'          => "rm-{$m->id}",
                'created_at'  => $m->message?->created_at?->toIso8601String(),
                'actor'       => $m->message?->author ? ['id' => $m->message->author->id, 'name' => $m->message->author->name] : null,
                'text'        => $m->message?->message,
                'request_id'  => $m->message?->contract_request_id,
                'customer'    => $req?->customer?->name,
                'project_name' => $req?->project_name, // texto livre da requisição
            ]);
        }

        // 4) Chat de contrato — cliente NUNCA recebe (não tem acesso ao chat de contrato)
        $contractMentions = collect();
        if (!$isClient) {
            $contractMentions = \App\Models\ContractMessageMention::query()
                ->where('mentioned_user_id', $user->id)
                ->with([
                    'message:id,contract_id,user_id,message,visibility,created_at',
                    'message.author:id,name',
                    'message.contract:id,customer_id,project_name',
                    'message.contract.customer:id,name',
                ])
                ->whereHas('message')
                ->orderByDesc('id')
                ->limit(100)
                ->get();
        }
        foreach ($contractMentions as $m) {
            $items->push([
                'source'      => 'contract_chat',
                'id'          => "cm-{$m->id}",
                'created_at'  => $m->message?->created_at?->toIso8601String(),
                'actor'       => $m->message?->author ? ['id' => $m->message->author->id, 'name' => $m->message->author->name] : null,
                'text'        => $m->message?->message,
                'contract_id' => $m->message?->contract_id,
                'customer'    => $m->message?->contract?->customer?->name,
                'project_name' => $m->message?->contract?->project_name,
            ]);
        }

        // Ordena tudo por created_at desc, limit 100 (FE separa não-lidas + 10 lidas)
        $final = $items
            ->sortByDesc(fn ($it) => $it['created_at'] ?? '')
            ->take(100)
            ->values();

        return response()->json([
            'items' => $final,
            'count' => $final->count(),
        ]);
    }
}
