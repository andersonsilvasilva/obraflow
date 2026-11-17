<?php

use App\Modules\Contracts\Http\Controllers\ContractController;
use Illuminate\Support\Facades\Route;

/*
| Fase 5A — Contratos (sempre de uma obra, A-45). TODAS em `tenant.required`. A rota exige a permissão do módulo; o
| ContractAccess aplica o acesso por obra (D-P56). Escrita exige `contracts.manage`. Aditivos endereçados pelo número.
*/

Route::middleware('tenant.required')->group(function (): void {
    Route::get('contracts', [ContractController::class, 'index'])->middleware('can:contracts.view')->name('contracts.index');
    Route::post('contracts', [ContractController::class, 'store'])->middleware('can:contracts.manage')->name('contracts.store');
    Route::get('contracts/next-code', [ContractController::class, 'nextCode'])->middleware('can:contracts.manage')->name('contracts.next-code');

    Route::prefix('contracts/{uuid}')->whereUuid('uuid')->group(function (): void {
        Route::get('/', [ContractController::class, 'show'])->middleware('can:contracts.view')->name('contracts.show');
        Route::put('/', [ContractController::class, 'update'])->middleware('can:contracts.manage')->name('contracts.update');
        Route::get('history', [ContractController::class, 'history'])->middleware('can:contracts.view')->name('contracts.history');
        Route::post('workflow/{action}', [ContractController::class, 'workflow'])->whereIn('action', ['activate', 'suspend', 'resume', 'complete', 'cancel'])
            ->middleware('can:contracts.manage')->name('contracts.workflow');

        Route::get('items', [ContractController::class, 'items'])->middleware('can:contracts.view')->name('contracts.items.index');
        Route::post('items', [ContractController::class, 'storeItem'])->middleware('can:contracts.manage')->name('contracts.items.store');
        Route::post('items/from-budget', [ContractController::class, 'fromBudget'])->middleware(['can:contracts.manage', 'can:budgets.view'])->name('contracts.items.from-budget');
        Route::put('items/{id}', [ContractController::class, 'updateItem'])->whereNumber('id')->middleware('can:contracts.manage')->name('contracts.items.update');
        Route::delete('items/{id}', [ContractController::class, 'destroyItem'])->whereNumber('id')->middleware('can:contracts.manage')->name('contracts.items.destroy');

        Route::get('addenda', [ContractController::class, 'addenda'])->middleware('can:contracts.view')->name('contracts.addenda.index');
        Route::post('addenda', [ContractController::class, 'storeAddendum'])->middleware('can:contracts.manage')->name('contracts.addenda.store');
        Route::prefix('addenda/{number}')->whereNumber('number')->middleware('can:contracts.manage')->group(function (): void {
            Route::put('/', [ContractController::class, 'updateAddendum'])->name('contracts.addenda.update');
            Route::post('approve', [ContractController::class, 'approveAddendum'])->name('contracts.addenda.approve');
            Route::post('cancel', [ContractController::class, 'cancelAddendum'])->name('contracts.addenda.cancel');
            Route::post('lines', [ContractController::class, 'storeLine'])->name('contracts.addenda.lines.store');
            Route::put('lines/{id}', [ContractController::class, 'updateLine'])->whereNumber('id')->name('contracts.addenda.lines.update');
            Route::delete('lines/{id}', [ContractController::class, 'destroyLine'])->whereNumber('id')->name('contracts.addenda.lines.destroy');
        });
    });
});
