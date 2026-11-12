<?php

use App\Modules\Core\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

// Públicas: sem autenticação e sem contexto de tenant.
Route::get('health', [HealthController::class, 'live'])->name('health.live');
Route::get('health/ready', [HealthController::class, 'ready'])->name('health.ready');
