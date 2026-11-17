<?php

use App\Modules\Budgets\Http\Controllers\BudgetContentController;
use App\Modules\Budgets\Http\Controllers\BudgetController;
use Illuminate\Support\Facades\Route;

/*
| Fase 4B — Orçamentos. TODAS em `tenant.required`. A rota exige a permissão do módulo; o BudgetAccess aplica o
| acesso por obra (D-P24) quando o orçamento é de uma obra. Versões endereçadas por número.
*/

Route::middleware('tenant.required')->group(function (): void {
    Route::get('budgets', [BudgetController::class, 'index'])->middleware('can:budgets.view')->name('budgets.index');
    Route::post('budgets', [BudgetController::class, 'store'])->middleware('can:budgets.manage')->name('budgets.store');

    Route::prefix('budgets/{uuid}')->whereUuid('uuid')->group(function (): void {
        Route::get('/', [BudgetController::class, 'show'])->middleware('can:budgets.view')->name('budgets.show');
        Route::put('/', [BudgetController::class, 'update'])->middleware('can:budgets.manage')->name('budgets.update');
        Route::delete('/', [BudgetController::class, 'destroy'])->middleware('can:budgets.manage')->name('budgets.destroy');
        Route::get('history', [BudgetController::class, 'history'])->middleware('can:budgets.view')->name('budgets.history');
        Route::post('versions', [BudgetController::class, 'newVersion'])->middleware('can:budgets.manage')->name('budgets.versions.store');
        Route::post('workflow/{action}', [BudgetController::class, 'workflow'])->whereIn('action', ['submit', 'withdraw', 'approve', 'reject', 'reopen'])
            ->middleware('can:budgets.view')->name('budgets.workflow'); // permissão fina (manage/approve) no BudgetAccess

        Route::prefix('versions/{number}')->whereNumber('number')->group(function (): void {
            Route::get('/', [BudgetContentController::class, 'show'])->middleware('can:budgets.view')->name('budgets.versions.show');
            Route::put('/', [BudgetController::class, 'updateVersion'])->middleware('can:budgets.manage')->name('budgets.versions.update');
            Route::get('abc', [BudgetContentController::class, 'abc'])->middleware('can:budgets.view')->name('budgets.abc');

            Route::post('groups', [BudgetContentController::class, 'storeGroup'])->middleware('can:budgets.manage')->name('budgets.groups.store');
            Route::put('groups/{id}', [BudgetContentController::class, 'updateGroup'])->whereNumber('id')->middleware('can:budgets.manage')->name('budgets.groups.update');
            Route::delete('groups/{id}', [BudgetContentController::class, 'destroyGroup'])->whereNumber('id')->middleware('can:budgets.manage')->name('budgets.groups.destroy');

            Route::post('items', [BudgetContentController::class, 'storeItem'])->middleware('can:budgets.manage')->name('budgets.items.store');
            Route::post('items/reference', [BudgetContentController::class, 'storeReferenceItem'])->middleware(['can:budgets.manage', 'can:references.view'])->name('budgets.items.reference');
            Route::put('items/{id}', [BudgetContentController::class, 'updateItem'])->whereNumber('id')->middleware('can:budgets.manage')->name('budgets.items.update');
            Route::delete('items/{id}', [BudgetContentController::class, 'destroyItem'])->whereNumber('id')->middleware('can:budgets.manage')->name('budgets.items.destroy');
            Route::get('items/{id}/composition', [BudgetContentController::class, 'composition'])->whereNumber('id')->middleware('can:budgets.view')->name('budgets.items.composition');

            Route::put('bdi', [BudgetContentController::class, 'bdi'])->middleware('can:budgets.manage')->name('budgets.bdi');
            Route::post('indirect-costs', [BudgetContentController::class, 'storeIndirect'])->middleware('can:budgets.manage')->name('budgets.indirect.store');
            Route::put('indirect-costs/{id}', [BudgetContentController::class, 'updateIndirect'])->whereNumber('id')->middleware('can:budgets.manage')->name('budgets.indirect.update');
            Route::delete('indirect-costs/{id}', [BudgetContentController::class, 'destroyIndirect'])->whereNumber('id')->middleware('can:budgets.manage')->name('budgets.indirect.destroy');
        });
    });
});
