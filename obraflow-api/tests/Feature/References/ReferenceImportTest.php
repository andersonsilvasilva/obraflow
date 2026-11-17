<?php

use App\Models\AuditLog;
use App\Models\ReferenceComposition;
use App\Models\ReferenceDataset;
use App\Models\ReferenceResource;
use App\Modules\Audit\AuditEvent;
use App\Modules\References\Import\Number;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fx;
use Tests\Support\SinapiFixture;
use Tests\Support\XlsxFixture;

/*
 * Fase 4A — importação de referências (D-P29/D-P30). Recorte com o MESMO layout dos arquivos SINAPI
 * (cabeçalhos e linhas copiados da base MT 12/2024 desonerada; dados públicos).
 */

function importRefs(array $opts): int
{
    return Artisan::call('obraflow:references:import', ['--competency' => '2024-12', '--state' => 'MT', '--regime' => 'with_relief', ...$opts]);
}

function refCounts(): array
{
    return array_map(fn ($t) => DB::table($t)->count(), [
        'ds' => 'reference_datasets', 'imp' => 'reference_imports', 'res' => 'reference_resources', 'comp' => 'reference_compositions', 'items' => 'reference_composition_items',
    ]);
}

it('importa insumos, composições e famílias com os valores exatamente como publicados, e ativa a base', function () {
    $code = importRefs(['--resources' => SinapiFixture::insumos(), '--compositions' => SinapiFixture::analitico(), '--families' => SinapiFixture::familias(), '--activate' => true]);
    expect($code)->toBe(0, Artisan::output());

    $ds = ReferenceDataset::query()->sole();
    expect($ds->status)->toBe('active')->and($ds->activated_at)->not->toBeNull()->and($ds->source->code)->toBe('SINAPI');

    $c = ReferenceComposition::query()->where('code', '97141')->sole();
    expect($c->total_cost)->toBe('3.84')->and($c->labor_cost)->toBe('1.49')->and($c->material_cost)->toBe('0.93')
        ->and($c->equipment_cost)->toBe('1.42')->and($c->class_code)->toBe('ASTU')->and($c->unit->code)->toBe('M');

    $items = $c->items()->with(['resource', 'child'])->get();
    expect($items)->toHaveCount(3)
        ->and($items[0]->resource->code)->toBe('20078')->and($items[0]->coefficient)->toBe('0.0046000')->and($items[0]->cost)->toBe('0.14')
        ->and($items[2]->child->code)->toBe('88316') // filha declarada depois no arquivo: resolvida
        ->and($items[2]->coefficient)->toBe('0.0320000');

    $pedreiro = ReferenceResource::query()->where('code', '4750')->sole();
    expect($pedreiro->price->price)->toBe('1234.56') // "1.234,56" → decimal exato
        ->and($pedreiro->kind)->toBe('labor');

    $citado = ReferenceResource::query()->where('code', '99999')->sole();
    expect($citado->price)->toBeNull() // só citado na composição: sem preço inventado
        ->and($citado->kind)->toBe('equipment') // EQUIPAMENTO (LOCAÇÃO)
        ->and(ReferenceResource::query()->where('code', '20078')->value('kind'))->toBeNull() // ESPECIAIS: sem tipo
        ->and(ReferenceResource::query()->where('code', '20078')->value('family_class'))->toBe('ESPECIAIS');

    $logs = AuditLog::query()->whereIn('event', AuditEvent::phase4())->get();
    expect($logs->pluck('event')->countBy()->all())->toEqual([AuditEvent::REFERENCE_IMPORTED => 3, AuditEvent::REFERENCE_ACTIVATED => 1])
        ->and($logs->pluck('tenant_id')->unique()->all())->toBe([null])
        ->and($logs->firstWhere('event', AuditEvent::REFERENCE_IMPORTED)->metadata['sha256'])->toMatch('/^[0-9a-f]{64}$/');
});

