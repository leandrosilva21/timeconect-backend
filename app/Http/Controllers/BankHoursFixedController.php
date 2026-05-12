<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Project;
use App\Models\ProjectChangeLog;
use App\Models\Timesheet;
use App\Models\MovideskTicket;
use App\Models\ServiceType;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * @OA\Tag(
 *     name="Dashboards",
 *     description="Dashboards e Relatórios"
 * )
 */
class BankHoursFixedController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/v1/dashboards/bank-hours-fixed",
     *     tags={"Dashboards"},
     *     summary="Dashboard de Banco de Horas Fixo",
     *     description="Retorna dados formatados para o dashboard de banco de horas fixo. Usuários de cliente só veem dados do seu cliente.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="customer_id",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="Filtrar por cliente (apenas para administradores)"
     *     ),
     *     @OA\Parameter(
     *         name="project_id",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="Filtrar por projeto pai específico"
     *     ),
     *     @OA\Parameter(
     *         name="month",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer", minimum=1, maximum=12),
     *         description="Mês para calcular o consumo (1-12). Se não fornecido, usa o mês atual."
     *     ),
     *     @OA\Parameter(
     *         name="year",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="Ano para calcular o consumo. Se não fornecido, usa o ano atual."
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Dados do dashboard",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Dados do dashboard obtidos com sucesso"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="contracted_hours", type="integer", example=1200, description="Total de horas contratadas (soma de sold_hours dos projetos pais)"),
     *                 @OA\Property(property="contributed_hours", type="integer", example=100, description="Total de aporte de horas (soma de hour_contribution de todos os projetos)"),
     *                 @OA\Property(property="consumed_hours", type="number", example=850.5, description="Consumo acumulado (horas apontadas, com regra especial para projetos fechados)"),
     *                 @OA\Property(property="month_consumed_hours", type="number", example=120.5, description="Consumo do mês (apontamentos do mês especificado + projetos fechados com start_date no mês especificado). Se mês/ano não forem fornecidos, usa o mês atual."),
     *                 @OA\Property(property="hours_balance", type="number", example=449.5, description="Saldo de horas (soma dos saldos dos projetos pais)"),
     *                 @OA\Property(property="exceeded_hours", type="number", example=0, description="Horas excedentes (valor absoluto do saldo quando negativo, ou 0 quando positivo/zero)"),
     *                 @OA\Property(property="amount_to_pay", type="number", nullable=true, example=0, description="Valor a pagar (horas excedentes * valor hora)"),
     *                 @OA\Property(property="hourly_rate", type="number", nullable=true, example=180.50, description="Valor hora (média de additional_hourly_rate dos projetos pais, ou valor específico se filtrado por projeto)"),
     *                 @OA\Property(property="customer_id", type="integer", nullable=true, example=1, description="ID do cliente filtrado"),
     *                 @OA\Property(property="project_id", type="integer", nullable=true, example=5, description="ID do projeto filtrado (se aplicável)")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Não autenticado"),
     *     @OA\Response(response=403, description="Sem permissão")
     * )
     */
    public function bankHoursFixed(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        // Verificar se o usuário tem permissão de dashboard geral ou é admin
        if (!$user->isAdmin() && !$user->hasAccess('dashboards.view')) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado. Você precisa da permissão "dashboards.view" para acessar este dashboard.',
                'required_permission' => 'dashboards.view'
            ], 403);
        }

        // Determinar o cliente a filtrar
        $customerId = null;

        // Se o usuário está vinculado a um cliente, usar esse cliente
        if ($user->customer_id) {
            $customerId = $user->customer_id;
        }
        // Se for admin e forneceu customer_id, usar o fornecido
        elseif ($user->isAdmin() && $request->has('customer_id')) {
            $customerId = $request->get('customer_id');
        }

        // Filtrar por projeto específico se fornecido
        $projectId = $request->get('project_id');

        // Filtrar por tipo de serviço se fornecido
        $serviceTypeName = $request->get('service_type_name');
        $serviceTypeId = null;
        if ($serviceTypeName) {
            $serviceType = ServiceType::where('code', strtolower($serviceTypeName))
                ->orWhere('name', $serviceTypeName)
                ->first();
            if ($serviceType) {
                $serviceTypeId = $serviceType->id;
            }
        }

        // Quando um projeto específico é informado, busca esse projeto diretamente
        // (pai ou filho) sem restrição de hierarquia.
        // Quando nenhum projeto é informado, busca todos os projetos BH Fixo do cliente —
        // incluindo filhos cujo pai não é BH Fixo (ex.: projeto filho independente).
        // Filhos cujo pai já é BH Fixo são excluídos para evitar dupla contagem,
        // pois o pai os agrega via getGeneralHoursBalance().
        $bhFixedType = \App\Models\ContractType::where('name', 'Banco de Horas Fixo')->first();

        if ($projectId) {
            $query = Project::where('id', $projectId);
        } else {
            $query = Project::where('contract_type_id', $bhFixedType?->id ?? 0);

            // Aplicar filtro de cliente
            if ($customerId) {
                $query->where('customer_id', $customerId);
            }

            // Aplicar filtro de executivo (admin apenas, quando não há cliente específico)
            if ($user->isAdmin() && !$customerId && $request->filled('executive_id')) {
                $executiveId = (int) $request->get('executive_id');
                $query->whereHas('customer', function ($q) use ($executiveId) {
                    $q->where('executive_id', $executiveId);
                });
            }

            // Incluir: (1) projetos raiz, OU (2) projetos filho cujo pai NÃO é BH Fixo.
            // Filhos cujo pai é BH Fixo são excluídos — o pai já os agrega.
            $query->where(function ($q) use ($bhFixedType) {
                $q->whereNull('parent_project_id')
                  ->orWhereDoesntHave('parentProject', function ($pq) use ($bhFixedType) {
                      $pq->where('contract_type_id', $bhFixedType?->id ?? 0);
                  });
            });
        }

        // Aplicar filtro de tipo de serviço
        // Se há filtro por tipo de serviço, incluir projetos pais que:
        // 1. Têm o tipo de serviço especificado, OU
        // 2. Têm filhos com o tipo de serviço especificado
        if ($serviceTypeId) {
            $query->where(function($q) use ($serviceTypeId) {
                // Projetos pais com o tipo de serviço especificado
                $q->where('service_type_id', $serviceTypeId)
                  // OU projetos pais que têm filhos com o tipo de serviço especificado
                  ->orWhereHas('childProjects', function($childQuery) use ($serviceTypeId) {
                      $childQuery->where('service_type_id', $serviceTypeId);
                  });
            });
        }

        // Calcular horas contratadas (soma de sold_hours dos projetos pais)
        $contractedHours = (int) $query->sum('sold_hours') ?? 0;

        // Calcular saldo de horas (soma dos saldos dos projetos pais)
        $hoursBalance = 0;
        $parentProjects = $query->get();

        foreach ($parentProjects as $parentProject) {
            // getGeneralHoursBalance já exclui filhos frozen; subtrair initial_hours_consumed (histórico pré-importação)
            $projectBalance = $parentProject->getGeneralHoursBalance()
                - (float) ($parentProject->initial_hours_consumed ?? 0);
            $hoursBalance += $projectBalance;
        }

        // Arredondar para 2 casas decimais
        $hoursBalance = round($hoursBalance, 2);

        // Calcular horas excedentes (valor absoluto do saldo quando negativo, ou 0 quando positivo/zero)
        $exceededHours = $hoursBalance < 0 ? abs($hoursBalance) : 0;

        // Calcular valor hora PADRÃO (média do campo additional_hourly_rate dos projetos pais)
        // Este é o valor/hora INICIAL mostrado no card
        $hourlyRate = null;

        if ($projectId) {
            // Se filtrou por projeto específico, retornar apenas o valor desse projeto
            $specificProject = Project::find($projectId);
            if ($specificProject && $specificProject->additional_hourly_rate !== null) {
                $hourlyRate = round((float) $specificProject->additional_hourly_rate, 2);
            }
        } else {
            // Calcular média dos projetos pais que têm additional_hourly_rate preenchido
            $parentProjectsWithRate = $parentProjects->filter(function($project) {
                return $project->additional_hourly_rate !== null && $project->additional_hourly_rate > 0;
            });

            if ($parentProjectsWithRate->count() > 0) {
                $totalRate = $parentProjectsWithRate->sum('additional_hourly_rate');
                $averageRate = $totalRate / $parentProjectsWithRate->count();
                $hourlyRate = round($averageRate, 2);
            }
        }

        // Calcular valor hora PONDERADO (considera aportes com valores diferentes)
        // Este é o valor usado para calcular "Valor a Pagar"
        $weightedHourlyRate = null;
        
        if ($projectId) {
            // Se filtrou por projeto específico, usar a média ponderada dele
            $specificProject = Project::find($projectId);
            if ($specificProject) {
                $weighted = $specificProject->getWeightedAverageHourlyRate();
                if ($weighted > 0) {
                    $weightedHourlyRate = round($weighted, 2);
                }
            }
        } else {
            // Calcular média ponderada de todos os projetos pais
            $totalValue = 0;
            $totalHours = 0;
            
            foreach ($parentProjects as $project) {
                $projectTotalValue = $project->calculateTotalProjectValue();
                $projectTotalHours = $project->getTotalAvailableHours();
                
                if ($projectTotalHours > 0) {
                    $totalValue += $projectTotalValue;
                    $totalHours += $projectTotalHours;
                }
            }
            
            if ($totalHours > 0) {
                $weightedHourlyRate = round($totalValue / $totalHours, 2);
            }
        }

        // Calcular valor a pagar usando a média ponderada (considera aportes)
        // Usa weightedHourlyRate se disponível, senão usa hourlyRate padrão
        $amountToPay = null;
        $rateForPayment = $weightedHourlyRate ?? $hourlyRate;
        
        if ($exceededHours > 0 && $rateForPayment !== null) {
            $amountToPay = round($exceededHours * $rateForPayment, 2);
        }

        // Buscar projetos para cálculo de aporte
        if ($projectId) {
            // Projeto específico: busca diretamente (pai ou filho)
            $projectsForContribution = Project::where('id', $projectId);
        } else {
            // Sem projeto específico: usar exatamente os mesmos IDs já filtrados em $query
            $parentProjectIds = $query->pluck('id')->toArray();
            $projectsForContribution = Project::query();
            if (!empty($parentProjectIds)) {
                $projectsForContribution->whereIn('id', $parentProjectIds);
            } else {
                $projectsForContribution->whereRaw('1 = 0');
            }
        }

        // Aplicar filtro de tipo de serviço também no cálculo de aporte
        if ($serviceTypeId) {
            $projectsForContribution->where('service_type_id', $serviceTypeId);
        }

        // Calcular aporte de horas (soma de aportes da nova tabela + fallback para hour_contribution legado)
        $contributedHours = 0;
        $projectsForContributionList = $projectsForContribution->get();
        foreach ($projectsForContributionList as $project) {
            // Somar aportes da nova tabela (prioridade)
            $newContributions = $project->hourContributions()->sum('contributed_hours') ?? 0;
            if ($newContributions > 0) {
                $contributedHours += $newContributions;
            } else {
                // Fallback: usar aporte legado para projetos antigos
                $contributedHours += $project->hour_contribution ?? 0;
            }
        }
        $contributedHours = (int) $contributedHours;

        // Calcular consumo acumulado (horas apontadas, com regra especial para projetos fechados)
        $consumedHours = 0;

        // Buscar todos os projetos com seus relacionamentos necessários
        // serviceType do próprio projeto + filhos (evita N+1)
        $parentProjects = $query->with(['serviceType', 'contractType', 'childProjects.contractType', 'childProjects.serviceType'])->get();

        foreach ($parentProjects as $parentProject) {
            // Se há filtro por tipo de serviço, só incluir o projeto pai se ele tiver o tipo especificado
            // (os filhos serão processados separadamente)
            $includeParent = true;
            if ($serviceTypeId && $parentProject->service_type_id !== $serviceTypeId) {
                $includeParent = false;
            }

            if ($includeParent) {
                // Verificar se o projeto pai é do tipo "Fechado"
                $isParentClosedContract = $parentProject->contractType &&
                                          strtolower(trim($parentProject->contractType->name)) === 'fechado';

                if ($isParentClosedContract) {
                    // Para projetos fechados: usar total de horas disponíveis (inclui aportes novos + fallback legado)
                    $parentTotalHours = $parentProject->getTotalAvailableHours();
                    $consumedHours += $parentTotalHours;
                } else {
                    // Para outros tipos: usar horas apontadas normalmente (excluindo rejeitados)
                    $parentLoggedMinutes = $parentProject->timesheets()
                        ->where('status', '!=', 'rejected')
                        ->sum('effort_minutes') ?? 0;
                    $parentLoggedHours = round($parentLoggedMinutes / 60, 2);
                    $consumedHours += $parentLoggedHours;
                    // Incluir horas consumidas do sistema anterior
                    $consumedHours += (float) ($parentProject->initial_hours_consumed ?? 0);
                }
            }

            // Processar projetos filhos
            if ($parentProject->hasChildProjects()) {
                foreach ($parentProject->childProjects as $childProject) {
                    // Subprojetos congelados (Auster histórico) não contam em nenhum totalizador
                    if ($childProject->isAusterFrozen()) {
                        continue;
                    }
                    // Filtrar por tipo de serviço se especificado
                    if ($serviceTypeId && $childProject->service_type_id !== $serviceTypeId) {
                        continue;
                    }

                    // Verificar se o projeto filho é do tipo "Fechado"
                    $isClosedContract = $childProject->contractType &&
                                        strtolower(trim($childProject->contractType->name)) === 'fechado';

                    if ($isClosedContract) {
                        // Para projetos fechados: usar total de horas disponíveis (inclui aportes novos + fallback legado)
                        $childTotalHours = $childProject->getTotalAvailableHours();
                        $consumedHours += $childTotalHours;
                    } else {
                        // Para outros tipos (inclui BH Fixo): apontadas + initial_hours_consumed
                        // (alinhado com ProjectController::index gestao mode)
                        $childLoggedMinutes = $childProject->timesheets()
                            ->where('status', '!=', 'rejected')
                            ->sum('effort_minutes') ?? 0;
                        $childLoggedHours = round($childLoggedMinutes / 60, 2);
                        $consumedHours += $childLoggedHours;
                        $consumedHours += (float) ($childProject->initial_hours_consumed ?? 0);
                    }
                }
            }
        }

        // Arredondar para 2 casas decimais
        $consumedHours = round($consumedHours, 2);

        // Calcular consumo do mês (apontamentos do mês especificado + projetos fechados com start_date no mês especificado)
        $monthConsumedHours = 0;

        // Obter mês e ano dos parâmetros da requisição (ou usar mês atual se não fornecidos)
        $month = $request->get('month');
        $year = $request->get('year');

        if ($month && $year) {
            // Usar mês e ano fornecidos
            $targetDate = \Carbon\Carbon::create($year, $month, 1);
        } else {
            // Usar mês atual
            $targetDate = now();
        }

        $monthStart = $targetDate->copy()->startOfMonth()->format('Y-m-d');
        $monthEnd = $targetDate->copy()->endOfMonth()->format('Y-m-d');

        foreach ($parentProjects as $parentProject) {
            // Se há filtro por tipo de serviço, só incluir o projeto pai se ele tiver o tipo especificado
            // (os filhos serão processados separadamente)
            $includeParent = true;
            if ($serviceTypeId && $parentProject->service_type_id !== $serviceTypeId) {
                $includeParent = false;
            }

            if ($includeParent) {
                // Verificar se o projeto pai é do tipo "Fechado"
                $isParentClosedContract = $parentProject->contractType &&
                                          strtolower(trim($parentProject->contractType->name)) === 'fechado';

                if ($isParentClosedContract) {
                    // Para projetos fechados: verificar se start_date está no mês especificado
                    if ($parentProject->start_date) {
                        $parentStartDate = \Carbon\Carbon::parse($parentProject->start_date);
                        $isInTargetMonth = $parentStartDate->year === $targetDate->year &&
                                           $parentStartDate->month === $targetDate->month;

                        if ($isInTargetMonth) {
                            // Se o projeto fechado começou no mês especificado, usar horas vendidas
                            $parentSoldHours = $parentProject->sold_hours ?? 0;
                            $monthConsumedHours += $parentSoldHours;
                        }
                    }
                } else {
                        // Para outros tipos: usar horas apontadas do mês especificado (excluindo rejeitados)
                    $parentMonthLoggedMinutes = $parentProject->timesheets()
                        ->where('status', '!=', 'rejected')
                        ->whereBetween('date', [$monthStart, $monthEnd])
                        ->sum('effort_minutes') ?? 0;
                    $parentMonthLoggedHours = round($parentMonthLoggedMinutes / 60, 2);
                    $monthConsumedHours += $parentMonthLoggedHours;
                }
            }

            // Processar projetos filhos
            if ($parentProject->hasChildProjects()) {
                foreach ($parentProject->childProjects as $childProject) {
                    // Filtrar por tipo de serviço se especificado
                    if ($serviceTypeId && $childProject->service_type_id !== $serviceTypeId) {
                        continue;
                    }

                    // Verificar se o projeto filho é do tipo "Fechado"
                    $isClosedContract = $childProject->contractType &&
                                        strtolower(trim($childProject->contractType->name)) === 'fechado';

                    if ($isClosedContract) {
                        // Para projetos fechados: verificar se start_date está no mês especificado
                        if ($childProject->start_date) {
                            $childStartDate = \Carbon\Carbon::parse($childProject->start_date);
                            $isInTargetMonth = $childStartDate->year === $targetDate->year &&
                                               $childStartDate->month === $targetDate->month;

                            if ($isInTargetMonth) {
                                // Se o projeto fechado começou no mês especificado, usar horas vendidas
                                $childSoldHours = $childProject->sold_hours ?? 0;
                                $monthConsumedHours += $childSoldHours;
                            }
                        }
                    } else {
                        // Para outros tipos: usar horas apontadas do mês especificado (excluindo rejeitados)
                        $childMonthLoggedMinutes = $childProject->timesheets()
                            ->where('status', '!=', 'rejected')
                            ->whereBetween('date', [$monthStart, $monthEnd])
                            ->sum('effort_minutes') ?? 0;
                        $childMonthLoggedHours = round($childMonthLoggedMinutes / 60, 2);
                        $monthConsumedHours += $childMonthLoggedHours;
                    }
                }
            }
        }

        // Arredondar para 2 casas decimais
        $monthConsumedHours = round($monthConsumedHours, 2);

        // Buscar histórico de mudanças de hour_contribution (legado) + novos aportes
        $projectIdsForHistory = $projectsForContribution->pluck('id')->toArray();
        $contributionHistory = [];

        if (!empty($projectIdsForHistory)) {
            // 1. Buscar mudanças legadas no campo hour_contribution
            $legacyChanges = ProjectChangeLog::whereIn('project_id', $projectIdsForHistory)
                ->where('field_name', 'hour_contribution')
                ->with(['project:id,name,code', 'changedByUser:id,name,email'])
                ->orderBy('created_at', 'desc')
                ->limit(25) // Limitar a 25 mudanças legadas
                ->get()
                ->map(function($log) {
                    return [
                        'id' => 'legacy_' . $log->id,
                        'type' => 'legacy_change',
                        'project_id' => $log->project_id,
                        'project' => [
                            'id' => $log->project->id,
                            'name' => $log->project->name,
                            'code' => $log->project->code,
                        ],
                        'old_value' => $log->old_value ? (int) $log->old_value : null,
                        'new_value' => $log->new_value ? (int) $log->new_value : null,
                        'difference' => $log->new_value && $log->old_value
                            ? (int) $log->new_value - (int) $log->old_value
                            : ($log->new_value ? (int) $log->new_value : 0),
                        'reason' => $log->reason,
                        'changed_by' => $log->changedByUser ? [
                            'id' => $log->changedByUser->id,
                            'name' => $log->changedByUser->name,
                            'email' => $log->changedByUser->email,
                        ] : null,
                        'created_at' => $log->created_at->toIso8601String(),
                    ];
                })->toArray();

            // 2. Buscar novos aportes da tabela hour_contributions
            $newContributions = \App\Models\HourContribution::whereIn('project_id', $projectIdsForHistory)
                ->with(['project:id,name,code', 'contributedBy:id,name,email'])
                ->orderBy('contributed_at', 'desc')
                ->limit(25) // Limitar a 25 novos aportes
                ->get()
                ->map(function($contribution) {
                    return [
                        'id' => 'contribution_' . $contribution->id,
                        'type' => 'new_contribution',
                        'project_id' => $contribution->project_id,
                        'project' => [
                            'id' => $contribution->project->id,
                            'name' => $contribution->project->name,
                            'code' => $contribution->project->code,
                        ],
                        'contributed_hours' => $contribution->contributed_hours,
                        'hourly_rate' => (float) $contribution->hourly_rate,
                        'total_value' => $contribution->getTotalValue(),
                        'description' => $contribution->description,
                        'changed_by' => $contribution->contributedBy ? [
                            'id' => $contribution->contributedBy->id,
                            'name' => $contribution->contributedBy->name,
                            'email' => $contribution->contributedBy->email,
                        ] : null,
                        'created_at' => $contribution->contributed_at->toIso8601String(),
                    ];
                })->toArray();

            // 3. Combinar históricos (legado + novos)
            $contributionHistory = array_merge($legacyChanges, $newContributions);
            
            // 4. Ordenar por data (mais recentes primeiro)
            usort($contributionHistory, function($a, $b) {
                return strtotime($b['created_at']) - strtotime($a['created_at']);
            });
            
            // 5. Limitar a 50 registros totais
            $contributionHistory = array_slice($contributionHistory, 0, 50);
        }

        // ─── Consumo por tipo de serviço (Projeto / Sustentação) ─────────────────
        // Calcula consumed_hours e month_consumed_hours separados por service_type
        // para exibição nos cards das abas Projetos e Sustentação do frontend.

        $serviceTypeProjetoId = ServiceType::where('code', 'projeto')->orWhere('name', 'Projeto')->value('id');
        $serviceTypeManutId   = ServiceType::where('code', 'sustentação')->orWhere('name', 'Sustentação')->value('id');
        $serviceTypeArqId     = ServiceType::where('code', 'arquitetura')->orWhere('name', 'Arquitetura')->value('id');

        $projectsConsumedHours          = 0.0;
        $projectsMonthConsumedHours     = 0.0;
        $maintenanceConsumedHours       = 0.0;
        $maintenanceMonthConsumedHours  = 0.0;
        $architectureConsumedHours      = 0.0;
        $architectureMonthConsumedHours = 0.0;

        foreach ($parentProjects as $parentProject) {
            $processProject = function ($proj, $stId, &$accum, &$accumMonth)
                use ($targetDate, $monthStart, $monthEnd) {
                if ($proj->service_type_id !== $stId) {
                    return;
                }
                $isClosedContract = $proj->contractType &&
                                    strtolower(trim($proj->contractType->name)) === 'fechado';

                // Consumo acumulado
                if ($isClosedContract) {
                    $accum += $proj->getTotalAvailableHours();
                } else {
                    $mins = $proj->timesheets()->where('status', '!=', 'rejected')->sum('effort_minutes') ?? 0;
                    $accum += round($mins / 60, 2);
                    // Histórico pré-importação (alinhado com ProjectController::index gestao mode)
                    $accum += (float) ($proj->initial_hours_consumed ?? 0);
                }

                // Consumo do mês
                if ($isClosedContract) {
                    if ($proj->start_date) {
                        $sd = \Carbon\Carbon::parse($proj->start_date);
                        if ($sd->year === $targetDate->year && $sd->month === $targetDate->month) {
                            $accumMonth += $proj->sold_hours ?? 0;
                        }
                    }
                } else {
                    $mins = $proj->timesheets()
                        ->where('status', '!=', 'rejected')
                        ->whereBetween('date', [$monthStart, $monthEnd])
                        ->sum('effort_minutes') ?? 0;
                    $accumMonth += round($mins / 60, 2);
                }
            };

            $processProject($parentProject, $serviceTypeProjetoId, $projectsConsumedHours,     $projectsMonthConsumedHours);
            $processProject($parentProject, $serviceTypeManutId,   $maintenanceConsumedHours,  $maintenanceMonthConsumedHours);
            if ($serviceTypeArqId) {
                $processProject($parentProject, $serviceTypeArqId, $architectureConsumedHours, $architectureMonthConsumedHours);
            }
            // initial_hours_consumed do pai já é somado dentro de processProject (alinhamento com gestao mode)

            // Filhos: sempre processa para Projeto; para Manutenção/Arquitetura apenas quando pai NÃO for daquele tipo
            if ($parentProject->hasChildProjects()) {
                foreach ($parentProject->childProjects as $childProject) {
                    if ($childProject->isAusterFrozen()) continue;
                    $processProject($childProject, $serviceTypeProjetoId, $projectsConsumedHours, $projectsMonthConsumedHours);
                    if ($parentProject->service_type_id !== $serviceTypeManutId) {
                        $processProject($childProject, $serviceTypeManutId, $maintenanceConsumedHours, $maintenanceMonthConsumedHours);
                    }
                    if ($serviceTypeArqId && $parentProject->service_type_id !== $serviceTypeArqId) {
                        $processProject($childProject, $serviceTypeArqId, $architectureConsumedHours, $architectureMonthConsumedHours);
                    }
                }
            }
        }

        $projectsConsumedHours          = round($projectsConsumedHours, 2);
        $projectsMonthConsumedHours     = round($projectsMonthConsumedHours, 2);
        $maintenanceConsumedHours       = round($maintenanceConsumedHours, 2);
        $maintenanceMonthConsumedHours  = round($maintenanceMonthConsumedHours, 2);
        $architectureConsumedHours      = round($architectureConsumedHours, 2);
        $architectureMonthConsumedHours = round($architectureMonthConsumedHours, 2);
        // ─────────────────────────────────────────────────────────────────────────

        // ─── has_support / has_architecture ──────────────────────────────────────
        // true se algum projeto (ou filho) tem service_type = Sustentação / Arquitetura
        // Usa dados já carregados via eager loading — sem queries extras (N+1)
        $hasSupport      = false;
        $hasArchitecture = false;
        foreach ($parentProjects as $proj) {
            $typeName = strtolower(trim($proj->serviceType->name ?? ''));
            if (str_contains($typeName, 'sustenta'))   $hasSupport      = true;
            if (str_contains($typeName, 'arquitet'))   $hasArchitecture = true;
            foreach ($proj->childProjects ?? [] as $child) {
                $childType = strtolower(trim($child->serviceType->name ?? ''));
                if (str_contains($childType, 'sustenta'))  $hasSupport      = true;
                if (str_contains($childType, 'arquitet'))  $hasArchitecture = true;
            }
            if ($hasSupport && $hasArchitecture) break;
        }
        // ─────────────────────────────────────────────────────────────────────────

        return response()->json([
            'success' => true,
            'message' => 'Dados do dashboard obtidos com sucesso',
            'data' => [
                'contracted_hours' => $contractedHours,
                'contributed_hours' => $contributedHours,
                'consumed_hours' => $consumedHours,
                'month_consumed_hours' => $monthConsumedHours,
                'projects_consumed_hours' => $projectsConsumedHours,
                'projects_month_consumed_hours' => $projectsMonthConsumedHours,
                'maintenance_consumed_hours' => $maintenanceConsumedHours,
                'maintenance_month_consumed_hours' => $maintenanceMonthConsumedHours,
                'architecture_consumed_hours' => $architectureConsumedHours,
                'architecture_month_consumed_hours' => $architectureMonthConsumedHours,
                'hours_balance' => $hoursBalance,
                'exceeded_hours' => $exceededHours,
                'amount_to_pay' => $amountToPay,
                'hourly_rate' => $hourlyRate,  // Valor/hora INICIAL (para card "Valor Hora")
                'weighted_hourly_rate' => $weightedHourlyRate,  // ✨ Média ponderada (usado no cálculo)
                'contributed_hours_history' => $contributionHistory,
                'has_support' => $hasSupport,
                'has_architecture' => $hasArchitecture,
                'customer_id' => $customerId,
                'project_id' => $projectId ? (int) $projectId : null
            ]
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/dashboards/bank-hours-fixed/projects",
     *     summary="Listar projetos para dashboard de Banco de Horas Fixo",
     *     tags={"Dashboards"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="customer_id",
     *         in="query",
     *         description="ID do cliente para filtrar projetos",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="project_id",
     *         in="query",
     *         description="ID do projeto específico para filtrar",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lista de projetos com informações do dashboard",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Projetos obtidos com sucesso"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string", example="Projeto Exemplo"),
     *                     @OA\Property(property="code", type="string", example="PRJ-001"),
     *                     @OA\Property(property="status", type="string", example="started"),
     *                     @OA\Property(property="status_display", type="string", example="Em Andamento"),
     *                     @OA\Property(property="sold_hours", type="integer", nullable=true, example=1200),
     *                     @OA\Property(property="hour_contribution", type="integer", nullable=true, example=100),
     *                     @OA\Property(property="hours_balance", type="number", example=449.5),
     *                     @OA\Property(property="start_date", type="string", nullable=true, example="2025-01-01"),
     *                     @OA\Property(property="parent_project_id", type="integer", nullable=true, example=null)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Não autenticado"),
     *     @OA\Response(response=403, description="Sem permissão")
     * )
     */
    public function bankHoursFixedProjects(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        if (!$user->isAdmin() && !$user->hasAccess('dashboards.view')) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado. Você precisa da permissão "dashboards.view" para acessar este dashboard.',
                'required_permission' => 'dashboards.view'
            ], 403);
        }

        $customerId = null;
        if ($user->customer_id) {
            $customerId = $user->customer_id;
        } elseif ($user->isAdmin() && $request->has('customer_id')) {
            $customerId = $request->get('customer_id');
        }

        $projectId = $request->get('project_id');

        // Usar o service_type_name do request (Projeto ou Sustentação); padrão = Projeto
        $serviceTypeName = $request->get('service_type_name', 'Projeto');
        $projectServiceType = ServiceType::where('name', 'like', "%{$serviceTypeName}%")
            ->first();

        if (!$projectServiceType) {
            // fallback para Projeto
            $projectServiceType = ServiceType::where('code', 'projeto')->orWhere('name', 'Projeto')->first();
        }

        if (!$projectServiceType) {
            return response()->json([
                'success' => false,
                'message' => 'Tipo de serviço não encontrado no sistema'
            ], 404);
        }

        // Se project_id foi passado, buscar o projeto pai (independente do tipo) e seus filhos (se houver)
        if ($projectId) {
            $parentProject = Project::with(['contractType', 'childProjects.contractType'])
                ->find($projectId);

            if (!$parentProject) {
                return response()->json([
                    'success' => false,
                    'message' => 'Projeto não encontrado ou não é do tipo "Projeto"'
                ], 404);
            }

            // Verificar se o usuário tem acesso ao projeto
            if ($user->customer_id && $parentProject->customer_id !== $user->customer_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Acesso negado. Você não tem permissão para visualizar este projeto.'
                ], 403);
            }

            // Coletar projetos para a listagem:
            // - Se o projeto pai for do tipo "Projeto", ele deve aparecer na listagem
            // - Sempre incluir apenas filhos que sejam do tipo "Projeto"
            $projects = collect();

            if ($parentProject->service_type_id === $projectServiceType->id) {
                $projects->push($parentProject);
            }

            if ($parentProject->childProjects && $parentProject->childProjects->count() > 0) {
                $filteredChildren = $parentProject->childProjects->filter(function ($child) use ($projectServiceType) {
                    return $child->service_type_id === $projectServiceType->id;
                });
                $projects = $projects->merge($filteredChildren);
            }
        } else {
            // Buscar projetos com o service_type solicitado, restritos ao contrato BH Fixo.
            // Inclui: (1) projetos BH Fixo diretos, OU (2) filhos de projetos BH Fixo.
            $bhFixedTypeForProjects = \App\Models\ContractType::where('name', 'Banco de Horas Fixo')->first();

            $query = Project::with(['contractType', 'parentProject'])
                ->where('service_type_id', $projectServiceType->id);

            if ($customerId) {
                $query->where('customer_id', $customerId);
            }

            // Aplicar filtro de executivo (admin apenas, quando não há cliente específico)
            if ($user->isAdmin() && !$customerId && $request->filled('executive_id')) {
                $executiveId = (int) $request->get('executive_id');
                $query->whereHas('customer', function ($q) use ($executiveId) {
                    $q->where('executive_id', $executiveId);
                });
            }

            // Restringir ao universo BH Fixo: projetos diretos OU filhos de BH Fixo
            if ($bhFixedTypeForProjects) {
                $query->where(function ($q) use ($bhFixedTypeForProjects) {
                    $q->where('contract_type_id', $bhFixedTypeForProjects->id)
                      ->orWhereHas('parentProject', function ($pq) use ($bhFixedTypeForProjects) {
                          $pq->where('contract_type_id', $bhFixedTypeForProjects->id);
                      });
                });
            }

            $projects = $query->get();
        }

        $projectsData = $projects->map(function($project) {
            $totalAvailable = $project->getTotalAvailableHours();

            $isClosedContract = $project->contractType &&
                                strtolower(trim($project->contractType->name)) === 'fechado';

            if ($isClosedContract) {
                // Fechado: compromete o total vendido do projeto pai — consumo = vendido, saldo = 0
                $consumed        = (float) $totalAvailable;
                $adjustedBalance = 0.0;
            } else {
                // BH Fixo e outros: consumo = apontamentos + horas iniciais já consumidas
                $balance         = round($project->getGeneralHoursBalance(), 2);
                $initialConsumed = (float) ($project->initial_hours_consumed ?? 0);
                $consumed        = round(max(0, $totalAvailable - $balance) + $initialConsumed, 2);
                $adjustedBalance = round($balance - $initialConsumed, 2);
            }
            return [
                'id' => $project->id,
                'name' => $project->name,
                'code' => $project->code,
                'status' => $project->status,
                'contract_type_display' => $project->contract_type_display,
                'status_display' => $project->getStatusDisplayAttribute(),
                'sold_hours' => $project->sold_hours,
                'hour_contribution' => $project->hour_contribution,  // @deprecated - mantido para compatibilidade
                'consumed_hours' => $consumed,
                'hours_balance' => $adjustedBalance,
                'start_date' => $project->start_date ? $project->start_date->format('Y-m-d') : null,
                'parent_project_id' => $project->parent_project_id,
                // ✨ Novos campos calculados usando hour_contributions table
                'total_available_hours' => $totalAvailable,
                'total_contributions_hours' => $project->hourContributions()->sum('contributed_hours') ?? 0,
                'weighted_hourly_rate' => $project->getWeightedAverageHourlyRate(),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'message' => 'Projetos obtidos com sucesso',
            'data' => $projectsData
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/dashboards/bank-hours-fixed/projects/{projectId}/tickets",
     *     summary="Listar apontamentos com tickets de um projeto",
     *     tags={"Dashboards"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="projectId",
     *         in="path",
     *         description="ID do projeto",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="start_date",
     *         in="query",
     *         description="Data inicial do filtro (formato: YYYY-MM-DD)",
     *         required=false,
     *         @OA\Schema(type="string", format="date")
     *     ),
     *     @OA\Parameter(
     *         name="end_date",
     *         in="query",
     *         description="Data final do filtro (formato: YYYY-MM-DD)",
     *         required=false,
     *         @OA\Schema(type="string", format="date")
     *     ),
     *     @OA\Parameter(
     *         name="user_id",
     *         in="query",
     *         description="ID do consultor para filtrar apontamentos",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lista de apontamentos com informações de tickets",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Apontamentos obtidos com sucesso"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="date", type="string", example="2025-01-15"),
     *                     @OA\Property(property="start_time", type="string", example="09:00"),
     *                     @OA\Property(property="end_time", type="string", example="12:00"),
     *                     @OA\Property(property="effort_minutes", type="integer", example=180),
     *                     @OA\Property(property="effort_hours", type="string", example="3:00"),
     *                     @OA\Property(property="observation", type="string", nullable=true),
     *                     @OA\Property(property="ticket", type="string", example="12345"),
     *                     @OA\Property(property="status", type="string", example="approved"),
     *                     @OA\Property(property="status_display", type="string", example="Aprovado"),
     *                     @OA\Property(property="user", type="object"),
     *                     @OA\Property(property="ticket_info", type="object", nullable=true, description="Informações adicionais do ticket do Movidesk")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Não autenticado"),
     *     @OA\Response(response=403, description="Sem permissão"),
     *     @OA\Response(response=404, description="Projeto não encontrado")
     * )
     */
    public function bankHoursFixedProjectTickets(Request $request, int $projectId): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        if (!$user->isAdmin() && !$user->hasAccess('dashboards.view')) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado. Você precisa da permissão "dashboards.view" para acessar este dashboard.',
                'required_permission' => 'dashboards.view'
            ], 403);
        }

        // Verificar se o projeto existe e se o usuário tem acesso
        $project = Project::find($projectId);

        if (!$project) {
            return response()->json([
                'success' => false,
                'message' => 'Projeto não encontrado'
            ], 404);
        }

        // Verificar se o usuário tem acesso ao projeto (se for cliente, só pode ver projetos do seu cliente)
        if ($user->customer_id && $project->customer_id !== $user->customer_id) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado. Você não tem permissão para visualizar apontamentos deste projeto.'
            ], 403);
        }

        // Buscar todos os timesheets do projeto que têm ticket preenchido
        $query = Timesheet::where('project_id', $projectId)
            ->whereNotNull('ticket')
            ->where('ticket', '!=', '');

        // Aplicar filtro de intervalo de datas
        if ($request->has('start_date') && $request->has('end_date')) {
            $startDate = $request->get('start_date');
            $endDate = $request->get('end_date');
            $query->whereBetween('date', [$startDate, $endDate]);
        } elseif ($request->has('start_date')) {
            $startDate = $request->get('start_date');
            $query->where('date', '>=', $startDate);
        } elseif ($request->has('end_date')) {
            $endDate = $request->get('end_date');
            $query->where('date', '<=', $endDate);
        }

        // Aplicar filtro de consultor
        if ($request->has('user_id') && $request->get('user_id') !== null) {
            $userId = $request->get('user_id');
            $query->where('user_id', $userId);
        }

        $timesheets = $query->with(['user:id,name,email', 'reviewedBy:id,name,email'])
            ->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        if ($timesheets->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => 'Nenhum apontamento com ticket encontrado para este projeto',
                'data' => []
            ]);
        }

        // Coletar todos os ticket_ids únicos
        // Extrair tickets únicos (preservando "0" como valor válido)
        $ticketIds = $timesheets->pluck('ticket')
            ->unique()
            ->filter(function($value) {
                // Preservar "0" e outros valores não-nulos/não-vazios
                return $value !== null && $value !== '';
            })
            ->values()
            ->toArray();

        // Buscar informações dos tickets na tabela movidesk_tickets
        $movideskTickets = MovideskTicket::whereIn('ticket_id', $ticketIds)
            ->get()
            ->keyBy('ticket_id');

        // Mapear timesheets com informações do ticket quando disponível
        $timesheetsData = $timesheets->map(function($timesheet) use ($movideskTickets) {
            $ticketInfo = null;

            if ($timesheet->ticket && $movideskTickets->has($timesheet->ticket)) {
                $movideskTicket = $movideskTickets->get($timesheet->ticket);
                $ticketInfo = [
                    'ticket_id' => $movideskTicket->ticket_id,
                    'titulo' => $movideskTicket->titulo,
                    'status' => $movideskTicket->status,
                    'categoria' => $movideskTicket->categoria,
                    'urgencia' => $movideskTicket->urgencia,
                    'nivel' => $movideskTicket->nivel,
                    'servico' => $movideskTicket->servico,
                    'solicitante' => $movideskTicket->solicitante,
                    'responsavel' => $movideskTicket->responsavel,
                    'created_at' => $movideskTicket->created_at->toIso8601String(),
                    'updated_at' => $movideskTicket->updated_at->toIso8601String(),
                ];
            }

            return [
                'id' => $timesheet->id,
                'date' => $timesheet->date->format('Y-m-d'),
                'start_time' => $timesheet->start_time->format('H:i'),
                'end_time' => $timesheet->end_time->format('H:i'),
                'effort_minutes' => $timesheet->effort_minutes,
                'effort_hours' => $timesheet->effort_hours,
                'observation' => $timesheet->observation,
                'ticket' => $timesheet->ticket,
                'status' => $timesheet->status,
                'status_display' => $timesheet->status_display,
                'user' => [
                    'id' => $timesheet->user->id,
                    'name' => $timesheet->user->name,
                    'email' => $timesheet->user->email,
                ],
                'reviewed_by' => $timesheet->reviewedBy ? [
                    'id' => $timesheet->reviewedBy->id,
                    'name' => $timesheet->reviewedBy->name,
                    'email' => $timesheet->reviewedBy->email,
                ] : null,
                'reviewed_at' => $timesheet->reviewed_at ? $timesheet->reviewed_at->toIso8601String() : null,
                'created_at' => $timesheet->created_at->toIso8601String(),
                'ticket_info' => $ticketInfo,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'message' => 'Apontamentos obtidos com sucesso',
            'data' => $timesheetsData
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/dashboards/bank-hours-fixed/maintenance/tickets",
     *     summary="Listar tickets aglutinados de projetos de Sustentação",
     *     tags={"Dashboards"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="customer_id",
     *         in="query",
     *         description="ID do cliente para filtrar projetos",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="project_id",
     *         in="query",
     *         description="ID do projeto específico para filtrar",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lista de tickets aglutinados",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Tickets obtidos com sucesso"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="ticket_id", type="string", example="12345"),
     *                     @OA\Property(property="ticket_info", type="object", nullable=true),
     *                     @OA\Property(property="total_timesheets", type="integer", example=5),
     *                     @OA\Property(property="total_hours", type="number", example=15.5),
     *                     @OA\Property(property="projects", type="array", @OA\Items(type="object"))
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Não autenticado"),
     *     @OA\Response(response=403, description="Sem permissão")
     * )
     */
    public function bankHoursFixedMaintenanceTickets(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        if (!$user->isAdmin() && !$user->hasAccess('dashboards.view')) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado. Você precisa da permissão "dashboards.view" para acessar este dashboard.',
                'required_permission' => 'dashboards.view'
            ], 403);
        }

        // Determinar o cliente a filtrar
        $customerId = null;
        if ($user->customer_id) {
            $customerId = $user->customer_id;
        } elseif ($user->isAdmin() && $request->has('customer_id')) {
            $customerId = $request->get('customer_id');
        }

        // Filtrar por projeto específico se fornecido
        $projectId = $request->get('project_id');

        // Buscar o tipo de serviço "Sustentação"
        $maintenanceServiceType = ServiceType::where('code', 'sustentacao')
            ->orWhere('name', 'Sustentação')
            ->first();

        if (!$maintenanceServiceType) {
            return response()->json([
                'success' => false,
                'message' => 'Tipo de serviço "Sustentação" não encontrado no sistema'
            ], 404);
        }

        // Buscar projetos de Sustentação
        $maintenanceProjects = [];

        if ($projectId) {
            // Se filtrou por projeto específico, buscar o projeto e seus filhos
            // Buscar o projeto independente do tipo de serviço
            $parentProject = Project::with('childProjects')->find($projectId);

            if (!$parentProject) {
                return response()->json([
                    'success' => true,
                    'message' => 'Projeto não encontrado',
                    'data' => []
                ]);
            }

            // Verificar se o usuário tem acesso ao projeto
            if ($user->customer_id && $parentProject->customer_id !== $user->customer_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Acesso negado. Você não tem permissão para visualizar este projeto.'
                ], 403);
            }

            // Coletar projetos de Sustentação: o projeto pai (se for de Sustentação) e seus filhos de Sustentação
            $maintenanceProjects = [];

            // Se o projeto pai for de Sustentação, incluí-lo
            if ($parentProject->service_type_id === $maintenanceServiceType->id) {
                $maintenanceProjects[] = $parentProject->id;
            }

            // Incluir filhos de Sustentação
            if ($parentProject->childProjects && $parentProject->childProjects->count() > 0) {
                $filteredChildren = $parentProject->childProjects->filter(function($child) use ($maintenanceServiceType) {
                    return $child->service_type_id === $maintenanceServiceType->id;
                });
                $maintenanceProjects = array_merge($maintenanceProjects, $filteredChildren->pluck('id')->toArray());
            }
        } else {
            // Buscar todos os projetos de Sustentação
            $projectsQuery = Project::where('service_type_id', $maintenanceServiceType->id);

            if ($customerId) {
                $projectsQuery->where('customer_id', $customerId);
            }

            $maintenanceProjects = $projectsQuery->pluck('id')->toArray();
        }

        if (empty($maintenanceProjects)) {
            return response()->json([
                'success' => true,
                'message' => 'Nenhum projeto de Sustentação encontrado',
                'data' => []
            ]);
        }

        // Buscar todos os timesheets desses projetos que têm ticket
        // Importante: ignorar timesheets com status "rejected"
        $timesheets = Timesheet::whereIn('project_id', $maintenanceProjects)
            ->whereNotNull('ticket')
            ->where('ticket', '!=', '')
            ->where('status', '!=', Timesheet::STATUS_REJECTED)
            ->with(['project:id,name,code', 'user:id,name,email'])
            ->get();

        if ($timesheets->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => 'Nenhum ticket encontrado para projetos de Sustentação',
                'data' => []
            ]);
        }

        // Coletar todos os ticket_ids únicos (garantir que são strings)
        // IMPORTANTE: Usar filter com callback para não remover valores "0" (que são falsy)
        $ticketIds = $timesheets->pluck('ticket')
            ->unique()
            ->filter(function($ticket) {
                return $ticket !== null && $ticket !== ''; // Manter "0" mas remover null e vazio
            })
            ->map(function($ticket) {
                return (string) $ticket; // Garantir que é string para buscar pelo campo ticket_id
            })
            ->values()
            ->toArray();

        // Buscar informações dos tickets na tabela movidesk_tickets
        // IMPORTANTE: Buscar pelo campo ticket_id (não pelo ID primário da tabela)
        $movideskTickets = MovideskTicket::whereIn('ticket_id', $ticketIds)
            ->get()
            ->keyBy('ticket_id');

        // Aglutinar tickets e calcular totais
        $ticketsMap = [];

        foreach ($timesheets as $timesheet) {
            $ticketId = $timesheet->ticket;

            if (!isset($ticketsMap[$ticketId])) {
                $ticketInfo = null;
                if ($movideskTickets->has($ticketId)) {
                    $movideskTicket = $movideskTickets->get($ticketId);
                    $ticketInfo = [
                        'ticket_id' => $movideskTicket->ticket_id,
                        'titulo' => $movideskTicket->titulo,
                        'status' => $movideskTicket->status,
                        'categoria' => $movideskTicket->categoria,
                        'urgencia' => $movideskTicket->urgencia,
                        'nivel' => $movideskTicket->nivel,
                        'servico' => $movideskTicket->servico,
                        'solicitante' => $movideskTicket->solicitante,
                        'responsavel' => $movideskTicket->responsavel,
                        'created_at' => $movideskTicket->created_at ? $movideskTicket->created_at->toIso8601String() : null,
                        'updated_at' => $movideskTicket->updated_at ? $movideskTicket->updated_at->toIso8601String() : null,
                    ];
                }

                $ticketsMap[$ticketId] = [
                    'ticket_id' => $ticketId,
                    'ticket_info' => $ticketInfo,
                    'total_timesheets' => 0,
                    'total_hours' => 0,
                    'total_minutes' => 0,
                    'projects' => []
                ];
            }

            // Incrementar contadores
            $ticketsMap[$ticketId]['total_timesheets']++;
            $ticketsMap[$ticketId]['total_minutes'] += $timesheet->effort_minutes;

            // Adicionar projeto se ainda não estiver na lista
            $projectKey = $timesheet->project_id;
            if (!isset($ticketsMap[$ticketId]['projects'][$projectKey])) {
                $ticketsMap[$ticketId]['projects'][$projectKey] = [
                    'id' => $timesheet->project->id,
                    'name' => $timesheet->project->name,
                    'code' => $timesheet->project->code,
                ];
            }
        }

        // Converter minutos para horas e formatar dados
        $ticketsData = array_map(function($ticket) {
            $ticket['total_hours'] = round($ticket['total_minutes'] / 60, 2);
            $ticket['projects'] = array_values($ticket['projects']);
            unset($ticket['total_minutes']);
            return $ticket;
        }, array_values($ticketsMap));

        // Ordenar por ticket_id
        usort($ticketsData, function($a, $b) {
            return strcmp($a['ticket_id'], $b['ticket_id']);
        });

        return response()->json([
            'success' => true,
            'message' => 'Tickets obtidos com sucesso',
            'data' => $ticketsData
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/dashboards/bank-hours-fixed/maintenance/tickets/{ticketId}/timesheets",
     *     summary="Listar apontamentos de um ticket específico de Sustentação",
     *     tags={"Dashboards"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="ticketId",
     *         in="path",
     *         description="ID do ticket",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="customer_id",
     *         in="query",
     *         description="ID do cliente para filtrar projetos",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="project_id",
     *         in="query",
     *         description="ID do projeto específico para filtrar",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="start_date",
     *         in="query",
     *         description="Data inicial do filtro (formato: YYYY-MM-DD)",
     *         required=false,
     *         @OA\Schema(type="string", format="date")
     *     ),
     *     @OA\Parameter(
     *         name="end_date",
     *         in="query",
     *         description="Data final do filtro (formato: YYYY-MM-DD)",
     *         required=false,
     *         @OA\Schema(type="string", format="date")
     *     ),
     *     @OA\Parameter(
     *         name="user_id",
     *         in="query",
     *         description="ID do consultor para filtrar apontamentos",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lista de apontamentos do ticket",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Apontamentos obtidos com sucesso"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="date", type="string", example="2025-01-15"),
     *                     @OA\Property(property="start_time", type="string", example="09:00"),
     *                     @OA\Property(property="end_time", type="string", example="12:00"),
     *                     @OA\Property(property="effort_hours", type="string", example="3:00"),
     *                     @OA\Property(property="user", type="object"),
     *                     @OA\Property(property="project", type="object"),
     *                     @OA\Property(property="status", type="string", example="approved")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Não autenticado"),
     *     @OA\Response(response=403, description="Sem permissão")
     * )
     */
    public function bankHoursFixedMaintenanceTicketTimesheets(Request $request, string $ticketId): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        if (!$user->isAdmin() && !$user->hasAccess('dashboards.view')) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado. Você precisa da permissão "dashboards.view" para acessar este dashboard.',
                'required_permission' => 'dashboards.view'
            ], 403);
        }

        // Determinar o cliente a filtrar
        $customerId = null;
        if ($user->customer_id) {
            $customerId = $user->customer_id;
        } elseif ($user->isAdmin() && $request->has('customer_id')) {
            $customerId = $request->get('customer_id');
        }

        // Filtrar por projeto específico se fornecido
        $projectId = $request->get('project_id');

        // Buscar o tipo de serviço "Sustentação"
        $maintenanceServiceType = ServiceType::where('code', 'sustentacao')
            ->orWhere('name', 'Sustentação')
            ->first();

        if (!$maintenanceServiceType) {
            return response()->json([
                'success' => false,
                'message' => 'Tipo de serviço "Sustentação" não encontrado no sistema'
            ], 404);
        }

        // Buscar projetos de Sustentação
        $maintenanceProjects = [];

        if ($projectId) {
            // Se filtrou por projeto específico, buscar o projeto e seus filhos
            // Buscar o projeto independente do tipo de serviço
            $parentProject = Project::with('childProjects')->find($projectId);

            if (!$parentProject) {
                return response()->json([
                    'success' => true,
                    'message' => 'Projeto não encontrado',
                    'data' => []
                ]);
            }

            // Verificar se o usuário tem acesso ao projeto
            if ($user->customer_id && $parentProject->customer_id !== $user->customer_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Acesso negado. Você não tem permissão para visualizar este projeto.'
                ], 403);
            }

            // Coletar projetos de Sustentação: o projeto pai (se for de Sustentação) e seus filhos de Sustentação
            $maintenanceProjects = [];

            // Se o projeto pai for de Sustentação, incluí-lo
            if ($parentProject->service_type_id === $maintenanceServiceType->id) {
                $maintenanceProjects[] = $parentProject->id;
            }

            // Incluir filhos de Sustentação
            if ($parentProject->childProjects && $parentProject->childProjects->count() > 0) {
                $filteredChildren = $parentProject->childProjects->filter(function($child) use ($maintenanceServiceType) {
                    return $child->service_type_id === $maintenanceServiceType->id;
                });
                $maintenanceProjects = array_merge($maintenanceProjects, $filteredChildren->pluck('id')->toArray());
            }
        } else {
            // Buscar todos os projetos de Sustentação
            $projectsQuery = Project::where('service_type_id', $maintenanceServiceType->id);

            if ($customerId) {
                $projectsQuery->where('customer_id', $customerId);
            }

            $maintenanceProjects = $projectsQuery->pluck('id')->toArray();
        }

        if (empty($maintenanceProjects)) {
            return response()->json([
                'success' => true,
                'message' => 'Nenhum projeto de Sustentação encontrado',
                'data' => []
            ]);
        }

        // Buscar todos os timesheets do ticket específico em projetos de Sustentação
        // Importante: ignorar timesheets com status "rejected"
        $query = Timesheet::whereIn('project_id', $maintenanceProjects)
            ->where('ticket', $ticketId)
            ->where('status', '!=', Timesheet::STATUS_REJECTED);

        // Aplicar filtro de intervalo de datas
        if ($request->has('start_date') && $request->has('end_date')) {
            $startDate = $request->get('start_date');
            $endDate = $request->get('end_date');
            $query->whereBetween('date', [$startDate, $endDate]);
        } elseif ($request->has('start_date')) {
            $startDate = $request->get('start_date');
            $query->where('date', '>=', $startDate);
        } elseif ($request->has('end_date')) {
            $endDate = $request->get('end_date');
            $query->where('date', '<=', $endDate);
        }

        // Aplicar filtro de consultor
        if ($request->has('user_id') && $request->get('user_id') !== null) {
            $userId = $request->get('user_id');
            $query->where('user_id', $userId);
        }

        $timesheets = $query->with(['project:id,name,code', 'user:id,name,email', 'reviewedBy:id,name,email'])
            ->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        if ($timesheets->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => 'Nenhum apontamento encontrado para este ticket',
                'data' => []
            ]);
        }

        // Buscar informações do ticket
        $movideskTicket = MovideskTicket::where('ticket_id', $ticketId)->first();
        $ticketInfo = null;

        if ($movideskTicket) {
            $ticketInfo = [
                'ticket_id' => $movideskTicket->ticket_id,
                'titulo' => $movideskTicket->titulo,
                'status' => $movideskTicket->status,
                'categoria' => $movideskTicket->categoria,
                'urgencia' => $movideskTicket->urgencia,
                'nivel' => $movideskTicket->nivel,
                'servico' => $movideskTicket->servico,
                'solicitante' => $movideskTicket->solicitante,
                'responsavel' => $movideskTicket->responsavel,
                'created_at' => $movideskTicket->created_at ? $movideskTicket->created_at->toIso8601String() : null,
                'updated_at' => $movideskTicket->updated_at ? $movideskTicket->updated_at->toIso8601String() : null,
            ];
        }

        // Mapear timesheets
        $timesheetsData = $timesheets->map(function($timesheet) {
            return [
                'id' => $timesheet->id,
                'date' => $timesheet->date->format('Y-m-d'),
                'start_time' => $timesheet->start_time->format('H:i'),
                'end_time' => $timesheet->end_time->format('H:i'),
                'effort_minutes' => $timesheet->effort_minutes,
                'effort_hours' => $timesheet->effort_hours,
                'observation' => $timesheet->observation,
                'status' => $timesheet->status,
                'status_display' => $timesheet->status_display,
                'user' => [
                    'id' => $timesheet->user->id,
                    'name' => $timesheet->user->name,
                    'email' => $timesheet->user->email,
                ],
                'project' => [
                    'id' => $timesheet->project->id,
                    'name' => $timesheet->project->name,
                    'code' => $timesheet->project->code,
                ],
                'reviewed_by' => $timesheet->reviewedBy ? [
                    'id' => $timesheet->reviewedBy->id,
                    'name' => $timesheet->reviewedBy->name,
                    'email' => $timesheet->reviewedBy->email,
                ] : null,
                'reviewed_at' => $timesheet->reviewed_at ? $timesheet->reviewed_at->toIso8601String() : null,
                'created_at' => $timesheet->created_at->toIso8601String(),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'message' => 'Apontamentos obtidos com sucesso',
            'data' => [
                'ticket_id' => $ticketId,
                'ticket_info' => $ticketInfo,
                'timesheets' => $timesheetsData
            ]
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/dashboards/bank-hours-fixed/indicators/hours-by-requester",
     *     tags={"Dashboards"},
     *     summary="Horas Utilizadas por Solicitante",
     *     description="Retorna a agregação de horas utilizadas agrupadas por solicitante dos tickets do Movidesk. Apenas apontamentos com status diferente de 'rejected' são considerados.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="customer_id",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="Filtrar por cliente (apenas para administradores)"
     *     ),
     *     @OA\Parameter(
     *         name="project_id",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="Filtrar por projeto pai específico"
     *     ),
     *     @OA\Parameter(
     *         name="month",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer", minimum=1, maximum=12),
     *         description="Filtrar por mês (1-12)"
     *     ),
     *     @OA\Parameter(
     *         name="year",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="Filtrar por ano"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Dados agregados por solicitante",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Dados obtidos com sucesso"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="requester", type="string", example="João Silva"),
     *                     @OA\Property(property="total_hours", type="number", example=45.5, description="Total de horas em formato decimal")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Não autenticado"),
     *     @OA\Response(response=403, description="Sem permissão")
     * )
     */
    public function bankHoursFixedHoursByRequester(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        if (!$user->isAdmin() && !$user->hasAccess('dashboards.view')) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado. Você precisa da permissão "dashboards.view" para acessar este dashboard.',
                'required_permission' => 'dashboards.view'
            ], 403);
        }

        // Determinar o cliente a filtrar
        $customerId = null;
        if ($user->customer_id) {
            $customerId = $user->customer_id;
        } elseif ($user->isAdmin() && $request->has('customer_id')) {
            $customerId = $request->get('customer_id');
        }

        // Filtrar por projeto específico se fornecido
        $projectId = $request->get('project_id');

        // Filtrar por mês e ano se fornecidos
        $month = $request->get('month');
        $year = $request->get('year');

        // Buscar todos os tickets do Movidesk
        $ticketsQuery = MovideskTicket::query();

        // Buscar timesheets com ticket preenchido e status diferente de 'rejected'
        $timesheetsQuery = Timesheet::whereNotNull('ticket')
            ->where('ticket', '!=', '')
            ->where('status', '!=', 'rejected');

        // Aplicar filtro de período (suporta range start_month/start_year → month/year)
        $dateRange = $this->resolveIndicatorDateRange($request);
        if ($dateRange) {
            $timesheetsQuery->whereBetween('date', $dateRange);
        }

        // Aplicar filtro de cliente através dos projetos
        if ($customerId) {
            $timesheetsQuery->whereHas('project', function ($query) use ($customerId) {
                $query->where('customer_id', $customerId);
            });
        }

        // Aplicar filtro de projeto
        if ($projectId) {
            // Se for projeto pai, incluir subprojetos
            $project = Project::find($projectId);
            if ($project) {
                $projectIds = [$projectId];
                // Buscar subprojetos se houver
                $childProjects = Project::where('parent_project_id', $projectId)->pluck('id');
                $projectIds = array_merge($projectIds, $childProjects->toArray());
                $timesheetsQuery->whereIn('project_id', $projectIds);
            } else {
                $timesheetsQuery->where('project_id', $projectId);
            }
        } else {
            // Sem projeto específico: filtrar pelo tipo de contrato BH Fixo
            $bhFixedType = \App\Models\ContractType::where('code', 'fixed_hours')->first();
            if ($bhFixedType) {
                $timesheetsQuery->whereHas('project', function ($q) use ($bhFixedType) {
                    $q->where('contract_type_id', $bhFixedType->id);
                });
            }
        }

        // Buscar todos os timesheets que atendem aos critérios
        $timesheets = $timesheetsQuery->get();

        // Agrupar por solicitante (horas + tickets únicos)
        $requesterHours   = [];
        $requesterTickets = [];

        foreach ($timesheets as $timesheet) {
            $ticket = MovideskTicket::where('ticket_id', $timesheet->ticket)->first();

            if ($ticket && $ticket->solicitante) {
                $solicitante = $ticket->solicitante;
                $requesterName = $solicitante['name'] ?? 'Não informado';
                $hours = $timesheet->effort_minutes / 60;

                $requesterHours[$requesterName] = ($requesterHours[$requesterName] ?? 0) + $hours;
                if (!isset($requesterTickets[$requesterName])) {
                    $requesterTickets[$requesterName] = [];
                }
                $requesterTickets[$requesterName][$timesheet->ticket] = true;
            }
        }

        $data = [];
        foreach ($requesterHours as $requester => $totalHours) {
            $data[] = [
                'requester'    => $requester,
                'total_hours'  => round($totalHours, 2),
                'ticket_count' => count($requesterTickets[$requester] ?? []),
            ];
        }

        usort($data, function ($a, $b) {
            return $b['total_hours'] <=> $a['total_hours'];
        });

        return response()->json([
            'success' => true,
            'message' => 'Dados obtidos com sucesso',
            'data' => $data
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/dashboards/bank-hours-fixed/indicators/requester-timesheets",
     *     tags={"Dashboards"},
     *     summary="Apontamentos por Solicitante",
     *     description="Retorna os apontamentos (timesheets) relacionados a um solicitante específico. Apenas apontamentos com status diferente de 'rejected' são considerados.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="requester",
     *         in="query",
     *         required=true,
     *         @OA\Schema(type="string"),
     *         description="Nome do solicitante"
     *     ),
     *     @OA\Parameter(
     *         name="customer_id",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="Filtrar por cliente (apenas para administradores)"
     *     ),
     *     @OA\Parameter(
     *         name="project_id",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="Filtrar por projeto pai específico"
     *     ),
     *     @OA\Parameter(
     *         name="month",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer", minimum=1, maximum=12),
     *         description="Filtrar por mês (1-12)"
     *     ),
     *     @OA\Parameter(
     *         name="year",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="Filtrar por ano"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lista de apontamentos",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Apontamentos obtidos com sucesso"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer"),
     *                     @OA\Property(property="date", type="string", format="date"),
     *                     @OA\Property(property="effort_hours", type="string"),
     *                     @OA\Property(property="user", type="object"),
     *                     @OA\Property(property="project", type="object"),
     *                     @OA\Property(property="ticket", type="string", nullable=true),
     *                     @OA\Property(property="status", type="string"),
     *                     @OA\Property(property="status_display", type="string")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Não autenticado"),
     *     @OA\Response(response=403, description="Sem permissão")
     * )
     */
    public function bankHoursFixedRequesterTimesheets(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        if (!$user->isAdmin() && !$user->hasAccess('dashboards.view')) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado. Você precisa da permissão "dashboards.view" para acessar este dashboard.',
                'required_permission' => 'dashboards.view'
            ], 403);
        }

        $requester = $request->get('requester');
        if (!$requester) {
            return response()->json([
                'success' => false,
                'message' => 'Parâmetro "requester" é obrigatório'
            ], 400);
        }

        // Determinar o cliente a filtrar
        $customerId = null;
        if ($user->customer_id) {
            $customerId = $user->customer_id;
        } elseif ($user->isAdmin() && $request->has('customer_id')) {
            $customerId = $request->get('customer_id');
        }

        // Filtrar por projeto específico se fornecido
        $projectId = $request->get('project_id');

        // Filtrar por mês e ano se fornecidos
        $month = $request->get('month');
        $year = $request->get('year');

        // Buscar timesheets com ticket preenchido e status diferente de 'rejected'
        $timesheetsQuery = Timesheet::whereNotNull('ticket')
            ->where('ticket', '!=', '')
            ->where('status', '!=', 'rejected')
            ->with(['user', 'project', 'reviewedBy']);

        // Aplicar filtro de período (suporta range start_month/start_year → month/year)
        $dateRange = $this->resolveIndicatorDateRange($request);
        if ($dateRange) {
            $timesheetsQuery->whereBetween('date', $dateRange);
        }

        // Aplicar filtro de cliente através dos projetos
        if ($customerId) {
            $timesheetsQuery->whereHas('project', function ($query) use ($customerId) {
                $query->where('customer_id', $customerId);
            });
        }

        // Aplicar filtro de projeto
        if ($projectId) {
            $project = Project::find($projectId);
            if ($project) {
                $projectIds = [$projectId];
                $childProjects = Project::where('parent_project_id', $projectId)->pluck('id');
                $projectIds = array_merge($projectIds, $childProjects->toArray());
                $timesheetsQuery->whereIn('project_id', $projectIds);
            } else {
                $timesheetsQuery->where('project_id', $projectId);
            }
        } else {
            // Sem projeto específico: filtrar pelo tipo de contrato BH Fixo
            $bhFixedType = \App\Models\ContractType::where('code', 'fixed_hours')->first();
            if ($bhFixedType) {
                $timesheetsQuery->whereHas('project', function ($q) use ($bhFixedType) {
                    $q->where('contract_type_id', $bhFixedType->id);
                });
            }
        }

        // Buscar todos os tickets do Movidesk com o solicitante específico
        // Como solicitante é JSON, precisamos buscar todos e filtrar
        $allTickets = MovideskTicket::all();
        $ticketIds = [];

        foreach ($allTickets as $ticket) {
            if ($ticket->solicitante && isset($ticket->solicitante['name'])) {
                if ($ticket->solicitante['name'] === $requester) {
                    $ticketIds[] = $ticket->ticket_id;
                }
            }
        }

        if (empty($ticketIds)) {
            return response()->json([
                'success' => true,
                'message' => 'Nenhum apontamento encontrado para este solicitante',
                'data' => []
            ]);
        }

        // Filtrar timesheets pelos tickets encontrados
        $timesheetsQuery->whereIn('ticket', $ticketIds);

        // Excluir projetos do tipo "Fechado"
        $timesheetsQuery->whereHas('project', function ($query) {
            $query->whereHas('contractType', function ($q) {
                $q->whereRaw('LOWER(TRIM(name)) != ?', ['fechado']);
            })->orWhereDoesntHave('contractType');
        });

        // Buscar timesheets
        $timesheets = $timesheetsQuery->get();

        // Formatar dados para resposta
        $timesheetsData = $timesheets->map(function($timesheet) {
            return [
                'id' => $timesheet->id,
                'date' => $timesheet->date->format('Y-m-d'),
                'start_time' => $timesheet->start_time->format('H:i'),
                'end_time' => $timesheet->end_time->format('H:i'),
                'effort_minutes' => $timesheet->effort_minutes,
                'effort_hours' => $timesheet->effort_hours,
                'observation' => $timesheet->observation,
                'ticket' => $timesheet->ticket,
                'status' => $timesheet->status,
                'status_display' => $timesheet->status_display,
                'user' => $timesheet->user ? [
                    'id' => $timesheet->user->id,
                    'name' => $timesheet->user->name,
                    'email' => $timesheet->user->email,
                ] : null,
                'project' => $timesheet->project ? [
                    'id' => $timesheet->project->id,
                    'name' => $timesheet->project->name,
                    'code' => $timesheet->project->code,
                ] : null,
                'reviewed_by' => $timesheet->reviewedBy ? [
                    'id' => $timesheet->reviewedBy->id,
                    'name' => $timesheet->reviewedBy->name,
                    'email' => $timesheet->reviewedBy->email,
                ] : null,
                'reviewed_at' => $timesheet->reviewed_at ? $timesheet->reviewed_at->toIso8601String() : null,
                'created_at' => $timesheet->created_at->toIso8601String(),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'message' => 'Apontamentos obtidos com sucesso',
            'data' => $timesheetsData
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/dashboards/bank-hours-fixed/indicators/hours-by-service",
     *     tags={"Dashboards"},
     *     summary="Horas Utilizadas por Módulo/Serviço",
     *     description="Retorna a agregação de horas utilizadas agrupadas por serviço/módulo dos tickets do Movidesk. Apenas apontamentos com status diferente de 'rejected' são considerados.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="customer_id",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="Filtrar por cliente (apenas para administradores)"
     *     ),
     *     @OA\Parameter(
     *         name="project_id",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="Filtrar por projeto pai específico"
     *     ),
     *     @OA\Parameter(
     *         name="month",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer", minimum=1, maximum=12),
     *         description="Filtrar por mês (1-12)"
     *     ),
     *     @OA\Parameter(
     *         name="year",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="Filtrar por ano"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Dados agregados por serviço/módulo",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Dados obtidos com sucesso"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="service", type="string", example="Módulo Financeiro"),
     *                     @OA\Property(property="total_hours", type="number", example=45.5, description="Total de horas em formato decimal")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Não autenticado"),
     *     @OA\Response(response=403, description="Sem permissão")
     * )
     */
    public function bankHoursFixedHoursByService(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        if (!$user->isAdmin() && !$user->hasAccess('dashboards.view')) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado. Você precisa da permissão "dashboards.view" para acessar este dashboard.',
                'required_permission' => 'dashboards.view'
            ], 403);
        }

        // Determinar o cliente a filtrar
        $customerId = null;
        if ($user->customer_id) {
            $customerId = $user->customer_id;
        } elseif ($user->isAdmin() && $request->has('customer_id')) {
            $customerId = $request->get('customer_id');
        }

        // Filtrar por projeto específico se fornecido
        $projectId = $request->get('project_id');

        // Filtrar por mês e ano se fornecidos
        $month = $request->get('month');
        $year = $request->get('year');

        // Buscar timesheets com ticket preenchido e status diferente de 'rejected'
        $timesheetsQuery = Timesheet::whereNotNull('ticket')
            ->where('ticket', '!=', '')
            ->where('status', '!=', 'rejected');

        // Aplicar filtro de período (suporta range start_month/start_year → month/year)
        $dateRange = $this->resolveIndicatorDateRange($request);
        if ($dateRange) {
            $timesheetsQuery->whereBetween('date', $dateRange);
        }

        // Aplicar filtro de cliente através dos projetos
        if ($customerId) {
            $timesheetsQuery->whereHas('project', function ($query) use ($customerId) {
                $query->where('customer_id', $customerId);
            });
        }

        // Aplicar filtro de projeto
        if ($projectId) {
            $project = Project::find($projectId);
            if ($project) {
                $projectIds = [$projectId];
                $childProjects = Project::where('parent_project_id', $projectId)->pluck('id');
                $projectIds = array_merge($projectIds, $childProjects->toArray());
                $timesheetsQuery->whereIn('project_id', $projectIds);
            } else {
                $timesheetsQuery->where('project_id', $projectId);
            }
        } else {
            // Sem projeto específico: filtrar pelo tipo de contrato BH Fixo
            $bhFixedType = \App\Models\ContractType::where('code', 'fixed_hours')->first();
            if ($bhFixedType) {
                $timesheetsQuery->whereHas('project', function ($q) use ($bhFixedType) {
                    $q->where('contract_type_id', $bhFixedType->id);
                });
            }
        }

        // Buscar todos os timesheets que atendem aos critérios
        $timesheets = $timesheetsQuery->get();

        // Agrupar por serviço/módulo (horas + tickets únicos)
        $serviceHours   = [];
        $serviceTickets = [];

        foreach ($timesheets as $timesheet) {
            $ticket = MovideskTicket::where('ticket_id', $timesheet->ticket)->first();

            if ($ticket && $ticket->servico) {
                $service = $ticket->servico;
                $hours = $timesheet->effort_minutes / 60;

                $serviceHours[$service] = ($serviceHours[$service] ?? 0) + $hours;
                if (!isset($serviceTickets[$service])) {
                    $serviceTickets[$service] = [];
                }
                $serviceTickets[$service][$timesheet->ticket] = true;
            }
        }

        $data = [];
        foreach ($serviceHours as $service => $totalHours) {
            $data[] = [
                'service'      => $service,
                'total_hours'  => round($totalHours, 2),
                'ticket_count' => count($serviceTickets[$service] ?? []),
            ];
        }

        usort($data, function ($a, $b) {
            return $b['total_hours'] <=> $a['total_hours'];
        });

        return response()->json([
            'success' => true,
            'message' => 'Dados obtidos com sucesso',
            'data' => $data
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/dashboards/bank-hours-fixed/indicators/service-timesheets",
     *     tags={"Dashboards"},
     *     summary="Apontamentos por Módulo/Serviço",
     *     description="Retorna os apontamentos (timesheets) relacionados a um serviço/módulo específico. Apenas apontamentos com status diferente de 'rejected' são considerados.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="service",
     *         in="query",
     *         required=true,
     *         @OA\Schema(type="string"),
     *         description="Nome do serviço/módulo"
     *     ),
     *     @OA\Parameter(
     *         name="customer_id",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="Filtrar por cliente (apenas para administradores)"
     *     ),
     *     @OA\Parameter(
     *         name="project_id",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="Filtrar por projeto pai específico"
     *     ),
     *     @OA\Parameter(
     *         name="month",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer", minimum=1, maximum=12),
     *         description="Filtrar por mês (1-12)"
     *     ),
     *     @OA\Parameter(
     *         name="year",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="Filtrar por ano"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lista de apontamentos",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Apontamentos obtidos com sucesso"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer"),
     *                     @OA\Property(property="date", type="string", format="date"),
     *                     @OA\Property(property="effort_hours", type="string"),
     *                     @OA\Property(property="user", type="object"),
     *                     @OA\Property(property="project", type="object"),
     *                     @OA\Property(property="ticket", type="string", nullable=true),
     *                     @OA\Property(property="status", type="string"),
     *                     @OA\Property(property="status_display", type="string")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Não autenticado"),
     *     @OA\Response(response=403, description="Sem permissão")
     * )
     */
    public function bankHoursFixedServiceTimesheets(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        if (!$user->isAdmin() && !$user->hasAccess('dashboards.view')) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado. Você precisa da permissão "dashboards.view" para acessar este dashboard.',
                'required_permission' => 'dashboards.view'
            ], 403);
        }

        $service = $request->get('service');
        if (!$service) {
            return response()->json([
                'success' => false,
                'message' => 'Parâmetro "service" é obrigatório'
            ], 400);
        }

        // Determinar o cliente a filtrar
        $customerId = null;
        if ($user->customer_id) {
            $customerId = $user->customer_id;
        } elseif ($user->isAdmin() && $request->has('customer_id')) {
            $customerId = $request->get('customer_id');
        }

        // Filtrar por projeto específico se fornecido
        $projectId = $request->get('project_id');

        // Filtrar por mês e ano se fornecidos
        $month = $request->get('month');
        $year = $request->get('year');

        // Buscar timesheets com ticket preenchido e status diferente de 'rejected'
        $timesheetsQuery = Timesheet::whereNotNull('ticket')
            ->where('ticket', '!=', '')
            ->where('status', '!=', 'rejected')
            ->with(['user', 'project', 'reviewedBy']);

        // Aplicar filtro de período (suporta range start_month/start_year → month/year)
        $dateRange = $this->resolveIndicatorDateRange($request);
        if ($dateRange) {
            $timesheetsQuery->whereBetween('date', $dateRange);
        }

        // Aplicar filtro de cliente através dos projetos
        if ($customerId) {
            $timesheetsQuery->whereHas('project', function ($query) use ($customerId) {
                $query->where('customer_id', $customerId);
            });
        }

        // Aplicar filtro de projeto
        if ($projectId) {
            $project = Project::find($projectId);
            if ($project) {
                $projectIds = [$projectId];
                $childProjects = Project::where('parent_project_id', $projectId)->pluck('id');
                $projectIds = array_merge($projectIds, $childProjects->toArray());
                $timesheetsQuery->whereIn('project_id', $projectIds);
            } else {
                $timesheetsQuery->where('project_id', $projectId);
            }
        } else {
            // Sem projeto específico: filtrar pelo tipo de contrato BH Fixo
            $bhFixedType = \App\Models\ContractType::where('code', 'fixed_hours')->first();
            if ($bhFixedType) {
                $timesheetsQuery->whereHas('project', function ($q) use ($bhFixedType) {
                    $q->where('contract_type_id', $bhFixedType->id);
                });
            }
        }

        // Excluir projetos do tipo "Fechado"
        $timesheetsQuery->whereHas('project', function ($query) {
            $query->whereHas('contractType', function ($q) {
                $q->whereRaw('LOWER(TRIM(name)) != ?', ['fechado']);
            })->orWhereDoesntHave('contractType');
        });

        // Buscar todos os timesheets que atendem aos critérios
        $timesheets = $timesheetsQuery->get();

        // Filtrar timesheets que têm ticket com o serviço específico
        $filteredTimesheets = $timesheets->filter(function ($timesheet) use ($service) {
            $ticket = MovideskTicket::where('ticket_id', $timesheet->ticket)->first();
            if ($ticket && $ticket->servico) {
                return $ticket->servico === $service;
            }
            return false;
        });

        // Formatar dados para resposta
        $timesheetsData = $filteredTimesheets->values()->map(function($timesheet) {
            return [
                'id' => $timesheet->id,
                'date' => $timesheet->date->format('Y-m-d'),
                'start_time' => $timesheet->start_time->format('H:i'),
                'end_time' => $timesheet->end_time->format('H:i'),
                'effort_minutes' => $timesheet->effort_minutes,
                'effort_hours' => $timesheet->effort_hours,
                'observation' => $timesheet->observation,
                'ticket' => $timesheet->ticket,
                'status' => $timesheet->status,
                'status_display' => $timesheet->status_display,
                'user' => $timesheet->user ? [
                    'id' => $timesheet->user->id,
                    'name' => $timesheet->user->name,
                    'email' => $timesheet->user->email,
                ] : null,
                'project' => $timesheet->project ? [
                    'id' => $timesheet->project->id,
                    'name' => $timesheet->project->name,
                    'code' => $timesheet->project->code,
                ] : null,
                'reviewed_by' => $timesheet->reviewedBy ? [
                    'id' => $timesheet->reviewedBy->id,
                    'name' => $timesheet->reviewedBy->name,
                    'email' => $timesheet->reviewedBy->email,
                ] : null,
                'reviewed_at' => $timesheet->reviewed_at ? $timesheet->reviewed_at->toIso8601String() : null,
                'created_at' => $timesheet->created_at->toIso8601String(),
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Apontamentos obtidos com sucesso',
            'data' => $timesheetsData
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/dashboards/bank-hours-fixed/indicators/tickets-by-status",
     *     tags={"Dashboards"},
     *     summary="Quantidade de Tickets por Status",
     *     description="Retorna a quantidade de tickets agrupados por status. Considera apenas tickets que possuem apontamentos de horas.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="customer_id",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="Filtrar por cliente (apenas para administradores)"
     *     ),
     *     @OA\Parameter(
     *         name="project_id",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="Filtrar por projeto pai específico"
     *     ),
     *     @OA\Parameter(
     *         name="month",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer", minimum=1, maximum=12),
     *         description="Filtrar por mês (1-12)"
     *     ),
     *     @OA\Parameter(
     *         name="year",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="Filtrar por ano"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Dados agregados por status",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Dados obtidos com sucesso"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="status", type="string", example="Em Andamento"),
     *                     @OA\Property(property="ticket_count", type="integer", example=15)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Não autenticado"),
     *     @OA\Response(response=403, description="Sem permissão")
     * )
     */
    public function bankHoursFixedTicketsByStatus(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        if (!$user->isAdmin() && !$user->hasAccess('dashboards.view')) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado. Você precisa da permissão "dashboards.view" para acessar este dashboard.',
                'required_permission' => 'dashboards.view'
            ], 403);
        }

        // Determinar o cliente a filtrar
        $customerId = null;
        if ($user->customer_id) {
            $customerId = $user->customer_id;
        } elseif ($user->isAdmin() && $request->has('customer_id')) {
            $customerId = $request->get('customer_id');
        }

        // Filtrar por projeto específico se fornecido
        $projectId = $request->get('project_id');

        // Filtrar por mês e ano se fornecidos
        $month = $request->get('month');
        $year = $request->get('year');

        // Buscar timesheets com ticket preenchido e status diferente de 'rejected'
        $timesheetsQuery = Timesheet::whereNotNull('ticket')
            ->where('ticket', '!=', '')
            ->where('status', '!=', 'rejected');

        // Aplicar filtro de período (suporta range start_month/start_year → month/year)
        $dateRange = $this->resolveIndicatorDateRange($request);
        if ($dateRange) {
            $timesheetsQuery->whereBetween('date', $dateRange);
        }

        // Aplicar filtro de cliente através dos projetos
        if ($customerId) {
            $timesheetsQuery->whereHas('project', function ($query) use ($customerId) {
                $query->where('customer_id', $customerId);
            });
        }

        // Aplicar filtro de projeto
        if ($projectId) {
            $project = Project::find($projectId);
            if ($project) {
                $projectIds = [$projectId];
                $childProjects = Project::where('parent_project_id', $projectId)->pluck('id');
                $projectIds = array_merge($projectIds, $childProjects->toArray());
                $timesheetsQuery->whereIn('project_id', $projectIds);
            } else {
                $timesheetsQuery->where('project_id', $projectId);
            }
        } else {
            // Sem projeto específico: filtrar pelo tipo de contrato BH Fixo
            $bhFixedType = \App\Models\ContractType::where('code', 'fixed_hours')->first();
            if ($bhFixedType) {
                $timesheetsQuery->whereHas('project', function ($q) use ($bhFixedType) {
                    $q->where('contract_type_id', $bhFixedType->id);
                });
            }
        }

        // Buscar todos os timesheets que atendem aos critérios
        $timesheets = $timesheetsQuery->get();

        // Extrair tickets únicos (preservando "0" como valor válido)
        $ticketIds = $timesheets->pluck('ticket')
            ->unique()
            ->filter(function($value) {
                // Preservar "0" e outros valores não-nulos/não-vazios
                return $value !== null && $value !== '';
            })
            ->values();

        if ($ticketIds->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => 'Dados obtidos com sucesso',
                'data' => []
            ]);
        }

        // Buscar tickets do Movidesk (incluindo ticket_id "0")
        $tickets = MovideskTicket::whereIn('ticket_id', $ticketIds->toArray())->get();

        // Agrupar por status
        $statusCounts = [];
        foreach ($tickets as $ticket) {
            $status = $ticket->status ?? 'Sem Status';

            if (!isset($statusCounts[$status])) {
                $statusCounts[$status] = 0;
            }
            $statusCounts[$status]++;
        }

        // Converter para formato de resposta
        $data = [];
        foreach ($statusCounts as $status => $count) {
            $data[] = [
                'status' => $status,
                'ticket_count' => $count
            ];
        }

        // Ordenar por quantidade de tickets (decrescente)
        usort($data, function ($a, $b) {
            return $b['ticket_count'] <=> $a['ticket_count'];
        });

        return response()->json([
            'success' => true,
            'message' => 'Dados obtidos com sucesso',
            'data' => $data
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/dashboards/bank-hours-fixed/indicators/status-timesheets",
     *     tags={"Dashboards"},
     *     summary="Apontamentos por Status de Ticket",
     *     description="Retorna os apontamentos (timesheets) relacionados a tickets com um status específico. Apenas apontamentos com status diferente de 'rejected' são considerados.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         required=true,
     *         @OA\Schema(type="string"),
     *         description="Status do ticket"
     *     ),
     *     @OA\Parameter(
     *         name="customer_id",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="Filtrar por cliente (apenas para administradores)"
     *     ),
     *     @OA\Parameter(
     *         name="project_id",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="Filtrar por projeto pai específico"
     *     ),
     *     @OA\Parameter(
     *         name="month",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer", minimum=1, maximum=12),
     *         description="Filtrar por mês (1-12)"
     *     ),
     *     @OA\Parameter(
     *         name="year",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="Filtrar por ano"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lista de apontamentos",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Apontamentos obtidos com sucesso"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer"),
     *                     @OA\Property(property="date", type="string", format="date"),
     *                     @OA\Property(property="effort_hours", type="string"),
     *                     @OA\Property(property="user", type="object"),
     *                     @OA\Property(property="project", type="object"),
     *                     @OA\Property(property="ticket", type="string", nullable=true),
     *                     @OA\Property(property="status", type="string"),
     *                     @OA\Property(property="status_display", type="string")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Não autenticado"),
     *     @OA\Response(response=403, description="Sem permissão")
     * )
     */
    public function bankHoursFixedStatusTimesheets(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        if (!$user->isAdmin() && !$user->hasAccess('dashboards.view')) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado. Você precisa da permissão "dashboards.view" para acessar este dashboard.',
                'required_permission' => 'dashboards.view'
            ], 403);
        }

        $status = $request->get('status');
        if (!$status) {
            return response()->json([
                'success' => false,
                'message' => 'Parâmetro "status" é obrigatório'
            ], 400);
        }

        // Determinar o cliente a filtrar
        $customerId = null;
        if ($user->customer_id) {
            $customerId = $user->customer_id;
        } elseif ($user->isAdmin() && $request->has('customer_id')) {
            $customerId = $request->get('customer_id');
        }

        // Filtrar por projeto específico se fornecido
        $projectId = $request->get('project_id');

        // Filtrar por mês e ano se fornecidos
        $month = $request->get('month');
        $year = $request->get('year');

        // Buscar timesheets com ticket preenchido e status diferente de 'rejected'
        $timesheetsQuery = Timesheet::whereNotNull('ticket')
            ->where('ticket', '!=', '')
            ->where('status', '!=', 'rejected')
            ->with(['user', 'project', 'reviewedBy']);

        // Aplicar filtro de período (suporta range start_month/start_year → month/year)
        $dateRange = $this->resolveIndicatorDateRange($request);
        if ($dateRange) {
            $timesheetsQuery->whereBetween('date', $dateRange);
        }

        // Aplicar filtro de cliente através dos projetos
        if ($customerId) {
            $timesheetsQuery->whereHas('project', function ($query) use ($customerId) {
                $query->where('customer_id', $customerId);
            });
        }

        // Aplicar filtro de projeto
        if ($projectId) {
            $project = Project::find($projectId);
            if ($project) {
                $projectIds = [$projectId];
                $childProjects = Project::where('parent_project_id', $projectId)->pluck('id');
                $projectIds = array_merge($projectIds, $childProjects->toArray());
                $timesheetsQuery->whereIn('project_id', $projectIds);
            } else {
                $timesheetsQuery->where('project_id', $projectId);
            }
        } else {
            // Sem projeto específico: filtrar pelo tipo de contrato BH Fixo
            $bhFixedType = \App\Models\ContractType::where('code', 'fixed_hours')->first();
            if ($bhFixedType) {
                $timesheetsQuery->whereHas('project', function ($q) use ($bhFixedType) {
                    $q->where('contract_type_id', $bhFixedType->id);
                });
            }
        }

        // Buscar tickets do Movidesk com o status específico
        $tickets = MovideskTicket::where('status', $status)->get();
        $ticketIds = $tickets->pluck('ticket_id')->toArray();

        if (empty($ticketIds)) {
            return response()->json([
                'success' => true,
                'message' => 'Nenhum apontamento encontrado para este status',
                'data' => []
            ]);
        }

        // Filtrar timesheets pelos tickets encontrados
        $timesheetsQuery->whereIn('ticket', $ticketIds);

        // Excluir projetos do tipo "Fechado"
        $timesheetsQuery->whereHas('project', function ($query) {
            $query->whereHas('contractType', function ($q) {
                $q->whereRaw('LOWER(TRIM(name)) != ?', ['fechado']);
            })->orWhereDoesntHave('contractType');
        });

        // Buscar timesheets
        $timesheets = $timesheetsQuery->get();

        // Formatar dados para resposta
        $timesheetsData = $timesheets->map(function($timesheet) {
            return [
                'id' => $timesheet->id,
                'date' => $timesheet->date->format('Y-m-d'),
                'start_time' => $timesheet->start_time->format('H:i'),
                'end_time' => $timesheet->end_time->format('H:i'),
                'effort_minutes' => $timesheet->effort_minutes,
                'effort_hours' => $timesheet->effort_hours,
                'observation' => $timesheet->observation,
                'ticket' => $timesheet->ticket,
                'status' => $timesheet->status,
                'status_display' => $timesheet->status_display,
                'user' => $timesheet->user ? [
                    'id' => $timesheet->user->id,
                    'name' => $timesheet->user->name,
                    'email' => $timesheet->user->email,
                ] : null,
                'project' => $timesheet->project ? [
                    'id' => $timesheet->project->id,
                    'name' => $timesheet->project->name,
                    'code' => $timesheet->project->code,
                ] : null,
                'reviewed_by' => $timesheet->reviewedBy ? [
                    'id' => $timesheet->reviewedBy->id,
                    'name' => $timesheet->reviewedBy->name,
                    'email' => $timesheet->reviewedBy->email,
                ] : null,
                'reviewed_at' => $timesheet->reviewed_at ? $timesheet->reviewed_at->toIso8601String() : null,
                'created_at' => $timesheet->created_at->toIso8601String(),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'message' => 'Apontamentos obtidos com sucesso',
            'data' => $timesheetsData
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/dashboards/bank-hours-fixed/indicators/tickets-by-level",
     *     tags={"Dashboards"},
     *     summary="Quantidade de Tickets por Nível de Atendimento",
     *     description="Retorna a quantidade e porcentagem de tickets agrupados por nível de atendimento. Considera apenas tickets que possuem apontamentos de horas.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="customer_id",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="Filtrar por cliente (apenas para administradores)"
     *     ),
     *     @OA\Parameter(
     *         name="project_id",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="Filtrar por projeto pai específico"
     *     ),
     *     @OA\Parameter(
     *         name="month",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer", minimum=1, maximum=12),
     *         description="Filtrar por mês (1-12)"
     *     ),
     *     @OA\Parameter(
     *         name="year",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="Filtrar por ano"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Dados agregados por nível",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Dados obtidos com sucesso"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="level", type="string", example="N1"),
     *                     @OA\Property(property="ticket_count", type="integer", example=15),
     *                     @OA\Property(property="percentage", type="number", example=25.5)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Não autenticado"),
     *     @OA\Response(response=403, description="Sem permissão")
     * )
     */
    public function bankHoursFixedTicketsByLevel(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        if (!$user->isAdmin() && !$user->hasAccess('dashboards.view')) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado. Você precisa da permissão "dashboards.view" para acessar este dashboard.',
                'required_permission' => 'dashboards.view'
            ], 403);
        }

        // Determinar o cliente a filtrar
        $customerId = null;
        if ($user->customer_id) {
            $customerId = $user->customer_id;
        } elseif ($user->isAdmin() && $request->has('customer_id')) {
            $customerId = $request->get('customer_id');
        }

        // Filtrar por projeto específico se fornecido
        $projectId = $request->get('project_id');

        // Filtrar por mês e ano se fornecidos
        $month = $request->get('month');
        $year = $request->get('year');

        // Buscar timesheets com ticket preenchido e status diferente de 'rejected'
        $timesheetsQuery = Timesheet::whereNotNull('ticket')
            ->where('ticket', '!=', '')
            ->where('status', '!=', 'rejected');

        // Aplicar filtro de período (suporta range start_month/start_year → month/year)
        $dateRange = $this->resolveIndicatorDateRange($request);
        if ($dateRange) {
            $timesheetsQuery->whereBetween('date', $dateRange);
        }

        // Aplicar filtro de cliente através dos projetos
        if ($customerId) {
            $timesheetsQuery->whereHas('project', function ($query) use ($customerId) {
                $query->where('customer_id', $customerId);
            });
        }

        // Aplicar filtro de projeto
        if ($projectId) {
            $project = Project::find($projectId);
            if ($project) {
                $projectIds = [$projectId];
                $childProjects = Project::where('parent_project_id', $projectId)->pluck('id');
                $projectIds = array_merge($projectIds, $childProjects->toArray());
                $timesheetsQuery->whereIn('project_id', $projectIds);
            } else {
                $timesheetsQuery->where('project_id', $projectId);
            }
        } else {
            // Sem projeto específico: filtrar pelo tipo de contrato BH Fixo
            $bhFixedType = \App\Models\ContractType::where('code', 'fixed_hours')->first();
            if ($bhFixedType) {
                $timesheetsQuery->whereHas('project', function ($q) use ($bhFixedType) {
                    $q->where('contract_type_id', $bhFixedType->id);
                });
            }
        }

        // Buscar todos os timesheets que atendem aos critérios
        $timesheets = $timesheetsQuery->get();

        // Extrair tickets únicos (preservando "0" como valor válido)
        $ticketIds = $timesheets->pluck('ticket')
            ->unique()
            ->filter(function($value) {
                // Preservar "0" e outros valores não-nulos/não-vazios
                return $value !== null && $value !== '';
            })
            ->values();

        if ($ticketIds->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => 'Dados obtidos com sucesso',
                'data' => []
            ]);
        }

        // Buscar tickets do Movidesk (incluindo ticket_id "0")
        $tickets = MovideskTicket::whereIn('ticket_id', $ticketIds->toArray())->get();

        // Agrupar por nível
        $levelCounts = [];
        foreach ($tickets as $ticket) {
            $level = $ticket->nivel ?? 'Sem Nível';

            if (!isset($levelCounts[$level])) {
                $levelCounts[$level] = 0;
            }
            $levelCounts[$level]++;
        }

        // Calcular total e porcentagens
        $total = array_sum($levelCounts);

        // Converter para formato de resposta
        $data = [];
        foreach ($levelCounts as $level => $count) {
            $percentage = $total > 0 ? round(($count / $total) * 100, 2) : 0;
            $data[] = [
                'level' => $level,
                'ticket_count' => $count,
                'percentage' => $percentage
            ];
        }

        // Ordenar por quantidade de tickets (decrescente)
        usort($data, function ($a, $b) {
            return $b['ticket_count'] <=> $a['ticket_count'];
        });

        return response()->json([
            'success' => true,
            'message' => 'Dados obtidos com sucesso',
            'data' => $data
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/dashboards/bank-hours-fixed/indicators/level-timesheets",
     *     tags={"Dashboards"},
     *     summary="Apontamentos por Nível de Atendimento",
     *     description="Retorna os apontamentos (timesheets) relacionados a tickets com um nível de atendimento específico. Apenas apontamentos com status diferente de 'rejected' são considerados.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="level",
     *         in="query",
     *         required=true,
     *         @OA\Schema(type="string"),
     *         description="Nível de atendimento"
     *     ),
     *     @OA\Parameter(
     *         name="customer_id",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="Filtrar por cliente (apenas para administradores)"
     *     ),
     *     @OA\Parameter(
     *         name="project_id",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="Filtrar por projeto pai específico"
     *     ),
     *     @OA\Parameter(
     *         name="month",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer", minimum=1, maximum=12),
     *         description="Filtrar por mês (1-12)"
     *     ),
     *     @OA\Parameter(
     *         name="year",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="Filtrar por ano"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lista de apontamentos",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Apontamentos obtidos com sucesso"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer"),
     *                     @OA\Property(property="date", type="string", format="date"),
     *                     @OA\Property(property="effort_hours", type="string"),
     *                     @OA\Property(property="user", type="object"),
     *                     @OA\Property(property="project", type="object"),
     *                     @OA\Property(property="ticket", type="string", nullable=true),
     *                     @OA\Property(property="status", type="string"),
     *                     @OA\Property(property="status_display", type="string")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Não autenticado"),
     *     @OA\Response(response=403, description="Sem permissão")
     * )
     */
    public function bankHoursFixedLevelTimesheets(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        if (!$user->isAdmin() && !$user->hasAccess('dashboards.view')) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado. Você precisa da permissão "dashboards.view" para acessar este dashboard.',
                'required_permission' => 'dashboards.view'
            ], 403);
        }

        $level = $request->get('level');
        if (!$level) {
            return response()->json([
                'success' => false,
                'message' => 'Parâmetro "level" é obrigatório'
            ], 400);
        }

        // Determinar o cliente a filtrar
        $customerId = null;
        if ($user->customer_id) {
            $customerId = $user->customer_id;
        } elseif ($user->isAdmin() && $request->has('customer_id')) {
            $customerId = $request->get('customer_id');
        }

        // Filtrar por projeto específico se fornecido
        $projectId = $request->get('project_id');

        // Filtrar por mês e ano se fornecidos
        $month = $request->get('month');
        $year = $request->get('year');

        // Buscar timesheets com ticket preenchido e status diferente de 'rejected'
        $timesheetsQuery = Timesheet::whereNotNull('ticket')
            ->where('ticket', '!=', '')
            ->where('status', '!=', 'rejected')
            ->with(['user', 'project', 'reviewedBy']);

        // Aplicar filtro de período (suporta range start_month/start_year → month/year)
        $dateRange = $this->resolveIndicatorDateRange($request);
        if ($dateRange) {
            $timesheetsQuery->whereBetween('date', $dateRange);
        }

        // Aplicar filtro de cliente através dos projetos
        if ($customerId) {
            $timesheetsQuery->whereHas('project', function ($query) use ($customerId) {
                $query->where('customer_id', $customerId);
            });
        }

        // Aplicar filtro de projeto
        if ($projectId) {
            $project = Project::find($projectId);
            if ($project) {
                $projectIds = [$projectId];
                $childProjects = Project::where('parent_project_id', $projectId)->pluck('id');
                $projectIds = array_merge($projectIds, $childProjects->toArray());
                $timesheetsQuery->whereIn('project_id', $projectIds);
            } else {
                $timesheetsQuery->where('project_id', $projectId);
            }
        } else {
            // Sem projeto específico: filtrar pelo tipo de contrato BH Fixo
            $bhFixedType = \App\Models\ContractType::where('code', 'fixed_hours')->first();
            if ($bhFixedType) {
                $timesheetsQuery->whereHas('project', function ($q) use ($bhFixedType) {
                    $q->where('contract_type_id', $bhFixedType->id);
                });
            }
        }

        // Buscar tickets do Movidesk com o nível específico
        $tickets = MovideskTicket::where('nivel', $level)->get();
        $ticketIds = $tickets->pluck('ticket_id')->toArray();

        if (empty($ticketIds)) {
            return response()->json([
                'success' => true,
                'message' => 'Nenhum apontamento encontrado para este nível',
                'data' => []
            ]);
        }

        // Filtrar timesheets pelos tickets encontrados
        $timesheetsQuery->whereIn('ticket', $ticketIds);

        // Excluir projetos do tipo "Fechado"
        $timesheetsQuery->whereHas('project', function ($query) {
            $query->whereHas('contractType', function ($q) {
                $q->whereRaw('LOWER(TRIM(name)) != ?', ['fechado']);
            })->orWhereDoesntHave('contractType');
        });

        // Buscar timesheets
        $timesheets = $timesheetsQuery->get();

        // Formatar dados para resposta
        $timesheetsData = $timesheets->map(function($timesheet) {
            return [
                'id' => $timesheet->id,
                'date' => $timesheet->date->format('Y-m-d'),
                'start_time' => $timesheet->start_time->format('H:i'),
                'end_time' => $timesheet->end_time->format('H:i'),
                'effort_minutes' => $timesheet->effort_minutes,
                'effort_hours' => $timesheet->effort_hours,
                'observation' => $timesheet->observation,
                'ticket' => $timesheet->ticket,
                'status' => $timesheet->status,
                'status_display' => $timesheet->status_display,
                'user' => $timesheet->user ? [
                    'id' => $timesheet->user->id,
                    'name' => $timesheet->user->name,
                    'email' => $timesheet->user->email,
                ] : null,
                'project' => $timesheet->project ? [
                    'id' => $timesheet->project->id,
                    'name' => $timesheet->project->name,
                    'code' => $timesheet->project->code,
                ] : null,
                'reviewed_by' => $timesheet->reviewedBy ? [
                    'id' => $timesheet->reviewedBy->id,
                    'name' => $timesheet->reviewedBy->name,
                    'email' => $timesheet->reviewedBy->email,
                ] : null,
                'reviewed_at' => $timesheet->reviewed_at ? $timesheet->reviewed_at->toIso8601String() : null,
                'created_at' => $timesheet->created_at->toIso8601String(),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'message' => 'Apontamentos obtidos com sucesso',
            'data' => $timesheetsData
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/dashboards/bank-hours-fixed/indicators/tickets-by-category",
     *     tags={"Dashboards"},
     *     summary="Quantidade de Tickets por Categoria (Motivo de Abertura)",
     *     description="Retorna a quantidade de tickets agrupados por categoria. Considera apenas tickets que possuem apontamentos de horas.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="customer_id",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="Filtrar por cliente (apenas para administradores)"
     *     ),
     *     @OA\Parameter(
     *         name="project_id",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="Filtrar por projeto pai específico"
     *     ),
     *     @OA\Parameter(
     *         name="month",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer", minimum=1, maximum=12),
     *         description="Filtrar por mês (1-12)"
     *     ),
     *     @OA\Parameter(
     *         name="year",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="Filtrar por ano"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Dados agregados por categoria",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Dados obtidos com sucesso"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="category", type="string", example="Desenvolvimento"),
     *                     @OA\Property(property="ticket_count", type="integer", example=15)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Não autenticado"),
     *     @OA\Response(response=403, description="Sem permissão")
     * )
     */
    public function bankHoursFixedTicketsByCategory(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        if (!$user->isAdmin() && !$user->hasAccess('dashboards.view')) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado. Você precisa da permissão "dashboards.view" para acessar este dashboard.',
                'required_permission' => 'dashboards.view'
            ], 403);
        }

        // Determinar o cliente a filtrar
        $customerId = null;
        if ($user->customer_id) {
            $customerId = $user->customer_id;
        } elseif ($user->isAdmin() && $request->has('customer_id')) {
            $customerId = $request->get('customer_id');
        }

        // Filtrar por projeto específico se fornecido
        $projectId = $request->get('project_id');

        // Filtrar por mês e ano se fornecidos
        $month = $request->get('month');
        $year = $request->get('year');

        // Buscar timesheets com ticket preenchido e status diferente de 'rejected'
        $timesheetsQuery = Timesheet::whereNotNull('ticket')
            ->where('ticket', '!=', '')
            ->where('status', '!=', 'rejected');

        // Aplicar filtro de período (suporta range start_month/start_year → month/year)
        $dateRange = $this->resolveIndicatorDateRange($request);
        if ($dateRange) {
            $timesheetsQuery->whereBetween('date', $dateRange);
        }

        // Aplicar filtro de cliente através dos projetos
        if ($customerId) {
            $timesheetsQuery->whereHas('project', function ($query) use ($customerId) {
                $query->where('customer_id', $customerId);
            });
        }

        // Aplicar filtro de projeto
        if ($projectId) {
            $project = Project::find($projectId);
            if ($project) {
                $projectIds = [$projectId];
                $childProjects = Project::where('parent_project_id', $projectId)->pluck('id');
                $projectIds = array_merge($projectIds, $childProjects->toArray());
                $timesheetsQuery->whereIn('project_id', $projectIds);
            } else {
                $timesheetsQuery->where('project_id', $projectId);
            }
        } else {
            // Sem projeto específico: filtrar pelo tipo de contrato BH Fixo
            $bhFixedType = \App\Models\ContractType::where('code', 'fixed_hours')->first();
            if ($bhFixedType) {
                $timesheetsQuery->whereHas('project', function ($q) use ($bhFixedType) {
                    $q->where('contract_type_id', $bhFixedType->id);
                });
            }
        }

        // Buscar todos os timesheets que atendem aos critérios
        $timesheets = $timesheetsQuery->get();

        // Extrair tickets únicos (preservando "0" como valor válido)
        $ticketIds = $timesheets->pluck('ticket')
            ->unique()
            ->filter(function($value) {
                // Preservar "0" e outros valores não-nulos/não-vazios
                return $value !== null && $value !== '';
            })
            ->values();

        if ($ticketIds->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => 'Dados obtidos com sucesso',
                'data' => []
            ]);
        }

        // Buscar tickets do Movidesk (incluindo ticket_id "0")
        $tickets = MovideskTicket::whereIn('ticket_id', $ticketIds->toArray())->get();

        // Agrupar por categoria
        $categoryCounts = [];
        foreach ($tickets as $ticket) {
            $category = $ticket->categoria ?? 'Sem Categoria';

            if (!isset($categoryCounts[$category])) {
                $categoryCounts[$category] = 0;
            }
            $categoryCounts[$category]++;
        }

        // Converter para formato de resposta
        $data = [];
        foreach ($categoryCounts as $category => $count) {
            $data[] = [
                'category' => $category,
                'ticket_count' => $count
            ];
        }

        // Ordenar por quantidade de tickets (decrescente)
        usort($data, function ($a, $b) {
            return $b['ticket_count'] <=> $a['ticket_count'];
        });

        return response()->json([
            'success' => true,
            'message' => 'Dados obtidos com sucesso',
            'data' => $data
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/dashboards/bank-hours-fixed/indicators/category-timesheets",
     *     tags={"Dashboards"},
     *     summary="Apontamentos por Categoria de Ticket",
     *     description="Retorna os apontamentos (timesheets) relacionados a tickets com uma categoria específica. Apenas apontamentos com status diferente de 'rejected' são considerados.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="category",
     *         in="query",
     *         required=true,
     *         @OA\Schema(type="string"),
     *         description="Categoria do ticket"
     *     ),
     *     @OA\Parameter(
     *         name="customer_id",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="Filtrar por cliente (apenas para administradores)"
     *     ),
     *     @OA\Parameter(
     *         name="project_id",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="Filtrar por projeto pai específico"
     *     ),
     *     @OA\Parameter(
     *         name="month",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer", minimum=1, maximum=12),
     *         description="Filtrar por mês (1-12)"
     *     ),
     *     @OA\Parameter(
     *         name="year",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="Filtrar por ano"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lista de apontamentos",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Apontamentos obtidos com sucesso"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer"),
     *                     @OA\Property(property="date", type="string", format="date"),
     *                     @OA\Property(property="effort_hours", type="string"),
     *                     @OA\Property(property="user", type="object"),
     *                     @OA\Property(property="project", type="object"),
     *                     @OA\Property(property="ticket", type="string", nullable=true),
     *                     @OA\Property(property="status", type="string"),
     *                     @OA\Property(property="status_display", type="string")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Não autenticado"),
     *     @OA\Response(response=403, description="Sem permissão")
     * )
     */
    public function bankHoursFixedCategoryTimesheets(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        if (!$user->isAdmin() && !$user->hasAccess('dashboards.view')) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado. Você precisa da permissão "dashboards.view" para acessar este dashboard.',
                'required_permission' => 'dashboards.view'
            ], 403);
        }

        $category = $request->get('category');
        if (!$category) {
            return response()->json([
                'success' => false,
                'message' => 'Parâmetro "category" é obrigatório'
            ], 400);
        }

        // Determinar o cliente a filtrar
        $customerId = null;
        if ($user->customer_id) {
            $customerId = $user->customer_id;
        } elseif ($user->isAdmin() && $request->has('customer_id')) {
            $customerId = $request->get('customer_id');
        }

        // Filtrar por projeto específico se fornecido
        $projectId = $request->get('project_id');

        // Filtrar por mês e ano se fornecidos
        $month = $request->get('month');
        $year = $request->get('year');

        // Buscar timesheets com ticket preenchido e status diferente de 'rejected'
        $timesheetsQuery = Timesheet::whereNotNull('ticket')
            ->where('ticket', '!=', '')
            ->where('status', '!=', 'rejected')
            ->with(['user', 'project', 'reviewedBy']);

        // Aplicar filtro de período (suporta range start_month/start_year → month/year)
        $dateRange = $this->resolveIndicatorDateRange($request);
        if ($dateRange) {
            $timesheetsQuery->whereBetween('date', $dateRange);
        }

        // Aplicar filtro de cliente através dos projetos
        if ($customerId) {
            $timesheetsQuery->whereHas('project', function ($query) use ($customerId) {
                $query->where('customer_id', $customerId);
            });
        }

        // Aplicar filtro de projeto
        if ($projectId) {
            $project = Project::find($projectId);
            if ($project) {
                $projectIds = [$projectId];
                $childProjects = Project::where('parent_project_id', $projectId)->pluck('id');
                $projectIds = array_merge($projectIds, $childProjects->toArray());
                $timesheetsQuery->whereIn('project_id', $projectIds);
            } else {
                $timesheetsQuery->where('project_id', $projectId);
            }
        } else {
            // Sem projeto específico: filtrar pelo tipo de contrato BH Fixo
            $bhFixedType = \App\Models\ContractType::where('code', 'fixed_hours')->first();
            if ($bhFixedType) {
                $timesheetsQuery->whereHas('project', function ($q) use ($bhFixedType) {
                    $q->where('contract_type_id', $bhFixedType->id);
                });
            }
        }

        // Buscar tickets do Movidesk com a categoria específica
        $tickets = MovideskTicket::where('categoria', $category)->get();
        $ticketIds = $tickets->pluck('ticket_id')->toArray();

        if (empty($ticketIds)) {
            return response()->json([
                'success' => true,
                'message' => 'Nenhum apontamento encontrado para esta categoria',
                'data' => []
            ]);
        }

        // Filtrar timesheets pelos tickets encontrados
        $timesheetsQuery->whereIn('ticket', $ticketIds);

        // Buscar timesheets
        $timesheets = $timesheetsQuery->get();

        // Formatar dados para resposta
        $timesheetsData = $timesheets->map(function($timesheet) {
            return [
                'id' => $timesheet->id,
                'date' => $timesheet->date->format('Y-m-d'),
                'start_time' => $timesheet->start_time->format('H:i'),
                'end_time' => $timesheet->end_time->format('H:i'),
                'effort_minutes' => $timesheet->effort_minutes,
                'effort_hours' => $timesheet->effort_hours,
                'observation' => $timesheet->observation,
                'ticket' => $timesheet->ticket,
                'status' => $timesheet->status,
                'status_display' => $timesheet->status_display,
                'user' => $timesheet->user ? [
                    'id' => $timesheet->user->id,
                    'name' => $timesheet->user->name,
                    'email' => $timesheet->user->email,
                ] : null,
                'project' => $timesheet->project ? [
                    'id' => $timesheet->project->id,
                    'name' => $timesheet->project->name,
                    'code' => $timesheet->project->code,
                ] : null,
                'reviewed_by' => $timesheet->reviewedBy ? [
                    'id' => $timesheet->reviewedBy->id,
                    'name' => $timesheet->reviewedBy->name,
                    'email' => $timesheet->reviewedBy->email,
                ] : null,
                'reviewed_at' => $timesheet->reviewed_at ? $timesheet->reviewed_at->toIso8601String() : null,
                'created_at' => $timesheet->created_at->toIso8601String(),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'message' => 'Apontamentos obtidos com sucesso',
            'data' => $timesheetsData
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/dashboards/bank-hours-fixed/indicators/tickets-above-8-hours",
     *     tags={"Dashboards"},
     *     summary="Tickets acima de 08 horas",
     *     description="Retorna tickets cujos apontamentos somados totalizam 8 horas ou mais. Considera apenas tickets com status diferente de 'Cancelado' ou 'Fechado'. Apenas apontamentos com status diferente de 'rejected' são considerados.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="customer_id",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="Filtrar por cliente (apenas para administradores)"
     *     ),
     *     @OA\Parameter(
     *         name="project_id",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="Filtrar por projeto pai específico"
     *     ),
     *     @OA\Parameter(
     *         name="month",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer", minimum=1, maximum=12),
     *         description="Filtrar por mês (1-12)"
     *     ),
     *     @OA\Parameter(
     *         name="year",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="Filtrar por ano"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lista de tickets acima de 8 horas",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Dados obtidos com sucesso"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="ticket_id", type="string", example="12345"),
     *                     @OA\Property(property="total_hours", type="number", example=12.5)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Não autenticado"),
     *     @OA\Response(response=403, description="Sem permissão")
     * )
     */
    public function bankHoursFixedTicketsAbove8Hours(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        if (!$user->isAdmin() && !$user->hasAccess('dashboards.view')) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado. Você precisa da permissão "dashboards.view" para acessar este dashboard.',
                'required_permission' => 'dashboards.view'
            ], 403);
        }

        // Determinar o cliente a filtrar
        $customerId = null;
        if ($user->customer_id) {
            $customerId = $user->customer_id;
        } elseif ($user->isAdmin() && $request->has('customer_id')) {
            $customerId = $request->get('customer_id');
        }

        // Filtrar por projeto específico se fornecido
        $projectId = $request->get('project_id');

        // Filtrar por mês e ano se fornecidos
        $month = $request->get('month');
        $year = $request->get('year');

        // Buscar timesheets com ticket preenchido e status diferente de 'rejected'
        $timesheetsQuery = Timesheet::whereNotNull('ticket')
            ->where('ticket', '!=', '')
            ->where('status', '!=', 'rejected');

        // Aplicar filtro de período (suporta range start_month/start_year → month/year)
        $dateRange = $this->resolveIndicatorDateRange($request);
        if ($dateRange) {
            $timesheetsQuery->whereBetween('date', $dateRange);
        }

        // Aplicar filtro de cliente através dos projetos
        if ($customerId) {
            $timesheetsQuery->whereHas('project', function ($query) use ($customerId) {
                $query->where('customer_id', $customerId);
            });
        }

        // Aplicar filtro de projeto
        if ($projectId) {
            $project = Project::find($projectId);
            if ($project) {
                $projectIds = [$projectId];
                $childProjects = Project::where('parent_project_id', $projectId)->pluck('id');
                $projectIds = array_merge($projectIds, $childProjects->toArray());
                $timesheetsQuery->whereIn('project_id', $projectIds);
            } else {
                $timesheetsQuery->where('project_id', $projectId);
            }
        } else {
            // Sem projeto específico: filtrar pelo tipo de contrato BH Fixo
            $bhFixedType = \App\Models\ContractType::where('code', 'fixed_hours')->first();
            if ($bhFixedType) {
                $timesheetsQuery->whereHas('project', function ($q) use ($bhFixedType) {
                    $q->where('contract_type_id', $bhFixedType->id);
                });
            }
        }

        // Buscar todos os timesheets que atendem aos critérios
        $timesheets = $timesheetsQuery->get();

        // Agrupar por ticket e somar horas
        $ticketHours = [];
        foreach ($timesheets as $timesheet) {
            $ticketId = $timesheet->ticket;

            if (!isset($ticketHours[$ticketId])) {
                $ticketHours[$ticketId] = 0;
            }

            // Converter minutos para horas
            $hours = $timesheet->effort_minutes / 60;
            $ticketHours[$ticketId] += $hours;
        }

        // Filtrar apenas tickets com 8 horas ou mais
        $ticketsAbove8Hours = [];
        foreach ($ticketHours as $ticketId => $totalHours) {
            if ($totalHours >= 8) {
                $ticketsAbove8Hours[$ticketId] = $totalHours;
            }
        }

        if (empty($ticketsAbove8Hours)) {
            return response()->json([
                'success' => true,
                'message' => 'Dados obtidos com sucesso',
                'data' => []
            ]);
        }

        // Buscar tickets do Movidesk e filtrar por status (excluir Cancelado e Fechado)
        $ticketIds = array_keys($ticketsAbove8Hours);

        // Buscar todos os tickets primeiro
        $allTickets = MovideskTicket::whereIn('ticket_id', $ticketIds)->get();

        // Filtrar tickets com status diferente de Cancelado e Fechado
        $validTickets = $allTickets->filter(function($ticket) {
            $status = $ticket->status;
            // Incluir tickets sem status (null ou vazio) e com status diferente de Cancelado/Fechado
            return ($status === null || $status === '') ||
                   (strtolower(trim($status)) !== 'cancelado' && strtolower(trim($status)) !== 'fechado');
        });

        // Criar mapa de ticket_id -> status para verificação
        $ticketStatusMap = [];
        foreach ($validTickets as $ticket) {
            $ticketStatusMap[$ticket->ticket_id] = $ticket->status;
        }

        // Converter para formato de resposta, incluindo apenas tickets válidos
        $data = [];
        foreach ($ticketsAbove8Hours as $ticketId => $totalHours) {
            // Verificar se o ticket está na lista de tickets válidos (não cancelado/fechado)
            if (isset($ticketStatusMap[$ticketId])) {
                $data[] = [
                    'ticket_id' => $ticketId,
                    'total_hours' => round($totalHours, 2)
                ];
            }
        }

        // Ordenar por total de horas (decrescente)
        usort($data, function ($a, $b) {
            return $b['total_hours'] <=> $a['total_hours'];
        });

        return response()->json([
            'success' => true,
            'message' => 'Dados obtidos com sucesso',
            'data' => $data
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/dashboards/bank-hours-fixed/indicators/ticket-timesheets",
     *     tags={"Dashboards"},
     *     summary="Apontamentos de um Ticket Específico",
     *     description="Retorna os apontamentos (timesheets) relacionados a um ticket específico. Apenas apontamentos com status diferente de 'rejected' são considerados.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="ticket_id",
     *         in="query",
     *         required=true,
     *         @OA\Schema(type="string"),
     *         description="ID do ticket"
     *     ),
     *     @OA\Parameter(
     *         name="customer_id",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="Filtrar por cliente (apenas para administradores)"
     *     ),
     *     @OA\Parameter(
     *         name="project_id",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="Filtrar por projeto pai específico"
     *     ),
     *     @OA\Parameter(
     *         name="month",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer", minimum=1, maximum=12),
     *         description="Filtrar por mês (1-12)"
     *     ),
     *     @OA\Parameter(
     *         name="year",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="Filtrar por ano"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lista de apontamentos",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Apontamentos obtidos com sucesso"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer"),
     *                     @OA\Property(property="date", type="string", format="date"),
     *                     @OA\Property(property="effort_hours", type="string"),
     *                     @OA\Property(property="user", type="object"),
     *                     @OA\Property(property="project", type="object"),
     *                     @OA\Property(property="ticket", type="string", nullable=true),
     *                     @OA\Property(property="status", type="string"),
     *                     @OA\Property(property="status_display", type="string")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Não autenticado"),
     *     @OA\Response(response=403, description="Sem permissão")
     * )
     */
    public function bankHoursFixedTicketTimesheets(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        if (!$user->isAdmin() && !$user->hasAccess('dashboards.view')) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado. Você precisa da permissão "dashboards.view" para acessar este dashboard.',
                'required_permission' => 'dashboards.view'
            ], 403);
        }

        $ticketId = $request->get('ticket_id');
        // Validar se ticket_id foi fornecido (permitir "0" como valor válido)
        if ($ticketId === null || $ticketId === '') {
            return response()->json([
                'success' => false,
                'message' => 'Parâmetro "ticket_id" é obrigatório'
            ], 400);
        }

        // Determinar o cliente a filtrar
        $customerId = null;
        if ($user->customer_id) {
            $customerId = $user->customer_id;
        } elseif ($user->isAdmin() && $request->has('customer_id')) {
            $customerId = $request->get('customer_id');
        }

        // Filtrar por projeto específico se fornecido
        $projectId = $request->get('project_id');

        // Filtrar por mês e ano se fornecidos
        // NOTA: Para tickets específicos, não aplicamos filtros de mês/ano porque
        // o gráfico mostra o total de horas do ticket, então devemos mostrar todos os apontamentos
        $month = $request->get('month');
        $year = $request->get('year');

        // Buscar timesheets com ticket preenchido e status diferente de 'rejected'
        // Converter ticketId para string para garantir comparação correta
        $ticketIdStr = (string) $ticketId;

        $timesheetsQuery = Timesheet::whereNotNull('ticket')
            ->where('ticket', '!=', '')
            ->where('ticket', $ticketIdStr)
            ->where('status', '!=', 'rejected')
            ->with(['user', 'project', 'reviewedBy']);

        // NÃO aplicar filtro de mês e ano para tickets específicos
        // O gráfico mostra tickets com 8h+ no total, então devemos mostrar todos os apontamentos
        // Se necessário filtrar por período, isso deve ser feito no frontend após receber os dados

        // Aplicar filtro de cliente através dos projetos
        if ($customerId) {
            $timesheetsQuery->whereHas('project', function ($query) use ($customerId) {
                $query->where('customer_id', $customerId);
            });
        }

        // Aplicar filtro de projeto
        if ($projectId) {
            $project = Project::find($projectId);
            if ($project) {
                $projectIds = [$projectId];
                $childProjects = Project::where('parent_project_id', $projectId)->pluck('id');
                $projectIds = array_merge($projectIds, $childProjects->toArray());
                $timesheetsQuery->whereIn('project_id', $projectIds);
            } else {
                $timesheetsQuery->where('project_id', $projectId);
            }
        } else {
            // Sem projeto específico: filtrar pelo tipo de contrato BH Fixo
            $bhFixedType = \App\Models\ContractType::where('code', 'fixed_hours')->first();
            if ($bhFixedType) {
                $timesheetsQuery->whereHas('project', function ($q) use ($bhFixedType) {
                    $q->where('contract_type_id', $bhFixedType->id);
                });
            }
        }

        // Excluir projetos do tipo "Fechado"
        $timesheetsQuery->whereHas('project', function ($query) {
            $query->whereHas('contractType', function ($q) {
                $q->whereRaw('LOWER(TRIM(name)) != ?', ['fechado']);
            })->orWhereDoesntHave('contractType');
        });

        // Buscar timesheets
        $timesheets = $timesheetsQuery->get();

        // Formatar dados para resposta
        $timesheetsData = $timesheets->map(function($timesheet) {
            return [
                'id' => $timesheet->id,
                'date' => $timesheet->date->format('Y-m-d'),
                'start_time' => $timesheet->start_time->format('H:i'),
                'end_time' => $timesheet->end_time->format('H:i'),
                'effort_minutes' => $timesheet->effort_minutes,
                'effort_hours' => $timesheet->effort_hours,
                'observation' => $timesheet->observation,
                'ticket' => $timesheet->ticket,
                'status' => $timesheet->status,
                'status_display' => $timesheet->status_display,
                'user' => $timesheet->user ? [
                    'id' => $timesheet->user->id,
                    'name' => $timesheet->user->name,
                    'email' => $timesheet->user->email,
                ] : null,
                'project' => $timesheet->project ? [
                    'id' => $timesheet->project->id,
                    'name' => $timesheet->project->name,
                    'code' => $timesheet->project->code,
                ] : null,
                'reviewed_by' => $timesheet->reviewedBy ? [
                    'id' => $timesheet->reviewedBy->id,
                    'name' => $timesheet->reviewedBy->name,
                    'email' => $timesheet->reviewedBy->email,
                ] : null,
                'reviewed_at' => $timesheet->reviewed_at ? $timesheet->reviewed_at->toIso8601String() : null,
                'created_at' => $timesheet->created_at->toIso8601String(),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'message' => 'Apontamentos obtidos com sucesso',
            'data' => $timesheetsData
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/dashboards/bank-hours-fixed/indicators/monthly-tickets",
     *     tags={"Dashboards"},
     *     summary="Quantidade de Tickets Mensal",
     *     description="Retorna a quantidade de tickets criados nos últimos 12 meses. Apenas tickets relacionados a apontamentos com status diferente de 'rejected' são considerados.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="customer_id",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="Filtrar por cliente (apenas para administradores)"
     *     ),
     *     @OA\Parameter(
     *         name="project_id",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="Filtrar por projeto pai específico"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Dados obtidos com sucesso",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Dados obtidos com sucesso"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="month", type="string", example="out/2025"),
     *                     @OA\Property(property="ticket_count", type="integer", example=15)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Não autenticado"),
     *     @OA\Response(response=403, description="Sem permissão")
     * )
     */
    public function bankHoursFixedMonthlyTickets(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        if (!$user->isAdmin() && !$user->hasAccess('dashboards.view')) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado. Você precisa da permissão "dashboards.view" para acessar este dashboard.',
                'required_permission' => 'dashboards.view'
            ], 403);
        }

        // Determinar o cliente a filtrar
        $customerId = null;
        if ($user->customer_id) {
            $customerId = $user->customer_id;
        } elseif ($user->isAdmin() && $request->has('customer_id')) {
            $customerId = $request->get('customer_id');
        }

        // Filtrar por projeto específico se fornecido
        $projectId = $request->get('project_id');

        // Buscar timesheets com ticket preenchido e status diferente de 'rejected'
        $timesheetsQuery = Timesheet::whereNotNull('ticket')
            ->where('ticket', '!=', '')
            ->where('status', '!=', 'rejected');

        // Aplicar filtro de cliente através dos projetos
        if ($customerId) {
            $timesheetsQuery->whereHas('project', function ($query) use ($customerId) {
                $query->where('customer_id', $customerId);
            });
        }

        // Aplicar filtro de projeto
        if ($projectId) {
            $project = Project::find($projectId);
            if ($project) {
                $projectIds = [$projectId];
                $childProjects = Project::where('parent_project_id', $projectId)->pluck('id');
                $projectIds = array_merge($projectIds, $childProjects->toArray());
                $timesheetsQuery->whereIn('project_id', $projectIds);
            } else {
                $timesheetsQuery->where('project_id', $projectId);
            }
        } else {
            // Sem projeto específico: filtrar pelo tipo de contrato BH Fixo
            $bhFixedType = \App\Models\ContractType::where('code', 'fixed_hours')->first();
            if ($bhFixedType) {
                $timesheetsQuery->whereHas('project', function ($q) use ($bhFixedType) {
                    $q->where('contract_type_id', $bhFixedType->id);
                });
            }
        }

        // Obter ticket_ids únicos dos timesheets
        $ticketIds = $timesheetsQuery->distinct()
            ->pluck('ticket')
            ->filter(function($value) {
                return $value !== null && $value !== '';
            })
            ->unique()
            ->values();

        // Buscar tickets do Movidesk
        $tickets = MovideskTicket::whereIn('ticket_id', $ticketIds->toArray())->get();

        // Calcular os últimos 12 meses
        $months = [];
        $currentDate = now();

        // Mapear números de meses para abreviações em português
        $monthNames = [
            1 => 'jan', 2 => 'fev', 3 => 'mar', 4 => 'abr', 5 => 'mai', 6 => 'jun',
            7 => 'jul', 8 => 'ago', 9 => 'set', 10 => 'out', 11 => 'nov', 12 => 'dez'
        ];

        for ($i = 11; $i >= 0; $i--) {
            $date = $currentDate->copy()->subMonths($i);
            $monthKey = $date->format('Y-m');
            $monthNumber = (int) $date->format('n');
            $year = $date->format('Y');
            $monthLabel = strtolower($monthNames[$monthNumber] . '/' . $year);

            $months[$monthKey] = [
                'month' => $monthLabel,
                'ticket_count' => 0
            ];
        }

        // Contar tickets por mês
        foreach ($tickets as $ticket) {
            if ($ticket->created_at) {
                $ticketMonth = $ticket->created_at->format('Y-m');
                if (isset($months[$ticketMonth])) {
                    $months[$ticketMonth]['ticket_count']++;
                }
            }
        }

        // Converter para array e ordenar por mês
        $data = array_values($months);

        return response()->json([
            'success' => true,
            'message' => 'Dados obtidos com sucesso',
            'data' => $data
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/dashboards/bank-hours-fixed/indicators/monthly-timesheets",
     *     tags={"Dashboards"},
     *     summary="Apontamentos de um Mês Específico",
     *     description="Retorna os apontamentos (timesheets) relacionados a tickets criados em um mês específico. Apenas apontamentos com status diferente de 'rejected' são considerados.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="month",
     *         in="query",
     *         required=true,
     *         @OA\Schema(type="string", example="out/2025"),
     *         description="Mês no formato 'mmm/aaaa' (ex: out/2025)"
     *     ),
     *     @OA\Parameter(
     *         name="customer_id",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="Filtrar por cliente (apenas para administradores)"
     *     ),
     *     @OA\Parameter(
     *         name="project_id",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="Filtrar por projeto pai específico"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lista de apontamentos",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Apontamentos obtidos com sucesso"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer"),
     *                     @OA\Property(property="date", type="string", format="date"),
     *                     @OA\Property(property="effort_hours", type="string"),
     *                     @OA\Property(property="user", type="object"),
     *                     @OA\Property(property="project", type="object"),
     *                     @OA\Property(property="ticket", type="string", nullable=true),
     *                     @OA\Property(property="status", type="string"),
     *                     @OA\Property(property="status_display", type="string")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Não autenticado"),
     *     @OA\Response(response=403, description="Sem permissão")
     * )
     */
    public function bankHoursFixedMonthlyTimesheets(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        if (!$user->isAdmin() && !$user->hasAccess('dashboards.view')) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado. Você precisa da permissão "dashboards.view" para acessar este dashboard.',
                'required_permission' => 'dashboards.view'
            ], 403);
        }

        $monthLabel = $request->get('month');
        if (!$monthLabel) {
            return response()->json([
                'success' => false,
                'message' => 'Parâmetro "month" é obrigatório'
            ], 400);
        }

        // Converter "out/2025" para "2025-10"
        $monthParts = explode('/', $monthLabel);
        if (count($monthParts) !== 2) {
            return response()->json([
                'success' => false,
                'message' => 'Formato de mês inválido. Use o formato "mmm/aaaa" (ex: out/2025)'
            ], 400);
        }

        $monthName = strtolower(trim($monthParts[0]));
        $year = (int) $monthParts[1];

        // Mapear nomes de meses em português para números
        $monthMap = [
            'jan' => 1, 'fev' => 2, 'mar' => 3, 'abr' => 4, 'mai' => 5, 'jun' => 6,
            'jul' => 7, 'ago' => 8, 'set' => 9, 'out' => 10, 'nov' => 11, 'dez' => 12
        ];

        if (!isset($monthMap[$monthName])) {
            return response()->json([
                'success' => false,
                'message' => 'Nome de mês inválido'
            ], 400);
        }

        $month = $monthMap[$monthName];
        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate = date('Y-m-t', strtotime($startDate));

        // Determinar o cliente a filtrar
        $customerId = null;
        if ($user->customer_id) {
            $customerId = $user->customer_id;
        } elseif ($user->isAdmin() && $request->has('customer_id')) {
            $customerId = $request->get('customer_id');
        }

        // Filtrar por projeto específico se fornecido
        $projectId = $request->get('project_id');

        // Buscar tickets criados no mês especificado
        $ticketsQuery = MovideskTicket::whereBetween('created_at', [$startDate, $endDate . ' 23:59:59']);
        $ticketIds = $ticketsQuery->pluck('ticket_id')
            ->filter(function($value) {
                return $value !== null && $value !== '';
            })
            ->unique()
            ->values();

        // Buscar timesheets com ticket preenchido e status diferente de 'rejected'
        $timesheetsQuery = Timesheet::whereNotNull('ticket')
            ->where('ticket', '!=', '')
            ->whereIn('ticket', $ticketIds->toArray())
            ->where('status', '!=', 'rejected')
            ->with(['user', 'project', 'reviewedBy']);

        // Aplicar filtro de cliente através dos projetos
        if ($customerId) {
            $timesheetsQuery->whereHas('project', function ($query) use ($customerId) {
                $query->where('customer_id', $customerId);
            });
        }

        // Aplicar filtro de projeto
        if ($projectId) {
            $project = Project::find($projectId);
            if ($project) {
                $projectIds = [$projectId];
                $childProjects = Project::where('parent_project_id', $projectId)->pluck('id');
                $projectIds = array_merge($projectIds, $childProjects->toArray());
                $timesheetsQuery->whereIn('project_id', $projectIds);
            } else {
                $timesheetsQuery->where('project_id', $projectId);
            }
        } else {
            // Sem projeto específico: filtrar pelo tipo de contrato BH Fixo
            $bhFixedType = \App\Models\ContractType::where('code', 'fixed_hours')->first();
            if ($bhFixedType) {
                $timesheetsQuery->whereHas('project', function ($q) use ($bhFixedType) {
                    $q->where('contract_type_id', $bhFixedType->id);
                });
            }
        }

        // Excluir projetos do tipo "Fechado"
        $timesheetsQuery->whereHas('project', function ($query) {
            $query->whereHas('contractType', function ($q) {
                $q->whereRaw('LOWER(TRIM(name)) != ?', ['fechado']);
            })->orWhereDoesntHave('contractType');
        });

        // Buscar timesheets
        $timesheets = $timesheetsQuery->get();

        // Formatar dados para resposta
        $timesheetsData = $timesheets->map(function($timesheet) {
            return [
                'id' => $timesheet->id,
                'date' => $timesheet->date->format('Y-m-d'),
                'start_time' => $timesheet->start_time->format('H:i'),
                'end_time' => $timesheet->end_time->format('H:i'),
                'effort_minutes' => $timesheet->effort_minutes,
                'effort_hours' => $timesheet->effort_hours,
                'observation' => $timesheet->observation,
                'ticket' => $timesheet->ticket,
                'status' => $timesheet->status,
                'status_display' => $timesheet->status_display,
                'user' => $timesheet->user ? [
                    'id' => $timesheet->user->id,
                    'name' => $timesheet->user->name,
                    'email' => $timesheet->user->email,
                ] : null,
                'project' => $timesheet->project ? [
                    'id' => $timesheet->project->id,
                    'name' => $timesheet->project->name,
                    'code' => $timesheet->project->code,
                ] : null,
                'reviewed_by' => $timesheet->reviewedBy ? [
                    'id' => $timesheet->reviewedBy->id,
                    'name' => $timesheet->reviewedBy->name,
                    'email' => $timesheet->reviewedBy->email,
                ] : null,
                'reviewed_at' => $timesheet->reviewed_at ? $timesheet->reviewed_at->toIso8601String() : null,
                'created_at' => $timesheet->created_at->toIso8601String(),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'message' => 'Apontamentos obtidos com sucesso',
            'data' => $timesheetsData
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/dashboards/bank-hours-fixed/indicators/monthly-consumption",
     *     tags={"Dashboards"},
     *     summary="Consumo Mensal de Horas",
     *     description="Retorna o consumo de horas nos últimos 12 meses. Usa a mesma lógica do cálculo de consumo mensal: para projetos fechados, considera horas vendidas se start_date está no mês; para outros tipos, considera horas apontadas do mês.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="customer_id",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="Filtrar por cliente (apenas para administradores)"
     *     ),
     *     @OA\Parameter(
     *         name="project_id",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="Filtrar por projeto pai específico"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Dados obtidos com sucesso",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Dados obtidos com sucesso"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="month", type="string", example="out/2025"),
     *                     @OA\Property(property="consumed_hours", type="number", example=120.5)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Não autenticado"),
     *     @OA\Response(response=403, description="Sem permissão")
     * )
     */
    public function bankHoursFixedMonthlyConsumption(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        if (!$user->isAdmin() && !$user->hasAccess('dashboards.view')) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado. Você precisa da permissão "dashboards.view" para acessar este dashboard.',
                'required_permission' => 'dashboards.view'
            ], 403);
        }

        // Determinar o cliente a filtrar
        $customerId = null;
        if ($user->customer_id) {
            $customerId = $user->customer_id;
        } elseif ($user->isAdmin() && $request->has('customer_id')) {
            $customerId = $request->get('customer_id');
        }

        // Filtrar por projeto específico se fornecido
        $projectId = $request->get('project_id');

        // Buscar projetos pais conforme filtros
        $parentProjectsQuery = Project::whereNull('parent_project_id')
            ->with(['contractType', 'childProjects.contractType']);

        if ($customerId) {
            $parentProjectsQuery->where('customer_id', $customerId);
        }

        if ($projectId) {
            $parentProjectsQuery->where('id', $projectId);
        }

        $parentProjects = $parentProjectsQuery->get();

        // Mapear números de meses para abreviações em português
        $monthNames = [
            1 => 'jan', 2 => 'fev', 3 => 'mar', 4 => 'abr', 5 => 'mai', 6 => 'jun',
            7 => 'jul', 8 => 'ago', 9 => 'set', 10 => 'out', 11 => 'nov', 12 => 'dez'
        ];

        // Calcular consumo para cada um dos últimos 12 meses
        $currentDate = now();
        $monthlyConsumption = [];

        for ($i = 11; $i >= 0; $i--) {
            $targetDate = $currentDate->copy()->subMonths($i);
            $monthKey = $targetDate->format('Y-m');
            $monthNumber = (int) $targetDate->format('n');
            $year = $targetDate->format('Y');
            $monthLabel = strtolower($monthNames[$monthNumber] . '/' . $year);

            $monthStart = $targetDate->copy()->startOfMonth()->format('Y-m-d');
            $monthEnd = $targetDate->copy()->endOfMonth()->format('Y-m-d');

            $monthConsumedHours = 0;

            foreach ($parentProjects as $parentProject) {
                // Verificar se o projeto pai é do tipo "Fechado"
                $isParentClosedContract = $parentProject->contractType &&
                                          strtolower(trim($parentProject->contractType->name)) === 'fechado';

                if ($isParentClosedContract) {
                    // Para projetos fechados: verificar se start_date está no mês alvo
                    if ($parentProject->start_date) {
                        $parentStartDate = \Carbon\Carbon::parse($parentProject->start_date);
                        $isInTargetMonth = $parentStartDate->year === $targetDate->year &&
                                           $parentStartDate->month === $targetDate->month;

                        if ($isInTargetMonth) {
                            // Se o projeto fechado começou no mês alvo, usar horas vendidas
                            $parentSoldHours = $parentProject->sold_hours ?? 0;
                            $monthConsumedHours += $parentSoldHours;
                        }
                    }
                } else {
                    // Para outros tipos: usar horas apontadas do mês alvo (excluindo rejeitados)
                    $parentMonthLoggedMinutes = $parentProject->timesheets()
                        ->where('status', '!=', 'rejected')
                        ->whereBetween('date', [$monthStart, $monthEnd])
                        ->sum('effort_minutes') ?? 0;
                    $parentMonthLoggedHours = round($parentMonthLoggedMinutes / 60, 2);
                    $monthConsumedHours += $parentMonthLoggedHours;
                }

                // Processar projetos filhos
                if ($parentProject->hasChildProjects()) {
                    foreach ($parentProject->childProjects as $childProject) {
                        // Verificar se o projeto filho é do tipo "Fechado"
                        $isClosedContract = $childProject->contractType &&
                                            strtolower(trim($childProject->contractType->name)) === 'fechado';

                        if ($isClosedContract) {
                            // Para projetos fechados: verificar se start_date está no mês alvo
                            if ($childProject->start_date) {
                                $childStartDate = \Carbon\Carbon::parse($childProject->start_date);
                                $isInTargetMonth = $childStartDate->year === $targetDate->year &&
                                                   $childStartDate->month === $targetDate->month;

                                if ($isInTargetMonth) {
                                    // Se o projeto fechado começou no mês alvo, usar horas vendidas
                                    $childSoldHours = $childProject->sold_hours ?? 0;
                                    $monthConsumedHours += $childSoldHours;
                                }
                            }
                        } else {
                            // Para outros tipos: usar horas apontadas do mês alvo (excluindo rejeitados)
                            $childMonthLoggedMinutes = $childProject->timesheets()
                                ->where('status', '!=', 'rejected')
                                ->whereBetween('date', [$monthStart, $monthEnd])
                                ->sum('effort_minutes') ?? 0;
                            $childMonthLoggedHours = round($childMonthLoggedMinutes / 60, 2);
                            $monthConsumedHours += $childMonthLoggedHours;
                        }
                    }
                }
            }

            // Arredondar para 2 casas decimais
            $monthConsumedHours = round($monthConsumedHours, 2);

            $monthlyConsumption[] = [
                'month' => $monthLabel,
                'consumed_hours' => $monthConsumedHours
            ];
        }

        return response()->json([
            'success' => true,
            'message' => 'Dados obtidos com sucesso',
            'data' => $monthlyConsumption
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/dashboards/bank-hours-fixed/indicators/monthly-consumption-timesheets",
     *     tags={"Dashboards"},
     *     summary="Apontamentos de Consumo Mensal",
     *     description="Retorna os apontamentos (timesheets) do mês especificado para o gráfico de Consumo Mensal. Filtra os apontamentos pela data do timesheet, não pela data de criação do ticket. Usa a mesma lógica do cálculo de consumo mensal.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="month",
     *         in="query",
     *         required=true,
     *         @OA\Schema(type="string", example="dez/2025"),
     *         description="Mês no formato 'mmm/aaaa' (ex: dez/2025)"
     *     ),
     *     @OA\Parameter(
     *         name="customer_id",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="Filtrar por cliente (apenas para administradores)"
     *     ),
     *     @OA\Parameter(
     *         name="project_id",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="Filtrar por projeto pai específico"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lista de apontamentos",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Apontamentos obtidos com sucesso"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer"),
     *                     @OA\Property(property="date", type="string", format="date"),
     *                     @OA\Property(property="effort_hours", type="string"),
     *                     @OA\Property(property="user", type="object"),
     *                     @OA\Property(property="project", type="object"),
     *                     @OA\Property(property="ticket", type="string", nullable=true),
     *                     @OA\Property(property="status", type="string"),
     *                     @OA\Property(property="status_display", type="string")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Não autenticado"),
     *     @OA\Response(response=403, description="Sem permissão")
     * )
     */
    public function bankHoursFixedMonthlyConsumptionTimesheets(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        if (!$user->isAdmin() && !$user->hasAccess('dashboards.view')) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado. Você precisa da permissão "dashboards.view" para acessar este dashboard.',
                'required_permission' => 'dashboards.view'
            ], 403);
        }

        $monthLabel = $request->get('month');
        if (!$monthLabel) {
            return response()->json([
                'success' => false,
                'message' => 'Parâmetro "month" é obrigatório'
            ], 400);
        }

        // Converter "dez/2025" para "2025-12"
        $monthParts = explode('/', $monthLabel);
        if (count($monthParts) !== 2) {
            return response()->json([
                'success' => false,
                'message' => 'Formato de mês inválido. Use o formato "mmm/aaaa" (ex: dez/2025)'
            ], 400);
        }

        $monthName = strtolower(trim($monthParts[0]));
        $year = (int) $monthParts[1];

        // Mapear nomes de meses em português para números
        $monthMap = [
            'jan' => 1, 'fev' => 2, 'mar' => 3, 'abr' => 4, 'mai' => 5, 'jun' => 6,
            'jul' => 7, 'ago' => 8, 'set' => 9, 'out' => 10, 'nov' => 11, 'dez' => 12
        ];

        if (!isset($monthMap[$monthName])) {
            return response()->json([
                'success' => false,
                'message' => 'Nome de mês inválido'
            ], 400);
        }

        $month = $monthMap[$monthName];
        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate = date('Y-m-t', strtotime($startDate)); // Último dia do mês

        // Determinar o cliente a filtrar
        $customerId = null;
        if ($user->customer_id) {
            $customerId = $user->customer_id;
        } elseif ($user->isAdmin() && $request->has('customer_id')) {
            $customerId = $request->get('customer_id');
        }

        // Filtrar por projeto específico se fornecido
        $projectId = $request->get('project_id');

        // Buscar timesheets do mês especificado
        // IMPORTANTE: Para o modal de consumo mensal, mostramos APENAS os apontamentos do mês especificado
        // Filtramos pela data do timesheet, independentemente do tipo de projeto ou data de criação do ticket
        $timesheetsQuery = Timesheet::whereNotNull('ticket')
            ->where('ticket', '!=', '')
            ->where('status', '!=', 'rejected')
            ->whereDate('date', '>=', $startDate)
            ->whereDate('date', '<=', $endDate)
            ->with(['user', 'project', 'reviewedBy']);

        // Aplicar filtro de cliente através dos projetos
        if ($customerId) {
            $timesheetsQuery->whereHas('project', function ($query) use ($customerId) {
                $query->where('customer_id', $customerId);
            });
        }

        // Aplicar filtro de projeto
        if ($projectId) {
            $project = Project::find($projectId);
            if ($project) {
                $projectIds = [$projectId];
                $childProjects = Project::where('parent_project_id', $projectId)->pluck('id');
                $projectIds = array_merge($projectIds, $childProjects->toArray());
                $timesheetsQuery->whereIn('project_id', $projectIds);
            } else {
                $timesheetsQuery->where('project_id', $projectId);
            }
        } else {
            // Sem projeto específico: filtrar pelo tipo de contrato BH Fixo
            $bhFixedType = \App\Models\ContractType::where('code', 'fixed_hours')->first();
            if ($bhFixedType) {
                $timesheetsQuery->whereHas('project', function ($q) use ($bhFixedType) {
                    $q->where('contract_type_id', $bhFixedType->id);
                });
            }
        }

        // Excluir projetos do tipo "Fechado"
        $timesheetsQuery->whereHas('project', function ($query) {
            $query->whereHas('contractType', function ($q) {
                $q->whereRaw('LOWER(TRIM(name)) != ?', ['fechado']);
            })->orWhereDoesntHave('contractType');
        });

        // Buscar timesheets
        $timesheets = $timesheetsQuery->get();

        // Formatar dados para resposta
        $timesheetsData = $timesheets->map(function($timesheet) {
            return [
                'id' => $timesheet->id,
                'date' => $timesheet->date->format('Y-m-d'),
                'start_time' => $timesheet->start_time->format('H:i'),
                'end_time' => $timesheet->end_time->format('H:i'),
                'effort_minutes' => $timesheet->effort_minutes,
                'effort_hours' => $timesheet->effort_hours,
                'observation' => $timesheet->observation,
                'ticket' => $timesheet->ticket,
                'status' => $timesheet->status,
                'status_display' => $timesheet->status_display,
                'user' => $timesheet->user ? [
                    'id' => $timesheet->user->id,
                    'name' => $timesheet->user->name,
                    'email' => $timesheet->user->email,
                ] : null,
                'project' => $timesheet->project ? [
                    'id' => $timesheet->project->id,
                    'name' => $timesheet->project->name,
                    'code' => $timesheet->project->code,
                ] : null,
                'reviewed_by' => $timesheet->reviewedBy ? [
                    'id' => $timesheet->reviewedBy->id,
                    'name' => $timesheet->reviewedBy->name,
                    'email' => $timesheet->reviewedBy->email,
                ] : null,
                'reviewed_at' => $timesheet->reviewed_at ? $timesheet->reviewed_at->toIso8601String() : null,
                'created_at' => $timesheet->created_at->toIso8601String(),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'message' => 'Apontamentos obtidos com sucesso',
            'data' => $timesheetsData
        ]);
    }

    /**
     * Tickets agrupados por urgência (Urgente | Alta | Média | Baixa).
     * Respeita filtros do dashboard (customer/project/mês/ano).
     */
    public function bankHoursFixedTicketsByUrgency(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Usuário não autenticado'], 401);
        }
        if (!$user->isAdmin() && !$user->hasAccess('dashboards.view')) {
            return response()->json(['success' => false, 'message' => 'Acesso negado'], 403);
        }

        $customerId = $user->customer_id ?: ($user->isAdmin() ? $request->get('customer_id') : null);
        $projectId  = $request->get('project_id');

        $tsQ = Timesheet::whereNotNull('ticket')
            ->where('ticket', '!=', '')
            ->where('status', '!=', 'rejected')
            ->whereRaw("ticket ~ '^[0-9]{5}$'");

        $dateRange = $this->resolveIndicatorDateRange($request);
        if ($dateRange) $tsQ->whereBetween('date', $dateRange);

        if ($customerId) {
            $tsQ->whereHas('project', fn ($q) => $q->where('customer_id', $customerId));
        }
        if ($projectId) {
            $projectIds = array_merge([$projectId], Project::where('parent_project_id', $projectId)->pluck('id')->toArray());
            $tsQ->whereIn('project_id', $projectIds);
        } else {
            $bhFixedType = \App\Models\ContractType::where('code', 'fixed_hours')->first();
            if ($bhFixedType) $tsQ->whereHas('project', fn ($q) => $q->where('contract_type_id', $bhFixedType->id));
        }

        $ticketIds = $tsQ->pluck('ticket')->unique()->values();
        if ($ticketIds->isEmpty()) return response()->json(['success' => true, 'data' => []]);

        $rows = MovideskTicket::whereIn('ticket_id', $ticketIds)
            ->whereNotNull('urgencia')
            ->where('urgencia', '!=', '')
            ->selectRaw('urgencia, COUNT(*) AS ticket_count')
            ->groupBy('urgencia')
            ->get()
            ->map(fn ($r) => ['urgency' => $r->urgencia, 'ticket_count' => (int) $r->ticket_count]);

        // Ordenação fixa Urgente → Alta → Média → Baixa → outros
        $order = ['Urgente' => 0, 'Alta' => 1, 'Média' => 2, 'Baixa' => 3];
        $rows = $rows->sortBy(fn ($r) => $order[$r['urgency']] ?? 99)->values();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    /**
     * Lista timesheets por categoria de service_type (architecture | maintenance).
     * Usado pelos modais "Ver Apontamentos" nas abas correspondentes do dashboard.
     */
    public function categoryTimesheetsModal(Request $request): JsonResponse
    {
        $user = $request->user();
        $category = (string) $request->get('category', '');

        $serviceCodeMap = [
            'architecture' => 'arquitetura',
            'maintenance'  => 'sustentação',
        ];
        $serviceNameMap = [
            'architecture' => 'Arquitetura',
            'maintenance'  => 'Sustentação',
        ];
        if (!isset($serviceCodeMap[$category])) {
            return response()->json(['success' => false, 'message' => 'category inválida'], 422);
        }

        $serviceTypeId = ServiceType::where('code', $serviceCodeMap[$category])
            ->orWhere('name', $serviceNameMap[$category])
            ->value('id');
        if (!$serviceTypeId) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $customerId = $request->get('customer_id') ?? ($user && method_exists($user, 'isCliente') && $user->isCliente() ? $user->customer_id : null);
        $projectId  = $request->get('project_id');
        $dateFrom   = $request->get('date_from');
        $dateTo     = $request->get('date_to');

        $projectIdsQuery = Project::where('service_type_id', $serviceTypeId)->whereNull('deleted_at');
        if ($customerId) $projectIdsQuery->where('customer_id', $customerId);
        if ($projectId) {
            $projectIdsQuery->where(function ($q) use ($projectId) {
                $q->where('id', $projectId)->orWhere('parent_project_id', $projectId);
            });
        }
        $projectIds = $projectIdsQuery->pluck('id');
        if ($projectIds->isEmpty()) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $ts = Timesheet::with(['user', 'project.contractType', 'project.customer'])
            ->whereIn('project_id', $projectIds)
            ->where('status', '!=', 'rejected');
        if ($dateFrom) $ts->where('date', '>=', $dateFrom);
        if ($dateTo)   $ts->where('date', '<=', $dateTo);
        $ts = $ts->orderByDesc('date')->orderByDesc('id')->limit(500)->get();

        $fmtTime = fn ($v) => $v instanceof \DateTimeInterface ? $v->format('H:i') : (is_string($v) && strlen($v) >= 5 ? substr($v, -8, 5) : null);
        $parseRequester = function ($req) {
            if (is_array($req) && isset($req['name'])) return $req['name'];
            if (is_string($req) && str_starts_with(trim($req), '{')) {
                $d = json_decode($req, true);
                if (is_array($d) && isset($d['name'])) return $d['name'];
            }
            return $req;
        };

        return response()->json([
            'success' => true,
            'data' => $ts->map(fn ($t) => [
                'id'                 => $t->id,
                'date'               => optional($t->date)->format('Y-m-d'),
                'start_time'         => $fmtTime($t->start_time),
                'end_time'           => $fmtTime($t->end_time),
                'effort_minutes'     => $t->effort_minutes,
                'description'        => $t->observation,
                'status'             => $t->status,
                'status_display'     => $t->status_display,
                'ticket'             => $t->ticket,
                'ticket_subject'     => $t->ticket_subject,
                'requester'          => $parseRequester($t->ticket_solicitante),
                'attachment_path'    => $t->attachment_path,
                'user'               => $t->user ? ['id' => $t->user->id, 'name' => $t->user->name] : null,
                'customer'           => $t->project && $t->project->customer ? $t->project->customer->name : null,
                'project'            => $t->project ? [
                    'id'            => $t->project->id,
                    'code'          => $t->project->code,
                    'name'          => $t->project->name,
                    'contract_type' => optional($t->project->contractType)->name,
                ] : null,
            ])->values(),
        ]);
    }

    /**
     * Lista timesheets de um projeto específico (e seus filhos), com todos os campos
     * necessários para o modal "Ver Apontamentos" + modal de detalhe.
     */
    public function projectTimesheetsModal(Request $request): JsonResponse
    {
        $user       = $request->user();
        $projectId  = $request->get('project_id');
        $customerId = $request->get('customer_id') ?? ($user && method_exists($user, 'isCliente') && $user->isCliente() ? $user->customer_id : null);
        $dateFrom   = $request->get('date_from');
        $dateTo     = $request->get('date_to');

        if (!$projectId) {
            return response()->json(['success' => false, 'message' => 'project_id obrigatório'], 422);
        }

        $projectIds = Project::where(function ($q) use ($projectId) {
                $q->where('id', $projectId)->orWhere('parent_project_id', $projectId);
            })
            ->when($customerId, fn ($q) => $q->where('customer_id', $customerId))
            ->whereNull('deleted_at')
            ->pluck('id');

        if ($projectIds->isEmpty()) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $ts = Timesheet::with(['user', 'project.contractType', 'project.customer'])
            ->whereIn('project_id', $projectIds)
            ->where('status', '!=', 'rejected')
            ->when($dateFrom, fn ($q) => $q->where('date', '>=', $dateFrom))
            ->when($dateTo,   fn ($q) => $q->where('date', '<=', $dateTo))
            ->orderByDesc('date')->orderByDesc('id')
            ->limit(1000)
            ->get();

        $data = $ts->map(function ($t) {
            $req = $t->ticket_solicitante;
            $reqName = null;
            if (is_array($req) && isset($req['name'])) $reqName = $req['name'];
            elseif (is_string($req) && str_starts_with(trim($req), '{')) {
                $decoded = json_decode($req, true);
                if (is_array($decoded) && isset($decoded['name'])) $reqName = $decoded['name'];
            } else $reqName = $req;

            $fmtTime = fn ($v) => $v instanceof \DateTimeInterface ? $v->format('H:i') : (is_string($v) && strlen($v) >= 5 ? substr($v, -8, 5) : null);

            return [
                'id'             => $t->id,
                'date'           => optional($t->date)->format('Y-m-d'),
                'start_time'     => $fmtTime($t->start_time),
                'end_time'       => $fmtTime($t->end_time),
                'effort_minutes' => $t->effort_minutes,
                'description'    => $t->observation,
                'ticket'         => $t->ticket,
                'ticket_subject' => $t->ticket_subject,
                'requester'      => $reqName,
                'status'         => $t->status,
                'status_display' => $t->status_display,
                'attachment_path'=> $t->attachment_path,
                'user'           => $t->user ? ['id' => $t->user->id, 'name' => $t->user->name] : null,
                'customer'       => $t->project && $t->project->customer ? $t->project->customer->name : null,
                'project'        => $t->project ? [
                    'id'   => $t->project->id,
                    'code' => $t->project->code,
                    'name' => $t->project->name,
                    'contract_type' => optional($t->project->contractType)->name,
                ] : null,
            ];
        });

        return response()->json(['success' => true, 'data' => $data->values()]);
    }

    /**
     * Agrupa apontamentos por ticket (5 dígitos) para a categoria informada,
     * respeitando os filtros do dashboard (customer/project/datas).
     * Retorna: ticket, title, requester, period_minutes, lifetime_minutes.
     */
    public function categoryTicketSummary(Request $request): JsonResponse
    {
        $user = $request->user();
        $category = (string) $request->get('category', 'maintenance');

        $serviceCodeMap = ['architecture' => 'arquitetura', 'maintenance' => 'sustentação'];
        $serviceNameMap = ['architecture' => 'Arquitetura',  'maintenance' => 'Sustentação'];
        if (!isset($serviceCodeMap[$category])) {
            return response()->json(['tickets' => []]);
        }
        $serviceTypeId = ServiceType::where('code', $serviceCodeMap[$category])
            ->orWhere('name', $serviceNameMap[$category])
            ->value('id');
        if (!$serviceTypeId) return response()->json(['tickets' => []]);

        $customerId = $request->get('customer_id') ?? ($user && method_exists($user, 'isCliente') && $user->isCliente() ? $user->customer_id : null);
        if (!$customerId) return response()->json(['tickets' => []]);
        $projectId  = $request->get('project_id');
        $dateFrom   = $request->get('date_from');
        $dateTo     = $request->get('date_to');

        $projectIdsQ = Project::where('service_type_id', $serviceTypeId)
            ->where('customer_id', $customerId)
            ->whereNull('deleted_at');
        if ($projectId) {
            $projectIdsQ->where(function ($q) use ($projectId) {
                $q->where('id', $projectId)->orWhere('parent_project_id', $projectId);
            });
        }
        $projectIds = $projectIdsQ->pluck('id');
        if ($projectIds->isEmpty()) return response()->json(['tickets' => []]);

        $base = Timesheet::query()
            ->where('timesheets.customer_id', $customerId)
            ->whereIn('timesheets.project_id', $projectIds)
            ->whereNotNull('timesheets.ticket')
            ->where('timesheets.ticket', '!=', '')
            ->whereRaw("timesheets.ticket ~ '^[0-9]{5}$'")
            ->where('timesheets.status', '!=', 'rejected');

        $periodQ = (clone $base);
        if ($dateFrom) $periodQ->where('timesheets.date', '>=', $dateFrom);
        if ($dateTo)   $periodQ->where('timesheets.date', '<=', $dateTo);
        $ticketsInPeriod = $periodQ->select('timesheets.ticket')->distinct()->pluck('ticket')->toArray();

        if (empty($ticketsInPeriod)) return response()->json(['tickets' => []]);

        $rows = (clone $base)
            ->whereIn('timesheets.ticket', $ticketsInPeriod)
            ->leftJoin('movidesk_tickets', 'movidesk_tickets.ticket_id', '=', 'timesheets.ticket')
            ->select(
                'timesheets.ticket',
                DB::raw("MAX(timesheets.ticket_subject) AS ticket_subject"),
                DB::raw("MAX(timesheets.ticket_solicitante::text) AS ticket_solicitante"),
                DB::raw("SUM(timesheets.effort_minutes) AS lifetime_minutes"),
                DB::raw(
                    "SUM(CASE WHEN timesheets.date BETWEEN " .
                    "COALESCE(?, timesheets.date) AND COALESCE(?, timesheets.date) " .
                    "THEN timesheets.effort_minutes ELSE 0 END) AS period_minutes"
                ),
            )
            ->addBinding($dateFrom, 'select')
            ->addBinding($dateTo,   'select')
            ->groupBy('timesheets.ticket')
            ->get();

        $tickets = $rows->map(function ($r) {
            $req = $r->ticket_solicitante;
            $reqName = null;
            if ($req && str_starts_with($req, '{')) {
                $decoded = json_decode($req, true);
                if (is_array($decoded) && isset($decoded['name'])) $reqName = $decoded['name'];
            } else {
                $reqName = $req;
            }
            return [
                'ticket'           => $r->ticket,
                'title'            => $r->ticket_subject,
                'requester'        => $reqName,
                'period_minutes'   => (int) $r->period_minutes,
                'lifetime_minutes' => (int) $r->lifetime_minutes,
            ];
        })->sortBy('ticket', SORT_NATURAL)->values();

        return response()->json(['tickets' => $tickets]);
    }

    /**
     * Lista despesas relacionadas ao recorte do dashboard (customer/project/datas).
     */
    public function expensesModal(Request $request): JsonResponse
    {
        $user = $request->user();
        $customerId = $request->get('customer_id') ?? ($user && method_exists($user, 'isCliente') && $user->isCliente() ? $user->customer_id : null);
        $projectId  = $request->get('project_id');
        $dateFrom   = $request->get('date_from');
        $dateTo     = $request->get('date_to');

        $q = Expense::with(['user', 'project', 'category']);
        if ($customerId) {
            $q->whereHas('project', fn ($pq) => $pq->where('customer_id', $customerId));
        }
        if ($projectId) {
            $q->where(function ($qq) use ($projectId) {
                $qq->where('project_id', $projectId)
                   ->orWhereHas('project', fn ($pq) => $pq->where('parent_project_id', $projectId));
            });
        }
        if ($dateFrom) $q->where('date', '>=', $dateFrom);
        if ($dateTo)   $q->where('date', '<=', $dateTo);

        $expenses = $q->orderByDesc('date')->orderByDesc('id')->limit(500)->get();

        return response()->json([
            'success' => true,
            'data' => $expenses->map(fn ($e) => [
                'id'             => $e->id,
                'date'           => optional($e->date)->format('Y-m-d'),
                'amount'         => (float) $e->amount,
                'description'    => $e->description,
                'status'         => $e->status,
                'status_display' => $e->status_display ?? $e->status,
                'user'           => $e->user ? ['id' => $e->user->id, 'name' => $e->user->name] : null,
                'project'        => $e->project ? ['id' => $e->project->id, 'code' => $e->project->code, 'name' => $e->project->name] : null,
                'category'       => $e->category ? ['id' => $e->category->id, 'name' => $e->category->name] : null,
            ])->values(),
        ]);
    }
}
