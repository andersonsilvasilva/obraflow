<?php

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Shared\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Usuário bloqueado perde acesso imediatamente (sessão e token). */
class EnsureUserActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->isActive()) {
            if ($request->hasSession()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
            }

            throw ApiException::forbidden('account_blocked', 'Esta conta está bloqueada.');
        }

        return $next($request);
    }
}
