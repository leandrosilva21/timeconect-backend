<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\ContractAttachment;
use App\Models\ContractContact;
use App\Models\ContractEvent;
use App\Models\ContractFlowSnapshot;
use App\Models\ContractKanbanLog;
use App\Models\Expense;
use App\Models\Timesheet;
use App\Models\ProjectKanbanLog;
use App\Models\ContractType;
use App\Models\Customer;
use App\Models\Project;
use App\Models\ProjectAttachment;
use App\Models\ProjectContact;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\ProjectCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Events\ContractEventCreated;
use App\Jobs\ReplayContractEventsJob;
use App\Listeners\ContractEventListener;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ContractController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Contract::with([
            'customer:id,name',
            'contractType:id,name',
            'serviceType:id,name',
            'architect:id,name',
            'project:id,code,name,sold_hours,hour_contribution,status',
        ])
            ->when($request->query('status'), fn($q) => $q->where('status', $request->query('status')))
            ->when($request->query('customer_id'), fn($q) => $q->where('customer_id', $request->query('customer_id')))
            ->when($request->query('search'), function ($q) use ($request) {
                $s = '%' . $request->query('search') . '%';
                $q->whereHas('customer', fn($c) => $c->where('name', 'ilike', $s));
            })
            ->orderBy('created_at', 'desc');

        $paginated = $query->paginate($request->query('per_page', 20));

        $projectIds = collect($paginated->items())->pluck('project_id')->filter()->values()->all();
        if (!empty($projectIds)) {
            $timesheetSums = Timesheet::whereIn('project_id', $projectIds)
                ->where('status', 'approved')
                ->groupBy('project_id')
                ->selectRaw('project_id, SUM(effort_minutes) as total_minutes')
                ->pluck('total_minutes', 'project_id');

            foreach ($paginated->items() as $contract) {
                if ($contract->project) {
                    $logged  = (float) ($timesheetSums[$contract->project_id] ?? 0);
                    $consumed = round($logged / 60, 1);
                    $contract->project->setAttribute('consumed_hours', $consumed);
                    $contract->project->setAttribute('general_hours_balance', round(
                        ($contract->project->sold_hours ?? 0) + ($contract->project->hour_contribution ?? 0) - $consumed,
                        1
                    ));
                }
            }
        }

        return response()->json($paginated);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id'            => 'required|exists:customers,id',
            'project_name'           => 'nullable|string|max:255',
            'parent_project_id'      => 'nullable|exists:projects,id',
            'categoria'              => 'required|in:projeto,sustentacao',
            'service_type_id'        => 'nullable|exists:service_types,id',
            'contract_type_id'       => 'nullable|exists:contract_types,id',
            'tipo_faturamento'       => 'nullable|in:on_demand,banco_horas_mensal,banco_horas_fixo,por_servico,saas',
            'cobra_despesa_cliente'  => 'boolean',
            'limite_despesa'         => 'nullable|numeric|min:0',
            'architect_id'           => 'nullable|exists:users,id',
            'tipo_alocacao'          => 'nullable|in:remoto,presencial,ambos',
            'horas_contratadas'      => 'required|integer|min:0',
            'valor_projeto'          => 'nullable|numeric|min:0',
            'valor_hora'             => 'nullable|numeric|min:0',
            'hora_adicional'         => 'nullable|numeric|min:0',
            'pct_horas_coordenador'  => 'nullable|numeric|min:0|max:100',
            'horas_consultor'        => 'nullable|integer|min:0',
            'expectativa_inicio'     => 'nullable|date',
            'condicao_pagamento'     => 'nullable|string',
            'executivo_conta_id'     => 'nullable|exists:users,id',
            'vendedor_id'            => 'nullable|exists:users,id',
            'observacoes'            => 'nullable|string',
            'project_code_preview'   => 'nullable|string|max:20',
            'contacts'               => 'nullable|array',
            'contacts.*.name'        => 'required|string',
            'contacts.*.cargo'       => 'nullable|string',
            'contacts.*.email'       => 'nullable|email',
            'contacts.*.phone'       => 'nullable|string',
        ]);

        $contract = DB::transaction(function () use ($validated, $request) {
            $data = collect($validated)->except('contacts')->merge([
                'created_by_id' => auth()->id(),
                'status'        => Contract::STATUS_RASCUNHO,
                'kanban_status' => Contract::KANBAN_BACKLOG,
            ])->toArray();

            if (empty($data['executivo_conta_id'])) {
                $customer = Customer::find($data['customer_id']);
                if ($customer?->executive_id) {
                    $data['executivo_conta_id'] = $customer->executive_id;
                }
            }

            $contract = Contract::create($data);

            foreach ($validated['contacts'] ?? [] as $c) {
                ContractContact::create(array_merge($c, ['contract_id' => $contract->id]));
            }

            return $contract;
        });

        return response()->json($contract->load(['customer:id,name', 'contacts', 'attachments']), 201);
    }

    public function show(Contract $contract): JsonResponse
    {
        return response()->json(
            $contract->load(['customer:id,name', 'serviceType:id,name', 'contractType:id,name', 'architect:id,name', 'executivoConta:id,name', 'vendedor:id,name', 'contacts', 'attachments', 'project:id,code,name,status'])
        );
    }

    public function update(Request $request, Contract $contract): JsonResponse
    {
        if ($contract->project_id) {
            return response()->json(['message' => 'Contrato com projeto gerado não pode ser editado.'], 422);
        }

        $validated = $request->validate([
            'customer_id'            => 'sometimes|exists:customers,id',
            'project_name'           => 'nullable|string|max:255',
            'parent_project_id'      => 'nullable|exists:projects,id',
            'categoria'              => 'sometimes|in:projeto,sustentacao',
            'service_type_id'        => 'nullable|exists:service_types,id',
            'contract_type_id'       => 'nullable|exists:contract_types,id',
            'tipo_faturamento'       => 'nullable|in:on_demand,banco_horas_mensal,banco_horas_fixo,por_servico,saas',
            'cobra_despesa_cliente'  => 'boolean',
            'limite_despesa'         => 'nullable|numeric|min:0',
            'architect_id'           => 'nullable|exists:users,id',
            'tipo_alocacao'          => 'nullable|in:remoto,presencial,ambos',
            'horas_contratadas'      => 'sometimes|integer|min:0',
            'valor_projeto'          => 'nullable|numeric|min:0',
            'valor_hora'             => 'nullable|numeric|min:0',
            'hora_adicional'         => 'nullable|numeric|min:0',
            'pct_horas_coordenador'  => 'nullable|numeric|min:0|max:100',
            'horas_consultor'        => 'nullable|integer|min:0',
            'expectativa_inicio'     => 'nullable|date',
            'condicao_pagamento'     => 'nullable|string',
            'executivo_conta_id'     => 'nullable|exists:users,id',
            'vendedor_id'            => 'nullable|exists:users,id',
            'observacoes'            => 'nullable|string',
            'project_code_preview'   => 'nullable|string|max:20',
            'contacts'               => 'nullable|array',
            'contacts.*.id'          => 'nullable|exists:contract_contacts,id',
            'contacts.*.name'        => 'required|string',
            'contacts.*.cargo'       => 'nullable|string',
            'contacts.*.email'       => 'nullable|email',
            'contacts.*.phone'       => 'nullable|string',
        ]);

        DB::transaction(function () use ($contract, $validated) {
            $contract->update(collect($validated)->except('contacts')->toArray());

            if (array_key_exists('contacts', $validated)) {
                $contract->contacts()->delete();
                foreach ($validated['contacts'] ?? [] as $c) {
                    ContractContact::create(array_merge($c, ['contract_id' => $contract->id]));
                }
            }
        });

        return response()->json($contract->fresh()->load(['customer:id,name', 'contacts', 'attachments']));
    }

    public function destroy(Contract $contract): JsonResponse
    {
        if ($contract->project_id) {
            if (Expense::where('project_id', $contract->project_id)->exists()) {
                return response()->json(['message' => 'Contrato com despesas registradas não pode ser excluído.'], 422);
            }
            if (Timesheet::where('project_id', $contract->project_id)->exists()) {
                return response()->json(['message' => 'Contrato com apontamentos registrados não pode ser excluído.'], 422);
            }
        }

        foreach ($contract->attachments as $att) {
            Storage::delete($att->path);
        }

        $contract->delete();
        return response()->json(null, 204);
    }

    public function updateStatus(Request $request, Contract $contract): JsonResponse
    {
        $request->validate(['status' => 'required|in:rascunho,aprovado,inicio_autorizado,ativo']);

        $newStatus = $request->input('status');

        if ($newStatus === Contract::STATUS_APROVADO && $contract->status !== Contract::STATUS_RASCUNHO) {
            return response()->json(['message' => 'Apenas contratos em Rascunho podem ser aprovados.'], 422);
        }
        if ($newStatus === Contract::STATUS_INICIO_AUTORIZADO && !in_array($contract->status, [Contract::STATUS_APROVADO, Contract::STATUS_RASCUNHO])) {
            return response()->json(['message' => 'Apenas contratos Aprovados podem ter início autorizado.'], 422);
        }

        $data = ['status' => $newStatus];

        if ($newStatus === Contract::STATUS_APROVADO) {
            $data['approved_by_id'] = auth()->id();
            $data['approved_at']    = now();
        }

        $contract->update($data);
        return response()->json($contract->fresh());
    }

    public function generateProject(Request $request, Contract $contract): JsonResponse
    {
        $request->validate([
            'coordinator_ids'   => 'nullable|array',
            'coordinator_ids.*' => 'integer|exists:users,id',
        ]);

        if (!in_array($contract->status, [Contract::STATUS_INICIO_AUTORIZADO, Contract::STATUS_APROVADO])) {
            return response()->json(['message' => 'Contrato precisa estar Aprovado ou com Início Autorizado.'], 422);
        }

        if ($contract->project_id) {
            return response()->json(['message' => 'Projeto já gerado para este contrato.', 'project_id' => $contract->project_id], 422);
        }

        $contract->load(['customer', 'contacts', 'attachments']);

        $coordinatorIds = $request->input('coordinator_ids', []);

        $project = DB::transaction(function () use ($contract, $coordinatorIds) {
            $codeService = new ProjectCodeService();
            $parentProject = $contract->parent_project_id ? Project::find($contract->parent_project_id) : null;
            $codeData    = $codeService->resolveForStore($contract->project_code_preview, $contract->customer, $parentProject);

            $projectName = $contract->project_name
                ?: ($contract->customer->name . ' — ' . now()->format('m/Y'));

            $project = Project::create(array_merge($codeData, [
                'name'                  => $projectName,
                'parent_project_id'     => $contract->parent_project_id,
                'customer_id'           => $contract->customer_id,
                'service_type_id'       => $contract->service_type_id,
                'contract_type_id'      => $contract->contract_type_id,
                'sold_hours'            => $contract->horas_contratadas,
                'project_value'         => $contract->valor_projeto,
                'hourly_rate'           => $contract->valor_hora,
                'additional_hourly_rate' => $contract->hora_adicional,
                'coordinator_hours'     => $contract->pct_horas_coordenador !== null ? (int) $contract->pct_horas_coordenador : null,
                'consultant_hours'      => $contract->horas_consultor,
                'start_date'            => $contract->expectativa_inicio,
                'status'                => Project::STATUS_AWAITING_START,
                'contract_id'           => $contract->id,
                'tipo_alocacao'         => $contract->tipo_alocacao,
                'architect_id'          => $contract->architect_id,
                'condicao_pagamento'    => $contract->condicao_pagamento,
                'observacoes_contrato'  => $contract->observacoes,
                'cobra_despesa_cliente' => $contract->cobra_despesa_cliente,
                'executivo_conta_id'    => $contract->executivo_conta_id,
                'vendedor_id'           => $contract->vendedor_id,
            ]));

            // Copiar contatos
            foreach ($contract->contacts as $c) {
                ProjectContact::create([
                    'project_id'          => $project->id,
                    'contract_contact_id' => $c->id,
                    'name'                => $c->name,
                    'cargo'               => $c->cargo,
                    'email'               => $c->email,
                    'phone'               => $c->phone,
                ]);
            }

            // Referenciar anexos (sem duplicar arquivo)
            foreach ($contract->attachments as $a) {
                ProjectAttachment::create([
                    'project_id'             => $project->id,
                    'contract_attachment_id' => $a->id,
                ]);
            }

            // Vincular coordenadores: usa os selecionados no modal; fallback para o arquiteto do contrato
            if (empty($coordinatorIds) && $contract->architect_id) {
                $coordinatorIds = [$contract->architect_id];
            }
            if (!empty($coordinatorIds)) {
                $project->coordinators()->attach($coordinatorIds);
            }

            // Atualizar contrato
            $contract->update([
                'project_id'      => $project->id,
                'generated_at'    => now(),
                'generated_by_id' => auth()->id(),
                'status'          => Contract::STATUS_ATIVO,
            ]);

            return $project;
        });

        return response()->json([
            'project_id'   => $project->id,
            'project_code' => $project->code,
            'message'      => 'Projeto gerado com sucesso.',
        ]);
    }

    public function uploadAttachment(Request $request, Contract $contract): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:20480|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,txt,csv,zip',
            'type' => 'required|in:proposta,contrato,logo',
        ]);

        $file = $request->file('file');
        $path = $file->store("contracts/{$contract->id}/attachments");

        $attachment = ContractAttachment::create([
            'contract_id'    => $contract->id,
            'type'           => $request->input('type'),
            'path'           => $path,
            'original_name'  => $file->getClientOriginalName(),
            'mime_type'      => $file->getMimeType(),
            'size'           => $file->getSize(),
            'uploaded_by_id' => auth()->id(),
        ]);

        return response()->json($attachment, 201);
    }

    public function downloadAttachment(Contract $contract, ContractAttachment $attachment): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        abort_if($attachment->contract_id !== $contract->id, 404);
        abort_unless(Storage::exists($attachment->path), 404, 'Arquivo não encontrado.');

        return Storage::download($attachment->path, $attachment->original_name);
    }

    public function deleteAttachment(Contract $contract, ContractAttachment $attachment): JsonResponse
    {
        abort_if($attachment->contract_id !== $contract->id, 404);

        Storage::delete($attachment->path);
        $attachment->delete();

        return response()->json(null, 204);
    }

    // ─── Kanban Unificado ────────────────────────────────────────────────────

    public function kanban(Request $request): JsonResponse
    {
        $user      = auth()->user();
        $isConsultor = $user?->isConsultor();
        $isCliente   = $user?->isCliente();

        // ── Fase Demanda: contratos NÃO-sustentação (admin/coordenador vê todos; cliente vê subset)
        $demandCards = collect();
        if (!$isConsultor) {
            $demandQuery = Contract::with([
                'customer:id,name,executive_id',
                'customer.executive:id,name',
                'contractType:id,name',
                'serviceType:id,name',
                'kanbanCoordinator:id,name',
                'executivoConta:id,name',
                'project:id,code,name,status',
            ])->where(function ($q) {
                $q->whereIn('kanban_status', array_merge(Contract::DEMAND_COLUMNS, [Contract::KANBAN_INICIO_AUTORIZADO, Contract::KANBAN_ALOCADO, 'novo', 'novo_contrato']))
                  ->orWhereNull('kanban_status');
              })
              ->whereNull('sustentacao_column')
              ->orderBy('kanban_order');

            if ($isCliente && $user->customer_id) {
                $demandQuery->where('customer_id', $user->customer_id);
            }

            $demandCards = $demandQuery->get()->map(fn($c) => $this->formatKanbanCard($c));
        }

        // ── Fase Transição: contratos status=inicio_autorizado sem projeto gerado
        $transitionCards = collect();
        if (!$isConsultor && !$isCliente) {
            $transitionCards = Contract::with([
                'customer:id,name,executive_id',
                'customer.executive:id,name',
                'contractType:id,name',
                'serviceType:id,name',
                'project:id,code,name,status',
            ])->where('status', Contract::STATUS_INICIO_AUTORIZADO)
              ->whereNull('project_id')
              ->orderBy('kanban_order')
              ->get()
              ->map(fn($c) => $this->formatKanbanCard($c));
        }

        // ── Fase Projeto: projetos gerados a partir de contratos
        // Inclui também projetos referenciados por contract.project_id (contratos 'alocado')
        $demandProjectIds = $demandCards->pluck('project_id')->filter()->unique()->values()->toArray();

        $projectQuery = \App\Models\Project::with([
            'customer:id,name,executive_id',
            'customer.executive:id,name',
            'contract:id,project_name',
            'coordinators:id,name',
            'consultants:id,name',
            'contractType:id,name',
            'serviceType:id,name',
            'executivoConta:id,name',
        ])->where(function ($q) use ($demandProjectIds) {
            $q->where(function ($inner) {
                $inner->whereNotNull('contract_id')
                      ->whereHas('contract', fn($c) => $c->whereNull('sustentacao_column'));
            });
            if (!empty($demandProjectIds)) {
                $q->orWhereIn('id', $demandProjectIds);
            }
            // Projetos importados diretamente (sem contract_id) mas com coordenador vinculado
            $q->orWhere(function ($inner) {
                $inner->whereNull('contract_id')
                      ->whereHas('coordinators');
            });
        })->orderBy('updated_at', 'desc');

        if ($isConsultor) {
            $projectQuery->whereHas('consultants', fn($q) => $q->where('users.id', $user->id));
        } elseif ($isCliente && $user->customer_id) {
            $projectQuery->where('customer_id', $user->customer_id);
        } elseif ($user?->isCoordenador()) {
            $projectQuery->where(function ($q) {
                $q->whereDoesntHave('serviceType')
                  ->orWhereHas('serviceType', fn($sq) => $sq->whereRaw("LOWER(name) NOT SIMILAR TO '%(sustentac|cloud)%'"));
            });
        }

        $projects    = $projectQuery->get();
        $projectIds  = $projects->pluck('id')->toArray();
        $timesheetSums = count($projectIds) > 0
            ? DB::table('timesheets')
                ->whereIn('project_id', $projectIds)
                ->where('status', '!=', 'rejected')
                ->groupBy('project_id')
                ->selectRaw('project_id, SUM(effort_minutes) as total_minutes')
                ->pluck('total_minutes', 'project_id')
                ->toArray()
            : [];

        $projectCards = $projects->map(fn($p) => $this->formatProjectCard($p, (float) ($timesheetSums[$p->id] ?? 0)));

        // ── Coordenadores ativos (apenas projetos — sustentação tem colunas próprias)
        // Inclui: coordenadores com coordinator_type=projetos + admins definidos em algum projeto
        $coordinators = User::where('enabled', true)
            ->where(function ($q) {
                $q->where(function ($inner) {
                    $inner->where('type', 'coordenador')
                          ->where('coordinator_type', 'projetos');
                })->orWhere(function ($inner) {
                    $inner->where('type', 'admin')
                          ->whereHas('coordinatorProjects');
                });
            })
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        // ── Sustentação / Cloud / Bizify — colunas agrupadas
        $sustentacaoGroups = [
            'sust_bh_fixo'   => [],
            'sust_bh_mensal' => [],
            'sust_on_demand' => [],
            'sust_cloud'     => [],
            'sust_bizify'    => [],
        ];
        $sustentacaoAutoCards = collect(); // backward compat
        if (!$isConsultor && !$isCliente) {
            // Todos contratos alocados numa fila de sustentação aparecem na coluna correta
            $sustCards = Contract::with([
                'customer:id,name,executive_id',
                'customer.executive:id,name',
                'contractType:id,name',
                'serviceType:id,name',
                'executivoConta:id,name',
                'project.customer:id,name,executive_id',
                'project.customer.executive:id,name',
                'project.executivoConta:id,name',
                'project.coordinators',
                'project.consultants',
                'project.contractType:id,name',
                'project.serviceType:id,name',
            ])
            ->whereNotNull('sustentacao_column')
            ->orderBy('kanban_order')
            ->get();

            foreach ($sustCards as $c) {
                $col = $c->sustentacao_column;
                if (!$col) {
                    $svcCode      = $c->serviceType?->code ?? '';
                    $svcName      = strtolower($c->serviceType?->name ?? '');
                    $contractName = strtolower($c->contractType?->name ?? '');
                    if ($svcCode === 'bizify' || str_contains($svcName, 'bizify')) {
                        $col = 'sust_bizify';
                    } elseif (str_contains($svcName, 'cloud')) {
                        $col = 'sust_cloud';
                    } elseif ($c->tipo_faturamento === 'banco_horas_mensal') {
                        $col = 'sust_bh_mensal';
                    } elseif ($c->tipo_faturamento === 'on_demand') {
                        $col = 'sust_on_demand';
                    } else {
                        $col = 'sust_bh_fixo';
                    }
                }
                if ($c->project_id && $c->project) {
                    $formatted = $this->formatProjectCard($c->project);
                    $formatted['kanban_order'] = $c->kanban_order;
                } else {
                    $formatted = $this->formatKanbanCard($c);
                }
                $formatted['sustentacao_column'] = $col;
                $sustentacaoGroups[$col][] = $formatted;
                $sustentacaoAutoCards[] = $formatted;
            }
        }

        // ── Requisições pendentes (contract_requests sem contrato gerado)
        $requestCards = collect();
        if (!$isConsultor) {
            $reqQuery = \App\Models\ContractRequest::with(['customer:id,name', 'createdBy:id,name', 'linkedContract:id,project_id,project_name', 'linkedContract.project:id,code'])
                ->where(function ($q) {
                    $q->whereNull('contract_id')
                      ->orWhereIn('kanban_column', ['req_planejamento', 'req_inicio_autorizado', 'inicio_autorizado', 'req_em_andamento']);
                })
                ->whereIn('status', [\App\Models\ContractRequest::STATUS_PENDENTE, \App\Models\ContractRequest::STATUS_EM_ANALISE, \App\Models\ContractRequest::STATUS_APROVADO]);

            if ($isCliente && $user->customer_id) {
                $reqQuery->where('customer_id', $user->customer_id);
            }

            $requestCards = $reqQuery->orderBy('created_at', 'desc')->get()->map(fn($r) => [
                'card_type'              => 'request',
                'id'                     => $r->id,
                'customer_name'          => $r->customer?->name ?? '—',
                'customer_id'            => $r->customer_id,
                'area_requisitante'      => $r->area_requisitante,
                'project_name'           => $r->project_name,
                'product_owner'          => $r->product_owner,
                'modulo_tecnologia'      => $r->modulo_tecnologia,
                'tipo_necessidade'       => $r->tipo_necessidade,
                'tipo_necessidade_outro' => $r->tipo_necessidade_outro,
                'nivel_urgencia'         => $r->nivel_urgencia,
                'descricao'              => $r->descricao,
                'cenario_atual'          => $r->cenario_atual,
                'cenario_desejado'       => $r->cenario_desejado,
                'status'                 => $r->status,
                'kanban_column'          => $r->kanban_column ?? 'backlog',
                'req_decision'           => $r->req_decision,
                'linked_contract_id'     => $r->linked_contract_id,
                'linked_contract_code'   => $r->linkedContract?->project?->code,
                'linked_coordinator_id'  => $r->linked_coordinator_id,
                'created_at'             => $r->created_at?->toISOString(),
            ]);
        }

        return response()->json([
            'demand_cards'          => $demandCards,
            'transition_cards'      => $transitionCards,
            'project_cards'         => $projectCards,
            'sustentacao_auto_cards'=> $sustentacaoAutoCards,
            'sustentacao_groups'    => $sustentacaoGroups,
            'request_cards'         => $requestCards,
            'coordinators'          => $coordinators,
            'user_role'             => $user?->type ?? 'admin',
            'contracts'             => $demandCards,
        ]);
    }

    public function kanbanMove(Request $request, Contract $contract): JsonResponse
    {
        $request->validate([
            'to_column'      => 'required|string',
            'coordinator_id' => 'nullable|exists:users,id',
            'order'          => 'nullable|integer',
        ]);

        $toColumn      = $request->input('to_column');
        $coordinatorId = $request->input('coordinator_id');
        $fromColumn    = $this->resolveColumnName($contract);

        $validDemandColumns = array_merge(Contract::DEMAND_COLUMNS, [Contract::KANBAN_ALOCADO]);

        // Mover para coluna de coordenador (legado) ou para "alocado" = gerar projeto
        if (str_starts_with($toColumn, 'coordinator:') || $toColumn === Contract::KANBAN_ALOCADO) {
            if (str_starts_with($toColumn, 'coordinator:')) {
                $coordinatorId = (int) str_replace('coordinator:', '', $toColumn);
            }

            if (!$contract->isKanbanComplete()) {
                return response()->json([
                    'message' => 'Contrato incompleto. Preencha: cliente, tipo de contrato e faturamento.',
                ], 422);
            }

            if ($contract->project_id) {
                $contract->update([
                    'kanban_status'         => Contract::KANBAN_ALOCADO,
                    'kanban_coordinator_id' => $coordinatorId,
                    'kanban_order'          => $request->input('order', 0),
                    'sustentacao_column'    => null,
                ]);
            } else {
                if (!in_array($contract->status, [Contract::STATUS_INICIO_AUTORIZADO, Contract::STATUS_APROVADO])) {
                    $contract->update(['status' => Contract::STATUS_APROVADO]);
                }

                $contract->load(['customer', 'contacts', 'attachments']);

                DB::transaction(function () use ($contract, $coordinatorId, $request) {
                    $project = $this->createProjectFromContract($contract, $coordinatorId);

                    $contract->update([
                        'project_id'            => $project->id,
                        'generated_at'          => now(),
                        'generated_by_id'       => auth()->id(),
                        'status'                => Contract::STATUS_ATIVO,
                        'kanban_status'         => Contract::KANBAN_ALOCADO,
                        'kanban_coordinator_id' => $coordinatorId,
                        'kanban_order'          => $request->input('order', 0),
                        'sustentacao_column'    => null,
                    ]);
                });
            }
        } elseif (str_starts_with($toColumn, 'sust_')) {
            if ($err = $this->validateSustentacaoContractType($contract, $toColumn)) {
                return $err;
            }
            // Mover para fila de sustentação — define sustentacao_column
            $contract->update([
                'sustentacao_column' => $toColumn,
                'kanban_order'       => $request->input('order', 0),
            ]);
        } elseif ($toColumn === 'req_inicio_autorizado') {
            $contract->update([
                'kanban_status' => 'req_inicio_autorizado',
                'kanban_order'  => $request->input('order', 0),
            ]);
        } elseif ($toColumn === Contract::KANBAN_INICIO_AUTORIZADO) {
            $parentProjectId = $request->input('parent_project_id');
            // Projeto filho não exige completude pois herda do projeto pai
            if (!$parentProjectId && !$contract->isKanbanComplete()) {
                return response()->json([
                    'message' => 'Contrato incompleto. Preencha: cliente, tipo de contrato e faturamento.',
                ], 422);
            }
            $updateData = [
                'kanban_status' => Contract::KANBAN_INICIO_AUTORIZADO,
                'status'        => Contract::STATUS_INICIO_AUTORIZADO,
                'kanban_order'  => $request->input('order', 0),
            ];
            if ($parentProjectId) {
                $updateData['parent_project_id'] = $parentProjectId;
            }
            $contract->update($updateData);

            // Avança a requisição vinculada para 'inicio_autorizado' sempre que o contrato é autorizado.
            // Cobre dois caminhos: novo_projeto → inicio_autorizado E req_inicio_autorizado → inicio_autorizado.
            \App\Models\ContractRequest::where('linked_contract_id', $contract->id)
                ->where('kanban_column', 'req_inicio_autorizado')
                ->update(['kanban_column' => 'inicio_autorizado']);
        } elseif (in_array($toColumn, ['cancelado', 'pausado'])) {
            // Contrato sem projeto: cancelar ou pausar remove do kanban ativo
            $contract->update([
                'kanban_status'         => $toColumn,
                'kanban_coordinator_id' => null,
                'kanban_order'          => 0,
            ]);
        } elseif (in_array($toColumn, $validDemandColumns)) {
            $contract->update([
                'kanban_status'         => $toColumn,
                'kanban_coordinator_id' => null,
                'kanban_order'          => $request->input('order', 0),
            ]);
        }

        ContractKanbanLog::create([
            'contract_id'    => $contract->id,
            'from_column'    => $fromColumn,
            'to_column'      => $toColumn,
            'moved_by_id'    => auth()->id(),
            'coordinator_id' => $coordinatorId ?? null,
        ]);

        return response()->json($this->formatKanbanCard($contract->fresh(['customer', 'contractType', 'serviceType', 'kanbanCoordinator', 'project'])));
    }

    // Mover projeto de fase de execução (em_andamento → liberado_para_testes → encerrado)
    public function projectMove(Request $request, \App\Models\Project $project): JsonResponse
    {
        $validated = $request->validate([
            'status'              => 'nullable|string|in:awaiting_start,started,liberado_para_testes,paused,cancelled,finished',
            'coordinator_id'      => 'nullable|integer|exists:users,id',
            'from_coordinator_id' => 'nullable|integer|exists:users,id',
        ]);

        $user = auth()->user();
        if ($user?->isConsultor()) {
            return response()->json(['message' => 'Sem permissão para mover projetos.'], 403);
        }

        // Coordinator reassignment
        if ($validated['coordinator_id'] ?? null) {
            $newCoordId  = (int) $validated['coordinator_id'];
            $fromCoordId = isset($validated['from_coordinator_id']) ? (int) $validated['from_coordinator_id'] : null;

            if ($fromCoordId && $project->coordinators()->where('users.id', $fromCoordId)->exists()) {
                $project->coordinators()->detach($fromCoordId);
            }
            if (!$project->coordinators()->where('users.id', $newCoordId)->exists()) {
                $project->coordinators()->attach($newCoordId);
            }

            if ($project->contract_id) {
                \App\Models\Contract::where('id', $project->contract_id)
                    ->where('kanban_coordinator_id', $fromCoordId)
                    ->update(['kanban_coordinator_id' => $newCoordId]);
            }

            // Reativação: muda status junto com coordenador (ex: terminal → awaiting_start)
            if ($validated['status'] ?? null) {
                $fromStatus = $project->status;
                $project->update(['status' => $validated['status']]);
                ProjectKanbanLog::create([
                    'project_id'  => $project->id,
                    'from_status' => $fromStatus,
                    'to_status'   => $validated['status'],
                    'moved_by_id' => auth()->id(),
                ]);
            }

            return response()->json($this->formatProjectCard($project->fresh(['customer', 'contract', 'coordinators', 'consultants'])));
        }

        // Status move
        if (!($validated['status'] ?? null)) {
            return response()->json(['message' => 'status ou coordinator_id é obrigatório.'], 422);
        }

        $fromStatus  = $project->status;
        $newStatus   = $validated['status'];
        $project->update(['status' => $newStatus]);

        ProjectKanbanLog::create([
            'project_id'  => $project->id,
            'from_status' => $fromStatus,
            'to_status'   => $newStatus,
            'moved_by_id' => auth()->id(),
        ]);

        // Projeto de sustentação encerrado/pausado/cancelado → sai da fila de sustentação
        if (in_array($newStatus, ['paused', 'cancelled', 'finished']) && $project->contract_id) {
            \App\Models\Contract::where('id', $project->contract_id)
                ->whereNotNull('sustentacao_column')
                ->update(['sustentacao_column' => null]);
        }

        return response()->json($this->formatProjectCard($project->fresh(['customer', 'contract', 'coordinators', 'consultants'])));
    }

    public function sustentacaoMove(Request $request, Contract $contract): JsonResponse
    {
        $request->validate([
            'to_column' => 'required|in:sust_bh_fixo,sust_bh_mensal,sust_on_demand,sust_cloud,sust_bizify',
        ]);

        $user = auth()->user();
        if (!$user?->isAdmin() && !($user?->isCoordenador() && $user?->coordinator_type === 'sustentacao')) {
            return response()->json(['message' => 'Apenas admin ou coordenador de sustentação pode mover este card.'], 403);
        }

        $toColumn  = $request->input('to_column');

        if ($err = $this->validateSustentacaoContractType($contract, $toColumn)) {
            return $err;
        }

        $projectId = $contract->project_id;

        if (!$projectId) {
            $contract->load(['customer', 'contacts', 'attachments']);
            DB::transaction(function () use ($contract, $toColumn, &$projectId) {
                $project   = $this->createProjectFromContract($contract, null);
                $projectId = $project->id;
                $contract->update([
                    'project_id'            => $project->id,
                    'generated_at'          => now(),
                    'generated_by_id'       => auth()->id(),
                    'sustentacao_column'    => $toColumn,
                    'kanban_coordinator_id' => null,
                    'kanban_status'         => Contract::KANBAN_INICIO_AUTORIZADO,
                ]);
            });
        } else {
            $contract->update([
                'sustentacao_column'    => $toColumn,
                'kanban_coordinator_id' => null,
                'kanban_status'         => Contract::KANBAN_INICIO_AUTORIZADO,
            ]);
        }

        return response()->json(['ok' => true, 'sustentacao_column' => $toColumn, 'project_id' => $projectId, 'project_created' => !$contract->getOriginal('project_id')]);
    }

    public function requestPlanDecision(Request $request, \App\Models\ContractRequest $contractRequest): JsonResponse
    {
        $data = $request->validate([
            'decision'          => 'required|in:novo_projeto,subprojeto',
            'project_id'        => 'nullable|exists:projects,id',
            'coordinator_id'    => 'nullable|exists:users,id',
            // contrato já criado externamente (via modal completo)
            'contract_id'       => 'nullable|exists:contracts,id',
            // campos para criar novo contrato (usado se contract_id não fornecido)
            'project_name'      => 'nullable|string|max:255',
            'categoria'         => 'nullable|in:projeto,sustentacao',
            'service_type_id'   => 'nullable|exists:service_types,id',
            'contract_type_id'  => 'nullable|exists:contract_types,id',
            'horas_contratadas' => 'nullable|integer|min:0',
            'tipo_faturamento'  => 'nullable|string',
            'valor_projeto'     => 'nullable|numeric|min:0',
        ]);

        $decision            = $data['decision'];
        $linkedContractId    = null;
        $linkedProjectId     = null;
        $linkedCoordinatorId = $data['coordinator_id'] ?? null;
        // novo_projeto: req aguarda em req_inicio_autorizado; subprojeto: vai direto para inicio_autorizado
        $toColumn = $decision === 'novo_projeto' ? 'req_inicio_autorizado' : 'inicio_autorizado';

        if ($decision === 'novo_projeto') {
            if (!empty($data['contract_id'])) {
                // Contrato já criado pelo modal completo — apenas vincular; mantém no kanban de contratos
                $contract = \App\Models\Contract::findOrFail($data['contract_id']);
                $contract->update(['kanban_status' => \App\Models\Contract::KANBAN_NOVO_PROJETO]);
                $linkedContractId = $contract->id;
            } else {
                $contract = \App\Models\Contract::create([
                    'customer_id'       => $contractRequest->customer_id,
                    'created_by_id'     => auth()->id(),
                    'status'            => \App\Models\Contract::STATUS_APROVADO,
                    'kanban_status'     => \App\Models\Contract::KANBAN_NOVO_PROJETO,
                    'project_name'      => $data['project_name'] ?? null,
                    'categoria'         => $data['categoria'] ?? 'projeto',
                    'service_type_id'   => $data['service_type_id'] ?? null,
                    'contract_type_id'  => $data['contract_type_id'] ?? null,
                    'horas_contratadas' => $data['horas_contratadas'] ?? 0,
                    'tipo_faturamento'  => $data['tipo_faturamento'] ?? null,
                    'valor_projeto'     => $data['valor_projeto'] ?? null,
                ]);
                $linkedContractId = $contract->id;
            }
        } else {
            // subprojeto: contrato aguarda em inicio_autorizado (igual ao novo_projeto)
            // O coordenador aloca no Kanban de Contratos; project_id é setado quando o projeto pai é gerado.
            $linkedProjectId = $data['project_id'] ?? null;
            if (!empty($data['contract_id'])) {
                $contract = \App\Models\Contract::findOrFail($data['contract_id']);
                $contract->update([
                    'kanban_status' => \App\Models\Contract::KANBAN_INICIO_AUTORIZADO,
                    'status'        => \App\Models\Contract::STATUS_INICIO_AUTORIZADO,
                    'parent_project_id' => $linkedProjectId,
                ]);
                $linkedContractId = $contract->id;
            }
        }

        $contractRequest->update([
            'req_decision'         => $decision,
            'linked_contract_id'   => $linkedContractId,
            'linked_project_id'    => $linkedProjectId,
            'linked_coordinator_id'=> $linkedCoordinatorId,
            'kanban_column'        => $toColumn,
        ]);

        \App\Models\ContractRequestKanbanLog::create([
            'contract_request_id' => $contractRequest->id,
            'from_column'         => $contractRequest->getOriginal('kanban_column') ?? 'req_inicio_autorizado',
            'to_column'           => $toColumn,
            'moved_by_id'         => auth()->id(),
        ]);

        return response()->json(['ok' => true, 'linked_contract_id' => $linkedContractId, 'linked_project_id' => $linkedProjectId]);
    }

    public function requestFinalize(Request $request, \App\Models\ContractRequest $contractRequest): JsonResponse
    {
        $data = $request->validate([
            'coordinator_id' => 'nullable|exists:users,id',
        ]);

        $linkedContractId = $contractRequest->linked_contract_id;
        $coordinatorId    = $data['coordinator_id'] ?? $contractRequest->linked_coordinator_id;

        if ($contractRequest->req_decision === 'subprojeto') {
            // Subprojeto: apenas fecha a requisição; o projeto já existe
            $contractRequest->update(['kanban_column' => 'req_em_andamento']);
        } else {
            if (!$linkedContractId) {
                return response()->json(['message' => 'Requisição sem contrato vinculado.'], 422);
            }

            $contract = \App\Models\Contract::findOrFail($linkedContractId);
            $contract->load(['customer', 'contacts', 'attachments']);

            DB::transaction(function () use ($contract, $coordinatorId, $linkedContractId, $contractRequest) {
                if (!$contract->project_id) {
                    $codeService   = new \App\Services\ProjectCodeService();
                    $parentProject = $contract->parent_project_id ? \App\Models\Project::find($contract->parent_project_id) : null;
                    $codeData      = $codeService->resolveForStore($contract->project_code_preview, $contract->customer, $parentProject);
                    $projectName   = $contract->project_name ?: ($contract->customer->name . ' — ' . now()->format('m/Y'));

                    $project = \App\Models\Project::create(array_merge($codeData, [
                        'name'                   => $projectName,
                        'parent_project_id'      => $contract->parent_project_id,
                        'customer_id'            => $contract->customer_id,
                        'service_type_id'        => $contract->service_type_id,
                        'contract_type_id'       => $contract->contract_type_id,
                        'sold_hours'             => $contract->horas_contratadas,
                        'project_value'          => $contract->valor_projeto,
                        'hourly_rate'            => $contract->valor_hora,
                        'additional_hourly_rate' => $contract->hora_adicional,
                        'coordinator_hours'      => $contract->pct_horas_coordenador !== null ? (int) $contract->pct_horas_coordenador : null,
                        'consultant_hours'       => $contract->horas_consultor,
                        'start_date'             => $contract->expectativa_inicio,
                        'status'                 => \App\Models\Project::STATUS_AWAITING_START,
                        'contract_id'            => $contract->id,
                        'contract_request_id'    => $contractRequest->id,
                        'tipo_alocacao'          => $contract->tipo_alocacao,
                        'architect_id'           => $contract->architect_id,
                        'condicao_pagamento'     => $contract->condicao_pagamento,
                        'observacoes_contrato'   => $contract->observacoes,
                        'cobra_despesa_cliente'  => $contract->cobra_despesa_cliente,
                        'executivo_conta_id'     => $contract->executivo_conta_id,
                        'vendedor_id'            => $contract->vendedor_id,
                    ]));

                    foreach ($contract->contacts as $c) {
                        \App\Models\ProjectContact::create(['project_id' => $project->id, 'contract_contact_id' => $c->id, 'name' => $c->name, 'cargo' => $c->cargo, 'email' => $c->email, 'phone' => $c->phone]);
                    }
                    foreach ($contract->attachments as $a) {
                        \App\Models\ProjectAttachment::create(['project_id' => $project->id, 'contract_attachment_id' => $a->id]);
                    }
                    if ($coordinatorId) {
                        $project->coordinators()->attach($coordinatorId);
                    }

                    $contract->update([
                        'project_id'            => $project->id,
                        'generated_at'          => now(),
                        'generated_by_id'       => auth()->id(),
                        'status'                => \App\Models\Contract::STATUS_ATIVO,
                        'kanban_status'         => \App\Models\Contract::KANBAN_ALOCADO,
                        'kanban_coordinator_id' => $coordinatorId,
                    ]);
                } else {
                    $contract->update([
                        'kanban_status'         => \App\Models\Contract::KANBAN_ALOCADO,
                        'kanban_coordinator_id' => $coordinatorId,
                    ]);
                }

                $contractRequest->update([
                    'contract_id'   => $linkedContractId,
                    'kanban_column' => 'req_em_andamento',
                ]);
            });
        }

        \App\Models\ContractRequestKanbanLog::create([
            'contract_request_id' => $contractRequest->id,
            'from_column'         => 'req_inicio_autorizado',
            'to_column'           => 'req_em_andamento',
            'moved_by_id'         => auth()->id(),
        ]);

        return response()->json(['ok' => true]);
    }

    public function requestKanbanMove(Request $request, \App\Models\ContractRequest $contractRequest): JsonResponse
    {
        $request->validate(['kanban_column' => 'required|string']);

        $fromColumn = $contractRequest->kanban_column ?? 'backlog';
        $toColumn   = $request->input('kanban_column');

        $contractRequest->update(['kanban_column' => $toColumn]);

        \App\Models\ContractRequestKanbanLog::create([
            'contract_request_id' => $contractRequest->id,
            'from_column'         => $fromColumn,
            'to_column'           => $toColumn,
            'moved_by_id'         => auth()->id(),
        ]);

        return response()->json(['ok' => true]);
    }

    private const SUST_COLUMN_CONTRACT_TYPE = [
        'sust_bh_fixo'   => 'fixed_hours',
        'sust_bh_mensal' => 'monthly_hours',
        'sust_on_demand' => 'on_demand',
        'sust_cloud'     => 'cloud',
    ];

    private function validateSustentacaoContractType(Contract $contract, string $toColumn): ?\Illuminate\Http\JsonResponse
    {
        // Coluna Bizify aceita qualquer contract_type, mas exige service_type = Bizify
        if ($toColumn === 'sust_bizify') {
            $contract->loadMissing('serviceType');
            $svcCode = $contract->serviceType?->code;
            $svcName = strtolower($contract->serviceType?->name ?? '');
            if ($svcCode !== 'bizify' && !str_contains($svcName, 'bizify')) {
                $actual = $contract->serviceType?->name ?? 'não definido';
                return response()->json([
                    'message' => "A coluna \"Bizify\" aceita apenas contratos com Tipo de Serviço \"Bizify\". Este contrato tem serviço \"{$actual}\".",
                ], 422);
            }
            return null;
        }

        $expectedCode = self::SUST_COLUMN_CONTRACT_TYPE[$toColumn] ?? null;
        if (!$expectedCode) return null;

        $contract->loadMissing('contractType');
        $actualCode = $contract->contractType?->code;

        // Fechado, cancelado e pausado transitam livremente entre colunas
        if ($actualCode === 'closed') return null;
        if ($contract->project_id) {
            $contract->loadMissing('project');
            $projectStatus = $contract->project?->status;
            if (in_array($projectStatus, ['cancelled', 'paused'])) return null;
        }

        if ($actualCode !== $expectedCode) {
            $labels = [
                'sust_bh_fixo'   => 'Banco de Horas Fixo',
                'sust_bh_mensal' => 'Banco de Horas Mensal',
                'sust_on_demand' => 'On Demand',
                'sust_cloud'     => 'Cloud',
            ];
            $actual = $contract->contractType?->name ?? 'não definido';
            return response()->json([
                'message' => "Esta coluna aceita apenas contratos do tipo \"{$labels[$toColumn]}\". Este contrato é do tipo \"{$actual}\".",
            ], 422);
        }

        return null;
    }

    private function resolveColumnName(Contract $contract): string
    {
        if ($contract->kanban_status === Contract::KANBAN_ALOCADO && $contract->kanban_coordinator_id) {
            return 'coordinator:' . $contract->kanban_coordinator_id;
        }
        return $contract->kanban_status ?? Contract::KANBAN_BACKLOG;
    }

    private function formatKanbanCard(Contract $contract): array
    {
        return [
            'card_type'        => 'contract',
            'id'               => $contract->id,
            'customer_name'    => $contract->customer?->name,
            'customer_id'      => $contract->customer_id,
            'project_name'     => $contract->project_name,
            'categoria'        => $contract->categoria,
            'contract_type'    => $contract->contractType?->name,
            'contract_type_id' => $contract->contract_type_id,
            'service_type'     => $contract->serviceType?->name,
            'tipo_faturamento' => $contract->tipo_faturamento,
            'horas_contratadas'=> $contract->horas_contratadas,
            'valor_projeto'    => $contract->valor_projeto,
            'kanban_status'    => $contract->kanban_status ?? Contract::KANBAN_BACKLOG,
            'kanban_coordinator_id' => $contract->kanban_coordinator_id,
            'kanban_coordinator'    => $contract->kanbanCoordinator?->name,
            'executivo_conta_name'  => $contract->executivoConta?->name ?? $contract->customer?->executive?->name,
            'kanban_order'     => $contract->kanban_order,
            'status'           => $contract->status,
            'project_id'       => $contract->project_id,
            'project_code'     => $contract->project?->code,
            'project_status'   => $contract->project?->status,
            'is_complete'      => $contract->isKanbanComplete(),
            'created_at'       => $contract->created_at,
        ];
    }

    private function formatProjectCard(\App\Models\Project $project, float $loggedMinutes = 0): array
    {
        $consumed = round($loggedMinutes / 60, 1);
        $saldo    = round(($project->sold_hours ?? 0) - $consumed, 1);

        return [
            'card_type'             => 'project',
            'id'                    => $project->id,
            'contract_id'           => $project->contract_id,
            'contract_request_id'   => $project->contract_request_id,
            'customer_name'         => $project->customer?->name,
            'customer_id'           => $project->customer_id,
            'project_name'          => $project->name,
            'code'                  => $project->code,
            'status'                => $project->status,
            'sold_hours'            => $project->sold_hours,
            'consumed_hours'        => $consumed,
            'general_hours_balance' => $saldo,
            'project_value'         => $project->project_value,
            'start_date'            => $project->start_date,
            'expected_end_date'     => $project->expected_end_date,
            'coordinator_ids'       => $project->coordinators->pluck('id'),
            'coordinators'          => $project->coordinators->pluck('name'),
            'consultants'           => $project->consultants->pluck('name'),
            'executivo_conta_name'  => $project->executivoConta?->name ?? $project->customer?->executive?->name,
            'contract_type'         => $project->contractType?->name,
            'service_type'          => $project->serviceType?->name,
            'is_complete'           => true,
            'created_at'            => $project->created_at,
        ];
    }

    private function createProjectFromContract(Contract $contract, ?int $coordinatorId): Project
    {
        $codeService   = new ProjectCodeService();
        $parentProject = $contract->parent_project_id ? Project::find($contract->parent_project_id) : null;
        $codeData      = $codeService->resolveForStore($contract->project_code_preview, $contract->customer, $parentProject);
        $projectName   = $contract->project_name ?: ($contract->customer->name . ' — ' . now()->format('m/Y'));

        $project = Project::create(array_merge($codeData, [
            'name'                   => $projectName,
            'parent_project_id'      => $contract->parent_project_id,
            'customer_id'            => $contract->customer_id,
            'service_type_id'        => $contract->service_type_id,
            'contract_type_id'       => $contract->contract_type_id,
            'sold_hours'             => $contract->horas_contratadas,
            'project_value'          => $contract->valor_projeto,
            'hourly_rate'            => $contract->valor_hora,
            'additional_hourly_rate' => $contract->hora_adicional,
            'coordinator_hours'      => $contract->pct_horas_coordenador !== null ? (int) $contract->pct_horas_coordenador : null,
            'consultant_hours'       => $contract->horas_consultor,
            'start_date'             => $contract->expectativa_inicio,
            'status'                 => Project::STATUS_AWAITING_START,
            'contract_id'            => $contract->id,
            'tipo_alocacao'          => $contract->tipo_alocacao,
            'architect_id'           => $contract->architect_id,
            'condicao_pagamento'     => $contract->condicao_pagamento,
            'observacoes_contrato'   => $contract->observacoes,
            'cobra_despesa_cliente'  => $contract->cobra_despesa_cliente,
            'executivo_conta_id'     => $contract->executivo_conta_id,
            'vendedor_id'            => $contract->vendedor_id,
        ]));

        foreach ($contract->contacts as $c) {
            ProjectContact::create(['project_id' => $project->id, 'contract_contact_id' => $c->id, 'name' => $c->name, 'cargo' => $c->cargo, 'email' => $c->email, 'phone' => $c->phone]);
        }
        foreach ($contract->attachments as $a) {
            ProjectAttachment::create(['project_id' => $project->id, 'contract_attachment_id' => $a->id]);
        }

        if ($coordinatorId) {
            $project->coordinators()->attach($coordinatorId);
        }

        return $project;
    }

    public function events(Contract $contract): JsonResponse
    {
        $events = ContractEvent::where('contract_id', $contract->id)
            ->with('triggeredBy:id,name')
            ->latest()
            ->limit(100)
            ->get();

        return response()->json(['data' => $events]);
    }

    public function snapshot(Contract $contract): JsonResponse
    {
        $cached = Cache::get(ContractEventListener::cacheKey($contract->id));

        if ($cached !== null) {
            return response()->json(['data' => $cached]);
        }

        $snap = ContractFlowSnapshot::where('contract_id', $contract->id)
            ->with('updatedBy:id,name')
            ->first();

        // Nunca cachear null
        if ($snap !== null) {
            Cache::put(ContractEventListener::cacheKey($contract->id), $snap, ContractEventListener::CACHE_TTL);
        }

        return response()->json(['data' => $snap]);
    }

    public function replay(Request $request, Contract $contract): JsonResponse
    {
        if (!$request->user()->isAdmin()) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        $snap = ContractFlowSnapshot::where('contract_id', $contract->id)->first();

        if ($snap && $snap->replay_in_progress) {
            return response()->json(['message' => 'Replay já em andamento para este contrato'], 409);
        }

        // Marcar como em andamento antes de despachar
        if ($snap) {
            $snap->update(['replay_in_progress' => true]);
        }

        Cache::forget(ContractEventListener::cacheKey($contract->id));

        $fromSequence = (int) $request->input('from_sequence', 0);

        dispatch(new ReplayContractEventsJob($contract->id, $fromSequence));

        return response()->json([
            'status'        => 'queued',
            'contract_id'   => $contract->id,
            'from_sequence' => $fromSequence,
        ], 202);
    }

    public function consistencyReport(Request $request): JsonResponse
    {
        if (!$request->user()->isAdmin()) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        $totalSnapshots = ContractFlowSnapshot::count();

        $withInconsistencies = ContractFlowSnapshot::where('inconsistency_count', '>', 0)->count();

        $topProblematic = ContractFlowSnapshot::where('inconsistency_count', '>', 0)
            ->with('contract:id,project_name')
            ->orderByDesc('inconsistency_count')
            ->limit(10)
            ->get(['contract_id', 'inconsistency_count', 'version', 'last_sequence']);

        $queueDepth   = DB::table('jobs')->count();
        $failedLast24 = DB::table('failed_jobs')
            ->where('failed_at', '>=', now()->subHours(24))
            ->count();

        $contractsWithoutSnapshot = \App\Models\Contract::whereNotIn(
            'id', ContractFlowSnapshot::select('contract_id')
        )->count();

        return response()->json([
            'total_snapshots'             => $totalSnapshots,
            'with_inconsistencies'        => $withInconsistencies,
            'contracts_without_snapshot'  => $contractsWithoutSnapshot,
            'top_problematic'             => $topProblematic,
            'queue_depth'                 => $queueDepth,
            'failed_jobs_last_24h'        => $failedLast24,
        ]);
    }
}
