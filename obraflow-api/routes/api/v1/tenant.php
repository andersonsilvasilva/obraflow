<?php

use App\Modules\Access\Http\Controllers\InvitationController;
use App\Modules\Access\Http\Controllers\MemberController;
use App\Modules\Access\Http\Controllers\RoleController;
use App\Modules\Audit\Http\Controllers\AuditLogController;
use App\Modules\Auth\Http\Controllers\TokenController;
use App\Modules\Tenancy\Http\Controllers\TenantController;
use App\Modules\Tenancy\Http\Controllers\TenantSettingController;
use Illuminate\Support\Facades\Route;

/*
| TODA rota de negócio fica no grupo `tenant.required` (autenticação → usuário ativo → sessão fresca →
| SetTenantContext). Rota nova nasce aqui por padrão.
*/

Route::middleware('tenant.required')->group(function (): void {
    Route::get('tenant', [TenantController::class, 'show'])->middleware('can:tenant.view')->name('tenant.show');

    Route::get('tenant/settings', [TenantSettingController::class, 'index'])->middleware('can:settings.view')->name('tenant.settings.index');
    Route::put('tenant/settings/{key}', [TenantSettingController::class, 'upsert'])->middleware('can:settings.manage')->name('tenant.settings.upsert');

    Route::get('tokens', [TokenController::class, 'index'])->middleware('can:tokens.manage')->name('tokens.index');
    Route::post('tokens', [TokenController::class, 'store'])->middleware('can:tokens.manage')->name('tokens.store');
    Route::delete('tokens/{id}', [TokenController::class, 'destroy'])->whereNumber('id')->middleware('can:tokens.manage')->name('tokens.destroy');

    Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('can:audit.view')->name('audit.index');

    // Membros
    Route::get('members', [MemberController::class, 'index'])->middleware('can:members.view')->name('members.index');
    Route::post('members/{id}/suspend', [MemberController::class, 'suspend'])->whereNumber('id')->middleware('can:members.manage')->name('members.suspend');
    Route::post('members/{id}/reactivate', [MemberController::class, 'reactivate'])->whereNumber('id')->middleware('can:members.manage')->name('members.reactivate');
    Route::delete('members/{id}', [MemberController::class, 'destroy'])->whereNumber('id')->middleware('can:members.manage')->name('members.destroy');
    Route::post('members/{id}/roles', [MemberController::class, 'assignRole'])->whereNumber('id')->middleware('can:roles.manage')->name('members.roles.assign');
    Route::delete('members/{id}/roles/{roleId}', [MemberController::class, 'revokeRole'])->whereNumber(['id', 'roleId'])->middleware('can:roles.manage')->name('members.roles.revoke');

    // Papéis (sempre do tenant) e catálogo de permissões
    Route::get('permissions', [RoleController::class, 'permissions'])->middleware('can:roles.view')->name('permissions.index');
    Route::get('roles', [RoleController::class, 'index'])->middleware('can:roles.view')->name('roles.index');
    Route::get('roles/{id}', [RoleController::class, 'show'])->whereNumber('id')->middleware('can:roles.view')->name('roles.show');
    Route::post('roles', [RoleController::class, 'store'])->middleware('can:roles.manage')->name('roles.store');
    Route::put('roles/{id}', [RoleController::class, 'update'])->whereNumber('id')->middleware('can:roles.manage')->name('roles.update');
    Route::delete('roles/{id}', [RoleController::class, 'destroy'])->whereNumber('id')->middleware('can:roles.manage')->name('roles.destroy');

    // Convites
    Route::get('invitations', [InvitationController::class, 'index'])->middleware('can:members.view')->name('invitations.index');
    Route::post('invitations', [InvitationController::class, 'store'])->middleware('can:members.invite')->name('invitations.store');
    Route::delete('invitations/{id}', [InvitationController::class, 'destroy'])->whereNumber('id')->middleware('can:members.invite')->name('invitations.destroy');
});

// PÚBLICA: o token do e-mail é a credencial (sem sessão/tenant). Com freio de tentativas.
Route::post('invitations/accept', [InvitationController::class, 'accept'])->middleware('throttle:password-reset')->name('invitations.accept');
