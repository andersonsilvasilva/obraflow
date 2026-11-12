<?php

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Shared\Exceptions\ApiException;
use App\Modules\Tenancy\ActiveTenantResolver;
use App\Modules\Tenancy\TenantContext;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Presente em TODA rota de negócio (grupo `tenant.required`). Falha fechada em qualquer dúvida.
 *
 *  1. resolve o tenant (sessão do servidor ou token) e revalida vínculo ativo, status do tenant e do usuário;
 *  2. `X-Tenant-Hint` é NÃO autoritativo: só é COMPARADO. Divergência → 409 `tenant_mismatch`, SEM executar
 *     a operação (proteção contra abas desatualizadas);
 *  3. registra o TenantContext (que também sincroniza o Spatie e descarta relações em cache).
 */
class SetTenantContext
{
    public const HINT_HEADER = 'X-Tenant-Hint';

    public function __construct(
        private readonly ActiveTenantResolver $resolver,
        private readonly TenantContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user() ?? throw new AuthenticationException;

        [$tenant, $membership] = $this->resolver->resolve($request, $user);

        $hint = $request->header(self::HINT_HEADER);
        if ($hint !== null && $hint !== '' && ! hash_equals($tenant->uuid, $hint)) {
            throw ApiException::conflict(
                'tenant_mismatch',
                'O tenant exibido não é o tenant ativo desta sessão. Recarregue a página para continuar.',
            );
        }

        $this->context->use($tenant, $membership, $user);
        $request->attributes->set('tenant', $tenant);
        Log::withContext(['tenant_id' => $tenant->getKey()]);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->context->forget();
    }
}
