<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Confia em proxies (Cloudflare/Render/Cloudflare Tunnel/VPS reverse proxy).
        // Sem isso, request->ip() retorna o IP do load balancer e o throttle
        // por IP não funciona — todas as requests parecem vir do mesmo (ou de
        // edges diferentes do CDN, dependendo do hop).
        $middleware->trustProxies(
            at: '*',
            headers: \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_HOST
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_AWS_ELB,
        );

        // Middleware para rotas da API
        $middleware->api(append: [
            \App\Http\Middleware\ApiSecurityHeaders::class,
        ]);

        // CORS para API
        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);

        // Middleware customizado para permissões
        $middleware->alias([
            'permission.or.admin' => \App\Http\Middleware\CheckPermissionOrAdmin::class,
            'block.cliente'       => \App\Http\Middleware\BlockCliente::class,
        ]);

        // Guest em rota /api não tem pra onde redirecionar: devolvendo null aqui, o
        // middleware Authenticate NÃO avalia route('login') (inexistente nesta API),
        // então a AuthenticationException chega limpa no handler e vira 401 JSON.
        // Sem isso, o route('login') estourava RouteNotFoundException dentro do
        // próprio middleware ("Route [login] not defined." → 500/422 em vez de 401).
        $middleware->redirectGuestsTo(fn (\Illuminate\Http\Request $request) => $request->is('api/*') ? null : '/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            // `is('api/*')` força resposta JSON em qualquer rota de API mesmo sem
            // header Accept: application/json — sem isso, request não-autenticada
            // sem Accept caía no default do Laravel 11, que tenta redirecionar pra
            // route('login') (inexistente numa API) e estourava 500
            // "Route [login] not defined." em vez de um 401 limpo.
            if (($request->expectsJson() || $request->is('api/*')) && !($e instanceof \Illuminate\Validation\ValidationException)) {
                if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                    return response()->json(['message' => 'Unauthenticated.'], 401);
                }
                if ($e instanceof \Illuminate\Auth\Access\AuthorizationException) {
                    return response()->json(['message' => 'This action is unauthorized.'], 403);
                }

                $payload = config('app.debug')
                    ? [
                        'message' => $e->getMessage() ?: get_class($e),
                        'file'    => basename($e->getFile()),
                        'line'    => $e->getLine(),
                    ]
                    : [
                        'message' => 'Erro ao processar a requisição',
                    ];

                return response()->json($payload, 422);
            }
        });
    })->create();
