<?php

namespace App\Modules\Shared\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Padroniza TODAS as respostas de erro da API no formato:
 *
 * { "error": { "code": "...", "message": "...", "details": {...}?, "request_id": "..." } }
 *
 * Erros 5xx nunca expõem mensagem interna fora de APP_DEBUG.
 */
final class ApiExceptionRenderer
{
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $e, Request $request): ?JsonResponse {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            return self::toResponse($e, $request);
        });
    }

    public static function toResponse(Throwable $e, Request $request): JsonResponse
    {
        $details = null;
        $headers = [];

        if ($e instanceof ApiException) {
            [$status, $code, $message] = [$e->status, $e->errorCode, $e->getMessage()];
            $details = $e->details ?: null;
        } elseif ($e instanceof ValidationException) {
            [$status, $code, $message] = [422, 'validation_failed', 'Os dados informados são inválidos.'];
            $details = $e->errors();
        } elseif ($e instanceof AuthenticationException) {
            [$status, $code, $message] = [401, 'unauthenticated', 'Autenticação necessária.'];
        } elseif ($e instanceof AuthorizationException) {
            [$status, $code, $message] = [403, 'forbidden', 'Você não tem permissão para esta ação.'];
        } elseif ($e instanceof ModelNotFoundException) {
            // Mesma resposta para "não existe" e "pertence a outro tenant": evita enumeração.
            [$status, $code, $message] = [404, 'not_found', 'Recurso não encontrado.'];
        } elseif ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $headers = $e->getHeaders();
            [$code, $message] = match ($status) {
                400 => ['bad_request', 'Requisição inválida.'],
                401 => ['unauthenticated', 'Autenticação necessária.'],
                403 => ['forbidden', 'Você não tem permissão para esta ação.'],
                404 => ['not_found', 'Recurso não encontrado.'],
                405 => ['method_not_allowed', 'Método não permitido.'],
                409 => ['conflict', 'Conflito com o estado atual do recurso.'],
                419 => ['session_expired', 'Sessão expirada.'],
                429 => ['too_many_requests', 'Muitas requisições. Tente novamente em instantes.'],
                503 => ['service_unavailable', 'Serviço temporariamente indisponível.'],
                default => ['http_error', $status >= 500 ? 'Erro interno do servidor.' : 'Erro na requisição.'],
            };
        } else {
            [$status, $code, $message] = [500, 'server_error', 'Erro interno do servidor.'];
        }

        $error = [
            'code' => $code,
            'message' => $message,
            'request_id' => $request->attributes->get('request_id'),
        ];

        if ($details !== null) {
            $error['details'] = $details;
        }

        if ($status >= 500 && config('app.debug')) {
            $error['debug'] = ['exception' => $e::class, 'message' => $e->getMessage()];
        }

        return response()->json(['error' => $error], $status, $headers);
    }
}
