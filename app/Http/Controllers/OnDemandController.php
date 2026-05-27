<?php

namespace App\Http\Controllers;

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
class OnDemandController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/v1/dashboards/on-demand",
     *     tags={"Dashboards"},
     *     summary="Dashboard de On Demand",
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
     *                 @OA\Property(property="hourly_rate", type="number", nullable=true, example=180.50, description="Valor hora do projeto (média do campo hourly_rate dos projetos pais, ou valor específico se filtrado por projeto)"),
     *                 @OA\Property(property="customer_id", type="integer", nullable=true, example=1, description="ID do cliente filtrado"),
     *                 @OA\Property(property="project_id", type="integer", nullable=true, example=5, description="ID do projeto filtrado (se aplicável)")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Não autenticado"),
     *     @OA\Response(response=403, description="Sem permissão")
     * )
     */
    public function onDemand(Request $request): JsonResponse
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

        // Extrair filtro de período (mês/ano) – usado tanto para consumo acumulado quanto para consumo do mês
        $month = $request->get('month');
        $year  = $request->get('year');

        // Data de corte para o período: se mês/ano fornecido, calcular até o último dia desse mês
        $periodEndDate = null;
        if ($month && $year) {
            $periodEndDate = \Carbon\Carbon::create((int) $year, (int) $month, 1)
                ->endOfMonth()
                ->format('Y-m-d');
        }

        // Construir query para projetos pais
        $query = Project::whereNull('parent_project_id');

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

        // Aplicar filtro de projeto específico
        if ($projectId) {
            $query->where('id', $projectId);
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
            // Usar o método getGeneralHoursBalance que já calcula corretamente incluindo filhos
            $projectBalance = $parentProject->getGeneralHoursBalance();
            $hoursBalance += $projectBalance;
        }

        // Arredondar para 2 casas decimais
        $hoursBalance = round($hoursBalance, 2);

        // Calcular horas excedentes (valor absoluto do saldo quando negativo, ou 0 quando positivo/zero)
        $exceededHours = $hoursBalance < 0 ? abs($hoursBalance) : 0;

        // Calcular valor hora PADRÃO (média do campo hourly_rate dos projetos pais)
        // Este é o valor/hora INICIAL mostrado no card (valor hora do projeto)
        $hourlyRate = null;

        if ($projectId) {
            // Se filtrou por projeto específico, retornar apenas o valor desse projeto
            $specificProject = Project::find($projectId);
            if ($specificProject && $specificProject->hourly_rate !== null) {
                $hourlyRate = round((float) $specificProject->hourly_rate, 2);
            }
        } else {
            // Calcular média dos projetos pais que têm hourly_rate preenchido
            $parentProjectsWithRate = $parentProjects->filter(function($project) {
                return $project->hourly_rate !== null && $project->hourly_rate > 0;
            });

            if ($parentProjectsWithRate->count() > 0) {
                $totalRate = $parentProjectsWithRate->sum('hourly_rate');
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

        // Buscar projetos para cálculo de aporte (apenas projetos pais, não filhos)
        $projectsForContribution = Project::query()
            ->whereNull('parent_project_id'); // Apenas projetos pais

        if ($customerId) {
            $projectsForContribution->where('customer_id', $customerId);
        }

        if ($projectId) {
            // Se filtrou por projeto específico, buscar apenas esse projeto (se for pai)
            $projectsForContribution->where('id', $projectId);
        } else {
            // Se não filtrou por projeto, usar os mesmos IDs dos projetos pais já filtrados
            $parentProjectIds = $query->pluck('id')->toArray();
            if (!empty($parentProjectIds)) {
                $projectsForContribution->whereIn('id', $parentProjectIds);
            } else {
                // Se não há projetos pais, não há projetos para calcular
                $projectsForContribution->whereRaw('1 = 0'); // Força query vazia
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

        // Buscar todos os projetos pais com seus relacionamentos necessários
        // Carregar também serviceType dos filhos para filtrar corretamente
        $parentProjects = $query->with(['contractType', 'childProjects.contractType', 'childProjects.serviceType'])->get();

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
                    // Para outros tipos: usar horas apontadas (com corte de data se período fornecido)
                    $parentLoggedMinutes = $parentProject->timesheets()
                        ->whereIn('status', ['approved', 'pending'])
                        ->when($periodEndDate, fn($q) => $q->where('date', '<=', $periodEndDate))
                        ->sum('effort_minutes') ?? 0;
                    $parentLoggedHours = round($parentLoggedMinutes / 60, 2);
                    $consumedHours += $parentLoggedHours;
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
                        // Para projetos fechados: usar total de horas disponíveis (inclui aportes novos + fallback legado)
                        $childTotalHours = $childProject->getTotalAvailableHours();
                        $consumedHours += $childTotalHours;
                    } else {
                        // Para outros tipos: usar horas apontadas (com corte de data se período fornecido)
                        $childLoggedMinutes = $childProject->timesheets()
                            ->whereIn('status', ['approved', 'pending'])
                            ->when($periodEndDate, fn($q) => $q->where('date', '<=', $periodEndDate))
                            ->sum('effort_minutes') ?? 0;
                        $childLoggedHours = round($childLoggedMinutes / 60, 2);
                        $consumedHours += $childLoggedHours;
                    }
                }
            }
        }

        // Arredondar para 2 casas decimais
        $consumedHours = round($consumedHours, 2);

        // Calcular consumo do mês (apontamentos do mês especificado + projetos fechados com start_date no mês especificado)
        $monthConsumedHours = 0;

        // $month e $year já foram extraídos anteriormente (antes do loop de consumo acumulado)
        if ($month && $year) {
            $targetDate = \Carbon\Carbon::create((int) $year, (int) $month, 1);
        } else {
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
                // Consumo do mês = sempre apontamentos do mês (independente do tipo de contrato).
                $parentMonthLoggedMinutes = $parentProject->timesheets()
                    ->whereIn('status', ['approved', 'pending'])
                    ->whereBetween('date', [$monthStart, $monthEnd])
                    ->sum('effort_minutes') ?? 0;
                $monthConsumedHours += round($parentMonthLoggedMinutes / 60, 2);
            }

            if ($parentProject->hasChildProjects()) {
                foreach ($parentProject->childProjects as $childProject) {
                    if ($serviceTypeId && $childProject->service_type_id !== $serviceTypeId) {
                        continue;
                    }
                    $childMonthLoggedMinutes = $childProject->timesheets()
                        ->whereIn('status', ['approved', 'pending'])
                        ->whereBetween('date', [$monthStart, $monthEnd])
                        ->sum('effort_minutes') ?? 0;
                    $monthConsumedHours += round($childMonthLoggedMinutes / 60, 2);
                }
            }
        }

        // Arredondar para 2 casas decimais
        $monthConsumedHours = round($monthConsumedHours, 2);

        // Split do consumo do mês por tipo de serviço (Sustentação vs Projeto) — card.
        $sustIds = \App\Models\ServiceType::where('code', 'sustentacao')
            ->orWhereRaw('LOWER(name) LIKE ?', ['%sustenta%'])->pluck('id')->all();
        $monthProjectIds = collect();
        foreach ($parentProjects as $pp) {
            $monthProjectIds->push($pp->id);
            if ($pp->hasChildProjects()) {
                foreach ($pp->childProjects as $cp) { $monthProjectIds->push($cp->id); }
            }
        }
        $sustProjectIds = \App\Models\Project::whereIn('id', $monthProjectIds->all())
            ->whereIn('service_type_id', $sustIds)->pluck('id')->all();
        $monthMaintenanceHours = round((float) \App\Models\Timesheet::whereIn('project_id', $sustProjectIds)
            ->whereIn('status', ['approved', 'pending'])
            ->whereBetween('date', [$monthStart, $monthEnd])
            ->sum('effort_minutes') / 60, 2);
        $monthProjectsHours = round(max(0, $monthConsumedHours - $monthMaintenanceHours), 2);

        // Se há filtro de período ativo, recalcular exceeded_hours e amount_to_pay
        // como horas excedentes APENAS do mês selecionado.
        //
        // Derivação sem query extra:
        //   consumed_hours já está filtrado com date <= periodEndDate (acumulado até fim do mês)
        //   monthConsumedHours cobre date BETWEEN monthStart AND monthEnd
        //   → consumed_before_month = consumed_hours - monthConsumedHours
        //   → balance_at_start_of_month = (contracted + contributions) - consumed_before_month
        //   → exceeded_in_month = max(0, monthConsumedHours - max(0, balance_at_start_of_month))
        if ($periodEndDate) {
            $consumedBeforeMonth = $consumedHours - $monthConsumedHours;
            $balanceAtStartOfMonth = round(($contractedHours + $contributedHours) - $consumedBeforeMonth, 2);
            $exceededHours = round(max(0, $monthConsumedHours - max(0, $balanceAtStartOfMonth)), 2);
            $hoursBalance = round($balanceAtStartOfMonth - $monthConsumedHours, 2);
            $rateForPayment = $weightedHourlyRate ?? $hourlyRate;
            $amountToPay = ($exceededHours > 0 && $rateForPayment !== null)
                ? round($exceededHours * $rateForPayment, 2)
                : null;
        }

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
                        'changed_by' => [
                            'id' => $log->changedByUser->id,
                            'name' => $log->changedByUser->name,
                            'email' => $log->changedByUser->email,
                        ],
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

        // On Demand não tem horas contratadas/buffer — cobra TODO o consumo do
        // período. Valor a pagar = consumo do mês × valor hora (ignora "excedente").
        $amountToPay = ($monthConsumedHours > 0 && $rateForPayment !== null)
            ? round($monthConsumedHours * $rateForPayment, 2)
            : null;

        return response()->json([
            'success' => true,
            'message' => 'Dados do dashboard obtidos com sucesso',
            'data' => [
                'contracted_hours' => $contractedHours,
                'contributed_hours' => $contributedHours,
                'consumed_hours' => $consumedHours,
                'month_consumed_hours' => $monthConsumedHours,
                'month_maintenance_hours' => $monthMaintenanceHours,
                'month_projects_hours' => $monthProjectsHours,
                'hours_balance' => $hoursBalance,
                'exceeded_hours' => $exceededHours,
                'amount_to_pay' => $amountToPay,
                'hourly_rate' => $hourlyRate,  // Valor/hora INICIAL (para card "Valor Hora")
                'weighted_hourly_rate' => $weightedHourlyRate,  // ✨ Média ponderada (usado no cálculo)
                'contributed_hours_history' => $contributionHistory,
                'customer_id' => $customerId,
                'project_id' => $projectId ? (int) $projectId : null
            ]
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/dashboards/on-demand/projects",
     *     summary="Listar projetos para dashboard de On Demand",
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
    public function onDemandProjects(Request $request): JsonResponse
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

        // Buscar o tipo de serviço "Projeto"
        $projectServiceType = ServiceType::where('code', 'projeto')
            ->orWhere('name', 'Projeto')
            ->first();

        if (!$projectServiceType) {
            return response()->json([
                'success' => false,
                'message' => 'Tipo de serviço "Projeto" não encontrado no sistema'
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
            // Buscar todos os projetos (pais e filhos) que atendem aos filtros e são do tipo "Projeto"
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

            $projects = $query->get();
        }

        $projectsData = $projects->map(function($project) {
            // On Demand não tem horas contratadas/saldo — o consumo do filho é a
            // SOMA dos apontamentos (não rejeitados) do projeto e dos seus filhos.
            $balance = round($project->getGeneralHoursBalance(), 2);
            $projIds = \App\Models\Project::where(function ($q) use ($project) {
                    $q->where('id', $project->id)->orWhere('parent_project_id', $project->id);
                })->whereNull('deleted_at')->pluck('id');
            $consumed = round((float) \App\Models\Timesheet::whereIn('project_id', $projIds)
                ->where('status', '!=', 'rejected')
                ->whereNull('deleted_at')
                ->sum('effort_minutes') / 60, 2);

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
                'hours_balance' => $balance,
                'start_date' => $project->start_date ? $project->start_date->format('Y-m-d') : null,
                'parent_project_id' => $project->parent_project_id,
                // ✨ Novos campos calculados usando hour_contributions table
                'total_available_hours' => $project->getTotalAvailableHours(),
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
     *     path="/api/v1/dashboards/on-demand/projects/{projectId}/tickets",
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
    public function onDemandProjectTickets(Request $request, int $projectId): JsonResponse
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
     *     path="/api/v1/dashboards/on-demand/maintenance/tickets",
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
    public function onDemandMaintenanceTickets(Request $request): JsonResponse
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
            ->whereIn('status', ['approved', 'pending'])
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
     *     path="/api/v1/dashboards/on-demand/maintenance/tickets/{ticketId}/timesheets",
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
    public function onDemandMaintenanceTicketTimesheets(Request $request, string $ticketId): JsonResponse
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
            ->whereIn('status', ['approved', 'pending']);

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
     *     path="/api/v1/dashboards/on-demand/indicators/hours-by-requester",
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
    public function onDemandHoursByRequester(Request $request): JsonResponse
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
            ->whereIn('status', ['approved', 'pending']);

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
            // Sem projeto específico: filtrar pelo tipo de contrato On Demand
            $onDemandType = \App\Models\ContractType::where('code', 'on_demand')->first();
            if ($onDemandType) {
                $timesheetsQuery->whereHas('project', function ($q) use ($onDemandType) {
                    $q->where('contract_type_id', $onDemandType->id);
                });
            }
        }

        // Buscar todos os timesheets que atendem aos critérios
        $timesheets = $timesheetsQuery->get();

        // Agrupar por solicitante
        $requesterHours = [];

        foreach ($timesheets as $timesheet) {
            // Buscar o ticket relacionado
            $ticket = MovideskTicket::where('ticket_id', $timesheet->ticket)->first();

            if ($ticket && $ticket->solicitante) {
                // Extrair nome do solicitante do JSON
                $solicitante = $ticket->solicitante;
                $requesterName = $solicitante['name'] ?? 'Não informado';

                // Converter minutos para horas
                $hours = $timesheet->effort_minutes / 60;

                // Acumular horas por solicitante
                if (!isset($requesterHours[$requesterName])) {
                    $requesterHours[$requesterName] = 0;
                }
                $requesterHours[$requesterName] += $hours;
            }
        }

        // Converter para formato de resposta
        $data = [];
        foreach ($requesterHours as $requester => $totalHours) {
            $data[] = [
                'requester' => $requester,
                'total_hours' => round($totalHours, 2)
            ];
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
     *     path="/api/v1/dashboards/on-demand/indicators/requester-timesheets",
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
    public function onDemandRequesterTimesheets(Request $request): JsonResponse
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
            ->whereIn('status', ['approved', 'pending'])
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
            // Sem projeto específico: filtrar pelo tipo de contrato On Demand
            $onDemandType = \App\Models\ContractType::where('code', 'on_demand')->first();
            if ($onDemandType) {
                $timesheetsQuery->whereHas('project', function ($q) use ($onDemandType) {
                    $q->where('contract_type_id', $onDemandType->id);
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
     *     path="/api/v1/dashboards/on-demand/indicators/hours-by-service",
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
    public function onDemandHoursByService(Request $request): JsonResponse
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
            ->whereIn('status', ['approved', 'pending']);

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
            // Sem projeto específico: filtrar pelo tipo de contrato On Demand
            $onDemandType = \App\Models\ContractType::where('code', 'on_demand')->first();
            if ($onDemandType) {
                $timesheetsQuery->whereHas('project', function ($q) use ($onDemandType) {
                    $q->where('contract_type_id', $onDemandType->id);
                });
            }
        }

        // Buscar todos os timesheets que atendem aos critérios
        $timesheets = $timesheetsQuery->get();

        // Agrupar por serviço/módulo
        $serviceHours = [];

        foreach ($timesheets as $timesheet) {
            // Buscar o ticket relacionado
            $ticket = MovideskTicket::where('ticket_id', $timesheet->ticket)->first();

            if ($ticket && $ticket->servico) {
                // Extrair serviço/módulo
                $service = $ticket->servico;

                // Converter minutos para horas
                $hours = $timesheet->effort_minutes / 60;

                // Acumular horas por serviço
                if (!isset($serviceHours[$service])) {
                    $serviceHours[$service] = 0;
                }
                $serviceHours[$service] += $hours;
            }
        }

        // Converter para formato de resposta
        $data = [];
        foreach ($serviceHours as $service => $totalHours) {
            $data[] = [
                'service' => $service,
                'total_hours' => round($totalHours, 2)
            ];
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
     *     path="/api/v1/dashboards/on-demand/indicators/service-timesheets",
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
    public function onDemandServiceTimesheets(Request $request): JsonResponse
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
            ->whereIn('status', ['approved', 'pending'])
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
            // Sem projeto específico: filtrar pelo tipo de contrato On Demand
            $onDemandType = \App\Models\ContractType::where('code', 'on_demand')->first();
            if ($onDemandType) {
                $timesheetsQuery->whereHas('project', function ($q) use ($onDemandType) {
                    $q->where('contract_type_id', $onDemandType->id);
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
     *     path="/api/v1/dashboards/on-demand/indicators/tickets-by-status",
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
    public function onDemandTicketsByStatus(Request $request): JsonResponse
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
            ->whereIn('status', ['approved', 'pending']);

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
            // Sem projeto específico: filtrar pelo tipo de contrato On Demand
            $onDemandType = \App\Models\ContractType::where('code', 'on_demand')->first();
            if ($onDemandType) {
                $timesheetsQuery->whereHas('project', function ($q) use ($onDemandType) {
                    $q->where('contract_type_id', $onDemandType->id);
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
     *     path="/api/v1/dashboards/on-demand/indicators/status-timesheets",
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
    public function onDemandStatusTimesheets(Request $request): JsonResponse
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
            ->whereIn('status', ['approved', 'pending'])
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
            // Sem projeto específico: filtrar pelo tipo de contrato On Demand
            $onDemandType = \App\Models\ContractType::where('code', 'on_demand')->first();
            if ($onDemandType) {
                $timesheetsQuery->whereHas('project', function ($q) use ($onDemandType) {
                    $q->where('contract_type_id', $onDemandType->id);
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
     *     path="/api/v1/dashboards/on-demand/indicators/tickets-by-level",
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
    public function onDemandTicketsByLevel(Request $request): JsonResponse
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
            ->whereIn('status', ['approved', 'pending']);

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
            // Sem projeto específico: filtrar pelo tipo de contrato On Demand
            $onDemandType = \App\Models\ContractType::where('code', 'on_demand')->first();
            if ($onDemandType) {
                $timesheetsQuery->whereHas('project', function ($q) use ($onDemandType) {
                    $q->where('contract_type_id', $onDemandType->id);
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
     *     path="/api/v1/dashboards/on-demand/indicators/level-timesheets",
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
    public function onDemandLevelTimesheets(Request $request): JsonResponse
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
            ->whereIn('status', ['approved', 'pending'])
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
            // Sem projeto específico: filtrar pelo tipo de contrato On Demand
            $onDemandType = \App\Models\ContractType::where('code', 'on_demand')->first();
            if ($onDemandType) {
                $timesheetsQuery->whereHas('project', function ($q) use ($onDemandType) {
                    $q->where('contract_type_id', $onDemandType->id);
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
     *     path="/api/v1/dashboards/on-demand/indicators/tickets-by-category",
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
    public function onDemandTicketsByCategory(Request $request): JsonResponse
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
            ->whereIn('status', ['approved', 'pending']);

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
            // Sem projeto específico: filtrar pelo tipo de contrato On Demand
            $onDemandType = \App\Models\ContractType::where('code', 'on_demand')->first();
            if ($onDemandType) {
                $timesheetsQuery->whereHas('project', function ($q) use ($onDemandType) {
                    $q->where('contract_type_id', $onDemandType->id);
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
     *     path="/api/v1/dashboards/on-demand/indicators/category-timesheets",
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
    public function onDemandCategoryTimesheets(Request $request): JsonResponse
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
            ->whereIn('status', ['approved', 'pending'])
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
            // Sem projeto específico: filtrar pelo tipo de contrato On Demand
            $onDemandType = \App\Models\ContractType::where('code', 'on_demand')->first();
            if ($onDemandType) {
                $timesheetsQuery->whereHas('project', function ($q) use ($onDemandType) {
                    $q->where('contract_type_id', $onDemandType->id);
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
     *     path="/api/v1/dashboards/on-demand/indicators/tickets-above-8-hours",
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
    public function onDemandTicketsAbove8Hours(Request $request): JsonResponse
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
            ->whereIn('status', ['approved', 'pending']);

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
            // Sem projeto específico: filtrar pelo tipo de contrato On Demand
            $onDemandType = \App\Models\ContractType::where('code', 'on_demand')->first();
            if ($onDemandType) {
                $timesheetsQuery->whereHas('project', function ($q) use ($onDemandType) {
                    $q->where('contract_type_id', $onDemandType->id);
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
     *     path="/api/v1/dashboards/on-demand/indicators/ticket-timesheets",
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
    public function onDemandTicketTimesheets(Request $request): JsonResponse
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
            ->whereIn('status', ['approved', 'pending'])
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
            // Sem projeto específico: filtrar pelo tipo de contrato On Demand
            $onDemandType = \App\Models\ContractType::where('code', 'on_demand')->first();
            if ($onDemandType) {
                $timesheetsQuery->whereHas('project', function ($q) use ($onDemandType) {
                    $q->where('contract_type_id', $onDemandType->id);
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
     *     path="/api/v1/dashboards/on-demand/indicators/monthly-tickets",
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
    public function onDemandMonthlyTickets(Request $request): JsonResponse
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
            ->whereIn('status', ['approved', 'pending']);

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
            // Sem projeto específico: filtrar pelo tipo de contrato On Demand
            $onDemandType = \App\Models\ContractType::where('code', 'on_demand')->first();
            if ($onDemandType) {
                $timesheetsQuery->whereHas('project', function ($q) use ($onDemandType) {
                    $q->where('contract_type_id', $onDemandType->id);
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
     *     path="/api/v1/dashboards/on-demand/indicators/monthly-timesheets",
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
    public function onDemandMonthlyTimesheets(Request $request): JsonResponse
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
            ->whereIn('status', ['approved', 'pending'])
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
            // Sem projeto específico: filtrar pelo tipo de contrato On Demand
            $onDemandType = \App\Models\ContractType::where('code', 'on_demand')->first();
            if ($onDemandType) {
                $timesheetsQuery->whereHas('project', function ($q) use ($onDemandType) {
                    $q->where('contract_type_id', $onDemandType->id);
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
     *     path="/api/v1/dashboards/on-demand/indicators/monthly-consumption",
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
    public function onDemandMonthlyConsumption(Request $request): JsonResponse
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
                // Consumo do mês = sempre apontamentos do mês (independente do tipo de contrato).
                $parentMonthLoggedMinutes = $parentProject->timesheets()
                    ->whereIn('status', ['approved', 'pending'])
                    ->whereBetween('date', [$monthStart, $monthEnd])
                    ->sum('effort_minutes') ?? 0;
                $monthConsumedHours += round($parentMonthLoggedMinutes / 60, 2);

                if ($parentProject->hasChildProjects()) {
                    foreach ($parentProject->childProjects as $childProject) {
                        $childMonthLoggedMinutes = $childProject->timesheets()
                            ->whereIn('status', ['approved', 'pending'])
                            ->whereBetween('date', [$monthStart, $monthEnd])
                            ->sum('effort_minutes') ?? 0;
                        $monthConsumedHours += round($childMonthLoggedMinutes / 60, 2);
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
     *     path="/api/v1/dashboards/on-demand/indicators/monthly-consumption-timesheets",
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
    public function onDemandMonthlyConsumptionTimesheets(Request $request): JsonResponse
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
            ->whereIn('status', ['approved', 'pending'])
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
            // Sem projeto específico: filtrar pelo tipo de contrato On Demand
            $onDemandType = \App\Models\ContractType::where('code', 'on_demand')->first();
            if ($onDemandType) {
                $timesheetsQuery->whereHas('project', function ($q) use ($onDemandType) {
                    $q->where('contract_type_id', $onDemandType->id);
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
}
