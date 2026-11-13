<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Fx;

/*
 * Fase 2 — etapa 1: unidades, empresas, filiais e seus endereços/contatos.
 * O banco é a última linha de defesa do isolamento: FKs compostas recusam vínculos entre tenants (1452),
 * CHECK recusa valores fora do domínio (3819), UNIQUE recusa duplicidade (1062).
 */

beforeEach(function () {
    $this->A = Fx::tenant();
    $this->B = Fx::tenant();
    $this->coA = Fx::company($this->A);
    $this->coB = Fx::company($this->B);
});

// ---------------------------------------------------------------- unidades de medida
it('unidade de medida é por tenant: mesmo código em tenants diferentes, nunca duplicado no mesmo', function () {
    $ins = fn (int $t, string $code) => DB::table('units_of_measure')->insert(['tenant_id' => $t, 'code' => $code, 'name' => $code]);

    expect(Fx::errno(fn () => $ins($this->A, 'm2')))->toBeNull()
        ->and(Fx::errno(fn () => $ins($this->B, 'm2')))->toBeNull()
        ->and(Fx::errno(fn () => $ins($this->A, 'm2')))->toBe(1062)
        ->and(Fx::errno(fn () => DB::table('units_of_measure')->insert(['tenant_id' => null, 'code' => 'kg', 'name' => 'kg'])))->toBe(1048);
});

// ---------------------------------------------------------------- empresas
it('o mesmo CNPJ pode existir em tenants diferentes, mas não duas vezes ativo no mesmo tenant', function () {
    expect(Fx::errno(fn () => Fx::company($this->A)))->toBe(1062)
        ->and(Fx::errno(fn () => Fx::company($this->B, '99888777000155')))->toBeNull();
});

it('CNPJ de empresa excluída (soft delete) pode ser recadastrado (active_marker, D-P13)', function () {
    DB::table('companies')->where('id', $this->coA)->update(['deleted_at' => now()]);

    expect(Fx::errno(fn () => Fx::company($this->A)))->toBeNull()
        ->and(DB::table('companies')->where('tenant_id', $this->A)->count())->toBe(2);
});

it('recusa CNPJ fora do formato (só 14 dígitos) e status inválido (CHECK)', function () {
    expect(Fx::errno(fn () => Fx::company($this->A, '11.222.333/0001-81')))->not->toBeNull()
        ->and(Fx::errno(fn () => Fx::company($this->A, '1122233300018X')))->toBe(3819)
        ->and(Fx::errno(fn () => Fx::company($this->A, '55666777000199', ['status' => 'blocked'])))->toBe(3819);
});

// ---------------------------------------------------------------- filiais
it('filial só pertence a empresa do mesmo tenant (FK composta)', function () {
    expect(Fx::errno(fn () => Fx::branch($this->A, $this->coA)))->toBeNull()
        ->and(Fx::errno(fn () => Fx::branch($this->A, $this->coB)))->toBe(1452)
        ->and(Fx::errno(fn () => Fx::branch($this->B, $this->coA)))->toBe(1452);
});

it('CNPJ de filial: opcional, único entre as ativas do tenant, só dígitos', function () {
    expect(Fx::errno(fn () => Fx::branch($this->A, $this->coA)))->toBeNull()
        ->and(Fx::errno(fn () => Fx::branch($this->A, $this->coA)))->toBeNull() // dois NULL não conflitam
        ->and(Fx::errno(fn () => Fx::branch($this->A, $this->coA, '11222333000262')))->toBeNull()
        ->and(Fx::errno(fn () => Fx::branch($this->A, $this->coA, '11222333000262')))->toBe(1062)
        ->and(Fx::errno(fn () => Fx::branch($this->A, $this->coA, 'ABC')))->toBe(3819);
});

// ---------------------------------------------------------------- endereços e contatos (D-P12)
dataset('owners', [
    'empresa' => ['company_addresses', 'company_contacts', 'company_id', 'company'],
    'filial' => ['company_branch_addresses', 'company_branch_contacts', 'company_branch_id', 'branch'],
    'cliente' => ['customer_addresses', 'customer_contacts', 'customer_id', 'customer'],
    'fornecedor' => ['supplier_addresses', 'supplier_contacts', 'supplier_id', 'supplier'],
]);

function ownerOf(string $kind, int $tenant, int $company): int
{
    return match ($kind) {
        'company' => $company,
        'branch' => Fx::branch($tenant, $company),
        'customer' => Fx::customer($tenant, $company),
        'supplier' => Fx::supplier($tenant, $company),
    };
}

