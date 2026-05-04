<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Customer;
use App\Models\ServiceType;
use App\Models\ContractType;
use App\Models\User;
use App\Models\ProjectChangeLog;
use App\Models\ProjectAttachment;
use App\Services\ProjectCodeService;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

/**
 * @OA\Tag(
 *     name="Projects",
 *     description="Gerenciamento de Projetos"
 * )
 */
class ProjectController extends Controller
{
    use \App\Http\Traits\ListCacheable;
    /**
     * @OA\Get(
     *     path="/api/v1/projects",
     *     tags={"Projects"},
     *     summary="Listar projetos",
     *     description="Lista projetos com paginação, filtros e ordenação",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer", minimum=1, example=1),
     *         description="Página (padrão: 1)"
     *     ),
     *     @OA\Parameter(
     *         name="pageSize",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer", minimum=1, maximum=100, example=15),
     *         description="Registros por página (padrão: 15, máximo: 100)"
     *     ),
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="string", example="Website"),
     *         description="Busca por name, code ou description",
     *     ),
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="string", example="started"),
     *         description="Filtrar por status (active, awaiting_start, started, paused, cancelled, finished)"
     *     ),
     *     @OA\Parameter(
     *         name="customer_id",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer", example=1),
     *         description="Filtrar por cliente"
     *     ),
     *     @OA\Parameter(
     *         name="approver_id",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer", example=1),
     *         description="Filtrar por aprovador"
     *     ),
     *     @OA\Parameter(
     *         name="contract_type_name",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="string", example="Banco de Horas Mensal"),
     *         description="Filtrar por nome do tipo de contrato"
     *     ),
     *     @OA\Parameter(
     *         name="order",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="string", example="name,-created_at"),
     *         description="Ordenação (ex: name,-created_at)",
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lista de projetos",
     *         @OA\JsonContent(
     *             @OA\Property(property="hasNext", type="boolean", example=true),
     *             @OA\Property(
     *                 property="items",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer"),
     *                     @OA\Property(property="name", type="string"),
     *                     @OA\Property(property="code", type="string"),
     *                     @OA\Property(property="description", type="string"),
     *                     @OA\Property(property="status", type="string"),
     *                     @OA\Property(property="status_display", type="string"),
     *                     @OA\Property(property="contract_type", type="string"),
     *                     @OA\Property(property="contract_type_display", type="string"),
     *                     @OA\Property(property="customer", type="object"),
     *                     @OA\Property(property="service_type", type="object"),
     *                     @OA\Property(property="created_at", type="string"),
     *                     @OA\Property(property="updated_at", type="string")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Não autenticado"),
     *     @OA\Response(response=403, description="Sem permissão")
     * )
     */
    /**
     * Retorna apenas os projetos do usuário logado (sem permissão projects.view).
     * Usado pelo meu-painel para popular dropdowns de cliente/projeto.
     */
    public function myProjects(Request $request): JsonResponse
    {
        $request->merge(['consultant_only' => 'true']);
        return $this->index($request);
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min($request->get('pageSize', $request->get('per_page', 15)), 200);
        $minimal = $request->boolean('minimal');
        $search = $request->get('filter') ?? $request->get('search');
        $status = $request->get('status');
        $codeExact = $request->get('code');

        // Validação exata de código (usado pelo frontend para checar unicidade antes de salvar)
        if ($codeExact) {
            $excludeId = $request->get('exclude_id');
            $query2 = Project::where('code', $codeExact);
            if ($excludeId) $query2->where('id', '!=', $excludeId);
            return response()->json(['total' => $query2->exists() ? 1 : 0, 'data' => []]);
        }

        // Modo minimal: retorna apenas id, name, code (para dropdowns)
        if ($minimal) {
            $q = Project::select('id', 'name', 'code', 'status');
            if ($search) $q->where(fn($x) => $x->where('name', 'ilike', "%{$search}%")->orWhere('code', 'ilike', "%{$search}%"));
            if ($status === 'active') $q->active();
            elseif ($status === 'open') $q->open();
            elseif ($status) $q->where('status', $status);
            if ($request->get('customer_id')) $q->where('customer_id', $request->get('customer_id'));
            $items = $q->orderBy('name')->limit($perPage)->get();
            return response()->json(['hasNext' => false, 'items' => $items]);
        }
        $customerId = $request->get('customer_id');
        $approverId = $request->get('approver_id');
        $executiveId = $request->get('executive_id');
        $consultantOnly = $request->get('consultant_only');
        $contractTypeName = $request->get('contract_type_name');
        $contractTypeCode = $request->get('contract_type_code');
        $contractTypeId = $request->get('contract_type_id');
        $serviceTypeName = $request->get('service_type_name');
        $parentProjectsOnly = $request->get('parent_projects_only') === 'true';

        // Modo gestão: query leve para o dashboard /gestao-projetos
        // Omite relações pesadas (hourContributions, serviceType, parentProject)
        // e pula cálculos financeiros detalhados
        $gestaoMode = $request->boolean('gestao');

        // Eager loading otimizado: carrega relacionamentos e soma de minutos apontados
        $withRelations = ['customer', 'contractType', 'serviceType'];
        if (!$gestaoMode) {
            $withRelations[] = 'parentProject';
            $withRelations[] = 'hourContributions';
        }
        // Em gestao simples (sem parentProjectsOnly), carrega equipe para indicadores
        // with_team=false pula apenas consultants (pesados); coordinators sempre são carregados
        $withTeam = $request->get('with_team', 'true') !== 'false';
        if ($gestaoMode && !$parentProjectsOnly) {
            $withRelations[] = 'coordinators';
            $withRelations[] = 'executivoConta';
            if ($withTeam) {
                $withRelations[] = 'consultants';
            }
        }
        // childProjects: carrega no modo completo OU em gestao+multicontratual
        if (!$gestaoMode || $parentProjectsOnly) {
            $withRelations[] = 'childProjects.contractType';
            // Em modo pai/filho carrega coordenadores dos pais e dos filhos para marcar node_state
            if ($parentProjectsOnly) {
                $withRelations[] = 'coordinators';
                $withRelations[] = 'childProjects.coordinators';
            }
        }

        $query = Project::with($withRelations);

        // Filtrar apenas projetos onde o usuário é consultor (exceto para Administrators)
        if ($consultantOnly === 'true') {
            $currentUser = $request->user();
            $requestedUserId = $request->get('user_id');

            // Determinar qual usuário usar para o filtro
            $targetUserId = $currentUser->id;
            $targetUser = $currentUser;

            // Se admin forneceu user_id, usar esse usuário
            if ($requestedUserId && $currentUser->isAdmin()) {
                $targetUserId = $requestedUserId;
                $targetUser = \App\Models\User::find($targetUserId);
            }

            // Apenas aplicar filtro se o usuário alvo NÃO for Administrator
            if ($targetUser && !$targetUser->isAdmin()) {
                // $query->whereHas('consultants', function ($q) use ($targetUserId) {
                //     $q->where('user_id', $targetUserId);
                // });
                $query->where(function ($q) use ($targetUserId) {
                    $q->whereHas('consultants', function ($subQ) use ($targetUserId) {
                        $subQ->where('user_id', $targetUserId);
                    })->orWhereHas('approvers', function ($subQ) use ($targetUserId) {
                        $subQ->where('user_id', $targetUserId);
                    })->orWhereHas('consultantGroups.consultants', function ($subQ) use ($targetUserId) {
                        $subQ->where('users.id', $targetUserId);
                    })->orWhereHas('coordinators', function ($subQ) use ($targetUserId) {
                        $subQ->where('user_id', $targetUserId);
                    });
                });
            }
            // Se o usuário alvo for Administrator, não aplica filtro (vê todos os projetos)
        }

        // Escopo por role: Coordenador só vê projetos onde é coordinator
        // (aplica apenas quando não está no modo consultant_only, que tem escopo próprio)
        if ($consultantOnly !== 'true') {
            $currentUser = $request->user();
            if ($currentUser && $currentUser->isCoordenador()) {
                $isSustentacao = $currentUser->coordinator_type === 'sustentacao';
                if ($parentProjectsOnly) {
                    $query->where(function ($q) use ($currentUser, $isSustentacao) {
                        $q->whereHas('coordinators', fn($sq) => $sq->where('users.id', $currentUser->id))
                          ->orWhereHas('childProjects.coordinators', fn($sq) => $sq->where('users.id', $currentUser->id));
                        if ($isSustentacao) {
                            $q->orWhereHas('serviceType', fn($sq) => $sq->where('code', 'sustentacao'));
                        }
                    });
                } else {
                    $query->where(function ($q) use ($currentUser, $isSustentacao) {
                        $q->whereHas('coordinators', fn($sq) => $sq->where('users.id', $currentUser->id));
                        if ($isSustentacao) {
                            $q->orWhereHas('serviceType', fn($sq) => $sq->where('code', 'sustentacao'));
                        }
                    });
                }
            }
        }

        // Busca por name, code ou description
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('code', 'ilike', "%{$search}%")
                  ->orWhere('description', 'ilike', "%{$search}%");
            });
        }

        // Filtro por status — hierárquico (WITH RECURSIVE) para status específicos
        $nodeStateMap = collect(); // id => node_state ('ACTIVE' | 'DISABLED')

        if ($status) {
            if ($status === 'open') {
                $query->open(); // Scope: exclui apenas cancelled e finished (permite paused)
            } elseif ($status === 'active') {
                $query->active(); // Scope: exclui cancelled, finished e paused
            } else {
                // CTE recursiva: sobe a árvore a partir dos nós que batem,
                // depois expande para mostrar todos os filhos de cada ancestral.
                $cte = "
                    WITH RECURSIVE path_nodes AS (
                        SELECT id, parent_project_id, status
                        FROM projects
                        WHERE status = ?
                          AND deleted_at IS NULL

                        UNION

                        SELECT p.id, p.parent_project_id, p.status
                        FROM projects p
                        INNER JOIN path_nodes pn ON p.id = pn.parent_project_id
                        WHERE p.deleted_at IS NULL
                    ),
                    path_deduped AS (
                        SELECT DISTINCT id, parent_project_id, status
                        FROM path_nodes
                    ),
                    all_visible AS (
                        SELECT id, parent_project_id, status
                        FROM path_deduped

                        UNION

                        SELECT p.id, p.parent_project_id, p.status
                        FROM projects p
                        INNER JOIN path_deduped pd ON p.parent_project_id = pd.id
                        WHERE p.deleted_at IS NULL
                    )
                    SELECT
                        av.id,
                        CASE WHEN av.status = ? THEN 'ACTIVE' ELSE 'DISABLED' END AS node_state
                    FROM all_visible av
                ";

                $rows = DB::select($cte, [$status, $status]);

                $nodeStateMap = collect($rows)->keyBy('id');
                $visibleIds   = $nodeStateMap->keys()->toArray();

                // Restringe a query principal aos IDs encontrados pela CTE
                $query->whereIn('projects.id', $visibleIds);
            }
        }

        // Filtro por cliente
        if ($customerId) {
            $query->where('customer_id', $customerId);
        }

        // Filtro por aprovador
        if ($approverId) {
            $query->whereHas('approvers', function ($q) use ($approverId) {
                $q->where('users.id', $approverId);
            });
        }

        // Filtro por executivo responsável do cliente
        if ($executiveId) {
            $query->whereHas('customer', function ($q) use ($executiveId) {
                $q->where('executive_id', $executiveId);
            });
        }

        // Filtro por tipo de contrato (por ID, code ou nome)
        if ($contractTypeId) {
            $query->where('contract_type_id', $contractTypeId);
        } elseif ($contractTypeCode) {
            $query->whereHas('contractType', function ($q) use ($contractTypeCode) {
                $q->where('code', $contractTypeCode);
            });
        } elseif ($contractTypeName) {
            $query->whereHas('contractType', function ($q) use ($contractTypeName) {
                $q->whereRaw('LOWER(name) = ?', [strtolower($contractTypeName)]);
            });
        }

        // Filtro por nome do tipo de serviço
        if ($serviceTypeName) {
            $query->whereHas('serviceType', function ($q) use ($serviceTypeName) {
                $q->where('name', $serviceTypeName);
            });
        }

        // Filtro para apenas projetos raiz (pai ou independentes)
        if ($request->get('parent_projects_only') === 'true') {
            $query->whereNull('parent_project_id');
        }

        // Filtro para apenas projetos raiz que POSSUEM subprojetos (modo multi-contratual)
        if ($request->get('with_children_only') === 'true') {
            $query->whereNull('parent_project_id')->whereHas('childProjects');
        }

        // Filtro para apenas projetos de nível raiz (sem pai), independente de ter filhos
        if ($request->get('top_level_only') === 'true') {
            $query->whereNull('parent_project_id');
        }

        // Excluir projeto específico (útil na edição)
        if ($request->has('exclude_id')) {
            $query->where('id', '!=', $request->get('exclude_id'));
        }

        // Projetos de Investimento Comercial ficam ocultos na listagem padrão;
        // só aparecem quando explicitamente solicitados (ex: dropdowns de apontamento).
        if ($request->boolean('only_investimento_comercial')) {
            $query->where('is_investimento_comercial', true);
        } elseif (!$request->boolean('include_investimento_comercial')) {
            $query->where('is_investimento_comercial', false);
        }

        // Mapeamento de campos virtuais/computados para colunas reais ou joins
        $virtualFieldMap = [
            'contract_type_display' => 'contract_types.name',
            'customer.name'         => 'customers.name',
        ];

        // Ordenação PO-UI
        if ($request->has('order')) {
            $orderFields = explode(',', $request->get('order'));
            foreach ($orderFields as $field) {
                $desc = str_starts_with($field, '-');
                $col  = $desc ? substr($field, 1) : $field;
                $direction = $desc ? 'desc' : 'asc';

                if (isset($virtualFieldMap[$col])) {
                    $mapped = $virtualFieldMap[$col];
                    [$joinTable] = explode('.', $mapped);
                    if ($joinTable === 'contract_types') {
                        $query->leftJoin('contract_types', 'contract_types.id', '=', 'projects.contract_type_id');
                    } elseif ($joinTable === 'customers') {
                        $query->leftJoin('customers', 'customers.id', '=', 'projects.customer_id');
                    }
                    $query->orderBy($mapped, $direction);
                } else {
                    $query->orderBy('projects.' . $col, $direction);
                }
            }
        } else {
            $query->orderBy('projects.name'); // Ordenação padrão
        }

        // Paginação PO-UI
        $page = (int) $request->get('page', 1);

        try {
        $currentUserForTransform = $request->user();
        $result = $this->cachedList($request, 'projects', function () use ($query, $perPage, $page, $nodeStateMap, $gestaoMode, $parentProjectsOnly, $currentUserForTransform) {
        $projects = $query->paginate($perPage, ['*'], 'page', $page);

        // Carregar soma de timesheets em batch: apenas para os projetos desta página
        // Evita JOIN na query principal (que agregaria TODA a tabela timesheets)
        $parentIds = $projects->getCollection()->pluck('id')->toArray();

        // childProjects só é eager-loaded quando NÃO estamos em gestaoMode puro
        // (em gestaoMode sem parentProjectsOnly, childProjects NÃO está no with())
        // Acessar ->childProjects sem eager load causaria N+1 queries
        $allChildProjectIds = collect();
        if (!$gestaoMode || $parentProjectsOnly) {
            $allChildProjectIds = $projects->getCollection()
                ->flatMap(function ($project) {
                    return $project->relationLoaded('childProjects') && $project->childProjects
                        ? $project->childProjects->pluck('id')
                        : collect();
                })
                ->unique()
                ->values();
        }

        $allIdsToSum = array_unique(array_merge($parentIds, $allChildProjectIds->toArray()));

        $timesheetsMap = [];
        if (!empty($allIdsToSum)) {
            $rows = DB::table('timesheets')
                ->selectRaw('project_id, COALESCE(SUM(effort_minutes), 0) as total_logged_minutes')
                ->whereIn('project_id', $allIdsToSum)
                ->where('status', '!=', 'rejected')
                ->groupBy('project_id')
                ->pluck('total_logged_minutes', 'project_id');
            $timesheetsMap = $rows->toArray();
        }

        // Atribuir total_logged_minutes aos projetos principais
        $projects->getCollection()->each(function ($project) use ($timesheetsMap) {
            $project->total_logged_minutes = $timesheetsMap[$project->id] ?? 0;
        });

        if ($allChildProjectIds->isNotEmpty()) {
            // Atribuir total_logged_minutes aos projetos filhos nos projetos principais
            $projects->getCollection()->each(function ($project) use ($timesheetsMap) {
                if ($project->relationLoaded('childProjects') && $project->childProjects) {
                    $project->childProjects->each(function ($childProject) use ($timesheetsMap) {
                        $childProject->total_logged_minutes = $timesheetsMap[$childProject->id] ?? 0;
                    });
                }
            });
        }

        // Adicionar atributos computed aos itens
        $projects->getCollection()->transform(function ($project) use ($nodeStateMap, $gestaoMode, $parentProjectsOnly, $currentUserForTransform) {
            $project->status_display = $project->status_display;
            $project->contract_type_display = $project->contract_type_display;

            if ($gestaoMode) {
                // Modo leve: usar apenas campos já presentes na query, sem relações extras
                $consumed = ($project->total_logged_minutes ?? 0) / 60;
                if ($project->isBankHoursMonthly()) {
                    // accumulated_sold_hours: usa valor do DB ou calcula meses × sold_hours
                    $dbAccum = $project->getRawOriginal('accumulated_sold_hours') ?? $project->accumulated_sold_hours;
                    if ($dbAccum !== null && $dbAccum > 0) {
                        $accumulatedHours = (int)$dbAccum;
                    } else {
                        $startDate = $project->start_date ? \Carbon\Carbon::parse($project->start_date) : null;
                        // Se encerramento_date definida, congela o acúmulo naquele mês
                        $refDate = $project->encerramento_date
                            ? \Carbon\Carbon::parse($project->encerramento_date)
                            : \Carbon\Carbon::now();
                        $months = $startDate ? max(1, (int)$startDate->diffInMonths($refDate) + 1) : 1;
                        $accumulatedHours = $months * (int)($project->sold_hours ?? 0);
                    }
                    $project->accumulated_sold_hours = $accumulatedHours;
                    // total_available_hours = mensal + aportes (consistente com modo não-gestao)
                    $totalAvailable = ($project->sold_hours ?? 0) + ($project->hour_contribution ?? 0);
                    // saldo usa o acumulado real; HS consumidas iniciais somadas às novas
                    $initialConsumed = (float)($project->initial_hours_consumed ?? 0);
                    $project->consumed_hours = round($consumed + $initialConsumed, 2);
                    $project->general_hours_balance = round($accumulatedHours + ($project->hour_contribution ?? 0) - $consumed - $initialConsumed, 2);
                } else {
                    $initialConsumed = (float)($project->initial_hours_consumed ?? 0);
                    $totalAvailable = ($project->sold_hours ?? 0) + ($project->hour_contribution ?? 0);

                    // Somar horas vendidas dos filhos Fechado (comprometidas no cadastro)
                    $closedChildrenHours = 0.0;
                    if ($project->relationLoaded('childProjects')) {
                        foreach ($project->childProjects as $child) {
                            if ($child->isAusterFrozen()) continue;
                            if (!$child->relationLoaded('contractType') || !$child->contractType) continue;
                            $childContractName = strtolower(trim($child->contractType->name));
                            if ($childContractName === 'fechado') {
                                // Fechado: compromete o total vendido
                                $closedChildrenHours += (float) ($child->sold_hours ?? 0);
                            } elseif ($childContractName === 'on demand') {
                                // On Demand filho: consome conforme apontamentos
                                $closedChildrenHours += ($child->total_logged_minutes ?? 0) / 60;
                            }
                        }
                    }

                    $project->consumed_hours = round($consumed + $initialConsumed + $closedChildrenHours, 2);
                    $project->general_hours_balance = round($totalAvailable - $consumed - $initialConsumed - $closedChildrenHours, 2);
                }
                $project->balance_percentage = $totalAvailable > 0 ? round(($project->consumed_hours / $totalAvailable) * 100, 2) : 0;
                $project->total_available_hours = round($totalAvailable, 2);
                $project->total_contributions_hours = 0;
                $project->total_project_value = null;
                $project->weighted_hourly_rate = null;
            } else {
                // Calcular saldo de horas de forma otimizada (sem queries adicionais)
                $project->general_hours_balance = $this->calculateGeneralHoursBalance($project);

                // Adicionar valores calculados de aportes de horas
                // Usa a relação já eager-loaded (hourContributions sem parênteses = coleção em memória)
                $project->total_available_hours = $project->getTotalAvailableHours();
                $project->total_project_value = $project->calculateTotalProjectValue();
                $project->weighted_hourly_rate = $project->getWeightedAverageHourlyRate();
                $project->total_contributions_hours = $project->hourContributions->sum('contributed_hours');
            }

            // node_state: 'ACTIVE' | 'DISABLED' | null (sem filtro de status ativo)
            $project->node_state = $nodeStateMap->has($project->id)
                ? $nodeStateMap->get($project->id)->node_state
                : null;

            // Em modo pai/filho: marca node_state dos filhos e coordinator_is_direct do pai
            if ($parentProjectsOnly && $currentUserForTransform) {
                $userId  = $currentUserForTransform->id;
                $isAdmin = $currentUserForTransform->isAdmin();

                // Verifica se o coordenador está DIRETAMENTE no pai
                $directOnParent = $isAdmin || (
                    $project->relationLoaded('coordinators') &&
                    $project->coordinators->contains('id', $userId)
                );
                $project->coordinator_is_direct = $directOnParent;

                // Marca node_state de cada filho
                if ($project->relationLoaded('childProjects')) {
                    $project->childProjects->each(function ($child) use ($userId, $isAdmin) {
                        if ($isAdmin) {
                            $child->node_state = 'ACTIVE';
                        } elseif ($child->relationLoaded('coordinators')) {
                            $child->node_state = $child->coordinators->contains('id', $userId)
                                ? 'ACTIVE' : 'DISABLED';
                        }
                    });
                }
            }

            return $project;
        });

        // Resposta PO-UI
        return [
            'hasNext' => $projects->hasMorePages(),
            'items'   => $projects->items(),
        ];
        }); // fim cachedList
        return response()->json($result);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ProjectController@index error', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);
            return response()->json(['error' => 'Erro ao listar projetos', 'details' => $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine()], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/projects",
     *     tags={"Projects"},
     *     summary="Criar projeto",
     *     description="Cria um novo projeto no sistema",
     *     security={{"sanctum": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name", "code", "customer_id", "service_type_id", "contract_type"},
     *             @OA\Property(property="name", type="string", example="Website Institucional", description="Nome do projeto"),
     *             @OA\Property(property="code", type="string", example="WEB-001", description="Código único do projeto"),
     *             @OA\Property(property="description", type="string", example="Desenvolvimento do website institucional"),
     *             @OA\Property(property="customer_id", type="integer", example=1, description="ID do cliente"),
     *             @OA\Property(property="service_type_id", type="integer", example=1, description="ID do tipo de serviço"),
                  *             @OA\Property(property="contract_type_id", type="integer", example=1, description="ID do tipo de contrato"),
     *             @OA\Property(property="project_value", type="number", example=50000.00, description="Valor do projeto"),
     *             @OA\Property(property="hourly_rate", type="number", example=150.00, description="Valor da hora"),
     *             @OA\Property(property="sold_hours", type="integer", example=200, description="Horas vendidas"),
     *             @OA\Property(property="hour_contribution", type="integer", example=20, description="Aporte de horas"),
     *             @OA\Property(property="exceeded_hour_contribution", type="integer", example=10, description="Aporte de horas excedidas"),
     *             @OA\Property(property="additional_hourly_rate", type="number", example=180.00, description="Valor de horas adicionais"),
     *             @OA\Property(property="start_date", type="string", format="date", example="2024-01-15", description="Data de início"),
     *             @OA\Property(property="save_erpserv", type="number", example=0.00, description="Valor Save ERPSERV"),
     *             @OA\Property(property="max_expense_per_consultant", type="number", example=500.00, description="Valor máximo de despesa por consultor"),
     *             @OA\Property(property="expense_responsible_party", type="string", example="consultancy", description="Responsável pelas despesas (consultancy/client)"),
     *             @OA\Property(property="consultant_ids", type="array", @OA\Items(type="integer"), example={1,2}, description="IDs dos consultores"),
     *             @OA\Property(property="approver_ids", type="array", @OA\Items(type="integer"), example={3}, description="IDs dos aprovadores")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Projeto criado com sucesso",
     *         @OA\JsonContent(
     *             @OA\Property(property="id", type="integer"),
     *             @OA\Property(property="name", type="string"),
     *             @OA\Property(property="code", type="string"),
     *             @OA\Property(property="status", type="string"),
     *             @OA\Property(property="customer", type="object"),
     *             @OA\Property(property="service_type", type="object"),
     *             @OA\Property(property="consultants", type="array", @OA\Items(type="object")),
     *             @OA\Property(property="approvers", type="array", @OA\Items(type="object"))
     *         )
     *     ),
     *     @OA\Response(response=422, description="Dados de validação inválidos"),
     *     @OA\Response(response=401, description="Não autenticado"),
     *     @OA\Response(response=403, description="Sem permissão")
     * )
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|min:2',
            'code' => 'nullable|string|max:50|unique:projects,code',
            'description' => 'nullable|string|max:2000',
            'customer_id' => 'required|exists:customers,id',
            'parent_project_id' => 'nullable|exists:projects,id',
            'service_type_id' => 'required|exists:service_types,id',
                        'contract_type_id' => 'required|exists:contract_types,id',
            'project_value' => 'nullable|numeric|min:0|max:999999999.99',
            'hourly_rate' => 'nullable|numeric|min:0|max:999999.99',
            'sold_hours' => 'nullable|integer|min:0|max:999999',
            'hour_contribution' => 'nullable|integer|min:0|max:999999',
            'exceeded_hour_contribution' => 'nullable|integer|min:0|max:999999',
            'initial_hours_balance' => 'nullable|numeric|min:-999999|max:999999',
            'initial_hours_consumed' => 'nullable|numeric|min:0|max:999999',
            'initial_cost' => 'nullable|numeric|min:0|max:999999999.99',
            'consultant_hours' => 'nullable|integer|min:0|max:999999',
            'coordinator_hours' => 'nullable|integer|min:0|max:999999',
            'additional_hourly_rate' => 'nullable|numeric|min:0|max:999999.99',
            'start_date' => 'nullable|date',
            'expected_end_date' => 'nullable|date',
            'encerramento_date' => 'nullable|date',
            'save_erpserv' => 'nullable|numeric|min:0|max:999999999.99',
            'max_expense_per_consultant' => 'nullable|numeric|min:0|max:999999999.99',
            'unlimited_expense' => 'nullable|boolean',
            'expense_responsible_party' => ['nullable', Rule::in(['consultancy', 'client'])],
            'timesheet_retroactive_limit_days' => 'nullable|integer|min:0|max:365',
            'allow_manual_timesheets' => 'nullable|boolean',
            'allow_negative_balance' => 'nullable|boolean',
            'status' => ['nullable', Rule::in(array_keys(Project::getStatuses()))],
            'consultant_ids' => 'nullable|array',
            'consultant_ids.*' => 'exists:users,id',
            'coordinator_ids' => 'nullable|array',
            'coordinator_ids.*' => 'exists:users,id',
            'consultant_group_ids' => 'nullable|array',
            'consultant_group_ids.*' => 'exists:consultant_groups,id',
            // Contract-origin fields
            'observacoes_contrato'  => 'nullable|string',
            'condicao_pagamento'    => 'nullable|string',
            'cobra_despesa_cliente' => 'nullable|boolean',
            'tipo_faturamento'      => 'nullable|string',
            'tipo_alocacao'         => 'nullable|in:remoto,presencial,ambos',
            'vendedor_id'           => 'nullable|exists:users,id',
            'architect_id'          => 'nullable|exists:users,id',
            'executivo_conta_id'    => 'nullable|exists:users,id',
        ], [
            'name.required' => 'O nome é obrigatório',
            'name.max' => 'O nome não pode ter mais de 255 caracteres',
            'name.min' => 'O nome deve ter pelo menos 2 caracteres',
            'code.unique' => 'Este código já está sendo usado por outro projeto',
            'customer_id.required' => 'O cliente é obrigatório',
            'customer_id.exists' => 'Cliente não encontrado',
            'parent_project_id.exists' => 'Projeto pai não encontrado',
            'service_type_id.required' => 'O tipo de serviço é obrigatório',
            'service_type_id.exists' => 'Tipo de serviço não encontrado',
                        'contract_type_id.required' => 'O tipo de contrato é obrigatório',
            'contract_type_id.exists' => 'Tipo de contrato inválido',
            'timesheet_retroactive_limit_days.integer' => 'O prazo deve ser um número inteiro',
            'timesheet_retroactive_limit_days.min' => 'O prazo não pode ser negativo',
            'timesheet_retroactive_limit_days.max' => 'O prazo não pode ser maior que 365 dias',
            'status.in' => 'Status inválido',
        ]);

        // Validar que o projeto pai não é um subprojeto (evitar múltiplos níveis)
        if (isset($validated['parent_project_id'])) {
            $parentProject = Project::find($validated['parent_project_id']);
            if ($parentProject && $parentProject->isSubProject()) {
                return response()->json([
                    'code' => 'INVALID_PARENT_PROJECT',
                    'type' => 'error',
                    'message' => 'Projeto pai inválido',
                    'detailMessage' => 'O projeto pai não pode ser um subprojeto. Selecione um projeto principal.'
                ], 422);
            }

            // Validar horas vendidas + aportes do subprojeto
            $subProjectSoldHours = $validated['sold_hours'] ?? 0;
            $subProjectHourContribution = $validated['hour_contribution'] ?? 0;
            $subProjectTotalHours = $subProjectSoldHours + $subProjectHourContribution;

            if ($subProjectTotalHours > 0) {
                $availableHours = $this->calculateAvailableHours($parentProject);

                if ($subProjectTotalHours > $availableHours) {
                    return response()->json([
                        'code' => 'INVALID_SOLD_HOURS',
                        'type' => 'error',
                        'message' => 'Horas inválidas',
                        'detailMessage' => "O subprojeto não pode ter mais horas (vendidas + aportes: {$subProjectTotalHours}h) do que as horas disponíveis no projeto pai ({$availableHours}h)."
                    ], 422);
                }
            }
        }

        // Separar relacionamentos
        $consultantIds      = $validated['consultant_ids'] ?? [];
        $coordinatorIds     = $validated['coordinator_ids'] ?? $validated['approver_ids'] ?? [];
        $consultantGroupIds = $validated['consultant_group_ids'] ?? [];
        unset($validated['consultant_ids'], $validated['coordinator_ids'], $validated['approver_ids'], $validated['consultant_group_ids']);

        if (!Schema::hasColumn('projects', 'allow_negative_balance')) {
            unset($validated['allow_negative_balance']);
        }

        // Gerar ou validar código do projeto
        $customer = Customer::findOrFail($validated['customer_id']);
        $parent   = isset($validated['parent_project_id']) ? Project::find($validated['parent_project_id']) : null;

        $codeService = new ProjectCodeService();
        $codeData    = $codeService->resolveForStore($validated['code'] ?? null, $customer, $parent);

        $validated = array_merge($validated, $codeData);

        $project = Project::create($validated);

        // Vincular consultores
        if (!empty($consultantIds)) {
            $project->consultants()->attach($consultantIds);
        }

        // Vincular coordenadores
        if (!empty($coordinatorIds)) {
            $project->coordinators()->attach($coordinatorIds);
        }

        // Vincular grupos de consultores
        if (!empty($consultantGroupIds)) {
            $project->consultantGroups()->sync($consultantGroupIds);
        }

        // Registrar histórico inicial de sold_hours para Banco de Horas Mensal
        $project->loadMissing('contractType');
        if ($project->isBankHoursMonthly() && $project->sold_hours) {
            $effectiveFrom = $project->start_date
                ? Carbon::parse($project->start_date)->startOfMonth()->toDateString()
                : Carbon::now()->startOfMonth()->toDateString();

            \App\Models\ProjectSoldHoursHistory::create([
                'project_id'   => $project->id,
                'sold_hours'   => $project->sold_hours,
                'effective_from' => $effectiveFrom,
                'changed_by'   => auth()->id(),
            ]);
        }

        // Recarregar com relacionamentos
        $project->load(['customer', 'serviceType', 'contractType', 'consultants', 'coordinators', 'consultantGroups', 'parentProject', 'soldHoursHistory.changer']);

        // Adicionar atributos computed
        $project->status_display = $project->status_display;
        $project->contract_type_display = $project->contract_type_display;
        $this->invalidateListCache('projects');

        return response()->json($project, 201);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/projects/{id}",
     *     tags={"Projects"},
     *     summary="Exibir projeto específico",
     *     description="Retorna os dados de um projeto específico",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer"),
     *         description="ID do projeto"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Dados do projeto",
     *         @OA\JsonContent(
     *             @OA\Property(property="id", type="integer"),
     *             @OA\Property(property="name", type="string"),
     *             @OA\Property(property="code", type="string"),
     *             @OA\Property(property="description", type="string"),
     *             @OA\Property(property="status", type="string"),
     *             @OA\Property(property="contract_type", type="string"),
     *             @OA\Property(property="project_value", type="number"),
     *             @OA\Property(property="customer", type="object"),
     *             @OA\Property(property="service_type", type="object"),
     *             @OA\Property(property="consultants", type="array", @OA\Items(type="object")),
     *             @OA\Property(property="approvers", type="array", @OA\Items(type="object"))
     *         )
     *     ),
     *     @OA\Response(response=404, description="Projeto não encontrado"),
     *     @OA\Response(response=401, description="Não autenticado"),
     *     @OA\Response(response=403, description="Sem permissão")
     * )
     */
    public function show(Project $project): JsonResponse
    {
        // Carregar relacionamentos essenciais
        $project->load(['customer', 'serviceType', 'contractType', 'consultants', 'coordinators', 'consultantGroups.consultants', 'parentProject', 'childProjects', 'hourContributions']);

        try {
            $project->load(['soldHoursHistory.changer']);
        } catch (\Throwable $e) {
            try { \Log::warning('ProjectController@show: falha ao carregar soldHoursHistory', ['error' => $e->getMessage(), 'project_id' => $project->id]); } catch (\Throwable $_) {}
            $project->setRelation('soldHoursHistory', collect());
        }

        // Carregar coordinators com fallback (tabela pode estar em migração)
        try {
            $project->load(['coordinators']);
        } catch (\Throwable $e) {
            try { \Log::warning('ProjectController@show: falha ao carregar coordinators', ['error' => $e->getMessage(), 'project_id' => $project->id]); } catch (\Throwable $_) {}
            $project->setRelation('coordinators', collect());
            $project->setRelation('approvers', collect());
        }

        // Adicionar atributos computed
        $project->status_display = $project->status_display;
        $project->contract_type_display = $project->contract_type_display;

        // Adicionar saldo de horas geral calculado
        try {
            $project->general_hours_balance = $project->getGeneralHoursBalance(false);
        } catch (\Throwable $e) {
            try { \Log::warning('ProjectController@show: falha ao calcular general_hours_balance', ['error' => $e->getMessage(), 'project_id' => $project->id]); } catch (\Throwable $_) {}
            $project->general_hours_balance = null;
        }

        // Adicionar valores calculados de aportes de horas
        $project->total_available_hours = $project->getTotalAvailableHours();
        $project->total_project_value = $project->calculateTotalProjectValue();
        $project->weighted_hourly_rate = $project->getWeightedAverageHourlyRate();
        $project->total_contributions_hours = $project->hourContributions()->sum('contributed_hours') ?? 0;

        // Adicionar total de minutos apontados (excluindo rejeitados)
        $project->total_logged_minutes = DB::table('timesheets')
            ->where('project_id', $project->id)
            ->where('status', '!=', 'rejected')
            ->sum('effort_minutes') ?? 0;

        $this->invalidateListCache('projects');

        return response()->json($project);
    }

    /**
     * @OA\Put(
     *     path="/api/v1/projects/{id}",
     *     tags={"Projects"},
     *     summary="Atualizar projeto",
     *     description="Atualiza os dados de um projeto específico",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer"),
     *         description="ID do projeto"
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string", example="Website Institucional v2"),
     *             @OA\Property(property="description", type="string", example="Nova versão do website"),
     *             @OA\Property(property="status", type="string", example="started"),
     *             @OA\Property(property="consultant_ids", type="array", @OA\Items(type="integer")),
     *             @OA\Property(property="approver_ids", type="array", @OA\Items(type="integer"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Projeto atualizado com sucesso",
     *         @OA\JsonContent(
     *             @OA\Property(property="id", type="integer"),
     *             @OA\Property(property="name", type="string"),
     *             @OA\Property(property="code", type="string"),
     *             @OA\Property(property="status", type="string")
     *         )
     *     ),
     *     @OA\Response(response=422, description="Dados de validação inválidos"),
     *     @OA\Response(response=404, description="Projeto não encontrado"),
     *     @OA\Response(response=401, description="Não autenticado"),
     *     @OA\Response(response=403, description="Sem permissão")
     * )
     */
    public function update(Request $request, Project $project): JsonResponse
    {
        // Verificar se projeto pode ser editado (admin sempre pode)
        if (!$project->canBeEdited() && !auth()->user()->isAdmin()) {
            return response()->json([
                'code' => 'PROJECT_FINISHED',
                'type' => 'error',
                'message' => 'Projeto finalizado não pode ser editado',
                'detailMessage' => 'Este projeto já foi finalizado e não pode mais ser modificado'
            ], 422);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255|min:2',
            'code' => 'sometimes|string|max:50|unique:projects,code,' . $project->id,
            'description' => 'nullable|string|max:2000',
            'customer_id' => 'sometimes|exists:customers,id',
            'parent_project_id' => 'nullable|exists:projects,id',
            'service_type_id' => 'sometimes|exists:service_types,id',
            'contract_type_id' => 'sometimes|exists:contract_types,id',
            'status' => ['sometimes', Rule::in(array_keys(Project::getStatuses()))],
            'project_value' => 'nullable|numeric|min:0|max:999999999.99',
            'hourly_rate' => 'nullable|numeric|min:0|max:999999.99',
            'sold_hours' => 'nullable|integer|min:0|max:999999',
            'hour_contribution' => 'nullable|integer|min:0|max:999999',
            'exceeded_hour_contribution' => 'nullable|integer|min:0|max:999999',
            'initial_hours_balance' => 'nullable|numeric|min:-999999|max:999999',
            'initial_hours_consumed' => 'nullable|numeric|min:0|max:999999',
            'initial_cost' => 'nullable|numeric|min:0|max:999999999.99',
            'consultant_hours' => 'nullable|integer|min:0|max:999999',
            'coordinator_hours' => 'nullable|integer|min:0|max:999999',
            'additional_hourly_rate' => 'nullable|numeric|min:0|max:999999.99',
            'start_date' => 'nullable|date',
            'expected_end_date' => 'nullable|date',
            'encerramento_date' => 'nullable|date',
            'save_erpserv' => 'nullable|numeric|min:0|max:999999999.99',
            'max_expense_per_consultant' => 'nullable|numeric|min:0|max:999999999.99',
            'unlimited_expense' => 'nullable|boolean',
            'expense_responsible_party' => ['nullable', Rule::in(['consultancy', 'client'])],
            'timesheet_retroactive_limit_days' => 'nullable|integer|min:0|max:365',
            'allow_manual_timesheets' => 'nullable|boolean',
            'allow_negative_balance' => 'nullable|boolean',
            'sold_hours_effective_from' => 'nullable|date',
            'hourly_rate_effective_from' => 'nullable|date',
            'consultant_ids' => 'nullable|array',
            'consultant_ids.*' => 'exists:users,id',
            'coordinator_ids' => 'nullable|array',
            'coordinator_ids.*' => 'exists:users,id',
            'consultant_group_ids' => 'nullable|array',
            'consultant_group_ids.*' => 'exists:consultant_groups,id',
            // Contract-origin fields
            'observacoes_contrato'  => 'nullable|string',
            'condicao_pagamento'    => 'nullable|string',
            'cobra_despesa_cliente' => 'nullable|boolean',
            'tipo_faturamento'      => 'nullable|string',
            'tipo_alocacao'         => 'nullable|in:remoto,presencial,ambos',
            'vendedor_id'           => 'nullable|exists:users,id',
            'architect_id'          => 'nullable|exists:users,id',
            'executivo_conta_id'    => 'nullable|exists:users,id',
        ], [
            'name.max' => 'O nome não pode ter mais de 255 caracteres',
            'name.min' => 'O nome deve ter pelo menos 2 caracteres',
            'code.unique' => 'Este código já está sendo usado por outro projeto',
            'customer_id.exists' => 'Cliente não encontrado',
            'parent_project_id.exists' => 'Projeto pai não encontrado',
            'service_type_id.exists' => 'Tipo de serviço não encontrado',
            'timesheet_retroactive_limit_days.integer' => 'O prazo deve ser um número inteiro',
            'timesheet_retroactive_limit_days.min' => 'O prazo não pode ser negativo',
            'timesheet_retroactive_limit_days.max' => 'O prazo não pode ser maior que 365 dias',
        ]);

        // Tratar atualização de código manual
        if (isset($validated['code']) && $validated['code'] !== $project->code) {
            // Novo código enviado → marcar como manual
            $validated['is_manual_code'] = true;
        } elseif (!$project->is_manual_code) {
            // Código automático não pode ser alterado pelo cliente sem enviar código novo
            unset($validated['code']);
        }

        // Validações de parent_project_id
        if (isset($validated['parent_project_id'])) {
            // Não pode ser pai de si mesmo
            if ($validated['parent_project_id'] === $project->id) {
                return response()->json([
                    'code' => 'INVALID_PARENT_PROJECT',
                    'type' => 'error',
                    'message' => 'Projeto pai inválido',
                    'detailMessage' => 'Um projeto não pode ser pai de si mesmo.'
                ], 422);
            }

            // O projeto pai não pode ser um subprojeto (evitar múltiplos níveis)
            $parentProject = Project::find($validated['parent_project_id']);
            if ($parentProject && $parentProject->isSubProject()) {
                return response()->json([
                    'code' => 'INVALID_PARENT_PROJECT',
                    'type' => 'error',
                    'message' => 'Projeto pai inválido',
                    'detailMessage' => 'O projeto pai não pode ser um subprojeto. Selecione um projeto principal.'
                ], 422);
            }

            // Se o projeto tem filhos, não pode se tornar um subprojeto
            if ($project->hasChildProjects()) {
                return response()->json([
                    'code' => 'PROJECT_HAS_CHILDREN',
                    'type' => 'error',
                    'message' => 'Operação não permitida',
                    'detailMessage' => 'Este projeto possui subprojetos e não pode se tornar um subprojeto.'
                ], 422);
            }

            // Validar horas vendidas + aporte de horas do subprojeto
            $newSoldHours = $validated['sold_hours'] ?? $project->sold_hours;
            $newHourContribution = $validated['hour_contribution'] ?? $project->hour_contribution;
            $newSubProjectTotalHours = $newSoldHours + $newHourContribution;

            if ($newSubProjectTotalHours > 0) {
                // Usar calculateAvailableHours que já retorna o saldo disponível excluindo o projeto filho atual
                // Segue a mesma lógica de $excludeProjectId: adiciona de volta o que foi subtraído do projeto filho
                $availableHours = $this->calculateAvailableHours($parentProject, $project->id);

                if ($newSubProjectTotalHours > $availableHours) {
                    return response()->json([
                        'code' => 'INVALID_SOLD_HOURS',
                        'type' => 'error',
                        'message' => 'Horas inválidas',
                        'detailMessage' => "O subprojeto não pode ter mais horas (vendidas + aporte: {$newSubProjectTotalHours}h) do que as horas disponíveis no projeto pai ({$availableHours}h)."
                    ], 422);
                }
            }
        }

        // Se o projeto é pai e está mudando sold_hours ou hour_contribution, validar que não fica menor que soma dos filhos
        if (!isset($validated['parent_project_id']) && (isset($validated['sold_hours']) || isset($validated['hour_contribution']))) {
            // Garantir que o tipo de contrato do projeto e dos filhos esteja carregado
            $project->loadMissing('contractType', 'childProjects.contractType');

            // === Horas efetivas do projeto pai ===
            $parentBaseSoldHours = $validated['sold_hours'] ?? $project->sold_hours ?? 0;
            $parentHourContribution = $validated['hour_contribution'] ?? $project->hour_contribution ?? 0;

            $parentEffectiveSoldHours = $parentBaseSoldHours;

            if ($project->isBankHoursMonthly()) {
                // Para Banco de Horas Mensal, multiplicar pela quantidade de meses ativos
                $startDateValue = $validated['start_date'] ?? $project->start_date;

                if ($startDateValue) {
                    $startDate = Carbon::parse($startDateValue);

                    if (!$startDate->isFuture()) {
                        $endDate = Carbon::now();
                        $startMonth = $startDate->copy()->startOfMonth();
                        $endMonth = $endDate->copy()->startOfMonth();
                        $monthsDiff = $startMonth->diffInMonths($endMonth);
                        // Incluir o mês corrente
                        $totalMonths = $monthsDiff + 1;

                        $parentEffectiveSoldHours = $parentBaseSoldHours * $totalMonths;
                    } else {
                        // Data de início no futuro: considerar 0 horas acumuladas
                        $parentEffectiveSoldHours = 0;
                    }
                } else {
                    // Sem data de início: considerar apenas 1 mês ativo
                    $parentEffectiveSoldHours = $parentBaseSoldHours;
                }
            }

            $parentTotalHours = $parentEffectiveSoldHours + $parentHourContribution;

            // === Soma das horas efetivas dos filhos ===
            $childrenTotalHours = 0;
            $childProjects = $project->childProjects()->get();

            foreach ($childProjects as $childProject) {
                if ($childProject->isBankHoursMonthly()) {
                    // Para Banco de Horas Mensal, usar accumulated_sold_hours se já calculado; fallback para sold_hours
                    $childSoldHours = $childProject->accumulated_sold_hours ?? $childProject->sold_hours ?? 0;
                } else {
                    $childSoldHours = $childProject->sold_hours ?? 0;
                }

                $childHourContribution = $childProject->hour_contribution ?? 0;
                $childrenTotalHours += ($childSoldHours + $childHourContribution);
            }

            if ($parentTotalHours < $childrenTotalHours) {
                return response()->json([
                    'code' => 'INVALID_SOLD_HOURS',
                    'type' => 'error',
                    'message' => 'Horas inválidas',
                    'detailMessage' => "O projeto pai não pode ter menos horas (vendidas + aporte: {$parentTotalHours}h) do que a soma das horas (vendidas + aporte) dos subprojetos ({$childrenTotalHours}h)."
                ], 422);
            }
        }

        // Separar relacionamentos e campos que não pertencem ao model
        $consultantIds      = $validated['consultant_ids'] ?? null;
        $coordinatorIds     = $validated['coordinator_ids'] ?? $validated['approver_ids'] ?? null;
        $consultantGroupIds = array_key_exists('consultant_group_ids', $validated) ? $validated['consultant_group_ids'] : false;
        $soldHoursEffectiveFrom = isset($validated['sold_hours_effective_from'])
            ? Carbon::parse($validated['sold_hours_effective_from'])->startOfMonth()->toDateString()
            : Carbon::now()->startOfMonth()->toDateString();
        $hourlyRateEffectiveFrom = isset($validated['hourly_rate_effective_from'])
            ? Carbon::parse($validated['hourly_rate_effective_from'])->startOfMonth()->toDateString()
            : null;
        $previousHourlyRate = $project->hourly_rate;
        unset($validated['consultant_ids'], $validated['coordinator_ids'], $validated['approver_ids'], $validated['consultant_group_ids'], $validated['sold_hours_effective_from'], $validated['hourly_rate_effective_from']);

        // Detectar mudança de sold_hours para registrar histórico (Banco de Horas Mensal)
        $previousSoldHours = (int) ($project->sold_hours ?? 0);
        $newSoldHours      = isset($validated['sold_hours']) ? (int) $validated['sold_hours'] : $previousSoldHours;

        // Log de alteração do percentual de coordenação (auditoria)
        $previousPercentage = (float) ($project->coordinator_hours ?? 0);
        $newPercentage      = isset($validated['coordinator_hours']) ? (float) $validated['coordinator_hours'] : $previousPercentage;

        // Tratar campos nullable explicitamente - se foram enviados como null ou string vazia, garantir que sejam null
        // Isso permite limpar campos que antes tinham valores
        // Verifica se o campo foi enviado na requisição (mesmo que seja null ou vazio)
        if ($request->has('max_expense_per_consultant')) {
            $maxExpenseValue = $request->input('max_expense_per_consultant');
            // Se foi enviado como null, string vazia, ou 0, definir como null
            if ($maxExpenseValue === null || $maxExpenseValue === '' || $maxExpenseValue === '0' || $maxExpenseValue === 0) {
                $validated['max_expense_per_consultant'] = null;
            }
        }

        // Remover campos que ainda não existem no banco (migrações pendentes)
        if (!Schema::hasColumn('projects', 'allow_negative_balance')) {
            unset($validated['allow_negative_balance']);
        }

        $project->update($validated);

        // Garantir que accumulated_sold_hours está atualizado para Banco de Horas Mensal
        if (!$project->relationLoaded('contractType') && $project->contract_type_id) {
            $project->load('contractType');
        }

        if ($project->isBankHoursMonthly()) {
            try {
                $project->updateAccumulatedSoldHours(null, true);
            } catch (\Exception $e) {
                \Log::warning('ProjectController@update: falha ao atualizar accumulated_sold_hours', ['error' => $e->getMessage()]);
            }
        }

        // Gravar log se o percentual de coordenação mudou
        if ($previousPercentage !== $newPercentage) {
            try {
                $previousBalance = $project->getGeneralHoursBalance();
                $project->refresh();
                $newBalance = $project->getGeneralHoursBalance();

                \App\Models\ProjectCoordinatorPercentageLog::create([
                    'project_id'          => $project->id,
                    'changed_by'          => auth()->id(),
                    'previous_percentage' => $previousPercentage,
                    'new_percentage'      => $newPercentage,
                    'previous_balance'    => $previousBalance,
                    'new_balance'         => $newBalance,
                ]);
            } catch (\Exception $e) {
                \Log::warning('ProjectController@update: falha ao gravar log de percentual', ['error' => $e->getMessage(), 'project_id' => $project->id]);
            }
        }

        // Atualizar consultores se fornecido
        if ($consultantIds !== null) {
            $project->consultants()->sync($consultantIds);
        }

        // Atualizar coordenadores se fornecido
        if ($coordinatorIds !== null) {
            try {
                $project->coordinators()->sync($coordinatorIds);
            } catch (\Exception $e) {
                \Log::warning('ProjectController@update: falha ao sincronizar coordinators', ['error' => $e->getMessage(), 'project_id' => $project->id]);
            }
        }

        // Atualizar grupos de consultores se fornecido
        if ($consultantGroupIds !== false) {
            try {
                $project->consultantGroups()->sync($consultantGroupIds ?? []);
            } catch (\Exception $e) {
                \Log::warning('ProjectController@update: falha ao sincronizar consultant_groups', ['error' => $e->getMessage(), 'project_id' => $project->id]);
            }
        }

        // Registrar histórico de sold_hours se mudou (Banco de Horas Mensal)
        $project->loadMissing('contractType');
        if ($previousSoldHours !== $newSoldHours && $project->isBankHoursMonthly()) {
            try {
                // Bootstrapar histórico inicial se ainda não existe nenhum registro
                if ($project->soldHoursHistory()->count() === 0 && $project->start_date) {
                    \App\Models\ProjectSoldHoursHistory::create([
                        'project_id'     => $project->id,
                        'sold_hours'     => $previousSoldHours,
                        'effective_from' => Carbon::parse($project->start_date)->startOfMonth()->toDateString(),
                        'changed_by'     => null,
                    ]);
                }

                // Não criar duplicata se já existe registro para a data efetiva informada
                $exists = $project->soldHoursHistory()
                    ->where('effective_from', $soldHoursEffectiveFrom)
                    ->exists();

                if (!$exists) {
                    \App\Models\ProjectSoldHoursHistory::create([
                        'project_id'     => $project->id,
                        'sold_hours'     => $newSoldHours,
                        'effective_from' => $soldHoursEffectiveFrom,
                        'changed_by'     => auth()->id(),
                    ]);
                } else {
                    // Atualizar o registro já existente para essa data
                    $project->soldHoursHistory()
                        ->where('effective_from', $soldHoursEffectiveFrom)
                        ->update(['sold_hours' => $newSoldHours, 'changed_by' => auth()->id()]);
                }
            } catch (\Exception $e) {
                \Log::warning('ProjectController@update: falha ao registrar histórico de sold_hours', ['error' => $e->getMessage()]);
            }
        }

        // Se hourly_rate mudou e foi enviada uma data de vigência, atualizar o change log
        if ($hourlyRateEffectiveFrom && $project->wasChanged('hourly_rate')) {
            try {
                ProjectChangeLog::where('project_id', $project->id)
                    ->where('field_name', 'hourly_rate')
                    ->where('changed_by', auth()->id())
                    ->latest()
                    ->first()
                    ?->update(['effective_from' => $hourlyRateEffectiveFrom]);
            } catch (\Exception $e) {
                \Log::warning('ProjectController@update: falha ao registrar effective_from no change log de hourly_rate', ['error' => $e->getMessage()]);
            }
        }

        // Recarregar com relacionamentos
        $project->load(['customer', 'serviceType', 'contractType', 'consultants', 'coordinators', 'consultantGroups.consultants']);
        try {
            $project->load(['soldHoursHistory.changer']);
        } catch (\Throwable $e) {
            try { \Log::warning('ProjectController@update: falha ao carregar soldHoursHistory', ['error' => $e->getMessage()]); } catch (\Throwable $_) {}
            $project->setRelation('soldHoursHistory', collect());
        }
        try {
            $project->load(['coordinators']);
        } catch (\Throwable $e) {
            try { \Log::warning('ProjectController@update: falha ao carregar coordinators', ['error' => $e->getMessage()]); } catch (\Throwable $_) {}
            $project->setRelation('coordinators', collect());
        }

        // Adicionar atributos computed
        $project->status_display = $project->status_display;
        $project->contract_type_display = $project->contract_type_display;

        return response()->json($project);
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/projects/{id}",
     *     tags={"Projects"},
     *     summary="Deletar projeto",
     *     description="Remove um projeto do sistema (soft delete)",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer"),
     *         description="ID do projeto"
     *     ),
     *     @OA\Response(response=204, description="Projeto deletado com sucesso"),
     *     @OA\Response(
     *         response=422,
     *         description="Projeto possui apontamentos vinculados e não pode ser deletado",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="code", type="string", example="PROJECT_HAS_TIMESHEETS"),
     *             @OA\Property(property="type", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Não é possível excluir o projeto pois existem apontamentos vinculados."),
     *             @OA\Property(property="detailMessage", type="string", example="Exclua ou remova os apontamentos vinculados antes de tentar excluir o projeto.")
     *         )
     *     ),
     *     @OA\Response(response=404, description="Projeto não encontrado"),
     *     @OA\Response(response=401, description="Não autenticado"),
     *     @OA\Response(response=403, description="Sem permissão")
     * )
     */
    public function destroy(Project $project): JsonResponse
    {
        // Verificar se existem timesheets (apontamentos) vinculados ao projeto
        if ($project->timesheets()->exists()) {
            return response()->json([
                'code' => 'PROJECT_HAS_TIMESHEETS',
                'type' => 'error',
                'message' => 'Não é possível excluir o projeto pois existem apontamentos vinculados.',
                'detailMessage' => 'Exclua ou remova os apontamentos vinculados antes de tentar excluir o projeto.'
            ], 422);
        }

        $project->delete();
        $this->invalidateListCache('projects');

        return response()->json(null, 204);
    }

    /**
     * Atualiza um registro do histórico e aplica o novo valor no projeto.
     */
    public function updateChangeHistory(Project $project, \App\Models\ProjectChangeLog $log, Request $request): JsonResponse
    {
        if ($log->project_id !== $project->id) {
            return response()->json(['message' => 'Registro não pertence ao projeto.'], 404);
        }

        $validated = $request->validate([
            'new_value' => 'nullable',
            'reason'    => 'nullable|string|max:1000',
        ]);

        $log->update($validated);

        // Aplicar o novo valor no projeto
        if (array_key_exists('new_value', $validated) && in_array($log->field_name, $project->getFillable())) {
            try {
                $project->update([$log->field_name => $validated['new_value']]);
            } catch (\Exception $e) {
                \Log::warning('updateChangeHistory: falha ao atualizar projeto', ['error' => $e->getMessage()]);
            }
        }

        return response()->json($log->fresh(['changedByUser'])->toFormattedArray());
    }

    /**
     * Remove um registro do histórico e reverte o campo do projeto ao valor anterior.
     */
    public function destroyChangeHistory(Project $project, \App\Models\ProjectChangeLog $log): JsonResponse
    {
        if ($log->project_id !== $project->id) {
            return response()->json(['message' => 'Registro não pertence ao projeto.'], 404);
        }

        // Reverter o campo do projeto ao valor anterior
        if ($log->old_value !== null && in_array($log->field_name, $project->getFillable())) {
            try {
                $project->update([$log->field_name => $log->old_value]);
            } catch (\Exception $e) {
                \Log::warning('destroyChangeHistory: falha ao reverter projeto', ['error' => $e->getMessage()]);
            }
        }

        $log->delete();

        return response()->json(null, 204);
    }

    /**
     * Atualiza um registro do histórico de horas vendidas.
     */
    public function updateSoldHoursHistory(Project $project, \App\Models\ProjectSoldHoursHistory $history, Request $request): JsonResponse
    {
        if ($history->project_id !== $project->id) {
            return response()->json(['message' => 'Registro não pertence ao projeto.'], 404);
        }

        $validated = $request->validate([
            'sold_hours'     => 'required|integer|min:0|max:999999',
            'effective_from' => 'required|date',
        ]);

        $validated['effective_from'] = Carbon::parse($validated['effective_from'])->startOfMonth()->toDateString();
        $validated['changed_by'] = auth()->id();

        $history->update($validated);

        $project->updateAccumulatedSoldHours(null, true);

        $project->load('soldHoursHistory.changer');

        return response()->json($project->soldHoursHistory->sortBy('effective_from')->values());
    }

    /**
     * Remove um registro do histórico de horas vendidas.
     */
    public function destroySoldHoursHistory(Project $project, \App\Models\ProjectSoldHoursHistory $history): JsonResponse
    {
        if ($history->project_id !== $project->id) {
            return response()->json(['message' => 'Registro não pertence ao projeto.'], 404);
        }

        $history->delete();

        $project->updateAccumulatedSoldHours(null, true);

        $project->load('soldHoursHistory.changer');

        return response()->json($project->soldHoursHistory->sortBy('effective_from')->values());
    }

    /**
     * @OA\Get(
     *     path="/api/v1/projects/{id}/cost-summary",
     *     tags={"Projects"},
     *     summary="Obter resumo de custos do projeto",
     *     description="Retorna informações detalhadas de custos e horas do projeto",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer", example=1),
     *         description="ID do projeto"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Resumo de custos do projeto",
     *         @OA\JsonContent(
     *             @OA\Property(property="project_info", type="object",
     *                 @OA\Property(property="id", type="integer"),
     *                 @OA\Property(property="name", type="string"),
     *                 @OA\Property(property="code", type="string"),
     *                 @OA\Property(property="project_value", type="number"),
     *                 @OA\Property(property="hourly_rate", type="number"),
     *                 @OA\Property(property="sold_hours", type="integer"),
     *                 @OA\Property(property="hour_contribution", type="integer"),
     *                 @OA\Property(property="exceeded_hour_contribution", type="integer")
     *             ),
     *             @OA\Property(property="hours_summary", type="object",
     *                 @OA\Property(property="total_logged_hours", type="number"),
     *                 @OA\Property(property="approved_hours", type="number"),
     *                 @OA\Property(property="pending_hours", type="number"),
     *                 @OA\Property(property="remaining_hours", type="number"),
     *                 @OA\Property(property="hours_percentage", type="number")
     *             ),
     *             @OA\Property(property="cost_calculation", type="object",
     *                 @OA\Property(property="total_cost", type="number"),
     *                 @OA\Property(property="approved_cost", type="number"),
     *                 @OA\Property(property="pending_cost", type="number"),
     *                 @OA\Property(property="margin", type="number"),
     *                 @OA\Property(property="margin_percentage", type="number")
     *             ),
     *             @OA\Property(property="consultant_breakdown", type="array",
     *                 @OA\Items(type="object",
     *                     @OA\Property(property="consultant_name", type="string"),
     *                     @OA\Property(property="total_hours", type="number"),
     *                     @OA\Property(property="approved_hours", type="number"),
     *                     @OA\Property(property="pending_hours", type="number"),
     *                     @OA\Property(property="cost", type="number")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Projeto não encontrado"
     *     )
     * )
     */
    public function costSummary(Project $project): JsonResponse
    {
        // Carregar dados relacionados do projeto principal
        $project->load(['timesheets.user', 'consultants', 'childProjects.timesheets.user', 'childProjects.contractType', 'hourContributions', 'contractType']);

        // Informações básicas do projeto
        $projectInfo = [
            'id' => $project->id,
            'name' => $project->name,
            'code' => $project->code,
            'project_value' => $project->project_value,
            'hourly_rate' => $project->hourly_rate,
            'sold_hours' => $project->sold_hours,
            'hour_contribution' => $project->hour_contribution,  // @deprecated - mantido para compatibilidade
            'exceeded_hour_contribution' => $project->exceeded_hour_contribution,
            'initial_hours_balance' => $project->initial_hours_balance,
            'initial_cost' => $project->initial_cost,
            'tipo_faturamento' => $project->tipo_faturamento,
            'has_child_projects' => $project->hasChildProjects(),
            // ✨ Novos campos calculados usando hour_contributions table
            'total_available_hours' => $project->getTotalAvailableHours(),
            'total_project_value' => $project->calculateTotalProjectValue(),
            'weighted_hourly_rate' => $project->getWeightedAverageHourlyRate(),
            'total_contributions_hours' => $project->hourContributions()->sum('contributed_hours') ?? 0,
        ];

        // Calcular horas do projeto principal
        $parentLoggedMinutes = $project->timesheets->sum('effort_minutes');
        $parentApprovedMinutes = $project->timesheets->where('status', 'approved')->sum('effort_minutes');
        $parentPendingMinutes = $project->timesheets->where('status', 'pending')->sum('effort_minutes');

        // Calcular horas dos projetos filhos
        $childLoggedMinutes = 0;
        $childApprovedMinutes = 0;
        $childPendingMinutes = 0;
        $childProjectsBreakdown = [];

        foreach ($project->childProjects as $childProject) {
            $childTotalMinutes = $childProject->timesheets->sum('effort_minutes');
            $childApproved = $childProject->timesheets->where('status', 'approved')->sum('effort_minutes');
            $childPending = $childProject->timesheets->where('status', 'pending')->sum('effort_minutes');

            $childLoggedMinutes += $childTotalMinutes;
            $childApprovedMinutes += $childApproved;
            $childPendingMinutes += $childPending;

            // Calcular saldo consumido baseado no tipo de contrato
            $childProject->loadMissing('contractType');
            $isClosedContract = $childProject->contractType &&
                                strtolower(trim($childProject->contractType->name)) === 'fechado';

            if ($isClosedContract) {
                // Para contratos fechados: saldo consumido = total de horas disponíveis (inclui aportes novos + fallback legado)
                $consumedBalance = $childProject->getTotalAvailableHours();
            } else {
                // Para outros tipos: saldo consumido = total de horas apontadas
                $consumedBalance = round($childTotalMinutes / 60, 2);
            }

            $childProjectsBreakdown[] = [
                'id' => $childProject->id,
                'name' => $childProject->name,
                'code' => $childProject->code,
                'total_hours' => round($childTotalMinutes / 60, 2),
                'approved_hours' => round($childApproved / 60, 2),
                'pending_hours' => round($childPending / 60, 2),
                'consumed_balance' => round($consumedBalance, 2),
            ];
        }

        // Totais combinados (projeto pai + filhos)
        $totalLoggedMinutes = $parentLoggedMinutes + $childLoggedMinutes;
        $approvedMinutes = $parentApprovedMinutes + $childApprovedMinutes;
        $pendingMinutes = $parentPendingMinutes + $childPendingMinutes;

        $totalLoggedHours = round($totalLoggedMinutes / 60, 2);
        $approvedHours = round($approvedMinutes / 60, 2);
        $pendingHours = round($pendingMinutes / 60, 2);

        $soldHours = $project->sold_hours ?? 0;
        // Usar método auxiliar para obter total disponível (inclui aportes novos + fallback legado)
        $totalAvailableHours = $project->getTotalAvailableHours();

        $remainingHours = max(0, $soldHours - $totalLoggedHours);
        // Calcular percentual considerando o total disponível (horas vendidas + aportes)
        $hoursPercentage = $totalAvailableHours > 0 ? round(($totalLoggedHours / $totalAvailableHours) * 100, 2) : 0;

        // Calcular saldo real disponível usando getGeneralHoursBalance (considera lógica de contratos fechados)
        $generalBalance = $project->getGeneralHoursBalance();

        $hoursSummary = [
            'total_logged_hours' => $totalLoggedHours,
            'approved_hours' => $approvedHours,
            'pending_hours' => $pendingHours,
            'remaining_hours' => $remainingHours, // Mantido para compatibilidade (cálculo simples)
            'general_balance' => round($generalBalance, 2), // Saldo real disponível calculado
            'total_available_hours' => round($totalAvailableHours, 2), // Horas vendidas + aporte de horas
            'hours_percentage' => $hoursPercentage,
            'parent_project_hours' => round($parentLoggedMinutes / 60, 2),
            'child_projects_hours' => round($childLoggedMinutes / 60, 2),
        ];

        // Calcular custos usando o valor/hora próprio de cada consultor
        $totalCost = 0;
        $approvedCost = 0;
        $pendingCost = 0;

        // Quebra por consultor (incluindo horas de projetos filhos)
        $consultantBreakdown = [];

        // Coletar todos os timesheets (do projeto pai e dos filhos)
        $allTimesheets = collect($project->timesheets);
        foreach ($project->childProjects as $childProject) {
            // Adicionar informação do projeto filho aos timesheets
            foreach ($childProject->timesheets as $timesheet) {
                $timesheet->child_project_name = $childProject->name;
                $timesheet->child_project_code = $childProject->code;
            }
            $allTimesheets = $allTimesheets->merge($childProject->timesheets);
        }

        $timesheetsByUser = $allTimesheets->groupBy('user_id');

        foreach ($timesheetsByUser as $userId => $userTimesheets) {
            $user = $userTimesheets->first()->user;
            $userTotalMinutes = $userTimesheets->sum('effort_minutes');
            $userApprovedMinutes = $userTimesheets->where('status', 'approved')->sum('effort_minutes');
            $userPendingMinutes = $userTimesheets->where('status', 'pending')->sum('effort_minutes');

            $userTotalHours = round($userTotalMinutes / 60, 2);
            $userApprovedHours = round($userApprovedMinutes / 60, 2);
            $userPendingHours = round($userPendingMinutes / 60, 2);

            // Calcular o valor/hora efetivo do consultor:
            // - rate_type = 'hourly': usa hourly_rate diretamente
            // - rate_type = 'monthly': divide hourly_rate por 180 (horas mensais convencionadas)
            // - sem hourly_rate: assume 0
            $userHourlyRate = (float) ($user->hourly_rate ?? 0);
            $rateType = $user->rate_type ?? 'hourly';
            $effectiveHourlyRate = ($rateType === 'monthly' && $userHourlyRate > 0)
                ? round($userHourlyRate / 180, 4)
                : $userHourlyRate;

            $userCost = round($userTotalHours * $effectiveHourlyRate, 2);
            $userApprovedCost = round($userApprovedHours * $effectiveHourlyRate, 2);
            $userPendingCost = round($userPendingHours * $effectiveHourlyRate, 2);

            $totalCost += $userCost;
            $approvedCost += $userApprovedCost;
            $pendingCost += $userPendingCost;

            // Detalhar horas por projeto (pai e filhos)
            $projectsBreakdown = [];

            // Horas do projeto pai
            $parentUserTimesheets = $userTimesheets->filter(function ($ts) use ($project) {
                return $ts->project_id === $project->id;
            });

            if ($parentUserTimesheets->count() > 0) {
                $parentUserHours = round($parentUserTimesheets->sum('effort_minutes') / 60, 2);
                $projectsBreakdown[] = [
                    'project_name' => $project->name . ' (Principal)',
                    'project_code' => $project->code,
                    'hours' => $parentUserHours,
                    'hourly_rate' => $effectiveHourlyRate,
                ];
            }

            // Horas dos projetos filhos
            foreach ($project->childProjects as $childProject) {
                $childUserTimesheets = $userTimesheets->filter(function ($ts) use ($childProject) {
                    return $ts->project_id === $childProject->id;
                });

                if ($childUserTimesheets->count() > 0) {
                    $childUserHours = round($childUserTimesheets->sum('effort_minutes') / 60, 2);
                    $projectsBreakdown[] = [
                        'project_name' => $childProject->name . ' (Subprojeto)',
                        'project_code' => $childProject->code,
                        'hours' => $childUserHours,
                        'hourly_rate' => $effectiveHourlyRate,
                    ];
                }
            }

            $consultantBreakdown[] = [
                'consultant_name' => $user->name,
                'total_hours' => $userTotalHours,
                'approved_hours' => $userApprovedHours,
                'pending_hours' => $userPendingHours,
                'cost' => $userCost,
                'consultant_hourly_rate' => $effectiveHourlyRate,
                'consultant_rate_type' => $rateType,
                'projects_breakdown' => $projectsBreakdown,
            ];
        }

        $aportesTotal     = $project->hourContributions->sum(fn($c) => (float)$c->contributed_hours * (float)$c->hourly_rate);
        $isOnDemand       = $project->tipo_faturamento === 'on_demand'
            || ($project->contractType && stripos($project->contractType->name, 'on demand') !== false)
            || ($project->contractType && stripos($project->contractType->name, 'on_demand') !== false);
        $projectRevenue   = $isOnDemand
            ? round($totalLoggedHours * (float)($project->hourly_rate ?? 0), 2)
            : (float)($project->project_value ?? 0);
        $receitaTotal     = $projectRevenue + $aportesTotal;
        $initialCost      = (float)($project->initial_cost ?? 0);
        $custoTotal       = $initialCost + $totalCost;
        $margin           = $receitaTotal - $custoTotal;
        $marginPercentage = $receitaTotal > 0 ? round(($margin / $receitaTotal) * 100, 2) : 0;
        $coordPct         = (float)($project->coordinator_percentage ?? 0);
        $valorCoordenador = $coordPct > 0 ? round($margin * ($coordPct / 100), 2) : 0;

        $costCalculation = [
            'total_cost' => round($totalCost, 2),
            'approved_cost' => round($approvedCost, 2),
            'pending_cost' => round($pendingCost, 2),
            'is_on_demand' => $isOnDemand,
            'project_revenue' => round($projectRevenue, 2),
            'aportes_total' => round($aportesTotal, 2),
            'receita_total' => round($receitaTotal, 2),
            'custo_operacional' => round($totalCost, 2),
            'custo_total' => round($custoTotal, 2),
            'margin' => round($margin, 2),
            'margin_percentage' => $marginPercentage,
            'coordinator_percentage' => $coordPct,
            'valor_coordenador' => $valorCoordenador,
        ];

        return response()->json([
            'project_info' => $projectInfo,
            'hours_summary' => $hoursSummary,
            'cost_calculation' => $costCalculation,
            'consultant_breakdown' => $consultantBreakdown,
            'child_projects_summary' => $childProjectsBreakdown,
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/projects/enum-values",
     *     tags={"Projects"},
     *     summary="Obter valores dos enums",
     *     description="Retorna os valores possíveis para enums de projetos",
     *     security={{"sanctum": {}}},
     *     @OA\Response(
     *         response=200,
     *         description="Valores dos enums",
     *         @OA\JsonContent(
     *             @OA\Property(property="contract_types", type="object"),
     *             @OA\Property(property="statuses", type="object"),
     *             @OA\Property(property="expense_responsible_parties", type="object")
     *         )
     *     )
     * )
     */
    public function enumValues(): JsonResponse
    {
        return response()->json([
            'contract_types' => ContractType::getActiveOptions(),
            'statuses' => Project::getStatuses(),
            'expense_responsible_parties' => Project::getExpenseResponsiblePartyOptions(),
        ]);
    }

    public function nextCode(Request $request): JsonResponse
    {
        $request->validate(['customer_id' => 'required|exists:customers,id']);

        $customer = Customer::findOrFail($request->customer_id);

        if (!$customer->code_prefix) {
            return response()->json(['code' => null, 'error' => 'Cliente sem prefixo de código'], 422);
        }

        $seq = \App\Models\ProjectSequence::where('customer_id', $customer->id)->first();
        $nextSeq = ($seq?->last_sequence ?? 0) + 1;
        $prefix  = strtoupper($customer->code_prefix);
        $year    = now()->format('y');

        // Avança até encontrar código disponível
        do {
            $padded = str_pad($nextSeq, 3, '0', STR_PAD_LEFT);
            $code   = $prefix . $padded . '-' . $year;
            $nextSeq++;
        } while (Project::withTrashed()->where('code', $code)->exists());

        return response()->json(['code' => $code, 'prefix' => $prefix, 'year' => $year]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/projects/{id}/available-hours",
     *     tags={"Projects"},
     *     summary="Obter horas disponíveis de um projeto pai",
     *     description="Retorna quantas horas ainda estão disponíveis em um projeto pai para alocar em subprojetos",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer"),
     *         description="ID do projeto pai"
     *     ),
     *     @OA\Parameter(
     *         name="exclude_id",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="ID do projeto a excluir do cálculo (útil na edição)"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Horas disponíveis",
     *         @OA\JsonContent(
     *             @OA\Property(property="parent_sold_hours", type="integer", example=100),
     *             @OA\Property(property="children_total_hours", type="integer", example=60),
     *             @OA\Property(property="available_hours", type="integer", example=40)
     *         )
     *     ),
     *     @OA\Response(response=404, description="Projeto não encontrado")
     * )
     */
    public function availableHours(Request $request, Project $project): JsonResponse
    {
        $excludeProjectId = $request->get('exclude_id');

        // Obter o saldo geral do projeto (já inclui todos os filhos com a lógica correta)
        $generalBalance = $project->getGeneralHoursBalance();

        // Calcular saldo excluindo o projeto filho específico (se fornecido)
        $availableBalance = $generalBalance;

        if ($excludeProjectId) {
            $excludedProject = $project->childProjects()->find($excludeProjectId);

            if ($excludedProject) {
                // Carregar contractType se necessário
                $excludedProject->loadMissing('contractType');

                // Verificar se o projeto excluído tem contract_type com name = "Fechado"
                $isClosedContract = $excludedProject->contractType &&
                                    strtolower(trim($excludedProject->contractType->name)) === 'fechado';

                if ($isClosedContract) {
                    // Para contratos fechados: foi subtraído (horas vendidas + aporte de horas)
                    $excludedSoldHours = $excludedProject->sold_hours ?? 0;
                    $excludedHourContribution = $excludedProject->hour_contribution ?? 0;
                    $availableBalance += ($excludedSoldHours + $excludedHourContribution);
                } else {
                    // Para outros tipos: foi subtraído pelas horas apontadas
                    $excludedLoggedHours = $excludedProject->getTotalLoggedHours(false);
                    $availableBalance += $excludedLoggedHours;
                }
            }
        }

        // Calcular informações adicionais para o retorno
        $parentSoldHours = $project->sold_hours ?? 0;
        $parentTotalLoggedHours = $project->getTotalLoggedHours(false);
        
        // Usar método auxiliar para obter total disponível (inclui aportes novos + fallback legado)
        $totalAvailable = $project->getTotalAvailableHours();

        return response()->json([
            'parent_sold_hours' => $parentSoldHours,
            'parent_hour_contribution' => $project->hour_contribution ?? 0,  // @deprecated - mantido para compatibilidade
            'parent_total_available' => $totalAvailable,
            'parent_total_logged_hours' => round($parentTotalLoggedHours, 2),
            'general_balance' => round($generalBalance, 2),
            'available_balance' => max(0, round($availableBalance, 2)),
            // ✨ Novos campos calculados usando hour_contributions table
            'parent_total_contributions_hours' => $project->hourContributions()->sum('contributed_hours') ?? 0,
            'parent_weighted_hourly_rate' => $project->getWeightedAverageHourlyRate(),
        ]);
    }

    /**
     * Calcula as horas disponíveis em um projeto pai para subprojetos
     *
     * Utiliza o método getGeneralHoursBalance do modelo Project para calcular o saldo,
     * que já considera a lógica especial para contratos fechados.
     *
     * @param Project $parentProject Projeto pai
     * @param int|null $excludeProjectId ID do projeto a excluir do cálculo (útil na edição)
     * @return int Horas disponíveis
     */
    private function calculateAvailableHours(Project $parentProject, ?int $excludeProjectId = null): int
    {
        // Obter o saldo geral do projeto pai (já inclui todos os filhos)
        $balance = $parentProject->getGeneralHoursBalance();

        // Se há um projeto filho para excluir, adicionar de volta o que foi subtraído dele
        if ($excludeProjectId) {
            $excludedProject = $parentProject->childProjects()->find($excludeProjectId);

            if ($excludedProject) {
                // Carregar contractType se necessário
                $excludedProject->loadMissing('contractType');

                // Verificar se o projeto excluído tem contract_type com name = "Fechado"
                $isClosedContract = $excludedProject->contractType &&
                                    strtolower(trim($excludedProject->contractType->name)) === 'fechado';

                if ($isClosedContract) {
                    // Para contratos fechados: foi subtraído (horas vendidas + aportes)
                    // Usar getTotalAvailableHours() que já contempla novos aportes + fallback legado
                    $excludedTotalHours = $excludedProject->getTotalAvailableHours();
                    $balance += $excludedTotalHours;
                } else {
                    // Para outros tipos: foi subtraído pelas horas apontadas
                    $excludedLoggedHours = $excludedProject->getTotalLoggedHours(false);
                    $balance += $excludedLoggedHours;
                }
            }
        }

        // Retornar como int (arredondado) e garantir que não seja negativo
        return max(0, (int) round($balance));
    }

    /**
     * @OA\Get(
     *     path="/api/v1/projects/{project}/change-history",
     *     tags={"Projects"},
     *     summary="Histórico de alterações do projeto",
     *     description="Lista o histórico de alterações de dados sensíveis do projeto (valores, horas e políticas de despesas)",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="project",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer", example=1),
     *         description="ID do projeto"
     *     ),
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer", minimum=1, example=1),
     *         description="Página (padrão: 1)"
     *     ),
     *     @OA\Parameter(
     *         name="pageSize",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer", minimum=1, maximum=100, example=20),
     *         description="Registros por página (padrão: 20)"
     *     ),
     *     @OA\Parameter(
     *         name="order",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="string", example="-created_at"),
     *         description="Ordenação (ex: -created_at para mais recentes primeiro)"
     *     ),
     *     @OA\Parameter(
     *         name="field_name",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="string", example="project_value"),
     *         description="Filtrar por campo alterado (ex: project_value, hourly_rate, sold_hours, etc.)"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Histórico de alterações",
     *         @OA\JsonContent(
     *             @OA\Property(property="hasNext", type="boolean", example=false),
     *             @OA\Property(
     *                 property="items",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer"),
     *                     @OA\Property(property="project_id", type="integer"),
     *                     @OA\Property(property="changed_by", type="integer"),
     *                     @OA\Property(property="field_name", type="string"),
     *                     @OA\Property(property="field_label", type="string"),
     *                     @OA\Property(property="old_value", type="string"),
     *                     @OA\Property(property="new_value", type="string"),
     *                     @OA\Property(property="old_value_formatted", type="string"),
     *                     @OA\Property(property="new_value_formatted", type="string"),
     *                     @OA\Property(property="reason", type="string", nullable=true),
     *                     @OA\Property(property="created_at", type="string", format="date-time"),
     *                     @OA\Property(
     *                         property="changed_by_user",
     *                         type="object",
     *                         @OA\Property(property="id", type="integer"),
     *                         @OA\Property(property="name", type="string"),
     *                         @OA\Property(property="email", type="string")
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Não autenticado"),
     *     @OA\Response(response=403, description="Sem permissão"),
     *     @OA\Response(response=404, description="Projeto não encontrado")
     * )
     */
    public function contractRequest(Project $project): JsonResponse
    {
        if (!$project->contract_request_id) {
            return response()->json(null);
        }

        $req = \App\Models\ContractRequest::with([
            'customer:id,name',
            'createdBy:id,name',
            'messages.author:id,name',
            'messages.attachments',
        ])->find($project->contract_request_id);

        return response()->json($req);
    }

    public function changeHistory(Request $request, Project $project): JsonResponse
    {
        // Preparar query base
        $query = ProjectChangeLog::query()
            ->where('project_id', $project->id)
            ->with('changedByUser:id,name,email');

        // Filtro por campo alterado
        if ($request->has('field_name') && $request->get('field_name') !== 'all' && $request->get('field_name') !== null) {
            $query->where('field_name', $request->get('field_name'));
        }

        // Ordenação
        $orderField = 'created_at';
        $orderDirection = 'desc';

        if ($request->has('order')) {
            $orderParam = $request->get('order');
            if (str_starts_with($orderParam, '-')) {
                $orderField = substr($orderParam, 1);
                $orderDirection = 'desc';
            } else {
                $orderField = $orderParam;
                $orderDirection = 'asc';
            }
        }

        $query->orderBy($orderField, $orderDirection);

        // Paginação
        $pageSize = min((int)$request->get('pageSize', 20), 100);
        $page = (int)$request->get('page', 1);

        $logs = $query->paginate($pageSize, ['*'], 'page', $page);

        // Formatar os registros
        $items = $logs->map(function ($log) {
            return $log->toFormattedArray();
        });

        return response()->json([
            'hasNext' => $logs->hasMorePages(),
            'items' => $items
        ]);
    }

    /**
     * Calcula o saldo geral de horas do projeto de forma otimizada
     * usando dados já carregados (evita N+1 queries)
     *
     * Replica a lógica de getGeneralHoursBalance mas usando:
     * - total_logged_minutes (carregado via withSum, já excluindo rejeitados)
     * - childProjects já carregados com suas somas
     * - accumulated_sold_hours para Banco de Horas Mensal
     *
     * @param Project $project Projeto com dados já carregados
     * @return float Saldo geral em horas
     */
    private function calculateGeneralHoursBalance(Project $project): float
    {
        // Carregar contractType se necessário
        if (!$project->relationLoaded('contractType') && $project->contract_type_id) {
            $project->load('contractType');
        }

        // Para Banco de Horas Mensal, usar accumulated_sold_hours; caso contrário, usar sold_hours
        if ($project->isBankHoursMonthly()) {
            $soldHours = $project->accumulated_sold_hours ?? $project->sold_hours ?? 0;
        } else {
            $soldHours = $project->sold_hours ?? 0;
        }
        
        // Usar método auxiliar para obter aportes (novos + fallback legado)
        $totalAvailableHours = $project->getTotalAvailableHours();
        $contributionHours = $totalAvailableHours - ($project->sold_hours ?? 0);

        // Converter minutos apontados para horas (dados já carregados via withSum, excluindo rejeitados)
        $totalLoggedMinutes = $project->total_logged_minutes ?? 0;
        $totalLoggedHours = round($totalLoggedMinutes / 60, 2);

        // Calcular saldo base do projeto atual
        // IMPORTANTE: Para Banco de Horas Mensal, soldHours já é accumulated_sold_hours
        $initialConsumed = (float) ($project->initial_hours_consumed ?? 0);
        $balance = ($soldHours + $contributionHours) - $totalLoggedHours - $initialConsumed;

        // Sempre incluir projetos filhos no cálculo (se existirem)
        if ($project->relationLoaded('childProjects') && $project->childProjects->isNotEmpty()) {
            foreach ($project->childProjects as $childProject) {
                if ($childProject->isAusterFrozen()) continue;

                // Verificar se o projeto filho é do tipo "Fechado"
                $isClosedContract = $childProject->contractType &&
                                    strtolower(trim($childProject->contractType->name)) === 'fechado';

                if ($isClosedContract) {
                    // Para contratos fechados: subtrair (horas vendidas + aportes) do projeto filho
                    // Usar getTotalAvailableHours() que já contempla novos aportes + fallback legado
                    $childTotalHours = $childProject->getTotalAvailableHours();
                    $balance -= $childTotalHours;
                } elseif ($childProject->isBankHoursMonthly()) {
                    // Para Banco de Horas Mensal: usar accumulated_sold_hours no cálculo
                    $childSoldHours = $childProject->accumulated_sold_hours ?? $childProject->sold_hours ?? 0;
                    
                    // Calcular aportes usando método auxiliar
                    $childTotalAvailable = $childProject->getTotalAvailableHours();
                    $childContributionHours = $childTotalAvailable - ($childProject->sold_hours ?? 0);
                    
                    // Calcular horas apontadas do filho (já excluindo rejeitados via withSum)
                    $childLoggedMinutes = $childProject->total_logged_minutes ?? 0;
                    $childLoggedHours = round($childLoggedMinutes / 60, 2);
                    
                    // Subtrair o saldo do filho: (accumulated_sold_hours + aportes) - horas apontadas - horas consumidas iniciais
                    $childInitialConsumed = (float) ($childProject->initial_hours_consumed ?? 0);
                    $childBalance = ($childSoldHours + $childContributionHours) - $childLoggedHours - $childInitialConsumed;
                    $balance -= $childBalance;
                } else {
                    // Para outros tipos: subtrair normalmente pelas horas apontadas (já excluindo rejeitados)
                    $childLoggedMinutes = $childProject->total_logged_minutes ?? 0;
                    $childLoggedHours = round($childLoggedMinutes / 60, 2);
                    $balance -= $childLoggedHours;
                }
            }
        }

        return round($balance, 2);
    }

    public function updateStatus(Request $request, Project $project): JsonResponse
    {
        $user = $request->user();
        if (!$user->isAdmin() && !$user->isCoordenador()) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(array_keys(Project::getStatuses()))],
        ]);

        $project->status = $validated['status'];
        $project->save();

        return response()->json([
            'success' => true,
            'message' => 'Status atualizado com sucesso',
            'status' => $project->status,
            'status_display' => $project->status_display,
        ]);
    }

    public function icSummary(Request $request): JsonResponse
    {
        $from = $request->get('start_date');
        $to   = $request->get('end_date');

        $query = \App\Models\Timesheet::query()
            ->join('projects', 'projects.id', '=', 'timesheets.project_id')
            ->join('customers', 'customers.id', '=', 'projects.customer_id')
            ->where('projects.is_investimento_comercial', true)
            ->whereNull('timesheets.deleted_at')
            ->whereNotIn('timesheets.status', [
                \App\Models\Timesheet::STATUS_REJECTED,
                \App\Models\Timesheet::STATUS_ADJUSTMENT_REQUESTED,
                \App\Models\Timesheet::STATUS_CONFLICTED,
            ])
            ->selectRaw('projects.id as project_id, projects.code, customers.id as customer_id, customers.name as customer_name, COALESCE(SUM(timesheets.effort_minutes), 0) as total_minutes')
            ->groupBy('projects.id', 'projects.code', 'customers.id', 'customers.name');

        if ($from) {
            $query->where('timesheets.date', '>=', $from);
        }
        if ($to) {
            $query->where('timesheets.date', '<=', $to);
        }

        $rows = $query->get()->map(fn ($r) => [
            'project_id'    => $r->project_id,
            'code'          => $r->code,
            'customer_id'   => $r->customer_id,
            'customer_name' => $r->customer_name,
            'total_hours'   => round($r->total_minutes / 60, 2),
        ]);

        return response()->json($rows);
    }

    public function hoursPerConsultant(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user->isAdmin() && !$user->isCoordenador()) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        // Escopo de projetos acessíveis
        $projectQuery = Project::query();
        if ($user->isCoordenador()) {
            $projectQuery->whereHas('coordinators', fn($q) => $q->where('users.id', $user->id));
        }
        $projectIds = $projectQuery->pluck('id');

        $rows = DB::table('timesheets')
            ->join('users', 'users.id', '=', 'timesheets.user_id')
            ->whereIn('timesheets.project_id', $projectIds)
            ->whereIn('timesheets.status', ['approved', 'pending'])
            ->select(
                'users.id',
                'users.name',
                DB::raw('ROUND(SUM(timesheets.effort_minutes) / 60.0, 1) as total_hours')
            )
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('total_hours')
            ->limit(30)
            ->get();

        return response()->json($rows);
    }

    // ─── Project Attachments ───────────────────────────────────────────────────

    public function listAttachments(Project $project): \Illuminate\Http\JsonResponse
    {
        $attachments = $project->attachments()->with('contractAttachment')->get()->map(function ($a) {
            return [
                'id'            => $a->id,
                'type'          => $a->type ?? $a->contractAttachment?->type,
                'original_name' => $a->display_name,
                'mime_type'     => $a->mime_type ?? $a->contractAttachment?->mime_type,
                'size'          => $a->size ?? $a->contractAttachment?->size,
                'source'        => $a->path ? 'project' : 'contract',
                'created_at'    => $a->created_at,
            ];
        });
        return response()->json($attachments);
    }

    public function uploadAttachment(Request $request, Project $project): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:20480',
            'type' => 'required|in:proposta,contrato,logo,outro',
        ]);

        $file = $request->file('file');
        $path = $file->store("projects/{$project->id}/attachments");

        $attachment = ProjectAttachment::create([
            'project_id'     => $project->id,
            'uploaded_by_id' => auth()->id(),
            'type'           => $request->input('type'),
            'path'           => $path,
            'original_name'  => $file->getClientOriginalName(),
            'mime_type'      => $file->getMimeType(),
            'size'           => $file->getSize(),
        ]);

        return response()->json($attachment, 201);
    }

    public function downloadAttachment(Project $project, ProjectAttachment $attachment): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        abort_if($attachment->project_id !== $project->id, 404);
        $path = $attachment->effective_path;
        abort_unless($path && Storage::exists($path), 404, 'Arquivo não encontrado.');
        return Storage::download($path, $attachment->display_name);
    }

    public function deleteAttachment(Project $project, ProjectAttachment $attachment): \Illuminate\Http\JsonResponse
    {
        abort_if($attachment->project_id !== $project->id, 404);
        if ($attachment->path) {
            Storage::delete($attachment->path);
        }
        $attachment->delete();
        return response()->json(null, 204);
    }
}
