<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\User;
use App\Models\UserHourlyRateLog;
use App\Http\Traits\ResponseHelpers;
use App\Traits\PasswordGenerator;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Notifications\TemporaryPasswordNotification;
use App\Notifications\WelcomeNotification;

/**
 * @OA\Tag(
 *     name="Users",
 *     description="API endpoints para gerenciamento de usuários"
 * )
 */

/**
 * @OA\Schema(
 *     schema="User",
 *     type="object",
 *     title="User",
 *     description="Modelo de usuário",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="name", type="string", example="João Silva"),
 *     @OA\Property(property="email", type="string", format="email", example="joao@email.com"),
 *     @OA\Property(property="email_verified_at", type="string", format="datetime", nullable=true, example="2024-01-15T10:30:00Z"),
 *     @OA\Property(property="created_at", type="string", format="datetime", example="2024-01-15T09:00:00Z"),
 *     @OA\Property(property="updated_at", type="string", format="datetime", example="2024-01-15T09:00:00Z"),
 *     @OA\Property(
 *         property="roles",
 *         type="array",
 *         @OA\Items(
 *             type="object",
 *             @OA\Property(property="id", type="integer", example=1),
 *             @OA\Property(property="name", type="string", example="Administrator"),
 *             @OA\Property(property="guard_name", type="string", example="web")
 *         )
 *     )
 * )
 */
class UserController extends Controller
{
    use ResponseHelpers, PasswordGenerator;

    /**
     * @OA\Get(
     *     path="/api/v1/users",
     *     summary="Listar usuários",
     *     description="Lista usuários com paginação, filtros e ordenação seguindo padrões PO-UI",
     *     operationId="getUsers",
     *     tags={"Users"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         description="Número da página",
     *         required=false,
     *         @OA\Schema(type="integer", default=1)
     *     ),
     *     @OA\Parameter(
     *         name="pageSize",
     *         in="query",
     *         description="Itens por página",
     *         required=false,
     *         @OA\Schema(type="integer", default=20)
     *     ),
     *     @OA\Parameter(
     *         name="order",
     *         in="query",
     *         description="Ordenação (ex: name,-created_at)",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         description="Busca no nome ou email",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         description="Filtrar por status (active ou inactive)",
     *         required=false,
     *         @OA\Schema(type="string", enum={"active","inactive"})
     *     ),
     *     @OA\Parameter(
     *         name="role",
     *         in="query",
     *         description="Filtrar por papel",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lista de usuários",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="hasNext", type="boolean"),
     *             @OA\Property(property="items", type="array", @OA\Items(ref="#/components/schemas/User"))
     *         )
     *     )
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        $pageSize = min((int) $request->get('pageSize', 20), 500);
        $page = (int) $request->get('page', 1);

        $query = User::with(['customer', 'partner:id,name']);

        // Se não é admin nem tem permissão para ver/editar/resetar todos, só pode ver próprio perfil
        // Coordenadores podem ver todos os usuários (necessário para filtros de aprovações/apontamentos)
        // Consultor NUNCA vê outros usuários — mesmo com extra_permissions; rotina não pertence ao escopo dele.
        $canSeeAll = !$user->isConsultor() && (
            $user->isAdmin()
            || $user->isCoordenador()
            || $user->hasAccess('users.view_all')
            || $user->hasAccess('users.update')
            || $user->hasAccess('users.reset_password')
            || $user->hasAccess('users.create')
        );
        if (!$canSeeAll) {
            $query->where('users.id', $user->id);
        }

        // Filtros
        // Importante: qualificar `users.*` porque o ORDER BY pode adicionar
        // leftJoin('partners', ...) e ambas as tabelas têm coluna `name` —
        // sem prefixo gera SQLSTATE[42702] "column reference is ambiguous".
        $search = $request->get('filter') ?? $request->get('search');
        if (!empty($search)) {
            $query->where(function($q) use ($search) {
                $q->where('users.name', 'ilike', "%{$search}%")
                  ->orWhere('users.email', 'ilike', "%{$search}%");
            });
        }

        if ($request->filled('role')) {
            // Aceita tanto type direto ('admin','consultor',...) quanto nome de role legado
            $roleTypeMap = [
                'Administrator' => 'admin', 'Coordenador' => 'coordenador',
                'Consultor' => 'consultor', 'Consultant' => 'consultor',
                'Cliente' => 'cliente', 'Parceiro ADM' => 'parceiro_admin',
            ];
            $typeFilter = $roleTypeMap[$request->role] ?? $request->role;
            $query->where('type', $typeFilter);
        }

        // Aceita ?type= como alias de ?role=. Suporta valores múltiplos via vírgula.
        if ($request->filled('type') && !$request->filled('role')) {
            $types = is_array($request->type)
                ? $request->type
                : array_values(array_filter(array_map('trim', explode(',', (string) $request->type))));
            if (count($types) === 1) {
                $query->where('type', $types[0]);
            } elseif (count($types) > 1) {
                $query->whereIn('type', $types);
            }
        }

        if ($request->filled('coordinator_type')) {
            $query->where('coordinator_type', $request->coordinator_type);
        }

        if ($request->filled('exclude_type')) {
            $query->where('type', '!=', $request->exclude_type);
        }

        if ($request->filled('is_executive')) {
            $query->where('is_executive', true);
        }

        if ($request->filled('partner_id')) {
            $query->where('partner_id', $request->partner_id);
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        // Filtro por status (ativo/inativo) usando campo enabled
        $status = $request->get('status');
        if ($status === 'active') {
            $query->where('enabled', true);
        } elseif ($status === 'inactive') {
            $query->where('enabled', false);
        }

        // Ordenação padrão PO-UI
        $orderFields = $request->get('order', 'name');
        if ($orderFields) {
            $fields = explode(',', $orderFields);
            foreach ($fields as $field) {
                $direction = 'asc';
                if (str_starts_with($field, '-')) {
                    $direction = 'desc';
                    $field = substr($field, 1);
                }

                $allowedFields = ['name', 'email', 'created_at', 'updated_at'];
                if (in_array($field, $allowedFields)) {
                    // Qualifica users.* — campo pode ser ambíguo se algum filtro
                    // anterior adicionou leftJoin com partners (mesma coluna name/email/timestamps).
                    $query->orderBy("users.{$field}", $direction);
                } elseif ($field === 'partner_name') {
                    $query->leftJoin('partners', 'partners.id', '=', 'users.partner_id')
                          ->orderBy('partners.name', $direction)
                          ->select('users.*');
                }
            }
        }

        $users = $query->paginate($pageSize, ['*'], 'page', $page);

        // Adicionar tipos de dashboard permitidos para cada usuário
        $items = collect($users->items())->map(function ($user) {
            $userData = $user->toArray();
            $userData['dashboard_types'] = $user->getAllowedDashboardTypes();
            return $userData;
        })->toArray();

        // Resposta no padrão PO-UI
        return response()->json([
            'hasNext' => $users->hasMorePages(),
            'items' => $items
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/users",
     *     summary="Criar novo usuário",
     *     description="Cria um novo usuário no sistema com senha gerada automaticamente e enviada por email",
     *     operationId="createUser",
     *     tags={"Users"},
     *     security={{"sanctum":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             required={"name", "email"},
     *             @OA\Property(property="name", type="string", example="João Silva"),
     *             @OA\Property(property="email", type="string", format="email", example="joao@email.com"),
     *             @OA\Property(property="enabled", type="boolean", example=true, description="Indica se o usuário está ativo (padrão: true)"),
     *             @OA\Property(property="hourly_rate", type="number", format="float", example=50.00, description="Valor hora do usuário"),
     *             @OA\Property(property="rate_type", type="string", enum={"hourly", "monthly"}, example="hourly", description="Tipo de rate (hora ou mês)"),
     *             @OA\Property(
     *                 property="roles",
     *                 type="array",
     *                 description="IDs dos papéis a serem atribuídos",
     *                 @OA\Items(type="integer")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Usuário criado com sucesso. Senha temporária enviada por email.",
     *         @OA\JsonContent(ref="#/components/schemas/User")
     *     )
     * )
     */
    /**
     * Tipo de contrato (cooperado/clt/pj): regra "trava em todos".
     *  - Parceiro (parceiro_admin com partner_id): grava no parceiro e PROPAGA p/ todos
     *    os usuários daquele partner_id (só quando o valor é enviado, p/ não zerar em update parcial).
     *  - Consultor vinculado a parceiro: SEMPRE herda do parceiro (ignora qualquer input).
     *  - Sem parceiro: o valor próprio já foi gravado via fillable — nada a propagar.
     */
    private function syncContractType(User $user, Request $request): void
    {
        if (!$user->partner_id) {
            return;
        }
        $partner = Partner::find($user->partner_id);
        if (!$partner) {
            return;
        }

        if ($user->type === 'parceiro_admin') {
            if ($request->has('contract_type')) {
                $ct = $request->input('contract_type');
                $partner->contract_type = $ct;
                $partner->save();
                User::where('partner_id', $partner->id)->update(['contract_type' => $ct]);
            }
        } else {
            if ($user->contract_type !== $partner->contract_type) {
                $user->forceFill(['contract_type' => $partner->contract_type])->save();
            }
        }
    }

