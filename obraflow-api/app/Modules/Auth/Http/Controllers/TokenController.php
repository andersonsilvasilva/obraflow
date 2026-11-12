<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PersonalAccessToken;
use App\Modules\Audit\AuditEvent;
use App\Modules\Audit\AuditLogger;
use App\Modules\Auth\Http\Requests\StoreTokenRequest;
use App\Modules\Auth\Services\IssueApiToken;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Tokens de API do tenant ativo (permissão `tokens.manage`). Só tokens DO TENANT ATIVO são visíveis/revogáveis;
 * o valor em claro aparece UMA vez, na emissão.
 */
class TokenController extends Controller
{
    public function index(TenantContext $context): JsonResponse
    {
        $tokens = PersonalAccessToken::query()->where('tenant_id', $context->idOrFail())->orderByDesc('id')->get()
            ->map(fn (PersonalAccessToken $t) => $this->present($t));

        return response()->json(['data' => $tokens]);
    }

    public function store(StoreTokenRequest $request, IssueApiToken $issuer, TenantContext $context): JsonResponse
    {
        $new = $issuer->issue(
            $request->user(),
            $context->tenantOrFail(),
            $request->validated('name'),
            (int) $request->validated('expires_in_days'),
            $request->validated('abilities', ['*']),
        );

        return response()->json([
            'data' => $this->present($new->accessToken),
            'token' => $new->plainTextToken, // exibido uma única vez
        ], 201);
    }

    public function destroy(Request $request, int $id, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        // findOrFail escopado ao tenant ativo: token de outro tenant ⇒ 404
        $token = PersonalAccessToken::query()->where('tenant_id', $context->idOrFail())->findOrFail($id);

        DB::transaction(function () use ($token, $request, $context, $audit): void {
            $audit->record(AuditEvent::TOKEN_REVOKED, tenantId: $context->idOrFail(), userId: $request->user()->getKey(),
                entityType: 'token', entityId: $token->getKey(), metadata: ['token_id' => $token->getKey(), 'name' => $token->name, 'reason' => 'revoked_by_admin']);
            $token->delete();
        });

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function present(PersonalAccessToken $t): array
    {
        return [
            'id' => $t->getKey(), 'name' => $t->name, 'abilities' => $t->abilities,
            'user_id' => $t->tokenable_id,
            'expires_at' => $t->expires_at?->toIso8601String(),
            'last_used_at' => $t->last_used_at?->toIso8601String(),
            'created_at' => $t->created_at?->toIso8601String(),
        ];
    }
}