it('endereço e contato só se ligam a dono do mesmo tenant (FK composta)', function (string $addr, string $cont, string $col, string $kind) {
    $ownerA = ownerOf($kind, $this->A, $this->coA);
    $ownerB = ownerOf($kind, $this->B, $this->coB);

    expect(Fx::errno(fn () => Fx::address($addr, $col, $this->A, $ownerA)))->toBeNull()
        ->and(Fx::errno(fn () => Fx::address($addr, $col, $this->A, $ownerB, ['kind' => 'billing'])))->toBe(1452)
        ->and(Fx::errno(fn () => Fx::address($addr, $col, $this->B, $ownerA, ['kind' => 'billing'])))->toBe(1452)
        ->and(Fx::errno(fn () => Fx::contact($cont, $col, $this->A, $ownerA)))->toBeNull()
        ->and(Fx::errno(fn () => Fx::contact($cont, $col, $this->A, $ownerB)))->toBe(1452);
})->with('owners');

it('no máximo um endereço principal e um contato principal por dono', function (string $addr, string $cont, string $col, string $kind) {
    $owner = ownerOf($kind, $this->A, $this->coA);

    expect(Fx::errno(fn () => Fx::address($addr, $col, $this->A, $owner)))->toBeNull()
        ->and(Fx::errno(fn () => Fx::address($addr, $col, $this->A, $owner)))->toBe(1062)
        ->and(Fx::errno(fn () => Fx::address($addr, $col, $this->A, $owner, ['kind' => 'delivery'])))->toBeNull()
        ->and(Fx::errno(fn () => Fx::address($addr, $col, $this->A, $owner, ['kind' => 'delivery'])))->toBeNull()
        ->and(Fx::errno(fn () => Fx::contact($cont, $col, $this->A, $owner, true)))->toBeNull()
        ->and(Fx::errno(fn () => Fx::contact($cont, $col, $this->A, $owner, true)))->toBe(1062)
        ->and(Fx::errno(fn () => Fx::contact($cont, $col, $this->A, $owner)))->toBeNull()
        ->and(Fx::errno(fn () => Fx::contact($cont, $col, $this->A, $owner)))->toBeNull();
})->with('owners');

it('endereço recusa tipo, UF e CEP fora do domínio (CHECK)', function (string $addr, string $cont, string $col, string $kind) {
    $owner = ownerOf($kind, $this->A, $this->coA);

    expect(Fx::errno(fn () => Fx::address($addr, $col, $this->A, $owner, ['kind' => 'home'])))->toBe(3819)
        ->and(Fx::errno(fn () => Fx::address($addr, $col, $this->A, $owner, ['state' => 'mt'])))->toBe(3819)
        ->and(Fx::errno(fn () => Fx::address($addr, $col, $this->A, $owner, ['state' => '1A'])))->toBe(3819)
        ->and(Fx::errno(fn () => Fx::address($addr, $col, $this->A, $owner, ['postal_code' => '7800000A'])))->toBe(3819)
        ->and(Fx::errno(fn () => Fx::address($addr, $col, $this->A, $owner, ['postal_code' => null])))->toBeNull();
})->with('owners');

// ---------------------------------------------------------------- clientes e fornecedores (etapa 2)
dataset('parties', [
    'cliente' => ['customers', 'customer'],
    'fornecedor' => ['suppliers', 'supplier'],
]);

function party(string $kind, int $tenant, int $company, ?string $doc, string $type = 'company', array $extra = []): int
{
    return $kind === 'customer'
        ? Fx::customer($tenant, $company, $doc, $type, $extra)
        : Fx::supplier($tenant, $company, $doc, $type, $extra);
}

it('cliente/fornecedor só pertence a empresa do mesmo tenant (FK composta)', function (string $table, string $kind) {
    expect(Fx::errno(fn () => party($kind, $this->A, $this->coA, '11222333000181')))->toBeNull()
        ->and(Fx::errno(fn () => party($kind, $this->A, $this->coB, '99888777000155')))->toBe(1452)
        ->and(Fx::errno(fn () => party($kind, $this->B, $this->coA, '99888777000155')))->toBe(1452);
})->with('parties');

it('documento único entre os ativos da empresa; outra empresa ou cadastro excluído não conflitam', function (string $table, string $kind) {
    $coA2 = Fx::company($this->A, '55666777000199');
    $id = party($kind, $this->A, $this->coA, '11222333000181');

    expect(Fx::errno(fn () => party($kind, $this->A, $this->coA, '11222333000181')))->toBe(1062)
        ->and(Fx::errno(fn () => party($kind, $this->A, $coA2, '11222333000181')))->toBeNull();

    DB::table($table)->where('id', $id)->update(['deleted_at' => now()]);
    expect(Fx::errno(fn () => party($kind, $this->A, $this->coA, '11222333000181')))->toBeNull();
})->with('parties');

