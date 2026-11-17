<?php

use App\Modules\References\Http\Controllers\ReferenceController;
use Illuminate\Support\Facades\Route;

/*
| Fase 4A — Referências técnicas (globais, somente leitura — D-P29). Em `tenant.required` como toda rota de
| negócio (o teste estrutural da D-29 verifica) e com `references.view`. Escrita só pelo comando de importação.
*/

Route::middleware(['tenant.required', 'can:references.view'])->prefix('references')->name('references.')->group(function (): void {
    Route::get('datasets', [ReferenceController::class, 'datasets'])->name('datasets.index');
    Route::get('datasets/{dataset}/resources', [ReferenceController::class, 'resources'])->whereNumber('dataset')->name('resources.index');
    Route::get('datasets/{dataset}/resources/{code}', [ReferenceController::class, 'resource'])->whereNumber('dataset')->name('resources.show');
    Route::get('datasets/{dataset}/compositions', [ReferenceController::class, 'compositions'])->whereNumber('dataset')->name('compositions.index');
    Route::get('datasets/{dataset}/compositions/{code}', [ReferenceController::class, 'composition'])->whereNumber('dataset')->name('compositions.show');
});
