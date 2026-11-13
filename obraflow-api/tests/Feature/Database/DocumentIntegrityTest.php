<?php

use Illuminate\Support\Facades\DB;
use Tests\Support\Fx;

/* Fase 2 — etapa 3: integridade das tabelas de documentos no banco. */

beforeEach(function () {
    $this->A = Fx::tenant();
    $this->B = Fx::tenant();
    $this->uA = Fx::user();
    $this->uB = Fx::user();
    $this->outsider = Fx::user(); // sem vínculo em A
    Fx::member($this->A, $this->uA);
    Fx::member($this->B, $this->uB);
    $this->docA = Fx::document($this->A, $this->uA);
});

it('autor e quem envia a versão precisam ter vínculo no tenant (FK composta → tenant_users)', function () {
    expect(Fx::errno(fn () => Fx::document($this->A, $this->outsider)))->toBe(1452)
        ->and(Fx::errno(fn () => Fx::document($this->A, $this->uB)))->toBe(1452)
        ->and(Fx::errno(fn () => Fx::version($this->A, $this->docA, $this->outsider)))->toBe(1452)
        ->and(Fx::errno(fn () => Fx::version($this->A, $this->docA, $this->uA)))->toBeNull();
});

it('versão, vínculo e permissão só apontam para documento do mesmo tenant', function () {
    expect(Fx::errno(fn () => Fx::version($this->B, $this->docA, $this->uB)))->toBe(1452)
        ->and(Fx::errno(fn () => DB::table('document_links')->insert([
            'tenant_id' => $this->B, 'document_id' => $this->docA, 'linkable_type' => 'company', 'linkable_id' => 1,
        ])))->toBe(1452)
        ->and(Fx::errno(fn () => DB::table('document_permissions')->insert([
            'tenant_id' => $this->B, 'document_id' => $this->docA, 'subject_type' => 'user', 'subject_id' => $this->uB, 'ability' => 'view',
        ])))->toBe(1452);
});

it('categoria: pai e categoria do documento são do mesmo tenant', function () {
    $catA = DB::table('document_categories')->insertGetId(['tenant_id' => $this->A, 'code' => 'contratos', 'name' => 'Contratos']);
    $catB = DB::table('document_categories')->insertGetId(['tenant_id' => $this->B, 'code' => 'contratos', 'name' => 'Contratos']);

    expect(Fx::errno(fn () => DB::table('document_categories')->insert(['tenant_id' => $this->A, 'parent_id' => $catB, 'code' => 'x', 'name' => 'x'])))->toBe(1452)
        ->and(Fx::errno(fn () => DB::table('document_categories')->insert(['tenant_id' => $this->A, 'parent_id' => $catA, 'code' => 'x', 'name' => 'x'])))->toBeNull()
        ->and(Fx::errno(fn () => Fx::document($this->A, $this->uA, $catB)))->toBe(1452)
        ->and(Fx::errno(fn () => DB::table('document_categories')->insert(['tenant_id' => $this->A, 'code' => 'contratos', 'name' => 'dup'])))->toBe(1062);
});

it('versão corrente precisa ser DESTE documento (FK composta tenant+documento+versão)', function () {
    $outroDoc = Fx::document($this->A, $this->uA);
    $vOutro = Fx::version($this->A, $outroDoc, $this->uA);
    $vProprio = Fx::version($this->A, $this->docA, $this->uA);

    expect(Fx::errno(fn () => DB::table('documents')->where('id', $this->docA)->update(['current_version_id' => $vOutro])))->toBe(1452)
        ->and(Fx::errno(fn () => DB::table('documents')->where('id', $this->docA)->update(['current_version_id' => $vProprio])))->toBeNull();
});

it('número de versão é único por documento e começa em 1; sha256 é hexadecimal minúsculo de 64', function () {
    Fx::version($this->A, $this->docA, $this->uA, 1);

    expect(Fx::errno(fn () => Fx::version($this->A, $this->docA, $this->uA, 1)))->toBe(1062)
        ->and(Fx::errno(fn () => Fx::version($this->A, $this->docA, $this->uA, 0)))->toBe(3819)
        ->and(Fx::errno(fn () => Fx::version($this->A, $this->docA, $this->uA, 2, ['sha256' => str_repeat('G', 64)])))->toBe(3819)
        ->and(Fx::errno(fn () => Fx::version($this->A, $this->docA, $this->uA, 2, ['sha256' => strtoupper(hash('sha256', 'x'))])))->toBe(3819);
});

it('a mesma chave de armazenamento não pode ser reutilizada', function () {
    Fx::version($this->A, $this->docA, $this->uA, 1, ['storage_key' => 'k/fixa']);

    expect(Fx::errno(fn () => Fx::version($this->A, $this->docA, $this->uA, 2, ['storage_key' => 'k/fixa'])))->toBe(1062);
});

it('domínios fechados: status, visibilidade, tipo de vínculo, sujeito e ability (CHECK)', function () {
    $link = fn (string $type) => DB::table('document_links')->insert(['tenant_id' => $this->A, 'document_id' => $this->docA, 'linkable_type' => $type, 'linkable_id' => 1]);
    $perm = fn (string $subject, string $ability) => DB::table('document_permissions')->insert([
        'tenant_id' => $this->A, 'document_id' => $this->docA, 'subject_type' => $subject, 'subject_id' => 1, 'ability' => $ability,
    ]);

    expect(Fx::errno(fn () => DB::table('documents')->where('id', $this->docA)->update(['status' => 'deleted'])))->toBe(3819)
        ->and(Fx::errno(fn () => DB::table('documents')->where('id', $this->docA)->update(['visibility' => 'public'])))->toBe(3819)
        ->and(Fx::errno(fn () => $link('App\\Models\\Company')))->toBe(3819)
        ->and(Fx::errno(fn () => $link('user')))->toBe(3819)
        ->and(Fx::errno(fn () => $link('customer')))->toBeNull()
        ->and(Fx::errno(fn () => $perm('project', 'view')))->toBe(3819) // obras só na Fase 3
        ->and(Fx::errno(fn () => $perm('user', 'delete')))->toBe(3819)
        ->and(Fx::errno(fn () => $perm('role', 'download')))->toBeNull();
});

it('documento com versão não pode ser apagado fisicamente (RESTRICT)', function () {
    Fx::version($this->A, $this->docA, $this->uA);

    expect(Fx::errno(fn () => DB::table('documents')->where('id', $this->docA)->delete()))->toBe(1451);
});
