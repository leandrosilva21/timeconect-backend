<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\ContractTypeController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectStageController;
use App\Http\Controllers\StageDeliveryController;
use App\Http\Controllers\DeliveryEventController;
use App\Http\Controllers\StageAllocationController;
use App\Http\Controllers\StageHourAporteController;
use App\Http\Controllers\ServiceTypeController;
use App\Http\Controllers\TimesheetController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\ExpenseCategoryController;
use App\Http\Controllers\ExpenseTypeController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\CustomFieldController;
use App\Http\Controllers\ConsultantGroupController;
use App\Http\Controllers\PermissionGroupController;
use App\Http\Controllers\SystemSettingController;
use App\Http\Controllers\MovideskWebhookController;
use App\Http\Controllers\ProjectStatusController;
use App\Http\Controllers\BankHoursFixedController;
use App\Http\Controllers\BankHoursMonthlyController;
use App\Http\Controllers\OnDemandController;
use App\Http\Controllers\ExecutiveController;
use App\Http\Controllers\HourContributionController;
use App\Http\Controllers\PartnerController;
use App\Http\Controllers\PartnerReportController;
use App\Http\Controllers\ConsultantHourBankController;
use App\Http\Controllers\HolidayController;
use App\Http\Controllers\ClientPortalController;
use App\Http\Controllers\FechadoController;
use App\Http\Controllers\ProjectMessageController;
use App\Http\Controllers\SustentacaoController;
use App\Http\Controllers\ContractController;
use App\Http\Controllers\CustomerContactController;
use App\Http\Controllers\ProjectContactController;
use App\Http\Controllers\SkillController;
use App\Http\Controllers\ConsultantSkillController;
use App\Http\Controllers\GapController;
use App\Http\Controllers\CandidateController;
use App\Http\Controllers\SearchController;

