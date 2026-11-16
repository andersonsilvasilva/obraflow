<?php

use App\Modules\Teams\Http\Controllers\CatalogController;
use App\Modules\Teams\Http\Controllers\EmployeeController;
use App\Modules\Teams\Http\Controllers\EquipmentController;
use App\Modules\Teams\Http\Controllers\ResourceGroupController;
use Illuminate\Support\Facades\Route;

/*
| Fase 3 — Equipes (funcionários, cargos, setores, certificações) e equipamentos. TODAS em `tenant.required`.
| Cargos e setores sob employees.*; grupos de recursos sob equipment.* (D-P28).
*/

Route::middleware('tenant.required')->group(function (): void {
    foreach (['job-titles' => 'job_titles', 'departments' => 'departments'] as $path => $catalog) {
        Route::get($path, [CatalogController::class, 'index'])->defaults('catalog', $catalog)->middleware('can:employees.view')->name("{$path}.index");
        Route::post($path, [CatalogController::class, 'store'])->defaults('catalog', $catalog)->middleware('can:employees.manage')->name("{$path}.store");
        Route::put("{$path}/{id}", [CatalogController::class, 'update'])->defaults('catalog', $catalog)->whereNumber('id')->middleware('can:employees.manage')->name("{$path}.update");
        Route::delete("{$path}/{id}", [CatalogController::class, 'destroy'])->defaults('catalog', $catalog)->whereNumber('id')->middleware('can:employees.manage')->name("{$path}.destroy");
    }

    // Funcionários e certificações
    Route::get('employees', [EmployeeController::class, 'index'])->middleware('can:employees.view')->name('employees.index');
    Route::post('employees', [EmployeeController::class, 'store'])->middleware('can:employees.manage')->name('employees.store');
    Route::get('employees/{uuid}', [EmployeeController::class, 'show'])->whereUuid('uuid')->middleware('can:employees.view')->name('employees.show');
    Route::put('employees/{uuid}', [EmployeeController::class, 'update'])->whereUuid('uuid')->middleware('can:employees.manage')->name('employees.update');
    Route::delete('employees/{uuid}', [EmployeeController::class, 'destroy'])->whereUuid('uuid')->middleware('can:employees.manage')->name('employees.destroy');
    Route::get('employees/{uuid}/certifications', [EmployeeController::class, 'certifications'])->whereUuid('uuid')->middleware('can:employees.view')->name('employees.certifications.index');
    Route::post('employees/{uuid}/certifications', [EmployeeController::class, 'storeCertification'])->whereUuid('uuid')->middleware('can:employees.manage')->name('employees.certifications.store');
    Route::put('employees/{uuid}/certifications/{id}', [EmployeeController::class, 'updateCertification'])->whereUuid('uuid')->whereNumber('id')->middleware('can:employees.manage')->name('employees.certifications.update');
    Route::delete('employees/{uuid}/certifications/{id}', [EmployeeController::class, 'destroyCertification'])->whereUuid('uuid')->whereNumber('id')->middleware('can:employees.manage')->name('employees.certifications.destroy');

    // Grupos de recursos (equipamentos) e equipamentos
    Route::get('resource-groups', [ResourceGroupController::class, 'index'])->middleware('can:equipment.view')->name('resource-groups.index');
    Route::post('resource-groups', [ResourceGroupController::class, 'store'])->middleware('can:equipment.manage')->name('resource-groups.store');
    Route::put('resource-groups/{id}', [ResourceGroupController::class, 'update'])->whereNumber('id')->middleware('can:equipment.manage')->name('resource-groups.update');
    Route::delete('resource-groups/{id}', [ResourceGroupController::class, 'destroy'])->whereNumber('id')->middleware('can:equipment.manage')->name('resource-groups.destroy');
    Route::get('equipment', [EquipmentController::class, 'index'])->middleware('can:equipment.view')->name('equipment.index');
    Route::post('equipment', [EquipmentController::class, 'store'])->middleware('can:equipment.manage')->name('equipment.store');
    Route::get('equipment/{uuid}', [EquipmentController::class, 'show'])->whereUuid('uuid')->middleware('can:equipment.view')->name('equipment.show');
    Route::put('equipment/{uuid}', [EquipmentController::class, 'update'])->whereUuid('uuid')->middleware('can:equipment.manage')->name('equipment.update');
    Route::delete('equipment/{uuid}', [EquipmentController::class, 'destroy'])->whereUuid('uuid')->middleware('can:equipment.manage')->name('equipment.destroy');
});
