<?php

namespace App\Modules\Access\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\UserInvitation;
use App\Modules\Access\Services\InvitationService;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvitationController extends Controller
{
    public function __construct(private readonly InvitationService $invitations, private readonly TenantContext $context) {}

    public function index(): JsonResponse
    {
        $invitations = UserInvitation::query()->with('role:id,name')->orderByDesc('id')->get();

        return response()->json(['data' => $invitations->map(fn (UserInvitation $i) => $this->present($i))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:190'],
            'role_id' => ['required', 'integer'],
        ]);

        // papel DO TENANT ATIVO (de outro tenant ⇒ 404)
        $role = Role::query()->forTenant($this->context->idOrFail())->findOrFail($data['role_id']);

        [$invitation, $sent] = $this->invitations->create($request->user(), $this->context->tenantOrFail(), $data['email'], $role);

        // o token NUNCA é devolvido: só vai por e-mail
        return response()->json(['data' => $this->present($invitation->load('role:id,name')), 'email_sent' => $sent], 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $invitation = UserInvitation::query()->findOrFail($id); // escopo de tenant: outro tenant ⇒ 404

        $this->invitations->revoke($request->user(), $this->context->tenantOrFail(), $invitation);

        return response()->json(null, 204);
    }

    /** Aceite público: o token é a credencial. Limitado por throttle. */
    public function accept(Request $request): JsonResponse
    {
        $request->validate(['token' => ['required', 'string', 'max:255']]);

        [$tenant, $user, $existed] = $this->invitations->accept($request->only('token', 'name', 'password', 'password_confirmation'));

        return response()->json(['data' => [
            'tenant' => ['uuid' => $tenant->uuid, 'name' => $tenant->name],
            'account_existed' => $existed,
            'next' => 'login',
        ]], 201);
    }

    /** @return array<string, mixed> */
    private function present(UserInvitation $i): array
    {
        return [
            'id' => $i->getKey(), 'email' => $i->email, 'status' => $i->status->value,
            'role' => ['id' => $i->role?->getKey(), 'name' => $i->role?->name],
            'expires_at' => $i->expires_at->toIso8601String(), 'accepted_at' => $i->accepted_at?->toIso8601String(),
        ];
    }
}