it('é idempotente pelo hash do arquivo e recusa outro arquivo para um tipo já importado (base imutável)', function () {
    $insumos = SinapiFixture::insumos();
    importRefs(['--resources' => $insumos]);
    $antes = refCounts();

    expect(importRefs(['--resources' => $insumos]))->toBe(0)
        ->and(Artisan::output())->toContain('já importado')
        ->and(refCounts())->toBe($antes);

    $outro = SinapiFixture::insumos(extra: [['A' => '1', 'B' => 'OUTRO', 'C' => 'UN', 'D' => 'CR', 'E' => '1,00']]);
    expect(importRefs(['--resources' => $outro]))->toBe(1)
        ->and(Artisan::output())->toContain('Bases são imutáveis')
        ->and(refCounts())->toBe($antes);
});

it('recusa arquivo de outro regime de encargos ou de outra competência, sem gravar nada', function () {
    expect(importRefs(['--resources' => SinapiFixture::insumos(encargos: 'NAO DESONERADOS')]))->toBe(1)
        ->and(Artisan::output())->toContain('without_relief')
        ->and(importRefs(['--resources' => SinapiFixture::insumos(mes: '11/2024')]))->toBe(1)
        ->and(Artisan::output())->toContain('competência 2024-11')
        ->and(refCounts())->toBe(['ds' => 0, 'imp' => 0, 'res' => 0, 'comp' => 0, 'items' => 0]);
});

it('aborta tudo se uma composição filha citada não existe no arquivo', function () {
    expect(importRefs(['--resources' => SinapiFixture::insumos(), '--compositions' => SinapiFixture::analitico(comFilha: false)]))->toBe(1)
        ->and(Artisan::output())->toContain('97141 → 77777')
        ->and(refCounts()['res'])->toBe(0); // nem os insumos do mesmo comando ficaram
});

