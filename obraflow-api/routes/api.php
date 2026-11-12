<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API — todas as rotas são versionadas (/api/v1).
| Cada módulo registra suas rotas em routes/api/v1/<modulo>.php.
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->name('v1.')->group(function (): void {
    foreach (glob(__DIR__.'/api/v1/*.php') ?: [] as $moduleRoutes) {
        require $moduleRoutes;
    }
});
