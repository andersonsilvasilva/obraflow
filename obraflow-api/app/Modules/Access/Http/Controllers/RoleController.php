<?php

namespace App\Modules\Access\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Modules\Access\Services\PermissionCatalog;
use App\Modules\Access\Services\RoleService;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class RoleController extends Controller
{
    public function __construct(private readonly RoleService $roles, private readonly TenantContext $context) {}

    public function index(): JsonResponse
    {
        $tenantId = $this->context->idOrFail();

        $counts = DB::table('model_has_roles')->where('tenant_id', $tenantId)->selectRaw('role_id, COUNT(*) as n')->groupBy('role_id')->pluck('n', 'role_id');

        return response()->json(['data' => Role::query()->forTenant($tenantId)->with('permissions')->orderBy('name')->get()
            ->map(fn (Role $r) => $this->present($r, (int) ($counts[$r->getKey()] ?? 0)))->values()]);
    }

    public function show(int $id): JsonResponse
    {
        $role = Role::query()->forTenant($this->context->idOrFail())->with('permissions')->findOrFail($id);

        return response()->json(['data' => $this->present($role)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9 _.-]+$/', $this->uniqueName()],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(array_keys(PermissionCatalog::PERMISSIONS))],
        ]);

        $role = $this->roles->create($request->user(), $this->context->tenantOrFail(), $data['name'], $data['permissions']);

        return response()->json(['data' => $this->present($role->load('permissions'))], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $role = Role::query()->forTenant($this->context->idOrFail())->findOrFail($id);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100', 'regex:/^[A-Za-z0-9 _.-]+$/', $this->uniqueName($role->getKey())],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::in(array_keys(PermissionCatalog::PERMISSIONS))],
        ]);

        $role = $this->roles->update($request->user(), $this->context->tenantOrFail(), $role, $data['name'] ?? null, $data['permissions'] ?? null);

        return response()->json(['data' => $this->present($role->load('permissions'))]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $role = Role::query()->forTenant($this->context->idOrFail())->findOrFail($id);

        $this->roles->delete($request->user(), $this->context->tenantOrFail(), $role);

        return response()->json(null, 204);
    }

    /** Catálogo GLOBAL (somente leitura) com os módulos. */
    public function permissions(): JsonResponse
    {
        return response()->json(['data' => collect(PermissionCatalog::PERMISSIONS)
            ->map(fn (string $module, string $name) => ['name' => $name, 'module' => $module])->values()]);
    }

    private function uniqueName(?int $ignoreId = null): Unique
    {
        return Rule::unique('roles', 'name')
            ->where('tenant_id', $this->context->idOrFail())->where('guard_name', 'web')->ignore($ignoreId);
    }

    /** @return array<string, mixed> */
    private function present(Role $role, ?int $members = null): array
    {
        return array_filter([
            'id' => $role->getKey(), 'name' => $role->name, 'is_system' => $role->is_system,
            'permissions' => $role->permissions->pluck('name')->sort()->values(),
            'members_count' => $members,
        ], fn ($v) => $v !== null);
    }
}
