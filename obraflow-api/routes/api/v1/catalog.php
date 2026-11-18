<?php

use App\Modules\Catalog\Http\Controllers\OwnCompositionController;
use App\Modules\Catalog\Http\Controllers\ResourceController;
use App\Modules\Teams\Http\Controllers\ResourceGroupController;
use Illuminate\Support\Facades\Route;

/*
| Fase 4C — Catálogo de insumos da organização (D-P37…D-P43). TODAS em `tenant.required`, com resources.view/manage.
| Grupos de materiais, mão de obra e serviços ficam aqui (os de equipamento continuam em /resource-groups).
*/

Route::middleware('tenant.required')->group(function (): void {
    Route::get('resources', [ResourceController::class, 'index'])->middleware('can:resources.view')->name('resources.index');
    Route::post('resources', [ResourceController::class, 'store'])->middleware('can:resources.manage')->name('resources.store');
    Route::post('resources/import/preview', [ResourceController::class, 'importPreview'])->middleware('can:resources.manage')->name('resources.import.preview');
    Route::post('resources/import', [ResourceController::class, 'import'])->middleware('can:resources.manage')->name('resources.import');
    Route::get('resources/{uuid}', [ResourceController::class, 'show'])->whereUuid('uuid')->middleware('can:resources.view')->name('resources.show');
    Route::put('resources/{uuid}', [ResourceController::class, 'update'])->whereUuid('uuid')->middleware('can:resources.manage')->name('resources.update');
    Route::delete('resources/{uuid}', [ResourceController::class, 'destroy'])->whereUuid('uuid')->middleware('can:resources.manage')->name('resources.destroy');
    Route::get('resources/{uuid}/labor-costs', [ResourceController::class, 'laborCosts'])->whereUuid('uuid')->middleware('can:resources.view')->name('resources.labor.index');
    Route::post('resources/{uuid}/labor-costs', [ResourceController::class, 'storeLaborCost'])->whereUuid('uuid')->middleware('can:resources.manage')->name('resources.labor.store');
    Route::delete('resources/{uuid}/labor-costs/{id}', [ResourceController::class, 'destroyLaborCost'])->whereUuid('uuid')->whereNumber('id')->middleware('can:resources.manage')->name('resources.labor.destroy');

    // 6B — composições próprias simplificadas (D-P87), com as permissões do catálogo (D-P97)
    Route::get('own-compositions', [OwnCompositionController::class, 'index'])->middleware('can:resources.view')->name('own-compositions.index');
    Route::post('own-compositions', [OwnCompositionController::class, 'store'])->middleware('can:resources.manage')->name('own-compositions.store');
    Route::get('own-compositions/{uuid}', [OwnCompositionController::class, 'show'])->whereUuid('uuid')->middleware('can:resources.view')->name('own-compositions.show');
    Route::put('own-compositions/{uuid}', [OwnCompositionController::class, 'update'])->whereUuid('uuid')->middleware('can:resources.manage')->name('own-compositions.update');
    Route::delete('own-compositions/{uuid}', [OwnCompositionController::class, 'destroy'])->whereUuid('uuid')->middleware('can:resources.manage')->name('own-compositions.destroy');

    // grupos do catálogo (material, labor, service) — mesmo controller dos grupos de equipamento, outro escopo de tipos
    $kinds = ['kinds' => ['material', 'labor', 'service']];
    Route::get('catalog-groups', [ResourceGroupController::class, 'index'])->defaults('scope', $kinds)->middleware('can:resources.view')->name('catalog-groups.index');
    Route::post('catalog-groups', [ResourceGroupController::class, 'store'])->defaults('scope', $kinds)->middleware('can:resources.manage')->name('catalog-groups.store');
    Route::put('catalog-groups/{id}', [ResourceGroupController::class, 'update'])->defaults('scope', $kinds)->whereNumber('id')->middleware('can:resources.manage')->name('catalog-groups.update');
    Route::delete('catalog-groups/{id}', [ResourceGroupController::class, 'destroy'])->defaults('scope', $kinds)->whereNumber('id')->middleware('can:resources.manage')->name('catalog-groups.destroy');
});