it('--dry-run executa e desfaz', function () {
    expect(importRefs(['--resources' => SinapiFixture::insumos(), '--compositions' => SinapiFixture::analitico(), '--activate' => true, '--dry-run' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('Simulação')
        ->and(refCounts())->toBe(['ds' => 0, 'imp' => 0, 'res' => 0, 'comp' => 0, 'items' => 0])
        ->and(AuditLog::query()->whereIn('event', AuditEvent::phase4())->count())->toBe(0);
});

it('ativar exige insumos e composições; nova competência ativa substitui a anterior (superseded)', function () {
    expect(importRefs(['--resources' => SinapiFixture::insumos(), '--activate' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('insumos e composições');

    importRefs(['--resources' => SinapiFixture::insumos(), '--compositions' => SinapiFixture::analitico(), '--activate' => true]);
    // mesma fonte/UF/regime, competência seguinte (recorte com mês ajustado)
    $jan = XlsxFixture::write(array_map(fn ($r) => array_map(fn ($v) => str_replace('12/2024', '01/2025', $v), $r), [
        ['A' => 'MES DE COLETA: 12/2024'], ['A' => 'ENCARGOS SOCIAIS DESONERADOS'],
        ['A' => 'CODIGO', 'B' => 'DESCRICAO DO INSUMO', 'C' => 'UNIDADE DE MEDIDA', 'D' => 'ORIGEM DO PRECO', 'E' => 'PRECO MEDIANO R$'],
        ['A' => '4750', 'B' => 'PEDREIRO (HORISTA)', 'C' => 'H', 'D' => 'CR', 'E' => '19,00'],
    ]));
    $janComp = XlsxFixture::write([
        ['A' => 'ENCARGOS SOCIAIS DESONERADOS'], ['A' => 'DATA DE PREÇO : 01/2025'], SinapiFixture::HEADER,
        ['G' => '88316', 'H' => 'SERVENTE', 'I' => 'H', 'K' => '19,00'],
        ['G' => '88316', 'H' => 'SERVENTE', 'I' => 'H', 'K' => '19,00', 'L' => 'INSUMO', 'M' => '4750', 'N' => 'PEDREIRO', 'O' => 'H', 'Q' => '1,0000000', 'R' => '19,00', 'S' => '19,00'],
    ]);
    expect(Artisan::call('obraflow:references:import', ['--competency' => '2025-01', '--state' => 'MT', '--regime' => 'with_relief',
        '--resources' => $jan, '--compositions' => $janComp, '--activate' => true]))->toBe(0, Artisan::output());

    expect(ReferenceDataset::query()->orderBy('version')->pluck('status', 'version')->all())->toBe(['2024-12' => 'superseded', '2025-01' => 'active']);
});

it('valida as opções obrigatórias', function () {
    expect(Artisan::call('obraflow:references:import', ['--competency' => '12/2024', '--state' => 'MT', '--regime' => 'with_relief']))->toBe(1)
        ->and(Artisan::call('obraflow:references:import', ['--competency' => '2024-12', '--state' => 'MT', '--regime' => 'desonerado']))->toBe(1);
});

// ---------------------------------------------------------------- integridade do banco
it('banco: item de composição não aponta para insumo/composição de outra base; exatamente um alvo; domínios', function () {
    $src = DB::table('reference_sources')->insertGetId(['code' => 'SINAPI', 'name' => 'SINAPI']);
    $unit = DB::table('reference_units')->insertGetId(['code' => 'UN']);
    $ds = fn (string $v) => DB::table('reference_datasets')->insertGetId(['source_id' => $src, 'version' => $v, 'state' => 'MT', 'charges_regime' => 'with_relief']);
    [$a, $b] = [$ds('2024-12'), $ds('2025-01')];
    $res = fn (int $d) => DB::table('reference_resources')->insertGetId(['dataset_id' => $d, 'code' => uniqid('R'), 'description' => 'x', 'unit_id' => $unit]);
    $comp = fn (int $d, string $c) => DB::table('reference_compositions')->insertGetId(['dataset_id' => $d, 'code' => $c, 'description' => 'x', 'unit_id' => $unit, 'total_cost' => 1]);
    $ca = $comp($a, 'C1');
    $rb = $res($b);
    $item = fn (array $x) => DB::table('reference_composition_items')->insert(['dataset_id' => $a, 'composition_id' => $ca, 'coefficient' => 1, ...$x]);

    expect(Fx::errno(fn () => $item(['resource_id' => $res($a)])))->toBeNull()
        ->and(Fx::errno(fn () => $item(['resource_id' => $rb])))->toBe(1452) // insumo de outra base
        ->and(Fx::errno(fn () => $item(['child_composition_id' => $comp($b, 'C2')])))->toBe(1452) // filha de outra base
        ->and(Fx::errno(fn () => $item([])))->toBe(3819) // nenhum alvo
        ->and(Fx::errno(fn () => $item(['resource_id' => $res($a), 'child_composition_id' => $comp($a, 'C3')])))->toBe(3819) // dois alvos
        ->and(Fx::errno(fn () => $item(['resource_id' => $rb, 'coefficient' => -1])))->not->toBeNull()
        ->and(Fx::errno(fn () => $ds('2024-13')))->toBe(3819)
        ->and(Fx::errno(fn () => $ds('2024-12')))->toBe(1062)
        ->and(Fx::errno(fn () => DB::table('reference_datasets')->insert(['source_id' => $src, 'version' => '2024-12', 'state' => 'MT', 'charges_regime' => 'mixed'])))->toBe(3819);
});

it('Number::decimal converte o formato publicado sem passar por float e arredonda meio-para-cima', function () {
    expect(Number::decimal('3,84'))->toBe('3.84')
        ->and(Number::decimal('1.234,56'))->toBe('1234.56')
        ->and(Number::decimal('0,0046000', 7))->toBe('0.0046000')
        ->and(Number::decimal('0.21917800000000001', 7))->toBe('0.2191780')
        ->and(Number::decimal('2,345'))->toBe('2.35')
        ->and(Number::decimal('2,344'))->toBe('2.34')
        ->and(Number::decimal(''))->toBeNull()
        ->and(fn () => Number::decimal('abc'))->toThrow(InvalidArgumentException::class);
});