    /**
     * Atualização em massa do tipo de contrato (cooperado/clt/pj) para usuários selecionados.
     * Respeita a trava: consultor vinculado a parceiro é pulado (herda do parceiro);
     * se um parceiro_admin estiver na seleção, propaga p/ todos os usuários daquele parceiro.
     */
    public function bulkContractType(Request $request): JsonResponse
    {
        $currentUser = Auth::user();
        if (!($currentUser->isAdmin() || $currentUser->hasAccess('users.update'))) {
            return $this->accessDeniedResponse('Você não tem permissão para atualizar usuários.');
        }

        $validator = Validator::make($request->all(), [
            'user_ids'      => 'required|array|min:1',
            'user_ids.*'    => 'integer|exists:users,id',
            'contract_type' => 'nullable|in:cooperado,clt,pj',
        ]);
        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors()->all());
        }

        $ct  = $request->input('contract_type');
        $ids = $request->input('user_ids');

        DB::beginTransaction();
        try {
            $applied = 0;
            $skipped = 0;
            foreach (User::whereIn('id', $ids)->get() as $u) {
                if ($u->type === 'parceiro_admin' && $u->partner_id) {
                    Partner::where('id', $u->partner_id)->update(['contract_type' => $ct]);
                    User::where('partner_id', $u->partner_id)->update(['contract_type' => $ct]);
                    $applied++;
                } elseif ($u->partner_id) {
                    // Consultor vinculado: herda do parceiro (trava) — não altera direto.
                    $skipped++;
                } else {
                    $u->forceFill(['contract_type' => $ct])->save();
                    $applied++;
                }
            }
            DB::commit();
            return response()->json(['applied' => $applied, 'skipped' => $skipped]);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->serverErrorResponse('Erro na atualização em massa: ' . $e->getMessage());
        }
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'nullable|string|min:8',
            'enabled' => 'sometimes|boolean',
            'hourly_rate' => 'nullable|numeric|min:0|max:999999.99',
            'hourly_rate_effective_from' => 'nullable|date',
            'rate_type' => 'nullable|in:hourly,monthly',
            'consultant_type' => 'nullable|in:horista,banco_de_horas,fixo',
            'contract_type' => 'nullable|in:cooperado,clt,pj',
            'full_name'      => 'nullable|string|max:255',
            'cpf'            => 'nullable|string|max:20',
            'matricula'      => 'nullable|string|max:30',
            'payroll_status' => 'nullable|string|max:40',
            'bank_hours_start_date' => 'nullable|date',
            'guaranteed_hours'      => 'nullable|numeric|min:0|max:744',
            'customer_id'  => 'nullable|exists:customers,id',
            'partner_id'   => 'nullable|exists:partners,id',
            'is_executive' => 'sometimes|boolean',
            'is_bizify' => 'sometimes|boolean',
            'dashboard_types' => 'nullable|array',
            'dashboard_types.*' => 'string|in:bank_hours_fixed',
            'type' => 'nullable|in:admin,administrativo,coordenador,consultor,cliente,parceiro_admin',
            'coordinator_type' => 'nullable|in:projetos,sustentacao',
            'can_timesheet_sustentacao' => 'sometimes|boolean',
            'extra_permissions'   => 'nullable|array',
            'extra_permissions.*' => 'string',
            'smtp_app_password'   => 'nullable|string|max:255',
        ], [
            'name.required'  => 'O nome é obrigatório.',
            'email.required' => 'O e-mail é obrigatório.',
            'email.email'    => 'O e-mail informado é inválido.',
            'email.unique'   => 'Este e-mail já está cadastrado no sistema.',
            'password.min'   => 'A senha deve ter pelo menos 6 caracteres.',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors()->all());
        }

        DB::beginTransaction();
        try {
            $userData = $validator->validated();

            // Normalizar email para minúsculas
            if (isset($userData['email'])) {
                $userData['email'] = strtolower(trim($userData['email']));
            }

            // Usar senha fornecida pelo admin ou gerar automaticamente
            $adminProvidedPassword = !empty($userData['password']) ? $userData['password'] : null;
            $temporaryPassword = $adminProvidedPassword ?? $this->generateTemporaryPassword();

            // Remover dashboard_types dos dados do usuário (campo auxiliar, não coluna)
            $dashboardTypes = $userData['dashboard_types'] ?? [];
            // smtp_app_password: setado explicitamente após o save (fora do mass-assignment).
            $smtpAppPassword = $userData['smtp_app_password'] ?? null;
            unset($userData['dashboard_types'], $userData['password'], $userData['smtp_app_password']);

            // Criar usuário com senha placeholder (setTemporaryPassword irá sobrescrever com hash correto)
            $userData['password'] = 'placeholder';

            // Separar campos protegidos (fora de $fillable) — admin pode setar via forceFill
            $protectedData = array_intersect_key($userData, array_flip(User::PROTECTED_FIELDS));
            $fillableData = array_diff_key($userData, $protectedData);

            $user = User::create($fillableData);
            if (!empty($protectedData)) {
                $user->forceFill($protectedData)->save();
            }

            // Tipo de contrato: parceiro define p/ todos; consultor vinculado herda (trava)
            $this->syncContractType($user, $request);

            // Definir senha com hash correto via DB direto (evita duplo hash pelo cast 'hashed')
            $user->setTemporaryPassword($temporaryPassword, 24);

            // App Password de SMTP (O365): só grava se veio NÃO-vazio; o cast criptografa.
            if (filled($smtpAppPassword)) {
                $user->smtp_app_password = $smtpAppPassword;
                $user->save();
            }

            // Sincronizar tipos de dashboard permitidos
            if (!empty($dashboardTypes)) {
                $user->syncDashboardTypes($dashboardTypes);
            }

            // Enviar email de boas-vindas com a senha (definida pelo admin ou gerada)
            $user->notify(new WelcomeNotification($temporaryPassword));

            DB::commit();

            $user->load(['customer']);

            // Adicionar tipos de dashboard permitidos na resposta
            $userData = $user->toArray();
            $userData['dashboard_types'] = $user->getAllowedDashboardTypes();

            \Log::info('✅ [USER CREATED] Usuário criado com sucesso:', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'customer_id' => $user->customer_id,
                'dashboard_types' => $userData['dashboard_types'],
                'password_source' => $adminProvidedPassword ? 'admin_defined' : 'auto_generated',
                'welcome_email_sent' => true,
            ]);

            return response()->json($userData, 201);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('🚨 [USER CREATED] Erro ao criar usuário:', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return $this->serverErrorResponse('Erro ao criar usuário: ' . $e->getMessage());
        }
    }

    /**
     * @OA\Get(
     *     path="/api/v1/users/{id}",
     *     summary="Visualizar usuário específico",
     *     description="Retorna detalhes de um usuário específico",
     *     operationId="getUser",
     *     tags={"Users"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID do usuário",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Detalhes do usuário",
     *         @OA\JsonContent(ref="#/components/schemas/User")
     *     )
     * )
     */
    public function show(int $id): JsonResponse
    {
        $currentUser = Auth::user();

        $user = User::with(['customer'])->find($id);

        if (!$user) {
            return $this->notFoundResponse('Usuário não encontrado');
        }

        // Verificar se pode visualizar este usuário
        $canViewUser = $currentUser->isAdmin()
            || $currentUser->isCoordenador()
            || $currentUser->hasAccess('users.view_all')
            || $currentUser->hasAccess('users.update')
            || $currentUser->hasAccess('users.reset_password')
            || $currentUser->hasAccess('users.create')
            || $user->id === $currentUser->id;
        if (!$canViewUser) {
            return $this->accessDeniedResponse('Você não tem permissão para visualizar este usuário');
        }

        // Adicionar tipos de dashboard permitidos na resposta
        $userData = $user->toArray();
        $userData['dashboard_types'] = $user->getAllowedDashboardTypes();

        return response()->json($userData);
    }

    /**
     * @OA\Put(
     *     path="/api/v1/users/{id}",
     *     summary="Atualizar usuário",
     *     description="Atualiza um usuário existente",
     *     operationId="updateUser",
     *     tags={"Users"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID do usuário",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="name", type="string", example="João Silva"),
     *             @OA\Property(property="email", type="string", format="email", example="joao@email.com"),
     *             @OA\Property(property="password", type="string", format="password", example="newpassword123"),
     *             @OA\Property(property="password_confirmation", type="string", format="password", example="newpassword123"),
     *             @OA\Property(property="enabled", type="boolean", example=true, description="Indica se o usuário está ativo"),
     *             @OA\Property(
     *                 property="roles",
     *                 type="array",
     *                 description="IDs dos papéis a serem atribuídos",
     *                 @OA\Items(type="integer")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Usuário atualizado com sucesso",
     *         @OA\JsonContent(ref="#/components/schemas/User")
     *     )
     * )
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $currentUser = Auth::user();
        $user = User::find($id);

        if (!$user) {
            return $this->notFoundResponse('Usuário não encontrado');
        }

        // Verificar permissões
        $canUpdateAll = $currentUser->isAdmin() || $currentUser->hasAccess('users.update');
        $canUpdateOwnProfile = $currentUser->hasAccess('users.update_own_profile') && $user->id === $currentUser->id;

        if (!$canUpdateAll && !$canUpdateOwnProfile) {
            return $this->accessDeniedResponse('Você não tem permissão para atualizar este usuário');
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'password' => 'sometimes|string|min:8|confirmed',
            'enabled' => 'sometimes|boolean',
            'hourly_rate' => 'nullable|numeric|min:0|max:999999.99',
            'hourly_rate_effective_from' => 'nullable|date',
            'rate_type' => 'nullable|in:hourly,monthly',
            'consultant_type' => 'nullable|in:horista,banco_de_horas,fixo',
            'contract_type' => 'nullable|in:cooperado,clt,pj',
            'full_name'      => 'nullable|string|max:255',
            'cpf'            => 'nullable|string|max:20',
            'matricula'      => 'nullable|string|max:30',
            'payroll_status' => 'nullable|string|max:40',
            'bank_hours_start_date' => 'nullable|date',
            'guaranteed_hours'      => 'nullable|numeric|min:0|max:744',
            'customer_id'  => 'nullable|exists:customers,id',
            'partner_id'   => 'nullable|exists:partners,id',
            'is_executive' => 'sometimes|boolean',
            'is_bizify' => 'sometimes|boolean',
            'dashboard_types' => 'sometimes|array',
            'dashboard_types.*' => 'string|in:bank_hours_fixed',
            'type' => 'sometimes|nullable|in:admin,administrativo,coordenador,consultor,cliente,parceiro_admin',
            'coordinator_type' => 'sometimes|nullable|in:projetos,sustentacao',
            'can_timesheet_sustentacao' => 'sometimes|boolean',
            'extra_permissions'   => 'sometimes|nullable|array',
            'extra_permissions.*' => 'string',
            'smtp_app_password'   => 'nullable|string|max:255',
        ], [
            'email.email'  => 'O e-mail informado é inválido.',
            'email.unique' => 'Este e-mail já está cadastrado no sistema.',
            'password.min' => 'A senha deve ter pelo menos 8 caracteres.',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors()->all());
        }

        DB::beginTransaction();
        try {
            $updateData = $validator->validated();

            // Normalizar email para minúsculas
            if (isset($updateData['email'])) {
                $updateData['email'] = strtolower(trim($updateData['email']));
            }

            // Capturar valores anteriores para log
            $oldHourlyRate     = $user->hourly_rate;
            $oldRateType       = $user->rate_type;
            $oldConsultantType = $user->consultant_type;

            $hourlyRateChanged     = isset($updateData['hourly_rate'])     && $updateData['hourly_rate']     != $oldHourlyRate;
            $rateTypeChanged       = isset($updateData['rate_type'])        && $updateData['rate_type']        != $oldRateType;
            $consultantTypeChanged = isset($updateData['consultant_type'])  && $updateData['consultant_type']  != $oldConsultantType;

            $shouldLog = $hourlyRateChanged || $rateTypeChanged || $consultantTypeChanged;

            // Hash da senha se fornecida
            if (isset($updateData['password'])) {
                $updateData['password'] = Hash::make($updateData['password']);
            }

            // Remover campos desnecessários
            $dashboardTypes = $updateData['dashboard_types'] ?? null;
            // smtp_app_password: setado explicitamente após o update (fora do mass-assignment);
            // só sobrescreve se veio NÃO-vazio — vazio/ausente preserva o valor atual.
            $smtpAppPassword = $updateData['smtp_app_password'] ?? null;
            unset($updateData['dashboard_types'], $updateData['password_confirmation'], $updateData['hourly_rate_effective_from'], $updateData['smtp_app_password']);

            // Separar campos protegidos (fora de $fillable) — admin pode setar via forceFill
            $protectedData = array_intersect_key($updateData, array_flip(User::PROTECTED_FIELDS));
            $fillableData = array_diff_key($updateData, $protectedData);

            $user->update($fillableData);
            if (!empty($protectedData)) {
                $user->forceFill($protectedData)->save();
            }

            // App Password de SMTP (O365): só grava se veio NÃO-vazio; o cast criptografa.
            // Vazio/ausente NÃO sobrescreve o valor existente.
            if (filled($smtpAppPassword)) {
                $user->smtp_app_password = $smtpAppPassword;
                $user->save();
            }

            // Tipo de contrato: parceiro define p/ todos; consultor vinculado herda (trava)
            $this->syncContractType($user, $request);

            // Registrar log de alteração de valor ou tipo de contrato
            if ($shouldLog) {
                $fresh = $user->fresh();

                // Dedup mesmo-dia: se já existe um log para este usuário criado HOJE,
                // atualiza-o com os valores mais recentes (mantendo os old_* do início do dia)
                // em vez de empilhar uma nova linha por alteração.
                $todayLog = UserHourlyRateLog::where('user_id', $user->id)
                    ->whereDate('created_at', now()->toDateString())
                    ->orderByDesc('id')
                    ->first();

                $newEffectiveFrom = $request->input('hourly_rate_effective_from')
                    ? \Carbon\Carbon::parse($request->input('hourly_rate_effective_from'))->startOfMonth()->toDateString()
                    : null;

                if ($todayLog) {
                    $todayLog->new_hourly_rate     = $fresh->hourly_rate;
                    $todayLog->new_rate_type       = $fresh->rate_type;
                    $todayLog->new_consultant_type = $fresh->consultant_type;
                    $todayLog->changed_by          = $currentUser->id;
                    $todayLog->reason              = $request->input('rate_change_reason');
                    // effective_from não-destrutivo: só sobrescreve se uma nova data foi enviada
                    if ($newEffectiveFrom !== null) {
                        $todayLog->effective_from = $newEffectiveFrom;
                    }
                    $todayLog->save();
                } else {
                    UserHourlyRateLog::create([
                        'user_id'              => $user->id,
                        'changed_by'           => $currentUser->id,
                        'old_hourly_rate'      => $oldHourlyRate,
                        'new_hourly_rate'      => $fresh->hourly_rate,
                        'old_rate_type'        => $oldRateType,
                        'new_rate_type'        => $fresh->rate_type,
                        'old_consultant_type'  => $oldConsultantType,
                        'new_consultant_type'  => $fresh->consultant_type,
                        'reason'               => $request->input('rate_change_reason'),
                        'effective_from'       => $newEffectiveFrom,
                    ]);
                }
            }

            // Sincronizar tipos de dashboard se fornecidos
            if (!is_null($dashboardTypes)) {
                $user->syncDashboardTypes($dashboardTypes);
            }

            DB::commit();

            $user->load(['customer']);

            // Adicionar tipos de dashboard permitidos na resposta
            $userData = $user->toArray();
            $userData['dashboard_types'] = $user->getAllowedDashboardTypes();

            return response()->json($userData);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('[UserController::update] Exception', [
                'user_id'   => $id,
                'message'   => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
                'trace'     => $e->getTraceAsString(),
            ]);
            return $this->serverErrorResponse('Erro ao atualizar usuário: ' . $e->getMessage());
        }
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/users/{id}",
     *     summary="Excluir usuário",
     *     description="Exclui um usuário do sistema",
     *     operationId="deleteUser",
     *     tags={"Users"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID do usuário",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=204,
     *         description="Usuário excluído com sucesso"
     *     )
     * )
     */
    public function destroy(int $id): JsonResponse
    {
        $currentUser = Auth::user();
        $user = User::find($id);

        if (!$user) {
            return $this->notFoundResponse('Usuário não encontrado');
        }

        // Não pode excluir próprio usuário
        if ($user->id === $currentUser->id) {
            return $this->businessRuleResponse(
                'CANNOT_DELETE_SELF',
                'Não é possível excluir próprio usuário',
                'Você não pode excluir sua própria conta'
            );
        }

        // Verificar permissões
        if (!$currentUser->isAdmin() && !$currentUser->hasAccess('users.delete')) {
            return $this->accessDeniedResponse('Você não tem permissão para excluir usuários');
        }

        // Não pode excluir último administrador
        if ($user->isAdmin()) {
            $adminCount = User::where('type', 'admin')->count();
            if ($adminCount <= 1) {
                return $this->businessRuleResponse(
                    'CANNOT_DELETE_LAST_ADMIN',
                    'Não é possível excluir último administrador',
                    'Deve existir pelo menos um administrador no sistema'
                );
            }
        }

        // Não pode excluir usuário com movimentações
        $hasTimesheets = \App\Models\Timesheet::where('user_id', $user->id)->exists();
        $hasExpenses   = \App\Models\Expense::where('user_id', $user->id)->exists();

        if ($hasTimesheets || $hasExpenses) {
            return $this->businessRuleResponse(
                'USER_HAS_MOVEMENTS',
                'Usuário possui movimentações',
                'Não é possível excluir um usuário que possui apontamentos ou despesas registrados. Desative o usuário em vez de excluí-lo.'
            );
        }

        DB::beginTransaction();
        try {
            $user->delete();

            DB::commit();

            return response()->json(null, 204);

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->serverErrorResponse('Erro ao excluir usuário: ' . $e->getMessage());
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/users/{id}/reset-password",
     *     summary="Resetar senha do usuário",
     *     description="Gera uma nova senha temporária para o usuário e envia por email",
     *     operationId="resetUserPassword",
     *     tags={"Users"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID do usuário",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Senha temporária enviada por email",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="Senha temporária enviada para o email do usuário")
     *         )
     *     )
     * )
     */
    public function resetPassword(int $id): JsonResponse
    {
        $currentUser = Auth::user();
        $user = User::find($id);

        if (!$user) {
            return $this->notFoundResponse('Usuário não encontrado');
        }

        // Verificar permissões
        if (!$currentUser->isAdmin() && !$currentUser->hasAccess('users.reset_password')) {
            return $this->accessDeniedResponse('Você não tem permissão para resetar senhas');
        }

        DB::beginTransaction();
        try {
            $temporaryPassword = $this->generateTemporaryPassword();
            $user->setTemporaryPassword($temporaryPassword, 24);
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->serverErrorResponse('Erro ao resetar senha: ' . $e->getMessage());
        }

        // Envia e-mail fora da transação — falha de e-mail não desfaz o reset
        $emailSent = false;
        try {
            $user->notify(new TemporaryPasswordNotification($temporaryPassword, 24));
            $emailSent = true;
        } catch (\Exception $e) {
            \Log::error('Falha ao enviar e-mail de reset de senha', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message'            => 'Senha temporária gerada com sucesso',
            'temporary_password' => $temporaryPassword,
            'email_sent'         => $emailSent,
        ]);
    }

    /**
     * Reenvia o e-mail de boas-vindas para um único usuário.
     * Aceita senha predefinida via body; se omitida, gera uma nova automaticamente.
     */
    public function resendWelcome(Request $request, int $id): JsonResponse
    {
        $currentUser = Auth::user();

        if (!$currentUser->isAdmin() && !$currentUser->hasAccess('users.reset_password')) {
            return $this->accessDeniedResponse('Você não tem permissão para reenviar e-mails de boas-vindas');
        }

        $validator = Validator::make($request->all(), [
            'password' => 'nullable|string|min:8',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors()->all());
        }

        $user = User::find($id);
        if (!$user) {
            return $this->notFoundResponse('Usuário não encontrado');
        }

        $temporaryPassword = $request->input('password') ?: $this->generateTemporaryPassword();

        DB::beginTransaction();
        try {
            $user->setTemporaryPassword($temporaryPassword, 24);
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->serverErrorResponse('Erro ao definir senha: ' . $e->getMessage());
        }

        $emailSent = false;
        try {
            $user->notify(new WelcomeNotification($temporaryPassword));
            $emailSent = true;
        } catch (\Exception $e) {
            \Log::error('Falha ao reenviar e-mail de boas-vindas', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message'    => 'E-mail de boas-vindas reenviado com sucesso',
            'email_sent' => $emailSent,
        ]);
    }

    /**
     * Reenvia o e-mail de boas-vindas em massa para uma lista de usuários.
     */
    public function resendWelcomeBulk(Request $request): JsonResponse
    {
        $currentUser = Auth::user();

        if (!$currentUser->isAdmin() && !$currentUser->hasAccess('users.reset_password')) {
            return $this->accessDeniedResponse('Você não tem permissão para reenviar e-mails de boas-vindas');
        }

        $validator = Validator::make($request->all(), [
            'user_ids'   => 'required|array|min:1|max:100',
            'user_ids.*' => 'integer|exists:users,id',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors()->all());
        }

        $userIds = $validator->validated()['user_ids'];
        $users   = User::whereIn('id', $userIds)->get();

        $results = ['sent' => 0, 'failed' => 0, 'errors' => []];

        foreach ($users as $user) {
            DB::beginTransaction();
            try {
                $temporaryPassword = $this->generateTemporaryPassword();
                $user->setTemporaryPassword($temporaryPassword, 24);
                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                $results['failed']++;
                $results['errors'][] = "Erro ao gerar senha para {$user->email}: " . $e->getMessage();
                continue;
            }

            try {
                $user->notify(new WelcomeNotification($temporaryPassword));
                $results['sent']++;
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = "Falha ao enviar e-mail para {$user->email}: " . $e->getMessage();
                \Log::error('Falha no resend welcome bulk', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            }
        }

        return response()->json([
            'message' => "{$results['sent']} e-mail(s) enviado(s), {$results['failed']} falha(s)",
            'sent'    => $results['sent'],
            'failed'  => $results['failed'],
            'errors'  => $results['errors'],
        ]);
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $currentUser = Auth::user();

        if (!$currentUser->isAdmin() && !$currentUser->hasAccess('users.delete')) {
            return $this->accessDeniedResponse('Você não tem permissão para excluir usuários');
        }

        $validator = Validator::make($request->all(), [
            'ids'   => 'required|array|min:1|max:100',
            'ids.*' => 'integer|exists:users,id',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors()->all());
        }

        $ids = collect($validator->validated()['ids'])
            ->filter(fn($id) => $id !== $currentUser->id)
            ->values();

        $count = User::whereIn('id', $ids)->delete();

        return response()->json(['message' => "{$count} usuário(s) excluído(s)"]);
    }

    /**
     * Gera uma senha temporária para o próprio usuário autenticado
     * (sem precisar de permissão de admin).
     */
    public function selfResetPassword(): JsonResponse
    {
        $user = Auth::user();

        DB::beginTransaction();
        try {
            $temporaryPassword = $this->generateTemporaryPassword();
            $user->setTemporaryPassword($temporaryPassword, 24);
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->serverErrorResponse('Erro ao gerar senha: ' . $e->getMessage());
        }

        try {
            $user->notify(new TemporaryPasswordNotification($temporaryPassword, 24));
        } catch (\Exception $e) {
            \Log::error('Falha ao enviar e-mail de reset (self)', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        return response()->json([
            'message'            => 'Nova senha gerada com sucesso',
            'temporary_password' => $temporaryPassword,
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/users/profile",
     *     summary="Perfil do usuário atual",
     *     description="Retorna o perfil do usuário autenticado",
     *     operationId="getUserProfile",
     *     tags={"Users"},
     *     security={{"sanctum":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Perfil do usuário",
     *         @OA\JsonContent(ref="#/components/schemas/User")
     *     )
     * )
     */
    public function profile(): JsonResponse
    {
        $user = Auth::user();

        return response()->json($user);
    }

    /**
     * @OA\Put(
     *     path="/api/v1/users/profile",
     *     summary="Atualizar próprio perfil",
     *     description="Permite ao usuário atualizar seu próprio perfil",
     *     operationId="updateUserProfile",
     *     tags={"Users"},
     *     security={{"sanctum":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="name", type="string", example="João Silva"),
     *             @OA\Property(property="email", type="string", format="email", example="joao@email.com"),
     *             @OA\Property(property="current_password", type="string", format="password", example="currentpass"),
     *             @OA\Property(property="password", type="string", format="password", example="newpassword123"),
     *             @OA\Property(property="password_confirmation", type="string", format="password", example="newpassword123")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Perfil atualizado com sucesso",
     *         @OA\JsonContent(ref="#/components/schemas/User")
     *     )
     * )
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = Auth::user();

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'current_password' => 'required_with:password|string',
            'password' => 'sometimes|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors()->all());
        }

        $updateData = $validator->validated();

        // Verificar senha atual se está tentando alterar a senha
        if (isset($updateData['password'])) {
            if (!Hash::check($updateData['current_password'], $user->password)) {
                return $this->businessRuleResponse(
                    'INVALID_CURRENT_PASSWORD',
                    'Senha atual incorreta',
                    'A senha atual fornecida não confere'
                );
            }
            $updateData['password'] = Hash::make($updateData['password']);
        }

        // Remover campos desnecessários
        unset($updateData['current_password'], $updateData['password_confirmation']);

        try {
            $user->update($updateData);

            return response()->json($user);

        } catch (\Exception $e) {
            return $this->serverErrorResponse('Erro ao atualizar perfil: ' . $e->getMessage());
        }
    }

    /**
     * @OA\Get(
     *     path="/api/v1/users/{id}/hourly-rate-history",
     *     summary="Histórico de alterações de valor hora",
     *     description="Retorna o histórico de alterações do valor hora de um usuário",
     *     operationId="getUserHourlyRateHistory",
     *     tags={"Users"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID do usuário",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         description="Número da página",
     *         required=false,
     *         @OA\Schema(type="integer", default=1)
     *     ),
     *     @OA\Parameter(
     *         name="pageSize",
     *         in="query",
     *         description="Itens por página",
     *         required=false,
     *         @OA\Schema(type="integer", default=20)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Histórico de alterações",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="hasNext", type="boolean"),
     *             @OA\Property(
     *                 property="items",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer"),
     *                     @OA\Property(property="old_hourly_rate", type="number", nullable=true),
     *                     @OA\Property(property="new_hourly_rate", type="number", nullable=true),
     *                     @OA\Property(property="old_rate_type", type="string", enum={"hourly", "monthly"}),
     *                     @OA\Property(property="new_rate_type", type="string", enum={"hourly", "monthly"}),
     *                     @OA\Property(property="reason", type="string", nullable=true),
     *                     @OA\Property(property="created_at", type="string", format="datetime"),
     *                     @OA\Property(
     *                         property="changed_by",
     *                         type="object",
     *                         @OA\Property(property="id", type="integer"),
     *                         @OA\Property(property="name", type="string"),
     *                         @OA\Property(property="email", type="string")
     *                     )
     *                 )
     *             )
     *         )
     *     )
     * )
     */
    public function getHourlyRateHistory(Request $request, int $id): JsonResponse
    {
        $currentUser = Auth::user();
        $user = User::find($id);

        if (!$user) {
            return $this->notFoundResponse('Usuário não encontrado');
        }

        // Verificar se pode visualizar este usuário
        $canViewUser2 = $currentUser->isAdmin()
            || $currentUser->isCoordenador()
            || $currentUser->hasAccess('users.view_all')
            || $currentUser->hasAccess('users.update')
            || $currentUser->hasAccess('users.reset_password')
            || $currentUser->hasAccess('users.create')
            || $user->id === $currentUser->id;
        if (!$canViewUser2) {
            return $this->accessDeniedResponse('Você não tem permissão para visualizar este usuário');
        }

        $pageSize = min((int) $request->get('pageSize', 20), 100);
        $page = (int) $request->get('page', 1);

        $query = $user->hourlyRateLogs()
            ->with(['changedBy:id,name,email']);

        // Ordenação PO-UI
        if ($request->has('order')) {
            $orderFields = explode(',', $request->get('order'));
            foreach ($orderFields as $field) {
                $direction = 'asc';
                if (str_starts_with($field, '-')) {
                    $direction = 'desc';
                    $field = substr($field, 1);
                }

                $allowedFields = ['created_at', 'old_hourly_rate', 'new_hourly_rate'];
                if (in_array($field, $allowedFields)) {
                    $query->orderBy($field, $direction);
                }
            }
        } else {
            $query->latest(); // Ordenação padrão
        }

        $logs = $query->paginate($pageSize, ['*'], 'page', $page);

        // Transformar os dados para incluir changed_by_user
        $transformedItems = $logs->items();
        foreach ($transformedItems as $item) {
            $item->changed_by_user = $item->changedBy;
            unset($item->changedBy);
        }

        return response()->json([
            'hasNext' => $logs->hasMorePages(),
            'items' => $transformedItems
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/users/approvers",
     *     summary="Listar usuários que podem aprovar",
     *     description="Lista usuários que têm permissão para aprovar projetos, timesheets e despesas",
     *     operationId="getApprovers",
     *     tags={"Users"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         description="Busca no nome ou email",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lista de usuários aprovadores",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/User")),
     *             @OA\Property(property="message", type="string", example="Usuários aprovadores listados com sucesso")
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Não autenticado"
     *     )
     * )
     */
    public function getApprovers(Request $request): JsonResponse
    {
        $user = Auth::user();

        // Verificar se o usuário tem permissão para ver usuários
        if (!$user->isAdmin() && !$user->hasAccess('users.view')) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado'
            ], 403);
        }

        $search = $request->get('filter') ?? $request->get('search', '');

        // Buscar usuários que podem aprovar (admin, coordenador ou parceiro_admin)
        $query = User::where('enabled', true)
            ->whereIn('type', ['admin', 'coordenador', 'parceiro_admin']);

        // Aplicar filtro de busca se fornecido
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('email', 'ilike', "%{$search}%");
            });
        }

        $approvers = $query->select('id', 'name', 'email')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $approvers,
            'hasNext' => false,
            'items' => $approvers,
            'message' => 'Usuários aprovadores listados com sucesso'
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/users/for-timesheets",
     *     summary="Lista usuários para seleção em apontamentos",
     *     description="Lista todos os usuários ativos que podem ter apontamentos criados por administradores",
     *     operationId="getUsersForTimesheets",
     *     tags={"Users"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         description="Termo de busca (nome ou email)",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lista de usuários para apontamentos",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="hasNext", type="boolean", example=false),
     *             @OA\Property(property="items", type="array", @OA\Items(
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string", example="João Silva"),
     *                 @OA\Property(property="email", type="string", example="joao@exemplo.com")
     *             ))
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Não autenticado"
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Acesso negado - apenas administradores"
     *     )
     * )
     */
    public function getUsersForTimesheets(Request $request): JsonResponse
    {
        $user = Auth::user();

        // Verificar se o usuário tem permissão admin.full_access
        if (!$user->isAdmin() && !$user->hasAccess('admin.full_access')) {
            return response()->json([
                'code' => 'ACCESS_DENIED',
                'type' => 'error',
                'message' => 'Acesso negado',
                'detailMessage' => 'Apenas administradores podem acessar esta funcionalidade'
            ], 403);
        }

        $search = $request->get('filter') ?? $request->get('search', '');

        // Buscar todos os usuários ativos
        $query = User::where('enabled', true);

        // Aplicar filtro de busca se fornecido
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('email', 'ilike', "%{$search}%");
            });
        }

        $users = $query->select('id', 'name', 'email')
            ->orderBy('name')
            ->get();

        // Resposta no padrão PO-UI
        return response()->json([
            'hasNext' => false,
            'items' => $users
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/users/profile/photo",
     *     summary="Upload de foto de perfil",
     *     description="Faz upload de uma foto de perfil para o usuário autenticado",
     *     operationId="uploadProfilePhoto",
     *     tags={"Users"},
     *     security={{"sanctum":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 type="object",
     *                 @OA\Property(
     *                     property="photo",
     *                     type="string",
     *                     format="binary",
     *                     description="Arquivo de imagem (JPG, PNG, GIF - máx 2MB)"
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Foto de perfil atualizada com sucesso",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="Foto de perfil atualizada com sucesso"),
     *             @OA\Property(property="photo_url", type="string", example="http://localhost:8000/storage/profile_photos/user_1_photo.jpg")
     *         )
     *     )
     * )
     */
    public function uploadProfilePhoto(Request $request): JsonResponse
    {
        $user = Auth::user();



        // Verificar se o arquivo foi enviado
        if (!$request->hasFile('photo')) {
            \Log::error('Upload de foto - Arquivo não encontrado');
            return $this->validationErrorResponse(['O arquivo de foto é obrigatório']);
        }

        $file = $request->file('photo');

        // Validação manual do arquivo
        if (!$file->isValid()) {
            \Log::error('Upload de foto - Arquivo inválido:', ['error' => $file->getError()]);
            return $this->validationErrorResponse(['O arquivo enviado é inválido']);
        }

        // Validar tipo de arquivo
        $allowedMimes = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif'];
        if (!in_array($file->getMimeType(), $allowedMimes)) {
            return $this->validationErrorResponse(['O arquivo deve ser uma imagem (JPG, PNG, GIF)']);
        }

        // Validar tamanho (2MB)
        if ($file->getSize() > 2 * 1024 * 1024) {
            return $this->validationErrorResponse(['O arquivo deve ter no máximo 2MB']);
        }



        try {
            // Remover foto anterior se existir
            $user->removeProfilePhoto();

            // Processar upload da nova foto (já temos $file da validação acima)
            $fileName = 'user_' . $user->id . '_' . time() . '.' . $file->getClientOriginalExtension();

            // Garantir que o diretório profile_photos existe
            $directory = storage_path('app/public/profile_photos');
            if (!file_exists($directory)) {
                mkdir($directory, 0755, true);
            }



            // Salvar arquivo no storage usando o disco public explicitamente
            $path = $file->storeAs('profile_photos', $fileName, 'public');



            $storagePath = 'profile_photos/' . $fileName;

            // FASE 11.7 — Persistência 100% na camada Attachment.
            // Falha aqui é fatal porque já não há legado pra cobrir o upload.
            app(\App\Attachments\AttachmentService::class)->registerExisting($user, [
                'entity_type'   => 'USER',
                'entity_id'     => $user->id,
                'category'      => 'avatar',
                'storage_path'  => $storagePath,
                'original_name' => $file->getClientOriginalName(),
                'mime_type'     => $file->getMimeType() ?: 'image/jpeg',
            ]);

            return response()->json([
                'message' => 'Foto de perfil atualizada com sucesso',
                'photo_url' => $user->fresh()->profile_photo_url
            ]);

        } catch (\Exception $e) {
            return $this->serverErrorResponse('Erro ao fazer upload da foto: ' . $e->getMessage());
        }
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/users/profile/photo",
     *     summary="Remover foto de perfil",
     *     description="Remove a foto de perfil do usuário autenticado",
     *     operationId="removeProfilePhoto",
     *     tags={"Users"},
     *     security={{"sanctum":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Foto de perfil removida com sucesso",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="Foto de perfil removida com sucesso")
     *         )
     *     )
     * )
     */
    public function removeProfilePhoto(): JsonResponse
    {
        $user = Auth::user();

        try {
            $user->removeProfilePhoto();

            return response()->json([
                'message' => 'Foto de perfil removida com sucesso'
            ]);

        } catch (\Exception $e) {
            return $this->serverErrorResponse('Erro ao remover foto de perfil: ' . $e->getMessage());
        }
    }

    /**
     * Gera uma senha temporária segura
     */
    private function generateTemporaryPassword(): string
    {
        // Gera uma senha com 12 caracteres incluindo letras, números e símbolos
        $lowercase = 'abcdefghijklmnopqrstuvwxyz';
        $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $numbers = '0123456789';
        $symbols = '@#$%&*!';

        $password = '';

        // Garantir pelo menos um de cada tipo
        $password .= $lowercase[random_int(0, strlen($lowercase) - 1)];
        $password .= $uppercase[random_int(0, strlen($uppercase) - 1)];
        $password .= $numbers[random_int(0, strlen($numbers) - 1)];
        $password .= $symbols[random_int(0, strlen($symbols) - 1)];

        // Completar com caracteres aleatórios
        $allChars = $lowercase . $uppercase . $numbers . $symbols;
        for ($i = 4; $i < 12; $i++) {
            $password .= $allChars[random_int(0, strlen($allChars) - 1)];
        }

        // Embaralhar a string
        return str_shuffle($password);
    }
}
