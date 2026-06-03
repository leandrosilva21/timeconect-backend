<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloqueia acesso de usuários com type='cliente'.
 *
 * Use em rotas que expõem detalhes operacionais internos (etapas, entregas,
 * timeline técnica). Cliente vê só o portal cliente com indicadores macro.
 * Ver ADR 0002.
 */
class BlockCliente
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && method_exists($user, 'isCliente') && $user->isCliente()) {
            return response()->json([
                'message' => 'Acesso negado para perfil cliente.',
            ], 403);
        }
        return $next($request);
    }
}
