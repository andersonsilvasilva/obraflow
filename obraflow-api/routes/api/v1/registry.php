<?php

use App\Modules\Registry\Http\Controllers\CompanyController;
use App\Modules\Registry\Http\Controllers\CustomerController;
use App\Modules\Registry\Http\Controllers\DocumentController;
use App\Modules\Registry\Http\Controllers\OwnedDetailController;
use App\Modules\Registry\Http\Controllers\SupplierController;
use App\Modules\Registry\Http\Controllers\UnitController;
use Illuminate\Support\Facades\Route;

/*
| Fase 2 — Cadastros. TODAS as rotas no grupo `tenant.required` (o teste estrutural da D-29 verifica).
| Entidades expostas por uuid (empresas, clientes, fornecedores); filiais, endereços, contatos e unidades por id
| (sempre consultados no escopo do tenant ativo — id de outro tenant responde 404).
*/

Route::middleware('tenant.required')->group(function (): void {
    // Unidades de medida
    Route::get('units', [UnitController::class, 'index'])->middleware('can:units.view')->name('units.index');
    Route::post('units', [UnitController::class, 'store'])->middleware('can:units.manage')->name('units.store');
    Route::put('units/{id}', [UnitController::class, 'update'])->whereNumber('id')->middleware('can:units.manage')->name('units.update');

    // Empresas
    Route::get('companies', [CompanyController::class, 'index'])->middleware('can:companies.view')->name('companies.index');
    Route::post('companies', [CompanyController::class, 'store'])->middleware('can:companies.manage')->name('companies.store');
    Route::get('companies/{uuid}', [CompanyController::class, 'show'])->whereUuid('uuid')->middleware('can:companies.view')->name('companies.show');
    Route::put('companies/{uuid}', [CompanyController::class, 'update'])->whereUuid('uuid')->middleware('can:companies.manage')->name('companies.update');
    Route::delete('companies/{uuid}', [CompanyController::class, 'destroy'])->whereUuid('uuid')->middleware('can:companies.manage')->name('companies.destroy');

    // Filiais
    Route::get('companies/{uuid}/branches', [CompanyController::class, 'branches'])->whereUuid('uuid')->middleware('can:companies.view')->name('branches.index');
    Route::post('companies/{uuid}/branches', [CompanyController::class, 'storeBranch'])->whereUuid('uuid')->middleware('can:companies.manage')->name('branches.store');
    Route::get('branches/{id}', [CompanyController::class, 'showBranch'])->whereNumber('id')->middleware('can:companies.view')->name('branches.show');
    Route::put('branches/{id}', [CompanyController::class, 'updateBranch'])->whereNumber('id')->middleware('can:companies.manage')->name('branches.update');
    Route::delete('branches/{id}', [CompanyController::class, 'destroyBranch'])->whereNumber('id')->middleware('can:companies.manage')->name('branches.destroy');

    // Clientes e fornecedores
    foreach (['customers' => CustomerController::class, 'suppliers' => SupplierController::class] as $prefix => $controller) {
        Route::get($prefix, [$controller, 'index'])->middleware("can:{$prefix}.view")->name("{$prefix}.index");
        Route::post($prefix, [$controller, 'store'])->middleware("can:{$prefix}.manage")->name("{$prefix}.store");
        Route::get("{$prefix}/{uuid}", [$controller, 'show'])->whereUuid('uuid')->middleware("can:{$prefix}.view")->name("{$prefix}.show");
        Route::put("{$prefix}/{uuid}", [$controller, 'update'])->whereUuid('uuid')->middleware("can:{$prefix}.manage")->name("{$prefix}.update");
        Route::delete("{$prefix}/{uuid}", [$controller, 'destroy'])->whereUuid('uuid')->middleware("can:{$prefix}.manage")->name("{$prefix}.destroy");
    }

    // Documentos e categorias (acesso fino por documento no DocumentAccess)
    Route::get('document-categories', [DocumentController::class, 'categories'])->middleware('can:documents.view')->name('document-categories.index');
    Route::post('document-categories', [DocumentController::class, 'storeCategory'])->middleware('can:documents.manage')->name('document-categories.store');
    Route::put('document-categories/{id}', [DocumentController::class, 'updateCategory'])->whereNumber('id')->middleware('can:documents.manage')->name('document-categories.update');
    Route::get('documents', [DocumentController::class, 'index'])->middleware('can:documents.view')->name('documents.index');
    Route::post('documents', [DocumentController::class, 'store'])->middleware('can:documents.manage')->name('documents.store');
    Route::get('documents/{uuid}', [DocumentController::class, 'show'])->whereUuid('uuid')->middleware('can:documents.view')->name('documents.show');
    Route::put('documents/{uuid}', [DocumentController::class, 'update'])->whereUuid('uuid')->middleware('can:documents.view')->name('documents.update');
    Route::post('documents/{uuid}/versions', [DocumentController::class, 'storeVersion'])->whereUuid('uuid')->middleware('can:documents.view')->name('documents.versions.store');
    Route::post('documents/{uuid}/versions/{number}/url', [DocumentController::class, 'url'])->whereUuid('uuid')->whereNumber('number')->middleware('can:documents.view')->name('documents.versions.url');
    Route::post('documents/{uuid}/links', [DocumentController::class, 'link'])->whereUuid('uuid')->middleware('can:documents.view')->name('documents.links.store');
    Route::delete('documents/{uuid}/links/{linkId}', [DocumentController::class, 'unlink'])->whereUuid('uuid')->whereNumber('linkId')->middleware('can:documents.view')->name('documents.links.destroy');
    Route::put('documents/{uuid}/permissions', [DocumentController::class, 'permissions'])->whereUuid('uuid')->middleware('can:documents.view')->name('documents.permissions.update');

    // Endereços e contatos de cada dono (permissão do módulo do dono; filial usa a de empresas)
    $owners = ['companies' => 'companies', 'branches' => 'companies', 'customers' => 'customers', 'suppliers' => 'suppliers'];
    foreach ($owners as $ownerType => $module) {
        foreach (['addresses' => 'Address', 'contacts' => 'Contact'] as $detail => $suffix) {
            $base = "{$ownerType}/{owner}/{$detail}";
            $name = "{$ownerType}.{$detail}";

            Route::get($base, [OwnedDetailController::class, $detail])->defaults('owner_type', $ownerType)
                ->middleware("can:{$module}.view")->name("{$name}.index");
            Route::post($base, [OwnedDetailController::class, "store{$suffix}"])->defaults('owner_type', $ownerType)
                ->middleware("can:{$module}.manage")->name("{$name}.store");
            Route::put("{$base}/{id}", [OwnedDetailController::class, "update{$suffix}"])->defaults('owner_type', $ownerType)
                ->whereNumber('id')->middleware("can:{$module}.manage")->name("{$name}.update");
            Route::delete("{$base}/{id}", [OwnedDetailController::class, "destroy{$suffix}"])->defaults('owner_type', $ownerType)
                ->whereNumber('id')->middleware("can:{$module}.manage")->name("{$name}.destroy");
        }
    }
});