/*
|--------------------------------------------------------------------------
| API Routes - v1
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Grupo de rotas versionadas v1
Route::prefix('v1')->group(function () {
    // Rotas públicas (sem autenticação) — throttle obrigatório para mitigar brute-force
    Route::prefix('auth')->group(function () {
        // Autenticação — 5 tentativas por minuto por IP
        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:5,1')
            ->name('auth.login');

        // Recuperação de senha — 3 solicitações por hora por IP
        Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword'])
            ->middleware('throttle:3,60')
            ->name('password.email');
        Route::post('/reset-password', [PasswordResetController::class, 'resetPassword'])
            ->middleware('throttle:5,15')
            ->name('password.reset');
        Route::post('/verify-reset-token', [PasswordResetController::class, 'verifyResetToken'])
            ->middleware('throttle:10,1')
            ->name('password.verify');
    });

    // 🎫 WEBHOOKS - Rotas públicas para receber notificações externas
    Route::post('/webhooks/movidesk/ticket', [MovideskWebhookController::class, 'handleTicket'])
        ->name('webhooks.movidesk.ticket');

    /**
     * @OA\Get(
     *     path="/api/v1/health",
     *     tags={"Sistema"},
     *     summary="Status da API",
     *     description="Verifica se a API está funcionando",
     *     @OA\Response(
     *         response=200,
     *         description="API funcionando corretamente",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="ok"),
     *             @OA\Property(property="message", type="string", example="API funcionando corretamente"),
     *             @OA\Property(property="timestamp", type="string", example="2024-01-01T00:00:00.000000Z"),
     *             @OA\Property(property="version", type="string", example="1.0.0")
     *         )
     *     )
     * )
     */
    // Rota para verificar se API está funcionando
    Route::get('/health', function () {
        return response()->json([
            'status'    => 'ok',
            'timestamp' => now(),
        ]);
    })->name('api.health');

    // Rotas protegidas (com autenticação Sanctum)
    // 👤 CADASTRO PÚBLICO DE CANDIDATO — sem auth, com throttle pra evitar spam
    Route::get('/candidates/form-data', [CandidateController::class, 'formData'])
        ->middleware('throttle:30,1')
        ->name('candidates.form-data');
    Route::post('/candidates',          [CandidateController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('candidates.store');

    Route::middleware('auth:sanctum')->group(function () {
        // Dados do usuário
        Route::get('/user', [AuthController::class, 'user'])->name('user.profile');
        Route::put('/user/profile', [AuthController::class, 'updateProfile'])->name('user.update');
        Route::put('/user/theme-preference', [AuthController::class, 'updateThemePreference'])->name('user.theme-preference');

        // Cards atribuídos ao usuário corrente (visão consultor — Bloco F)
        Route::get('/me/cards', [MeController::class, 'cards'])->middleware('block.cliente')->name('me.cards');

        // Autenticação
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('/auth/logout-all', [AuthController::class, 'logoutAll'])->name('auth.logout.all');
        Route::get('/auth/verify-token', [AuthController::class, 'verifyToken'])->name('auth.verify');
        Route::get('/auth/permissions', [AuthController::class, 'getPermissions'])->name('auth.permissions');

        // === DASHBOARDS ===
        // Dashboard de Banco de Horas Fixo - Protegido por permissão dashboards.view
        Route::middleware('permission.or.admin:dashboards.view')->group(function () {
            Route::get('/dashboards/bank-hours-fixed', [BankHoursFixedController::class, 'bankHoursFixed'])
                ->name('dashboards.bank-hours-fixed');
            Route::get('/dashboards/bank-hours-fixed/projects', [BankHoursFixedController::class, 'bankHoursFixedProjects'])
                ->name('dashboards.bank-hours-fixed.projects');
            Route::get('/dashboards/bank-hours-fixed/projects/{projectId}/tickets', [BankHoursFixedController::class, 'bankHoursFixedProjectTickets'])
                ->name('dashboards.bank-hours-fixed.projects.tickets');
            Route::get('/dashboards/bank-hours-fixed/maintenance/tickets', [BankHoursFixedController::class, 'bankHoursFixedMaintenanceTickets'])
                ->name('dashboards.bank-hours-fixed.maintenance.tickets');
            Route::get('/dashboards/bank-hours-fixed/maintenance/tickets/{ticketId}/timesheets', [BankHoursFixedController::class, 'bankHoursFixedMaintenanceTicketTimesheets'])
                ->name('dashboards.bank-hours-fixed.maintenance.tickets.timesheets');
            Route::get('/dashboards/bank-hours-fixed/indicators/hours-by-requester', [BankHoursFixedController::class, 'bankHoursFixedHoursByRequester'])
                ->name('dashboards.bank-hours-fixed.indicators.hours-by-requester');
            Route::get('/dashboards/bank-hours-fixed/indicators/requester-timesheets', [BankHoursFixedController::class, 'bankHoursFixedRequesterTimesheets'])
                ->name('dashboards.bank-hours-fixed.indicators.requester-timesheets');
            Route::get('/dashboards/bank-hours-fixed/indicators/hours-by-service', [BankHoursFixedController::class, 'bankHoursFixedHoursByService'])
                ->name('dashboards.bank-hours-fixed.indicators.hours-by-service');
            Route::get('/dashboards/bank-hours-fixed/indicators/service-timesheets', [BankHoursFixedController::class, 'bankHoursFixedServiceTimesheets'])
                ->name('dashboards.bank-hours-fixed.indicators.service-timesheets');
            Route::get('/dashboards/bank-hours-fixed/indicators/tickets-by-status', [BankHoursFixedController::class, 'bankHoursFixedTicketsByStatus'])
                ->name('dashboards.bank-hours-fixed.indicators.tickets-by-status');
            Route::get('/dashboards/bank-hours-fixed/indicators/status-timesheets', [BankHoursFixedController::class, 'bankHoursFixedStatusTimesheets'])
                ->name('dashboards.bank-hours-fixed.indicators.status-timesheets');
            Route::get('/dashboards/bank-hours-fixed/indicators/tickets-by-level', [BankHoursFixedController::class, 'bankHoursFixedTicketsByLevel'])
                ->name('dashboards.bank-hours-fixed.indicators.tickets-by-level');
            Route::get('/dashboards/bank-hours-fixed/indicators/level-timesheets', [BankHoursFixedController::class, 'bankHoursFixedLevelTimesheets'])
                ->name('dashboards.bank-hours-fixed.indicators.level-timesheets');
            Route::get('/dashboards/bank-hours-fixed/indicators/tickets-by-category', [BankHoursFixedController::class, 'bankHoursFixedTicketsByCategory'])
                ->name('dashboards.bank-hours-fixed.indicators.tickets-by-category');
            Route::get('/dashboards/bank-hours-fixed/indicators/category-timesheets', [BankHoursFixedController::class, 'bankHoursFixedCategoryTimesheets'])
                ->name('dashboards.bank-hours-fixed.indicators.category-timesheets');
            Route::get('/dashboards/bank-hours-fixed/indicators/tickets-above-8-hours', [BankHoursFixedController::class, 'bankHoursFixedTicketsAbove8Hours'])
                ->name('dashboards.bank-hours-fixed.indicators.tickets-above-8-hours');
            Route::get('/dashboards/bank-hours-fixed/indicators/ticket-timesheets', [BankHoursFixedController::class, 'bankHoursFixedTicketTimesheets'])
                ->name('dashboards.bank-hours-fixed.indicators.ticket-timesheets');
            Route::get('/dashboards/bank-hours-fixed/indicators/monthly-tickets', [BankHoursFixedController::class, 'bankHoursFixedMonthlyTickets'])
                ->name('dashboards.bank-hours-fixed.indicators.monthly-tickets');
            Route::get('/dashboards/bank-hours-fixed/indicators/monthly-timesheets', [BankHoursFixedController::class, 'bankHoursFixedMonthlyTimesheets'])
                ->name('dashboards.bank-hours-fixed.indicators.monthly-timesheets');
            Route::get('/dashboards/bank-hours-fixed/indicators/monthly-consumption', [BankHoursFixedController::class, 'bankHoursFixedMonthlyConsumption'])
                ->name('dashboards.bank-hours-fixed.indicators.monthly-consumption');
            Route::get('/dashboards/bank-hours-fixed/indicators/monthly-consumption-timesheets', [BankHoursFixedController::class, 'bankHoursFixedMonthlyConsumptionTimesheets'])
                ->name('dashboards.bank-hours-fixed.indicators.monthly-consumption-timesheets');
        });

        // Dashboard de Banco de Horas Mensais - Protegido por permissão dashboards.view
        Route::middleware('permission.or.admin:dashboards.view')->group(function () {
            Route::get('/dashboards/bank-hours-monthly', [BankHoursMonthlyController::class, 'bankHoursMonthly'])
                ->name('dashboards.bank-hours-monthly');
            Route::get('/dashboards/bank-hours-monthly/projects', [BankHoursMonthlyController::class, 'bankHoursMonthlyProjects'])
                ->name('dashboards.bank-hours-monthly.projects');
            Route::get('/dashboards/bank-hours-monthly/projects/{projectId}/tickets', [BankHoursMonthlyController::class, 'bankHoursMonthlyProjectTickets'])
                ->name('dashboards.bank-hours-monthly.projects.tickets');
            Route::get('/dashboards/bank-hours-monthly/maintenance/tickets', [BankHoursMonthlyController::class, 'bankHoursMonthlyMaintenanceTickets'])
                ->name('dashboards.bank-hours-monthly.maintenance.tickets');
            Route::get('/dashboards/bank-hours-monthly/maintenance/tickets/{ticketId}/timesheets', [BankHoursMonthlyController::class, 'bankHoursMonthlyMaintenanceTicketTimesheets'])
                ->name('dashboards.bank-hours-monthly.maintenance.tickets.timesheets');
            Route::get('/dashboards/bank-hours-monthly/indicators/hours-by-requester', [BankHoursMonthlyController::class, 'bankHoursMonthlyHoursByRequester'])
                ->name('dashboards.bank-hours-monthly.indicators.hours-by-requester');
            Route::get('/dashboards/bank-hours-monthly/indicators/requester-timesheets', [BankHoursMonthlyController::class, 'bankHoursMonthlyRequesterTimesheets'])
                ->name('dashboards.bank-hours-monthly.indicators.requester-timesheets');
            Route::get('/dashboards/bank-hours-monthly/indicators/hours-by-service', [BankHoursMonthlyController::class, 'bankHoursMonthlyHoursByService'])
                ->name('dashboards.bank-hours-monthly.indicators.hours-by-service');
            Route::get('/dashboards/bank-hours-monthly/indicators/service-timesheets', [BankHoursMonthlyController::class, 'bankHoursMonthlyServiceTimesheets'])
                ->name('dashboards.bank-hours-monthly.indicators.service-timesheets');
            Route::get('/dashboards/bank-hours-monthly/indicators/tickets-by-status', [BankHoursMonthlyController::class, 'bankHoursMonthlyTicketsByStatus'])
                ->name('dashboards.bank-hours-monthly.indicators.tickets-by-status');
            Route::get('/dashboards/bank-hours-monthly/indicators/status-timesheets', [BankHoursMonthlyController::class, 'bankHoursMonthlyStatusTimesheets'])
                ->name('dashboards.bank-hours-monthly.indicators.status-timesheets');
            Route::get('/dashboards/bank-hours-monthly/indicators/tickets-by-level', [BankHoursMonthlyController::class, 'bankHoursMonthlyTicketsByLevel'])
                ->name('dashboards.bank-hours-monthly.indicators.tickets-by-level');
            Route::get('/dashboards/bank-hours-monthly/indicators/level-timesheets', [BankHoursMonthlyController::class, 'bankHoursMonthlyLevelTimesheets'])
                ->name('dashboards.bank-hours-monthly.indicators.level-timesheets');
            Route::get('/dashboards/bank-hours-monthly/indicators/tickets-by-category', [BankHoursMonthlyController::class, 'bankHoursMonthlyTicketsByCategory'])
                ->name('dashboards.bank-hours-monthly.indicators.tickets-by-category');
            Route::get('/dashboards/bank-hours-monthly/indicators/category-timesheets', [BankHoursMonthlyController::class, 'bankHoursMonthlyCategoryTimesheets'])
                ->name('dashboards.bank-hours-monthly.indicators.category-timesheets');
            Route::get('/dashboards/bank-hours-monthly/indicators/tickets-above-8-hours', [BankHoursMonthlyController::class, 'bankHoursMonthlyTicketsAbove8Hours'])
                ->name('dashboards.bank-hours-monthly.indicators.tickets-above-8-hours');
            Route::get('/dashboards/bank-hours-monthly/indicators/ticket-timesheets', [BankHoursMonthlyController::class, 'bankHoursMonthlyTicketTimesheets'])
                ->name('dashboards.bank-hours-monthly.indicators.ticket-timesheets');
            Route::get('/dashboards/bank-hours-monthly/indicators/monthly-tickets', [BankHoursMonthlyController::class, 'bankHoursMonthlyMonthlyTickets'])
                ->name('dashboards.bank-hours-monthly.indicators.monthly-tickets');
            Route::get('/dashboards/bank-hours-monthly/indicators/monthly-timesheets', [BankHoursMonthlyController::class, 'bankHoursMonthlyMonthlyTimesheets'])
                ->name('dashboards.bank-hours-monthly.indicators.monthly-timesheets');
            Route::get('/dashboards/bank-hours-monthly/indicators/monthly-consumption', [BankHoursMonthlyController::class, 'bankHoursMonthlyMonthlyConsumption'])
                ->name('dashboards.bank-hours-monthly.indicators.monthly-consumption');
            Route::get('/dashboards/bank-hours-monthly/indicators/monthly-consumption-timesheets', [BankHoursMonthlyController::class, 'bankHoursMonthlyMonthlyConsumptionTimesheets'])
                ->name('dashboards.bank-hours-monthly.indicators.monthly-consumption-timesheets');
        });

        // Dashboard de Sustentação On Demand - Protegido por permissão dashboards.view
        Route::middleware('permission.or.admin:dashboards.view')->group(function () {
            Route::get('/dashboards/on-demand', [OnDemandController::class, 'onDemand'])
                ->name('dashboards.on-demand');
            Route::get('/dashboards/on-demand/projects', [OnDemandController::class, 'onDemandProjects'])
                ->name('dashboards.on-demand.projects');
            Route::get('/dashboards/on-demand/projects/{projectId}/tickets', [OnDemandController::class, 'onDemandProjectTickets'])
                ->name('dashboards.on-demand.projects.tickets');
            Route::get('/dashboards/on-demand/maintenance/tickets', [OnDemandController::class, 'onDemandMaintenanceTickets'])
                ->name('dashboards.on-demand.maintenance.tickets');
            Route::get('/dashboards/on-demand/maintenance/tickets/{ticketId}/timesheets', [OnDemandController::class, 'onDemandMaintenanceTicketTimesheets'])
                ->name('dashboards.on-demand.maintenance.tickets.timesheets');
            Route::get('/dashboards/on-demand/indicators/hours-by-requester', [OnDemandController::class, 'onDemandHoursByRequester'])
                ->name('dashboards.on-demand.indicators.hours-by-requester');
            Route::get('/dashboards/on-demand/indicators/requester-timesheets', [OnDemandController::class, 'onDemandRequesterTimesheets'])
                ->name('dashboards.on-demand.indicators.requester-timesheets');
            Route::get('/dashboards/on-demand/indicators/hours-by-service', [OnDemandController::class, 'onDemandHoursByService'])
                ->name('dashboards.on-demand.indicators.hours-by-service');
            Route::get('/dashboards/on-demand/indicators/service-timesheets', [OnDemandController::class, 'onDemandServiceTimesheets'])
                ->name('dashboards.on-demand.indicators.service-timesheets');
            Route::get('/dashboards/on-demand/indicators/tickets-by-status', [OnDemandController::class, 'onDemandTicketsByStatus'])
                ->name('dashboards.on-demand.indicators.tickets-by-status');
            Route::get('/dashboards/on-demand/indicators/status-timesheets', [OnDemandController::class, 'onDemandStatusTimesheets'])
                ->name('dashboards.on-demand.indicators.status-timesheets');
            Route::get('/dashboards/on-demand/indicators/tickets-by-level', [OnDemandController::class, 'onDemandTicketsByLevel'])
                ->name('dashboards.on-demand.indicators.tickets-by-level');
            Route::get('/dashboards/on-demand/indicators/level-timesheets', [OnDemandController::class, 'onDemandLevelTimesheets'])
                ->name('dashboards.on-demand.indicators.level-timesheets');
            Route::get('/dashboards/on-demand/indicators/tickets-by-category', [OnDemandController::class, 'onDemandTicketsByCategory'])
                ->name('dashboards.on-demand.indicators.tickets-by-category');
            Route::get('/dashboards/on-demand/indicators/category-timesheets', [OnDemandController::class, 'onDemandCategoryTimesheets'])
                ->name('dashboards.on-demand.indicators.category-timesheets');
            Route::get('/dashboards/on-demand/indicators/tickets-above-8-hours', [OnDemandController::class, 'onDemandTicketsAbove8Hours'])
                ->name('dashboards.on-demand.indicators.tickets-above-8-hours');
            Route::get('/dashboards/on-demand/indicators/ticket-timesheets', [OnDemandController::class, 'onDemandTicketTimesheets'])
                ->name('dashboards.on-demand.indicators.ticket-timesheets');
            Route::get('/dashboards/on-demand/indicators/monthly-tickets', [OnDemandController::class, 'onDemandMonthlyTickets'])
                ->name('dashboards.on-demand.indicators.monthly-tickets');
            Route::get('/dashboards/on-demand/indicators/monthly-timesheets', [OnDemandController::class, 'onDemandMonthlyTimesheets'])
                ->name('dashboards.on-demand.indicators.monthly-timesheets');
            Route::get('/dashboards/on-demand/indicators/monthly-consumption', [OnDemandController::class, 'onDemandMonthlyConsumption'])
                ->name('dashboards.on-demand.indicators.monthly-consumption');
            Route::get('/dashboards/on-demand/indicators/monthly-consumption-timesheets', [OnDemandController::class, 'onDemandMonthlyConsumptionTimesheets'])
                ->name('dashboards.on-demand.indicators.monthly-consumption-timesheets');
        });

        // Dashboard de Projetos Fechados - Protegido por permissão dashboards.view
        Route::middleware('permission.or.admin:dashboards.view')->group(function () {
            Route::get('/dashboards/fechado', [FechadoController::class, 'fechado'])
                ->name('dashboards.fechado');
            Route::get('/dashboards/fechado/projects', [FechadoController::class, 'fechadoProjects'])
                ->name('dashboards.fechado.projects');
            Route::get('/dashboards/fechado/expenses', [FechadoController::class, 'fechadoExpenses'])
                ->name('dashboards.fechado.expenses');
        });

        // Alteração de senha
        Route::post('/auth/change-password', [AuthController::class, 'changePassword'])
            ->name('auth.change-password');
        Route::post('/auth/change-temporary-password', [AuthController::class, 'changeTemporaryPassword'])
            ->name('auth.change-temporary-password');

        // 🏆 EXECUTIVES - Gestão de executivos
        Route::get('/executives', [ExecutiveController::class, 'index'])->name('executives.index');
        Route::get('/executives/all', [ExecutiveController::class, 'all'])->name('executives.all');
        Route::middleware('permission.or.admin:users.update')->group(function () {
            Route::patch('/executives/{user}', [ExecutiveController::class, 'toggle'])->name('executives.toggle');
        });

        // 🏢 CLIENT PORTAL
        Route::get('/client/portal', [ClientPortalController::class, 'portal'])->name('client.portal');
        Route::get('/client/portal/customers', [ClientPortalController::class, 'customers'])->name('client.portal.customers');
        Route::get('/client/portal/projects/{projectId}/operational-summary', [ClientPortalController::class, 'operationalSummary'])->name('client.portal.project-operational-summary');

        // 👥 CUSTOMERS - Protegido por permissões específicas (Admins sempre têm acesso)
        Route::middleware('permission.or.admin:customers.view')->group(function () {
            Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
            Route::get('/customers/user-linked', [CustomerController::class, 'getUserLinkedCustomers'])->name('customers.user-linked');
            Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
        });

        Route::middleware('permission.or.admin:customers.create')->group(function () {
            Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store');
        });

        Route::middleware('permission.or.admin:customers.update')->group(function () {
            Route::put('/customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
        });

        Route::middleware('permission.or.admin:customers.delete')->group(function () {
            Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->name('customers.destroy');
        });

        // 🔧 SERVICE TYPES - Tipos de Serviço
        // Rotas de leitura - acessíveis a todos os usuários autenticados
        Route::get('/service-types', [ServiceTypeController::class, 'index'])->name('service-types.index');
        Route::get('/service-types/{id}', [ServiceTypeController::class, 'show'])->name('service-types.show');

        // Rotas de escrita - protegidas por permissões
        Route::middleware('permission.or.admin:service_types.create')->group(function () {
            Route::post('/service-types', [ServiceTypeController::class, 'store'])->name('service-types.store');
        });

        Route::middleware('permission.or.admin:service_types.update')->group(function () {
            Route::put('/service-types/{id}', [ServiceTypeController::class, 'update'])->name('service-types.update');
        });

        Route::middleware('permission.or.admin:service_types.delete')->group(function () {
            Route::delete('/service-types/{id}', [ServiceTypeController::class, 'destroy'])->name('service-types.destroy');
        });

        // 📋 CONTRACT TYPES - Tipos de Contrato
        // Rotas de leitura - acessíveis a todos os usuários autenticados
        Route::get('/contract-types', [ContractTypeController::class, 'index'])->name('contract-types.index');
        Route::get('/contract-types/{id}', [ContractTypeController::class, 'show'])->name('contract-types.show');

        // Rotas de escrita - protegidas por permissões
        Route::middleware('permission.or.admin:contract_types.create')->group(function () {
            Route::post('/contract-types', [ContractTypeController::class, 'store'])->name('contract-types.store');
        });

        Route::middleware('permission.or.admin:contract_types.update')->group(function () {
            Route::put('/contract-types/{id}', [ContractTypeController::class, 'update'])->name('contract-types.update');
        });

        Route::middleware('permission.or.admin:contract_types.delete')->group(function () {
            Route::delete('/contract-types/{id}', [ContractTypeController::class, 'destroy'])->name('contract-types.destroy');
        });

        // 📋 PROJECT STATUSES - Status de Projetos
        // Rotas de leitura - acessíveis a todos os usuários autenticados
        Route::get('/project-statuses', [ProjectStatusController::class, 'index'])->name('project-statuses.index');
        Route::get('/project-statuses/{id}', [ProjectStatusController::class, 'show'])->name('project-statuses.show');

        // Rotas de escrita - protegidas por permissões
        Route::middleware('permission.or.admin:project_statuses.create')->group(function () {
            Route::post('/project-statuses', [ProjectStatusController::class, 'store'])->name('project-statuses.store');
        });

        Route::middleware('permission.or.admin:project_statuses.update')->group(function () {
            Route::put('/project-statuses/{id}', [ProjectStatusController::class, 'update'])->name('project-statuses.update');
        });

        Route::middleware('permission.or.admin:project_statuses.delete')->group(function () {
            Route::delete('/project-statuses/{id}', [ProjectStatusController::class, 'destroy'])->name('project-statuses.destroy');
        });

        // 🏗️ PROJECTS - Protegido por permissões específicas (Admins sempre têm acesso)

        // Enum values - endpoint público dentro da autenticação
        Route::get('/projects/enum-values', [ProjectController::class, 'enumValues'])->name('projects.enum-values');
        Route::get('/projects/next-code', [ProjectController::class, 'nextCode'])->name('projects.next-code');

        // Projetos do próprio usuário (sem permissão especial — filtra automaticamente pelo consultor logado)
        Route::get('/my-projects', [ProjectController::class, 'myProjects'])->name('projects.my');

        Route::middleware('permission.or.admin:projects.view')->group(function () {
            Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
            Route::get('/projects/ic-summary', [ProjectController::class, 'icSummary'])->name('projects.ic-summary');
            Route::get('/projects/ic-analytics', [ProjectController::class, 'icAnalytics'])->name('projects.ic-analytics');
            Route::get('/projects/hours-per-consultant', [ProjectController::class, 'hoursPerConsultant'])->name('projects.hours-per-consultant');
            Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
            Route::get('/projects/{project}/change-history', [ProjectController::class, 'changeHistory'])->name('projects.change-history');
            Route::get('/projects/{project}/contract-request', [ProjectController::class, 'contractRequest'])->name('projects.contract-request');
        });

        Route::middleware('permission.or.admin:projects.view_costs')->group(function () {
            Route::get('/projects/{project}/cost-summary', [ProjectController::class, 'costSummary'])->name('projects.cost-summary');
        });

        Route::middleware('permission.or.admin:projects.view')->group(function () {
            Route::get('/projects/{project}/available-hours', [ProjectController::class, 'availableHours'])->name('projects.available-hours');
        });

        Route::middleware('permission.or.admin:projects.create')->group(function () {
            Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
            Route::post('/investimento-interno/projects', [ProjectController::class, 'storeInternalProject'])->name('projects.store-internal');
        });

        Route::middleware('permission.or.admin:projects.update')->group(function () {
            Route::patch('/projects/{project}/status', [ProjectController::class, 'updateStatus'])->name('projects.update-status');
            Route::put('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
            Route::patch('/projects/{project}', [ProjectController::class, 'update'])->name('projects.patch');
            Route::put('/projects/{project}/sold-hours-history/{history}', [ProjectController::class, 'updateSoldHoursHistory'])->name('projects.sold-hours-history.update');
            Route::delete('/projects/{project}/sold-hours-history/{history}', [ProjectController::class, 'destroySoldHoursHistory'])->name('projects.sold-hours-history.destroy');
            Route::put('/projects/{project}/change-history/{log}', [ProjectController::class, 'updateChangeHistory'])->name('projects.change-history.update');
            Route::delete('/projects/{project}/change-history/{log}', [ProjectController::class, 'destroyChangeHistory'])->name('projects.change-history.destroy');
            Route::post('/projects/{project}/detach-from-parent', [ProjectController::class, 'detachFromParent'])->name('projects.detach-from-parent');
            Route::post('/projects/{project}/attach-to-parent',  [ProjectController::class, 'attachToParent'])->name('projects.attach-to-parent');
        });

        Route::middleware('permission.or.admin:projects.delete')->group(function () {
            Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');
        });

        // 🧱 PROJECT STAGES + STAGE DELIVERIES + DELIVERY EVENTS
        // Etapas (frentes paralelas) e entregas (cards do kanban operacional).
        // Autorização granular via Policy do Project (read/update do Project).
        Route::middleware(['permission.or.admin:projects.view', 'block.cliente'])->group(function () {
            Route::get('/projects/{project}/stages', [ProjectStageController::class, 'index'])->name('stages.index');
            Route::get('/stages/{stage}', [ProjectStageController::class, 'show'])->name('stages.show');
            Route::get('/stages/{stage}/activity', [ProjectStageController::class, 'activity'])->name('stages.activity');
            Route::get('/stages/{stage}/deliveries', [StageDeliveryController::class, 'index'])->name('deliveries.index');
            Route::get('/deliveries/{delivery}', [StageDeliveryController::class, 'show'])->name('deliveries.show');
            Route::get('/deliveries/{delivery}/events', [DeliveryEventController::class, 'index'])->name('deliveries.events');
            Route::get('/stages/{stage}/allocations', [StageAllocationController::class, 'index'])->name('stages.allocations.index');
            Route::get('/stages/{stage}/aportes', [StageHourAporteController::class, 'index'])->name('stages.aportes.index');
        });
        Route::middleware(['permission.or.admin:projects.update', 'block.cliente'])->group(function () {
            Route::post('/projects/{project}/stages', [ProjectStageController::class, 'store'])->name('stages.store');
            Route::patch('/stages/{stage}', [ProjectStageController::class, 'update'])->name('stages.update');
            Route::delete('/stages/{stage}', [ProjectStageController::class, 'destroy'])->name('stages.destroy');
            Route::post('/projects/{project}/stages/reorder', [ProjectStageController::class, 'reorder'])->name('stages.reorder');

            Route::post('/stages/{stage}/deliveries', [StageDeliveryController::class, 'store'])->name('deliveries.store');
            Route::patch('/deliveries/{delivery}', [StageDeliveryController::class, 'update'])->name('deliveries.update');
            Route::delete('/deliveries/{delivery}', [StageDeliveryController::class, 'destroy'])->name('deliveries.destroy');
            Route::post('/deliveries/{delivery}/move', [StageDeliveryController::class, 'move'])->name('deliveries.move');
            Route::post('/stages/{stage}/allocations', [StageAllocationController::class, 'store'])->name('stages.allocations.store');
            Route::post('/stages/{stage}/aportes', [StageHourAporteController::class, 'store'])->name('stages.aportes.store');
            Route::patch('/allocations/{allocation}', [StageAllocationController::class, 'update'])->name('allocations.update');
            Route::delete('/allocations/{allocation}', [StageAllocationController::class, 'destroy'])->name('allocations.destroy');
        });

        // 💰 HOUR CONTRIBUTIONS - Aportes de Horas (vinculados a projetos)
        Route::middleware('permission.or.admin:projects.view')->group(function () {
            Route::get('/projects/{project}/hour-contributions', [HourContributionController::class, 'index'])->name('hour-contributions.index');
        });

        Route::middleware('permission.or.admin:projects.update')->group(function () {
            Route::post('/projects/{project}/hour-contributions', [HourContributionController::class, 'store'])->name('hour-contributions.store');
            Route::put('/projects/{project}/hour-contributions/{contribution}', [HourContributionController::class, 'update'])->name('hour-contributions.update');
            Route::delete('/projects/{project}/hour-contributions/{contribution}', [HourContributionController::class, 'destroy'])->name('hour-contributions.destroy');
        });

        // ⏰ TIMESHEETS - Protegido por permissões específicas (Admins sempre têm acesso)

        // Rotas que qualquer usuário autenticado pode acessar (com lógica de permissão no controller)
        Route::get('/timesheets', [TimesheetController::class, 'index'])->name('timesheets.index');
        Route::get('/timesheets/export', [TimesheetController::class, 'export'])->name('timesheets.export');
        Route::put('/timesheets/bulk-extra-pct', [TimesheetController::class, 'bulkExtraPct'])->name('timesheets.bulk-extra-pct');
        Route::post('/timesheets/reprocess-movidesk', [TimesheetController::class, 'reprocessMovidesk'])->name('timesheets.reprocess-movidesk');
        Route::get('/timesheets/summary-by-ticket', [TimesheetController::class, 'summaryByTicket'])->name('timesheets.summary-by-ticket');
        Route::get('/timesheets/{timesheet}', [TimesheetController::class, 'show'])->name('timesheets.show');

        // Histórico de alterações de um apontamento específico (admin/coord)
        Route::middleware('permission.or.admin:hours.approve')->group(function () {
            Route::get('/timesheets/{id}/logs', [\App\Http\Controllers\TimesheetLogController::class, 'forTimesheet'])->name('timesheets.logs');
            Route::get('/timesheet-logs', [\App\Http\Controllers\TimesheetLogController::class, 'index'])->name('timesheet-logs.index');
        });

        // Qualquer usuário autenticado (exceto Cliente — verificado no controller) pode criar apontamentos
        Route::post('/timesheets', [TimesheetController::class, 'store'])->name('timesheets.store');

        // Atualização e exclusão verificadas no controller baseado na propriedade
        Route::put('/timesheets/{timesheet}', [TimesheetController::class, 'update'])->name('timesheets.update');
        Route::patch('/timesheets/{timesheet}', [TimesheetController::class, 'update'])->name('timesheets.patch');
        Route::delete('/timesheets/{timesheet}', [TimesheetController::class, 'destroy'])->name('timesheets.destroy');

        // Aprovação, liberação e rejeição
        Route::middleware('permission.or.admin:hours.approve')->group(function () {
            Route::post('/timesheets/{timesheet}/approve', [TimesheetController::class, 'approve'])->name('timesheets.approve');
            Route::post('/timesheets/{timesheet}/release', [TimesheetController::class, 'release'])->name('timesheets.release');
            Route::post('/timesheets/{timesheet}/reverse-release', [TimesheetController::class, 'reverseRelease'])->name('timesheets.reverse-release');
        });

        Route::middleware('permission.or.admin:hours.reject')->group(function () {
            Route::post('/timesheets/{timesheet}/reject', [TimesheetController::class, 'reject'])->name('timesheets.reject');
            Route::post('/timesheets/{timesheet}/request-adjustment', [TimesheetController::class, 'requestAdjustment'])->name('timesheets.request-adjustment');
            Route::post('/timesheets/{timesheet}/reverse-approval', [TimesheetController::class, 'reverseApproval'])->name('timesheets.reverse-approval');
            Route::post('/timesheets/{timesheet}/reverse-rejection', [TimesheetController::class, 'reverseRejection'])->name('timesheets.reverse-rejection');
        });

        // 💰 DESPESAS - Protegido por permissões específicas (Admins sempre têm acesso)
        Route::get('/expenses', [ExpenseController::class, 'index'])->name('expenses.index');
        Route::get('/expenses/export', [ExpenseController::class, 'export'])->name('expenses.export');
        Route::get('/expenses/{expense}', [ExpenseController::class, 'show'])->name('expenses.show');

        // Qualquer usuário autenticado (exceto Cliente — verificado no controller) pode registrar despesas
        Route::post('/expenses', [ExpenseController::class, 'store'])->name('expenses.store');

        Route::put('/expenses/{expense}', [ExpenseController::class, 'update'])->name('expenses.update');
        Route::patch('/expenses/{expense}', [ExpenseController::class, 'update'])->name('expenses.patch');
        Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy'])->name('expenses.destroy');

        Route::middleware('permission.or.admin:expenses.approve')->group(function () {
            Route::post('/expenses/{expense}/approve', [ExpenseController::class, 'approve'])->name('expenses.approve');
        });

        Route::middleware('permission.or.admin:expenses.reject')->group(function () {
            Route::post('/expenses/{expense}/reject', [ExpenseController::class, 'reject'])->name('expenses.reject');
            Route::post('/expenses/{expense}/request-adjustment', [ExpenseController::class, 'requestAdjustment'])->name('expenses.request-adjustment');
            Route::post('/expenses/{expense}/reverse-approval', [ExpenseController::class, 'reverseApproval'])->name('expenses.reverse-approval');
            Route::post('/expenses/{expense}/reverse-rejection', [ExpenseController::class, 'reverseRejection'])->name('expenses.reverse-rejection');
        });

        Route::post('/expenses/{expense}/upload-receipt', [ExpenseController::class, 'uploadReceipt'])->name('expenses.upload-receipt');
        Route::get('/expenses/{expense}/receipt', [ExpenseController::class, 'downloadReceipt'])->name('expenses.download-receipt');

        Route::post('/expenses/{expense}/set-paid', [ExpenseController::class, 'setPaid'])->name('expenses.set-paid');

        Route::get('/timesheets/{id}/attachment', [TimesheetController::class, 'downloadAttachment'])->name('timesheets.download-attachment');

        // 📝 CATEGORIAS DE DESPESAS
        // Rotas de leitura - acessíveis a todos os usuários autenticados
        Route::get('/expense-categories', [ExpenseCategoryController::class, 'index'])->name('expense-categories.index');
        Route::get('/expense-categories/tree', [ExpenseCategoryController::class, 'tree'])->name('expense-categories.tree');
        Route::get('/expense-categories/main', [ExpenseCategoryController::class, 'main'])->name('expense-categories.main');
        Route::get('/expense-categories/{id}', [ExpenseCategoryController::class, 'show'])->name('expense-categories.show');
        Route::get('/expense-categories/{parentId}/subcategories', [ExpenseCategoryController::class, 'subcategories'])->name('expense-categories.subcategories');

        // Rotas de escrita - protegidas por permissões
        Route::middleware('permission.or.admin:expense_categories.create')->group(function () {
            Route::post('/expense-categories', [ExpenseCategoryController::class, 'store'])->name('expense-categories.store');
        });

        Route::middleware('permission.or.admin:expense_categories.update')->group(function () {
            Route::put('/expense-categories/{id}', [ExpenseCategoryController::class, 'update'])->name('expense-categories.update');
        });

        Route::middleware('permission.or.admin:expense_categories.delete')->group(function () {
            Route::delete('/expense-categories/{id}', [ExpenseCategoryController::class, 'destroy'])->name('expense-categories.destroy');
        });

        // 📋 TIPOS DE DESPESAS
        // Rotas de leitura - acessíveis a todos os usuários autenticados
        Route::get('/expense-types', [ExpenseTypeController::class, 'index'])->name('expense-types.index');
        Route::get('/expense-types/{id}', [ExpenseTypeController::class, 'show'])->name('expense-types.show');

        // Rotas de escrita - protegidas por permissões
        Route::middleware('permission.or.admin:expense_types.create')->group(function () {
            Route::post('/expense-types', [ExpenseTypeController::class, 'store'])->name('expense-types.store');
        });

        Route::middleware('permission.or.admin:expense_types.update')->group(function () {
            Route::put('/expense-types/{id}', [ExpenseTypeController::class, 'update'])->name('expense-types.update');
        });

        Route::middleware('permission.or.admin:expense_types.delete')->group(function () {
            Route::delete('/expense-types/{id}', [ExpenseTypeController::class, 'destroy'])->name('expense-types.destroy');
        });

        // 💳 MÉTODOS DE PAGAMENTO
        // Rotas de leitura - acessíveis a todos os usuários autenticados
        Route::get('/payment-methods', [PaymentMethodController::class, 'index'])->name('payment-methods.index');
        Route::get('/payment-methods/{id}', [PaymentMethodController::class, 'show'])->name('payment-methods.show');

        // Rotas de escrita - protegidas por permissões
        Route::middleware('permission.or.admin:payment_methods.create')->group(function () {
            Route::post('/payment-methods', [PaymentMethodController::class, 'store'])->name('payment-methods.store');
        });

        Route::middleware('permission.or.admin:payment_methods.update')->group(function () {
            Route::put('/payment-methods/{id}', [PaymentMethodController::class, 'update'])->name('payment-methods.update');
        });

        Route::middleware('permission.or.admin:payment_methods.delete')->group(function () {
            Route::delete('/payment-methods/{id}', [PaymentMethodController::class, 'destroy'])->name('payment-methods.destroy');
        });

        // 👤 USUÁRIOS - Protegido por permissões específicas (Admins sempre têm acesso)
        // Buscar usuários que podem aprovar (para seleção em projetos)
        Route::get('/users/approvers', [UserController::class, 'getApprovers'])->name('users.approvers');

        // Buscar usuários para seleção em apontamentos (apenas administradores)
        Route::get('/users/for-timesheets', [UserController::class, 'getUsersForTimesheets'])->name('users.for-timesheets');

        // Perfil do usuário (sempre acessível para usuários autenticados)
        Route::get('/users/profile', [UserController::class, 'profile'])->name('users.profile');
        Route::put('/users/profile', [UserController::class, 'updateProfile'])->name('users.update-profile');

        // Upload de foto de perfil
        Route::post('/users/profile/photo', [UserController::class, 'uploadProfilePhoto'])->name('users.upload-photo');
        Route::delete('/users/profile/photo', [UserController::class, 'removeProfilePhoto'])->name('users.remove-photo');
        Route::post('/users/profile/reset-password', [UserController::class, 'selfResetPassword'])->name('users.profile.reset-password');

        // Gerenciamento completo de usuários (requer permissões específicas)
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show');

        Route::middleware('permission.or.admin:users.create')->group(function () {
            Route::post('/users', [UserController::class, 'store'])->name('users.store');
        });

        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::patch('/users/{user}', [UserController::class, 'update'])->name('users.patch');

        Route::middleware('permission.or.admin:users.delete')->group(function () {
            Route::delete('/users/{user}',    [UserController::class, 'destroy'])->name('users.destroy');
            Route::delete('/users',           [UserController::class, 'bulkDestroy'])->name('users.bulk-destroy');
        });

        Route::middleware('permission.or.admin:users.reset_password')->group(function () {
            Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
            Route::post('/users/{user}/resend-welcome', [UserController::class, 'resendWelcome'])->name('users.resend-welcome');
            Route::post('/users/resend-welcome-bulk',   [UserController::class, 'resendWelcomeBulk'])->name('users.resend-welcome-bulk');
        });

        // Histórico de alterações de valor hora
        Route::get('/users/{user}/hourly-rate-history', [UserController::class, 'getHourlyRateHistory'])->name('users.hourly-rate-history');

        // 🎯 APROVAÇÕES - Endpoints para gerenciar aprovações pendentes
        Route::middleware('permission.or.admin:timesheets.approve,expenses.approve')->group(function () {
            Route::get('/approvals/pending', [ApprovalController::class, 'getPendingApprovals'])->name('approvals.pending');
            Route::get('/approvals/timesheets', [ApprovalController::class, 'getPendingTimesheets'])->name('approvals.timesheets');
            Route::get('/approvals/expenses', [ApprovalController::class, 'getPendingExpenses'])->name('approvals.expenses');
            Route::post('/approvals/timesheets/bulk-approve', [ApprovalController::class, 'bulkApproveTimesheets'])->name('approvals.timesheets.bulk-approve');
            Route::post('/approvals/timesheets/bulk-reject', [ApprovalController::class, 'bulkRejectTimesheets'])->name('approvals.timesheets.bulk-reject');
            Route::post('/approvals/timesheets/bulk-request-adjustment', [ApprovalController::class, 'bulkRequestAdjustmentTimesheets'])->name('approvals.timesheets.bulk-request-adjustment');
            Route::post('/approvals/expenses/bulk-approve', [ApprovalController::class, 'bulkApproveExpenses'])->name('approvals.expenses.bulk-approve');
        });

        // 🔧 CAMPOS CUSTOMIZADOS - Campos customizados por contexto
        // Listar e visualizar campos (todos usuários autenticados)
        Route::get('/custom-fields', [CustomFieldController::class, 'index'])->name('custom-fields.index');
        Route::get('/custom-fields/{customField}', [CustomFieldController::class, 'show'])->name('custom-fields.show');

        // Gerenciar campos customizados (apenas administradores)
        Route::post('/custom-fields', [CustomFieldController::class, 'store'])->name('custom-fields.store');
        Route::put('/custom-fields/{customField}', [CustomFieldController::class, 'update'])->name('custom-fields.update');
        Route::delete('/custom-fields/{customField}', [CustomFieldController::class, 'destroy'])->name('custom-fields.destroy');

        // Valores de campos customizados (contexto dinâmico: projects, timesheets, expenses, customers)
        Route::get('/{context}/{entityId}/custom-field-values', [CustomFieldController::class, 'getValues'])
            ->name('custom-field-values.get')
            ->where('context', 'projects|timesheets|expenses|customers');
        Route::post('/{context}/{entityId}/custom-field-values', [CustomFieldController::class, 'saveValues'])
            ->name('custom-field-values.save')
            ->where('context', 'projects|timesheets|expenses|customers');

        // 👥 GRUPOS DE CONSULTORES - Protegido por permissões específicas (Admins sempre têm acesso)
        // Listar consultores disponíveis
        Route::middleware('permission.or.admin:consultant_groups.view')->group(function () {
            Route::get('/consultant-groups/available-consultants', [ConsultantGroupController::class, 'availableConsultants'])
                ->name('consultant-groups.available-consultants');
        });

        Route::middleware('permission.or.admin:consultant_groups.view')->group(function () {
            Route::get('/consultant-groups', [ConsultantGroupController::class, 'index'])->name('consultant-groups.index');
            Route::get('/consultant-groups/{consultant_group}', [ConsultantGroupController::class, 'show'])->name('consultant-groups.show');
        });

        Route::middleware('permission.or.admin:consultant_groups.create')->group(function () {
            Route::post('/consultant-groups', [ConsultantGroupController::class, 'store'])->name('consultant-groups.store');
        });

        Route::middleware('permission.or.admin:consultant_groups.update')->group(function () {
            Route::put('/consultant-groups/{consultant_group}', [ConsultantGroupController::class, 'update'])->name('consultant-groups.update');
        });

        Route::middleware('permission.or.admin:consultant_groups.delete')->group(function () {
            Route::delete('/consultant-groups/{consultant_group}', [ConsultantGroupController::class, 'destroy'])->name('consultant-groups.destroy');
        });

        // 🔐 GRUPOS DE PERMISSÕES
        Route::get('/permission-groups/available-permissions', [PermissionGroupController::class, 'availablePermissions'])
            ->name('permission-groups.available-permissions');
        Route::get('/permission-groups', [PermissionGroupController::class, 'index'])->name('permission-groups.index');
        Route::get('/permission-groups/{permissionGroup}', [PermissionGroupController::class, 'show'])->name('permission-groups.show');
        Route::get('/permission-groups/{permissionGroup}/users', [PermissionGroupController::class, 'users'])->name('permission-groups.users');
        Route::post('/permission-groups', [PermissionGroupController::class, 'store'])->name('permission-groups.store');
        Route::put('/permission-groups/{permissionGroup}', [PermissionGroupController::class, 'update'])->name('permission-groups.update');
        Route::post('/permission-groups/{permissionGroup}/users', [PermissionGroupController::class, 'addUser'])->name('permission-groups.add-user');
        Route::delete('/permission-groups/{permissionGroup}/users/{user}', [PermissionGroupController::class, 'removeUser'])->name('permission-groups.remove-user');
        Route::delete('/permission-groups/{permissionGroup}', [PermissionGroupController::class, 'destroy'])->name('permission-groups.destroy');

        // 🤝 PARCEIROS
        Route::get('/partner/report', [PartnerReportController::class, 'index'])->name('partner.report');

        Route::middleware('permission.or.admin:partners.view')->group(function () {
            Route::get('/partners', [PartnerController::class, 'index'])->name('partners.index');
            Route::get('/partners/{partner}', [PartnerController::class, 'show'])->name('partners.show');
        });

        Route::middleware('permission.or.admin:partners.create')->group(function () {
            Route::post('/partners', [PartnerController::class, 'store'])->name('partners.store');
        });

        Route::middleware('permission.or.admin:partners.update')->group(function () {
            Route::put('/partners/{partner}', [PartnerController::class, 'update'])->name('partners.update');
        });

        Route::middleware('permission.or.admin:partners.delete')->group(function () {
            Route::delete('/partners/{partner}', [PartnerController::class, 'destroy'])->name('partners.destroy');
        });

        // 🏦 BANCO DE HORAS (CONSULTORES)
        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/consultant-hour-bank/consultants', [ConsultantHourBankController::class, 'consultants']);
            Route::get('/consultant-hour-bank/{userId}/range', [ConsultantHourBankController::class, 'range']);
            Route::get('/consultant-hour-bank/{userId}/preview', [ConsultantHourBankController::class, 'preview']);
            Route::get('/consultant-hour-bank/{userId}/history', [ConsultantHourBankController::class, 'history']);
            Route::post('/consultant-hour-bank/{userId}/close', [ConsultantHourBankController::class, 'close']);
            Route::post('/consultant-hour-bank/{userId}/reopen', [ConsultantHourBankController::class, 'reopen']);
        });

        // 💰 FECHAMENTO ADMINISTRATIVO
        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/fechamento',                                  [\App\Http\Controllers\FechamentoController::class, 'index']);
            Route::get('/fechamento/{yearMonth}/producao',             [\App\Http\Controllers\FechamentoController::class, 'producao']);
            Route::get('/fechamento/{yearMonth}/custo',                [\App\Http\Controllers\FechamentoController::class, 'custo']);
            Route::get('/fechamento/{yearMonth}/receita',              [\App\Http\Controllers\FechamentoController::class, 'receita']);
            Route::get('/fechamento/{yearMonth}/consolidado',          [\App\Http\Controllers\FechamentoController::class, 'consolidado']);
            Route::get('/fechamento/{yearMonth}/validar',              [\App\Http\Controllers\FechamentoController::class, 'validar']);
            Route::post('/fechamento/{yearMonth}/fechar',              [\App\Http\Controllers\FechamentoController::class, 'fechar']);
            Route::post('/fechamento/{yearMonth}/reabrir',             [\App\Http\Controllers\FechamentoController::class, 'reabrir']);
        });

        // 📋 FECHAMENTO POR CONTRATO
        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/fechamento-contrato', [\App\Http\Controllers\FechamentoContratoController::class, 'index']);
        });

        // 🧾 FECHAMENTO CLIENTE
        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/fechamento-cliente',                                                      [\App\Http\Controllers\FechamentoClienteController::class, 'index']);
            Route::get('/fechamento-cliente/{customerId}/{yearMonth}/contratos',                   [\App\Http\Controllers\FechamentoClienteController::class, 'contratos']);
            Route::get('/fechamento-cliente/{customerId}/{yearMonth}/por-tipo',                    [\App\Http\Controllers\FechamentoClienteController::class, 'porTipo']);
            Route::get('/fechamento-cliente/{customerId}/{yearMonth}/apontamentos',               [\App\Http\Controllers\FechamentoClienteController::class, 'apontamentos']);
            Route::get('/fechamento-cliente/{customerId}/{yearMonth}/despesas',                    [\App\Http\Controllers\FechamentoClienteController::class, 'despesas']);
            Route::get('/fechamento-cliente/{customerId}/{yearMonth}/pendencias',                  [\App\Http\Controllers\FechamentoClienteController::class, 'pendencias']);
            Route::get('/fechamento-cliente/{customerId}/{yearMonth}/pagamento',                   [\App\Http\Controllers\FechamentoClienteController::class, 'pagamento']);
            Route::post('/fechamento-cliente/{customerId}/{yearMonth}/fechar',                     [\App\Http\Controllers\FechamentoClienteController::class, 'fechar']);
            Route::post('/fechamento-cliente/{customerId}/{yearMonth}/reabrir',                    [\App\Http\Controllers\FechamentoClienteController::class, 'reabrir']);
        });

        // 🤝 FECHAMENTO PARCEIRO
        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/fechamento-parceiro',                                                     [\App\Http\Controllers\FechamentoParceiroController::class, 'index']);
            Route::get('/fechamento-parceiro/{partnerId}/{yearMonth}/consultores',                 [\App\Http\Controllers\FechamentoParceiroController::class, 'consultores']);
            Route::get('/fechamento-parceiro/{partnerId}/{yearMonth}/despesas',                    [\App\Http\Controllers\FechamentoParceiroController::class, 'despesas']);
            Route::get('/fechamento-parceiro/{partnerId}/{yearMonth}/apontamentos',               [\App\Http\Controllers\FechamentoParceiroController::class, 'apontamentos']);
            Route::post('/fechamento-parceiro/{partnerId}/{yearMonth}/fechar',                     [\App\Http\Controllers\FechamentoParceiroController::class, 'fechar']);
            Route::post('/fechamento-parceiro/{partnerId}/{yearMonth}/reabrir',                    [\App\Http\Controllers\FechamentoParceiroController::class, 'reabrir']);
        });

        // 👤 FECHAMENTO CONSULTOR
        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/fechamento-consultor/{yearMonth}',                              [\App\Http\Controllers\FechamentoConsultorController::class, 'index']);
            Route::get('/fechamento-consultor/{userId}/{yearMonth}/apontamentos',        [\App\Http\Controllers\FechamentoConsultorController::class, 'apontamentos']);
            Route::get('/fechamento-consultor/{userId}/{yearMonth}/banco-horas',         [\App\Http\Controllers\FechamentoConsultorController::class, 'bancoHoras']);
        });

        // 📅 FERIADOS
        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/holidays', [HolidayController::class, 'index']);
            Route::post('/holidays/import', [HolidayController::class, 'importFromApi']);
            Route::post('/holidays', [HolidayController::class, 'store']);
            Route::put('/holidays/{holiday}', [HolidayController::class, 'update']);
            Route::delete('/holidays/{holiday}', [HolidayController::class, 'destroy']);
        });

        // 💬 MENSAGENS DE PROJETO
        Route::get('/messages/unread-count',    [ProjectMessageController::class, 'unreadCount'])->name('messages.unread-count');
        Route::get('/messages/unread-projects', [ProjectMessageController::class, 'unreadProjects'])->name('messages.unread-projects');
        Route::get('/messages/notifications',   [ProjectMessageController::class, 'notifications'])->name('messages.notifications');
        Route::get('/messages/mentionable-users', [ProjectMessageController::class, 'mentionableUsers'])->name('messages.mentionable-users');
        Route::get('/projects/{project}/messages',           [ProjectMessageController::class, 'index'])->name('project-messages.index');
        Route::post('/projects/{project}/messages',          [ProjectMessageController::class, 'store'])->name('project-messages.store');
        Route::post('/projects/{project}/messages/mark-read', [ProjectMessageController::class, 'markRead'])->name('project-messages.mark-read');
        Route::get('/messages/{message}/attachments/{attachment}/download', [ProjectMessageController::class, 'downloadAttachment'])->name('project-messages.attachment-download');

        // 👤 CONTATOS DE CLIENTES
        Route::get('/customer-contacts',                           [CustomerContactController::class, 'index'])->name('customer-contacts.index');
        Route::post('/customer-contacts',                          [CustomerContactController::class, 'store'])->name('customer-contacts.store');
        Route::put('/customer-contacts/{customerContact}',         [CustomerContactController::class, 'update'])->name('customer-contacts.update');
        Route::delete('/customer-contacts/{customerContact}',      [CustomerContactController::class, 'destroy'])->name('customer-contacts.destroy');

        // 👤 CONTATOS DE PROJETOS
        Route::get('/projects/{project}/contacts',  [ProjectContactController::class, 'index'])->name('project-contacts.index');
        Route::put('/projects/{project}/contacts',  [ProjectContactController::class, 'sync'])->name('project-contacts.sync');

        // 📎 ANEXOS DE PROJETOS
        Route::get('/projects/{project}/attachments',                    [ProjectController::class, 'listAttachments'])->name('project-attachments.index');
        Route::post('/projects/{project}/attachments',                   [ProjectController::class, 'uploadAttachment'])->name('project-attachments.upload');
        Route::get('/projects/{project}/attachments/{attachment}',       [ProjectController::class, 'downloadAttachment'])->name('project-attachments.download');
        Route::delete('/projects/{project}/attachments/{attachment}',    [ProjectController::class, 'deleteAttachment'])->name('project-attachments.delete');
        Route::put('/projects/{project}/consultants/{userId}/manual-timesheet', [ProjectController::class, 'toggleConsultantManualTimesheet'])->name('projects.consultant-manual-timesheet');
        Route::get('/projects/{project}/open-periods',  [ProjectController::class, 'listOpenPeriods'])->name('projects.open-periods.index');
        Route::post('/projects/{project}/open-period',  [ProjectController::class, 'openPeriod'])->name('projects.open-periods.open');
        Route::post('/projects/{project}/close-periods',[ProjectController::class, 'closePeriods'])->name('projects.open-periods.close');

        // 📄 CONTRATOS
        Route::get('/contracts/kanban',                              [ContractController::class, 'kanban'])->name('contracts.kanban');
        Route::patch('/contracts/{contract}/kanban-move',            [ContractController::class, 'kanbanMove'])->name('contracts.kanban-move');
        Route::patch('/contracts/{contract}/sustentacao-move',       [ContractController::class, 'sustentacaoMove'])->name('contracts.sustentacao-move');
        Route::patch('/projects/{project}/kanban-move',              [ContractController::class, 'projectMove'])->name('projects.kanban-move');
        Route::get('/contracts/{contract}/kanban-logs',              [\App\Http\Controllers\KanbanLogController::class, 'contractLogs'])->name('contracts.kanban-logs');
        Route::get('/contracts/{contract}/events',                    [ContractController::class, 'events'])->name('contracts.events');
        Route::get('/contracts/{contract}/snapshot',                  [ContractController::class, 'snapshot'])->name('contracts.snapshot');
        Route::post('/contracts/{contract}/snapshot/replay',          [ContractController::class, 'replay'])->name('contracts.snapshot.replay');
        Route::get('/contracts/consistency-report',                   [ContractController::class, 'consistencyReport'])->name('contracts.consistency-report');
        Route::get('/projects/{project}/kanban-logs',                [\App\Http\Controllers\KanbanLogController::class, 'projectLogs'])->name('projects.kanban-logs');
        Route::get('/contract-requests/{contractRequest}/kanban-logs', [\App\Http\Controllers\KanbanLogController::class, 'requestLogs'])->name('contract-requests.kanban-logs');

        Route::prefix('contracts')->group(function () {
            Route::get('/',                                         [ContractController::class, 'index'])->name('contracts.index');
            Route::post('/',                                        [ContractController::class, 'store'])->name('contracts.store');
            Route::get('/{contract}',                              [ContractController::class, 'show'])->name('contracts.show');
            Route::put('/{contract}',                              [ContractController::class, 'update'])->name('contracts.update');
            Route::delete('/{contract}',                           [ContractController::class, 'destroy'])->name('contracts.destroy');
            Route::patch('/{contract}/status',                     [ContractController::class, 'updateStatus'])->name('contracts.update-status');
            Route::post('/{contract}/generate-project',            [ContractController::class, 'generateProject'])->name('contracts.generate-project');
            Route::post('/{contract}/attachments',                 [ContractController::class, 'uploadAttachment'])->name('contracts.upload-attachment');
            Route::get('/{contract}/attachments/{attachment}',     [ContractController::class, 'downloadAttachment'])->name('contracts.download-attachment');
            Route::delete('/{contract}/attachments/{attachment}',  [ContractController::class, 'deleteAttachment'])->name('contracts.delete-attachment');
        });

        // 📋 REQUISIÇÕES DE CONTRATO (clientes enviam necessidades)
        Route::get('/contract-requests/options',              [\App\Http\Controllers\ContractRequestController::class, 'options'])->name('contract-requests.options');
        Route::get('/contract-requests',                      [\App\Http\Controllers\ContractRequestController::class, 'index'])->name('contract-requests.index');
        Route::post('/contract-requests',                     [\App\Http\Controllers\ContractRequestController::class, 'store'])->name('contract-requests.store');
        Route::get('/contract-requests/{contractRequest}',    [\App\Http\Controllers\ContractRequestController::class, 'show'])->name('contract-requests.show');
        Route::patch('/contract-requests/{contractRequest}/review', [\App\Http\Controllers\ContractRequestController::class, 'review'])->name('contract-requests.review');
        Route::patch('/contract-requests/{contractRequest}/kanban-move', [\App\Http\Controllers\ContractController::class, 'requestKanbanMove'])->name('contract-requests.kanban-move');
        Route::post('/contract-requests/{contractRequest}/plan-decision', [\App\Http\Controllers\ContractController::class, 'requestPlanDecision'])->name('contract-requests.plan-decision');
        Route::post('/contract-requests/{contractRequest}/finalize', [\App\Http\Controllers\ContractController::class, 'requestFinalize'])->name('contract-requests.finalize');
        Route::get('/contract-requests/{contractRequest}/messages',  [\App\Http\Controllers\ContractRequestMessageController::class, 'index'])->name('contract-request-messages.index');
        Route::post('/contract-requests/{contractRequest}/messages', [\App\Http\Controllers\ContractRequestMessageController::class, 'store'])->name('contract-request-messages.store');
        Route::get('/contract-requests/{contractRequest}/mentionable-users', [\App\Http\Controllers\ContractRequestMessageController::class, 'mentionableUsers'])->name('contract-request-messages.mentionable-users');
        Route::get('/req-messages/{message}/attachments/{attachment}/download', [\App\Http\Controllers\ContractRequestMessageController::class, 'downloadAttachment'])->name('contract-request-messages.attachment-download');

        // 💬 CHAT DE CONTRATOS
        Route::get('/contracts/{contract}/messages',              [\App\Http\Controllers\ContractMessageController::class, 'index'])->name('contract-messages.index');
        Route::post('/contracts/{contract}/messages',             [\App\Http\Controllers\ContractMessageController::class, 'store'])->name('contract-messages.store');
        Route::post('/contracts/{contract}/messages/mark-read',   [\App\Http\Controllers\ContractMessageController::class, 'markRead'])->name('contract-messages.mark-read');
        Route::get('/contracts/{contract}/mentionable-users',     [\App\Http\Controllers\ContractMessageController::class, 'mentionableUsers'])->name('contract-messages.mentionable-users');
        Route::get('/contract-messages/notifications',            [\App\Http\Controllers\ContractMessageController::class, 'notifications'])->name('contract-messages.notifications');
        Route::get('/contract-messages/unread-contracts',         [\App\Http\Controllers\ContractMessageController::class, 'unreadContracts'])->name('contract-messages.unread-contracts');
        Route::get('/contract-messages/{message}/attachments/{attachment}/download', [\App\Http\Controllers\ContractMessageController::class, 'downloadAttachment'])->name('contract-messages.attachment-download');

        // 🛡️ PORTAL DE SUSTENTAÇÃO - Admins e coordenadores do tipo "sustentacao"
        Route::prefix('sustentacao')->group(function () {
            Route::get('/kpis',         [SustentacaoController::class, 'kpis'])->name('sustentacao.kpis');
            Route::get('/queue',        [SustentacaoController::class, 'queue'])->name('sustentacao.queue');
            Route::get('/sla',          [SustentacaoController::class, 'sla'])->name('sustentacao.sla');
            Route::get('/productivity', [SustentacaoController::class, 'productivity'])->name('sustentacao.productivity');
            Route::get('/financial',    [SustentacaoController::class, 'financial'])->name('sustentacao.financial');
            Route::get('/clients',      [SustentacaoController::class, 'clients'])->name('sustentacao.clients');
            Route::get('/distribution', [SustentacaoController::class, 'distribution'])->name('sustentacao.distribution');
            Route::get('/evolution',       [SustentacaoController::class, 'evolution'])->name('sustentacao.evolution');
            Route::get('/context-stats',       [SustentacaoController::class, 'contextStats'])->name('sustentacao.context-stats');
            Route::get('/filter-options',      [SustentacaoController::class, 'filterOptions'])->name('sustentacao.filter-options');
            Route::get('/executive',           [SustentacaoController::class, 'executive'])->name('sustentacao.executive');
            Route::get('/debug-clientes',      [SustentacaoController::class, 'debugClientes'])->name('sustentacao.debug-clientes');
            Route::get('/debug-responsaveis',  [SustentacaoController::class, 'debugResponsaveis'])->name('sustentacao.debug-responsaveis');
            Route::get('/debug-project-map',   [SustentacaoController::class, 'debugProjectMap'])->name('sustentacao.debug-project-map');
            Route::post('/sync-orgs',          [SustentacaoController::class, 'syncOrgs'])->name('sustentacao.sync-orgs');
            Route::post('/sync-agents',        [SustentacaoController::class, 'syncAgents'])->name('sustentacao.sync-agents');
        });

        // ⚙️ CONFIGURAÇÕES DO SISTEMA - Protegido por permissões específicas (Admins sempre têm acesso)
        Route::middleware('permission.or.admin:system_settings.view')->group(function () {
            Route::get('/system-settings', [SystemSettingController::class, 'index'])->name('system-settings.index');
            Route::get('/system-settings/{key}', [SystemSettingController::class, 'show'])->name('system-settings.show');
        });

        Route::middleware('permission.or.admin:system_settings.update')->group(function () {
            Route::put('/system-settings', [SystemSettingController::class, 'update'])->name('system-settings.update');
        });

        // 🔗 MOVIDESK ADMIN - Sync manual e status da integração (somente admins)
        Route::middleware('permission.or.admin:system_settings.view')->group(function () {
            Route::get('/movidesk/status', [\App\Http\Controllers\MovideskAdminController::class, 'status'])->name('movidesk.status');
        });

        Route::middleware('permission.or.admin:system_settings.update')->group(function () {
            Route::post("/movidesk/sync", [\App\Http\Controllers\MovideskAdminController::class, "sync"])->name("movidesk.sync");
            Route::post("/movidesk/history-import", [\App\Http\Controllers\MovideskAdminController::class, "historyImport"])->name("movidesk.history_import");

            // Diagnóstico e vinculação manual de orgs Movidesk — antes públicas, agora protegidas
            Route::get('/movidesk/debug',             [\App\Http\Controllers\MovideskAdminController::class, 'debug'])->name('movidesk.debug');
            Route::get('/movidesk/debug-orgs',        [\App\Http\Controllers\MovideskAdminController::class, 'debugOrgs'])->name('movidesk.debug.orgs');
            Route::post('/movidesk/link-org',         [\App\Http\Controllers\MovideskAdminController::class, 'linkOrg'])->name('movidesk.link.org');
            Route::post('/movidesk/link-org-project', [\App\Http\Controllers\MovideskAdminController::class, 'linkOrgProject'])->name('movidesk.link.org.project');
        });

        // 🧠 MATRIZ DE CONHECIMENTO — Skills + Consultant Skills
        // Auth simples (sanctum); permissões granulares ficam pra evolução conforme o módulo amadurece.
        Route::get('/skills',                          [SkillController::class, 'index'])->name('skills.index');
        Route::post('/skills',                         [SkillController::class, 'store'])->name('skills.store');
        Route::get('/skills/{id}/holders',             [SkillController::class, 'holders'])->name('skills.holders');
        Route::get('/consultants/{id}/skills',         [ConsultantSkillController::class, 'indexByConsultant'])->name('consultants.skills.index');
        Route::post('/consultants/{id}/skills',        [ConsultantSkillController::class, 'storeForConsultant'])->name('consultants.skills.store');
        Route::put('/consultant-skills/{id}',          [ConsultantSkillController::class, 'update'])->name('consultant-skills.update');
        Route::get('/consultants/{id}/profile',        [ConsultantSkillController::class, 'showProfile'])->name('consultants.profile.show');
        Route::patch('/consultants/{id}/profile',      [ConsultantSkillController::class, 'updateProfile'])->name('consultants.profile.update');

        // 📋 KANBAN DE CANDIDATOS
        Route::get('/candidates',                      [CandidateController::class, 'index'])->name('candidates.index');
        Route::get('/candidates/triage-queue',         [CandidateController::class, 'triageQueue'])->name('candidates.triage-queue');

        // 🔍 BUSCA GLOBAL
        Route::get('/search',                          [SearchController::class, 'search'])->name('search.global');
        Route::get('/search/advanced',                 [SearchController::class, 'advanced'])->name('search.advanced');
        Route::patch('/candidates/{id}',               [CandidateController::class, 'update'])->name('candidates.update');
        Route::patch('/candidates/{id}/status',        [CandidateController::class, 'updateStatus'])->name('candidates.status.update');

        // 🎯 GAPS — Detecção de lacunas de skills (critical e por projeto)
        Route::get('/consultants/{id}/gaps',          [GapController::class, 'consultantGaps'])->name('consultants.gaps');
        Route::get('/projects/{id}/gaps',             [GapController::class, 'projectGaps'])->name('projects.gaps');
        Route::post('/critical-skills',               [GapController::class, 'storeCriticalSkill'])->name('critical-skills.store');
        Route::post('/projects/{id}/required-skills', [GapController::class, 'storeProjectRequiredSkill'])->name('projects.required-skills.store');
        Route::get('/projects/{id}/recommendations',  [GapController::class, 'recommendations'])->name('projects.recommendations');
        Route::get('/projects/{id}/candidate-match',  [GapController::class, 'candidateMatch'])->name('projects.candidate-match');
        Route::post('/projects/{id}/allocate',        [GapController::class, 'allocate'])->name('projects.allocate');
        Route::get('/projects/{id}/team-recommendation', [GapController::class, 'teamRecommendation'])->name('projects.team-recommendation');
        Route::post('/projects/{id}/allocate-team',    [GapController::class, 'allocateTeam'])->name('projects.allocate-team');
    });
});
