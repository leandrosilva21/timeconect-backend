<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{


    /**
     * @OA\Post(
     *     path="/api/v1/auth/login",
     *     tags={"Autenticação"},
     *     summary="Login do usuário",
     *     description="Autentica um usuário e retorna token de acesso",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email","password"},
     *             @OA\Property(property="email", type="string", format="email", example="admin@minutor.com"),
     *             @OA\Property(property="password", type="string", format="password", example="admin123456"),
     *             @OA\Property(property="device_name", type="string", example="web-app", description="Nome do dispositivo (opcional)")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Login realizado com sucesso",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Login realizado com sucesso"),
     *             @OA\Property(
     *                 property="user",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string", example="Administrador"),
     *                 @OA\Property(property="email", type="string", example="admin@minutor.com"),
     *                 @OA\Property(property="email_verified_at", type="string", example="2024-01-01T00:00:00.000000Z")
     *             ),
     *             @OA\Property(property="token", type="string", example="1|abc123..."),
     *             @OA\Property(property="token_type", type="string", example="Bearer")
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Credenciais inválidas",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Credenciais inválidas")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Dados de validação inválidos",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Dados inválidos"),
     *             @OA\Property(property="errors", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=429,
     *         description="Muitas tentativas de login",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Muitas tentativas de login. Tente novamente em alguns minutos."),
     *             @OA\Property(property="retry_after", type="integer", example=60)
     *         )
     *     )
     * )
     */
    public function login(Request $request): JsonResponse
    {

        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string|min:6',
        ], [
            'email.required' => 'O email é obrigatório',
            'email.email' => 'Formato de email inválido',
            'password.required' => 'A senha é obrigatória',
            'password.min' => 'A senha deve ter pelo menos 6 caracteres',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::whereRaw('LOWER(email) = ?', [strtolower(trim($request->email))])->first();

        if (!$user || !$user->verifyPassword($request->password)) {
            return response()->json(['message' => 'Credenciais inválidas'], 401);
        }

        // Verificar se usuário está desabilitado
        if (!$user->enabled) {
            return response()->json([
                'message' => 'Usuário desabilitado. Entre em contato com o administrador.'
            ], 401);
        }

        // Verificar se senha temporária expirou
        if ($user->temporaryPasswordExpired()) {
            return response()->json([
                'message' => 'Senha temporária expirada. Solicite uma nova recuperação de senha.',
                'password_expired' => true
            ], 401);
        }

        // Verificar se usuário tem senha temporária
        if ($user->hasTemporaryPassword()) {
            $token = $user->createToken($request->device_name ?? 'api-token')->plainTextToken;

            return response()->json([
                'message' => 'Login realizado com sucesso',
                'user' => [
                    'id'                             => $user->id,
                    'name'                           => $user->name,
                    'email'                          => $user->email,
                    'email_verified_at'              => $user->email_verified_at,
                    'has_temporary_password'         => true,
                    'temporary_password_expires_at'  => $user->temporary_password_expires_at,
                    'type'                           => $user->type,
                ],
                'token'                    => $token,
                'token_type'               => 'Bearer',
                'requires_password_change' => true,
            ], 200);
        }

        // Revoga tokens antigos do dispositivo
        $user->tokens()->where('name', $request->device_name ?? 'api-token')->delete();

        $token = $user->createToken($request->device_name ?? 'api-token')->plainTextToken;

        return response()->json([
            'message' => 'Login realizado com sucesso',
            // Payload alinhado com /user (AuthController::user) — sem isso, campos
            // como is_executive/permissions/extra_permissions ficavam undefined no
            // frontend logo após login e a sidebar do parceiro_admin caía no
            // ramo "parceiro simples" até /user ser re-chamado na próxima visibility.
            'user' => array_merge($user->toArray(), [
                'has_temporary_password'  => false,
                'type'                    => $user->type,
                'coordinator_type'        => $user->coordinator_type,
                'extra_permissions'       => $user->extra_permissions ?? [],
                'permissions'             => \App\Services\PermissionService::for($user),
                'consultant_type'         => $user->consultant_type,
                'rate_type'               => $user->rate_type,
                'hourly_rate'             => $user->hourly_rate,
                'daily_hours'             => $user->daily_hours,
                'bank_hours_start_date'   => $user->bank_hours_start_date,
                'guaranteed_hours'        => $user->guaranteed_hours,
            ]),
            'token'                    => $token,
            'token_type'               => 'Bearer',
            'requires_password_change' => false,
        ], 200);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/auth/logout",
     *     tags={"Autenticação"},
     *     summary="Logout do usuário",
     *     description="Desconecta o usuário (revoga token atual)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Logout realizado com sucesso",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Logout realizado com sucesso")
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Token inválido ou expirado",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Unauthenticated.")
     *         )
     *     )
     * )
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logout realizado com sucesso'
        ], 200);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/auth/logout-all",
     *     tags={"Autenticação"},
     *     summary="Logout de todos os dispositivos",
     *     description="Desconecta o usuário de todos os dispositivos",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Logout realizado em todos os dispositivos",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Logout realizado em todos os dispositivos")
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Token inválido ou expirado",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Unauthenticated.")
     *         )
     *     )
     * )
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return response()->json([
            'message' => 'Logout realizado em todos os dispositivos'
        ], 200);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/user",
     *     tags={"Usuário"},
     *     summary="Dados do usuário autenticado",
     *     description="Retorna dados do usuário autenticado",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Dados do usuário",
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="user",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string", example="Administrador"),
     *                 @OA\Property(property="email", type="string", example="admin@minutor.com"),
     *                 @OA\Property(property="email_verified_at", type="string", example="2024-01-01T00:00:00.000000Z"),
     *                 @OA\Property(property="created_at", type="string", example="2024-01-01T00:00:00.000000Z"),
     *                 @OA\Property(property="updated_at", type="string", example="2024-01-01T00:00:00.000000Z")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Token inválido ou expirado",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Unauthenticated.")
     *         )
     *     )
     * )
     */
    public function user(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => array_merge($user->toArray(), [
                'type'                  => $user->type,
                'coordinator_type'      => $user->coordinator_type,
                'extra_permissions'     => $user->extra_permissions ?? [],
                'permissions'           => \App\Services\PermissionService::for($user),
                'consultant_type'       => $user->consultant_type,
                'rate_type'             => $user->rate_type,
                'hourly_rate'           => $user->hourly_rate,
                'daily_hours'           => $user->daily_hours,
                'bank_hours_start_date' => $user->bank_hours_start_date,
                'guaranteed_hours'      => $user->guaranteed_hours,
            ])
        ], 200);
    }

    /**
     * @OA\Put(
     *     path="/api/v1/user/profile",
     *     tags={"Usuário"},
     *     summary="Atualizar dados do usuário",
     *     description="Atualiza dados do perfil do usuário",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string", example="Novo Nome"),
     *             @OA\Property(property="email", type="string", format="email", example="novo@email.com")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Perfil atualizado com sucesso",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Perfil atualizado com sucesso"),
     *             @OA\Property(
     *                 property="user",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string", example="Novo Nome"),
     *                 @OA\Property(property="email", type="string", example="novo@email.com"),
     *                 @OA\Property(property="email_verified_at", type="string", example="2024-01-01T00:00:00.000000Z")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Dados de validação inválidos",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Dados inválidos"),
     *             @OA\Property(property="errors", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Token inválido ou expirado",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Unauthenticated.")
     *         )
     *     )
     * )
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email|unique:users,email,' . $user->id,
        ], [
            'name.required' => 'O nome é obrigatório',
            'name.max' => 'O nome não pode ter mais de 255 caracteres',
            'email.required' => 'O email é obrigatório',
            'email.email' => 'Formato de email inválido',
            'email.unique' => 'Este email já está em uso',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        $user->update($validator->validated());

        return response()->json([
            'message' => 'Perfil atualizado com sucesso',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at,
                'enabled' => $user->enabled,
                'theme_preference' => $user->theme_preference,
                'type' => $user->type,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
            ]
        ], 200);
    }

    /**
     * @OA\Put(
     *     path="/api/v1/user/theme-preference",
     *     tags={"Usuário"},
     *     summary="Atualizar preferência de tema",
     *     description="Permite ao usuário atualizar sua preferência de tema (claro/escuro)",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="theme_preference", type="string", enum={"light", "dark"}, example="dark")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Preferência de tema atualizada com sucesso",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Preferência de tema atualizada com sucesso"),
     *             @OA\Property(
     *                 property="user",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string", example="João Silva"),
     *                 @OA\Property(property="email", type="string", example="joao@email.com"),
     *                 @OA\Property(property="theme_preference", type="string", example="dark")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Dados de validação inválidos",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Dados inválidos"),
     *             @OA\Property(property="errors", type="object")
     *         )
     *     )
     * )
     */
    public function updateThemePreference(Request $request): JsonResponse
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'theme_preference' => 'required|in:light,dark',
        ], [
            'theme_preference.required' => 'A preferência de tema é obrigatória',
            'theme_preference.in' => 'A preferência de tema deve ser "light" ou "dark"',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        $user->update($validator->validated());

        return response()->json([
            'message' => 'Preferência de tema atualizada com sucesso',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at,
                'enabled' => $user->enabled,
                'theme_preference' => $user->theme_preference,
                'type' => $user->type,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
            ]
        ], 200);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/auth/change-password",
     *     tags={"Autenticação"},
     *     summary="Alterar senha do usuário",
     *     description="Altera a senha do usuário autenticado",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"current_password","new_password","new_password_confirmation"},
     *             @OA\Property(property="current_password", type="string", format="password", example="senha_atual"),
     *             @OA\Property(property="new_password", type="string", format="password", example="nova_senha_123"),
     *             @OA\Property(property="new_password_confirmation", type="string", format="password", example="nova_senha_123")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Senha alterada com sucesso",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Senha alterada com sucesso. Faça login novamente.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Senha atual incorreta",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Senha atual incorreta")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Dados de validação inválidos",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Dados inválidos"),
     *             @OA\Property(property="errors", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Token inválido ou expirado",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Unauthenticated.")
     *         )
     *     )
     * )
     */
    public function changePassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|confirmed',
        ], [
            'current_password.required' => 'A senha atual é obrigatória',
            'new_password.required' => 'A nova senha é obrigatória',
            'new_password.min' => 'A nova senha deve ter pelo menos 8 caracteres',
            'new_password.confirmed' => 'A confirmação da nova senha não confere',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();

        if (!$user->verifyPassword($request->current_password)) {
            return response()->json([
                'message' => 'Senha atual incorreta'
            ], 400);
        }

        $user->updatePassword($request->new_password);

        // Revoga todos os tokens existentes por segurança
        $user->tokens()->delete();

        return response()->json([
            'message' => 'Senha alterada com sucesso. Faça login novamente.'
        ], 200);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/auth/verify-token",
     *     tags={"Autenticação"},
     *     summary="Verificar validade do token",
     *     description="Verifica se token atual é válido",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Token válido",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Token válido"),
     *             @OA\Property(property="valid", type="boolean", example=true),
     *             @OA\Property(
     *                 property="user",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string", example="Administrador"),
     *                 @OA\Property(property="email", type="string", example="admin@minutor.com")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Token inválido ou expirado",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Unauthenticated.")
     *         )
     *     )
     * )
     */
    public function verifyToken(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'message' => 'Token válido',
            'valid' => true,
            'user' => $user
        ], 200);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/auth/change-temporary-password",
     *     tags={"Autenticação"},
     *     summary="Alterar senha temporária",
     *     description="Permite ao usuário alterar uma senha temporária para uma definitiva",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"current_password","new_password","new_password_confirmation"},
     *             @OA\Property(property="current_password", type="string", format="password", example="temp123456"),
     *             @OA\Property(property="new_password", type="string", format="password", example="nova_senha_123"),
     *             @OA\Property(property="new_password_confirmation", type="string", format="password", example="nova_senha_123")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Senha alterada com sucesso",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Senha alterada com sucesso")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Usuário não possui senha temporária",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Usuário não possui senha temporária")
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Senha atual inválida",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Senha atual inválida")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Dados de validação inválidos",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Dados inválidos"),
     *             @OA\Property(property="errors", type="object")
     *         )
     *     )
     * )
     */
    public function changeTemporaryPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'new_password' => 'required|string|min:8|confirmed',
        ], [
            'new_password.required' => 'A nova senha é obrigatória',
            'new_password.min' => 'A nova senha deve ter pelo menos 8 caracteres',
            'new_password.confirmed' => 'A confirmação da nova senha não confere',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();

        // Verificar se o usuário tem senha temporária
        if (!$user->hasTemporaryPassword()) {
            return response()->json([
                'message' => 'Usuário não possui senha temporária'
            ], 400);
        }

        // Atualizar senha e remover marca de temporária
        $user->updatePassword($request->new_password);
        $user->clearTemporaryPassword();

        // Revogar todos os tokens por segurança
        $user->tokens()->delete();

        return response()->json([
            'message' => 'Senha alterada com sucesso. Faça login novamente com sua nova senha.'
        ], 200);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/auth/permissions",
     *     tags={"Autenticação"},
     *     summary="Obter permissões do usuário logado",
     *     description="Retorna todas as permissões do usuário autenticado",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Permissões obtidas com sucesso",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Permissões obtidas com sucesso"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(
     *                     property="user",
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string", example="Administrador"),
     *                     @OA\Property(property="email", type="string", example="admin@minutor.com")
     *                 ),
     *                 @OA\Property(
     *                     property="permissions",
     *                     type="array",
     *                     @OA\Items(type="string", example="users.view")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Não autenticado",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Unauthenticated")
     *         )
     *     )
     * )
     */
    public function getPermissions(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        // Buscar permissões via PermissionService
        $allPermissions = \App\Services\PermissionService::for($user);

        return response()->json([
            'success' => true,
            'message' => 'Permissões obtidas com sucesso',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email
                ],
                'permissions' => array_values($allPermissions) // array_values para reindexar
            ]
        ]);
    }
}
