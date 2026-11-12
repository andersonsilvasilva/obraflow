<?php

use App\Modules\Auth\Http\Controllers\AuthController;
use App\Modules\Auth\Http\Controllers\SessionTenantController;
use Illuminate\Support\Facades\Route;

/*
| Grupo `auth/*`: aceita usuário autenticado SEM tenant ativo (inclusive token sem tenant_id).
| Nenhuma rota daqui devolve dados de negócio.
*/

Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login');
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:password-reset')->name('forgot');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:password-reset')->name('reset');

    Route::middleware('authenticated')->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::get('tenants', [AuthController::class, 'tenants'])->name('tenants');
        Route::post('logout-others', [AuthController::class, 'logoutOthers'])->middleware('throttle:login')->name('logout-others');
    });
});

Route::middleware('authenticated')->post('session/tenant', [SessionTenantController::class, 'store'])->name('session.tenant');
