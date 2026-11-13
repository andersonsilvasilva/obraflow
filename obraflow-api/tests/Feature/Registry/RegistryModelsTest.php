<?php

use App\Models\Company;
use App\Models\CompanyAddress;
use App\Models\CompanyBranch;
use App\Models\CompanyBranchContact;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\Supplier;
use App\Models\SupplierAddress;
use App\Models\UnitOfMeasure;
use App\Modules\Tenancy\Exceptions\MissingTenantContext;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Relations\Relation;
use Tests\Support\Scenario;

/* Fase 2 — modelos: escopo de tenant fail-closed, tenant_id vindo do contexto, normalização e morph map. */

beforeEach(function () {
    Scenario::catalog();
    $this->A = Scenario::tenant();
    $this->B = Scenario::tenant();
});

dataset('tenant_models', [
    UnitOfMeasure::class, Company::class, CompanyBranch::class, CompanyAddress::class, CompanyBranchContact::class,
    Customer::class, CustomerContact::class, Supplier::class, SupplierAddress::class, Document::class, DocumentCategory::class,
]);

it('consultar sem tenant ativo lança exceção (nunca devolve "tudo")', function (string $model) {
    expect(fn () => $model::query()->count())->toThrow(MissingTenantContext::class);
})->with('tenant_models');

it('tenant_id vem do contexto e ignora valor enviado; cada tenant só enxerga o seu', function () {
    $ctx = app(TenantContext::class);

    $coA = $ctx->run($this->A, fn () => Company::create(['legal_name' => 'DEMO A', 'document_number' => '11222333000181', 'tenant_id' => $this->B->id]));
    $ctx->run($this->B, fn () => Company::create(['legal_name' => 'DEMO B', 'document_number' => '11222333000181']));

    expect($coA->tenant_id)->toBe($this->A->id)
        ->and($ctx->run($this->A, fn () => Company::pluck('legal_name')->all()))->toBe(['DEMO A'])
        ->and($ctx->run($this->B, fn () => Company::find($coA->id)))->toBeNull();
});

it('documento, CEP e UF são normalizados (só dígitos / maiúsculas)', function () {
    $ctx = app(TenantContext::class);

    [$co, $addr, $cu] = $ctx->run($this->A, function () {
        $co = Company::create(['legal_name' => 'DEMO', 'document_number' => '11.222.333/0001-81']);
        $addr = CompanyAddress::create(['company_id' => $co->id, 'street' => 'Rua DEMO', 'city' => 'Cuiabá', 'state' => 'mt', 'postal_code' => '78.000-000']);
        $cu = Customer::create(['company_id' => $co->id, 'type' => 'person', 'document_number' => '529.982.247-25', 'name' => 'DEMO']);

        return [$co, $addr, $cu];
    });

    expect($co->document_number)->toBe('11222333000181')
        ->and($addr->postal_code)->toBe('78000000')
        ->and($addr->state)->toBe('MT')
        ->and($cu->document_number)->toBe('52998224725')
        ->and($co->uuid)->toMatch('/^[0-9a-f-]{36}$/')
        ->and($co->getRouteKeyName())->toBe('uuid');
});

it('soft delete libera o CNPJ para recadastro pela aplicação', function () {
    app(TenantContext::class)->run($this->A, function () {
        Company::create(['legal_name' => 'DEMO', 'document_number' => '11222333000181'])->delete();
        $novo = Company::create(['legal_name' => 'DEMO 2', 'document_number' => '11222333000181']);

        expect($novo->exists)->toBeTrue()
            ->and(Company::withTrashed()->count())->toBe(2)
            ->and(Company::count())->toBe(1);
    });
});

it('morph map da Fase 2 usa aliases curtos (os aceitos pelo CHECK de document_links)', function () {
    expect(Relation::getMorphedModel('company'))->toBe(Company::class)
        ->and(Relation::getMorphedModel('company_branch'))->toBe(CompanyBranch::class)
        ->and(Relation::getMorphedModel('customer'))->toBe(Customer::class)
        ->and(Relation::getMorphedModel('supplier'))->toBe(Supplier::class)
        ->and((new Supplier)->getMorphClass())->toBe('supplier');
});
