<?php

use App\Modules\Planning\Http\Controllers\ScheduleController;
use Illuminate\Support\Facades\Route;

/*
| Fase 6A — Planejamento da obra. TODAS em `tenant.required`. Leitura: `schedules.view`; escrita: `schedules.manage`
| e nível `edit` na obra (conferido no controller, D-P24). Atividades e dependências endereçadas pelo id.
*/

Route::middleware('tenant.required')->prefix('projects/{uuid}/schedule')->whereUuid('uuid')->group(function (): void {
    Route::get('/', [ScheduleController::class, 'show'])->middleware('can:schedules.view')->name('schedules.show');
    Route::post('/', [ScheduleController::class, 'store'])->middleware('can:schedules.manage')->name('schedules.store');
    Route::put('/', [ScheduleController::class, 'update'])->middleware('can:schedules.manage')->name('schedules.update');
    Route::delete('/', [ScheduleController::class, 'destroy'])->middleware('can:schedules.manage')->name('schedules.destroy');

    Route::post('tasks', [ScheduleController::class, 'storeTask'])->middleware('can:schedules.manage')->name('schedules.tasks.store');
    Route::put('tasks/{id}', [ScheduleController::class, 'updateTask'])->whereNumber('id')->middleware('can:schedules.manage')->name('schedules.tasks.update');
    Route::post('tasks/{id}/move', [ScheduleController::class, 'moveTask'])->whereNumber('id')->middleware('can:schedules.manage')->name('schedules.tasks.move');
    Route::delete('tasks/{id}', [ScheduleController::class, 'destroyTask'])->whereNumber('id')->middleware('can:schedules.manage')->name('schedules.tasks.destroy');

    Route::post('dependencies', [ScheduleController::class, 'storeDependency'])->middleware('can:schedules.manage')->name('schedules.dependencies.store');
    Route::delete('dependencies/{id}', [ScheduleController::class, 'destroyDependency'])->whereNumber('id')->middleware('can:schedules.manage')->name('schedules.dependencies.destroy');

    Route::post('baselines', [ScheduleController::class, 'storeBaseline'])->middleware('can:schedules.manage')->name('schedules.baselines.store');

    // importação por planilha (D-P68b): modelo, pré-visualização (nada gravado) e importação
    Route::get('import/template', [ScheduleController::class, 'importTemplate'])->middleware('can:schedules.view')->name('schedules.import.template');
    Route::post('import/preview', [ScheduleController::class, 'importPreview'])->middleware('can:schedules.manage')->name('schedules.import.preview');
    Route::post('import', [ScheduleController::class, 'import'])->middleware('can:schedules.manage')->name('schedules.import');
});