it('tipo exige documento compatível: pessoa = CPF (11), empresa = CNPJ (14) (CHECK)', function (string $table, string $kind) {
    expect(Fx::errno(fn () => party($kind, $this->A, $this->coA, '52998224725', 'person')))->toBeNull()
        ->and(Fx::errno(fn () => party($kind, $this->A, $this->coA, '11222333000181', 'person')))->toBe(3819)
        ->and(Fx::errno(fn () => party($kind, $this->A, $this->coA, '52998224725', 'company')))->toBe(3819)
        ->and(Fx::errno(fn () => party($kind, $this->A, $this->coA, '5299822472X', 'person')))->toBe(3819)
        ->and(Fx::errno(fn () => party($kind, $this->A, $this->coA, '99888777000155', 'other')))->toBe(3819)
        ->and(Fx::errno(fn () => party($kind, $this->A, $this->coA, '99888777000155', 'company', ['status' => 'x'])))->toBe(3819);
})->with('parties');

it('documento é opcional no cliente e obrigatório no fornecedor', function () {
    expect(Fx::errno(fn () => Fx::customer($this->A, $this->coA, null)))->toBeNull()
        ->and(Fx::errno(fn () => Fx::customer($this->A, $this->coA, null)))->toBeNull() // dois NULL não conflitam
        ->and(Fx::errno(fn () => DB::table('suppliers')->insert([
            'tenant_id' => $this->A, 'uuid' => (string) Str::uuid(), 'company_id' => $this->coA, 'type' => 'company',
            'document_number' => null, 'legal_name' => 'DEMO',
        ])))->toBe(1048);
});

it('cliente/fornecedor com endereço ou contato não pode ser apagado fisicamente, nem a empresa (RESTRICT)', function () {
    $c = Fx::customer($this->A, $this->coA);
    Fx::address('customer_addresses', 'customer_id', $this->A, $c);
    $s = Fx::supplier($this->A, $this->coA);
    Fx::contact('supplier_contacts', 'supplier_id', $this->A, $s);

    expect(Fx::errno(fn () => DB::table('customers')->where('id', $c)->delete()))->toBe(1451)
        ->and(Fx::errno(fn () => DB::table('suppliers')->where('id', $s)->delete()))->toBe(1451)
        ->and(Fx::errno(fn () => DB::table('companies')->where('id', $this->coA)->delete()))->toBe(1451);
});

// ---------------------------------------------------------------- exclusão física
it('exclusão física de empresa/filial com dependentes é recusada (RESTRICT)', function () {
    $branch = Fx::branch($this->A, $this->coA);
    Fx::address('company_branch_addresses', 'company_branch_id', $this->A, $branch);

    expect(Fx::errno(fn () => DB::table('companies')->where('id', $this->coA)->delete()))->toBe(1451)
        ->and(Fx::errno(fn () => DB::table('company_branches')->where('id', $branch)->delete()))->toBe(1451)
        ->and(Fx::errno(fn () => DB::table('tenants')->where('id', $this->A)->delete()))->toBe(1451);
});

it('tenant_id das tabelas da Fase 2 é NOT NULL e toda FK de dono é composta com tenant_id', function () {
    $tables = ['units_of_measure', 'companies', 'company_branches', 'company_addresses', 'company_contacts',
        'company_branch_addresses', 'company_branch_contacts', 'customers', 'suppliers', 'customer_addresses',
        'customer_contacts', 'supplier_addresses', 'supplier_contacts', 'document_categories', 'documents',
        'document_versions', 'document_links', 'document_permissions'];

    foreach ($tables as $t) {
        $nullable = DB::selectOne(
            'SELECT is_nullable AS n FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$t, 'tenant_id'],
        )->n;
        expect($nullable)->toBe('NO', "{$t}.tenant_id deveria ser NOT NULL");
    }

    // Toda FK entre tabelas da etapa 1 (exceto para tenants) deve ter 2 colunas, a primeira tenant_id.
    $fks = DB::select(
        "SELECT constraint_name AS c, table_name AS t, GROUP_CONCAT(column_name ORDER BY ordinal_position) AS cols
         FROM information_schema.key_column_usage
         WHERE table_schema = DATABASE() AND referenced_table_name IS NOT NULL AND referenced_table_name <> 'tenants'
           AND table_name IN ('".implode("','", $tables)."')
         GROUP BY constraint_name, table_name",
    );

    expect($fks)->toHaveCount(19);
    foreach ($fks as $fk) {
        expect(str_starts_with($fk->cols, 'tenant_id,'))->toBeTrue("{$fk->t}.{$fk->c} não é composta: {$fk->cols}");
    }
});
