<?php

use App\Modules\Shared\Exceptions\ApiExceptionRenderer;
use App\Modules\Shared\Http\Middleware\RequestId;
use App\Modules\Tenancy\Http\Middleware\EnsureUserActive;
use App\Modules\Tenancy\Http\Middleware\SetTenantContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(RequestId::class);

        // SPA: sessão por cookie (Sanctum). Tokens Bearer continuam funcionando sem sessão.
        $middleware->statefulApi();

        $middleware->alias([
            'user.active' => EnsureUserActive::class,
            'tenant.context' => SetTenantContext::class,
        ]);

        // Autenticado (com ou sem tenant): grupo `auth/*` e seleção de tenant.
        // Sessões antigas caem sozinhas quando a senha muda: o `AuthenticateSession` do Sanctum faz parte da
        // pilha stateful (config/sanctum.php → middleware.authenticate_session).
        $middleware->group('authenticated', ['auth:sanctum', 'user.active']);

        // Rotas de NEGÓCIO: exigem tenant ativo validado a cada requisição.
        $middleware->group('tenant.required', ['authenticated', 'tenant.context']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        ApiExceptionRenderer::register($exceptions);
    })->create();
