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
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            if ($request->expectsJson() && !($e instanceof \Illuminate\Validation\ValidationException)) {
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
