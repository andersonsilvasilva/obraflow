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
use Tests\Support\XlsxFixture;

/*
 * Fase 4A — importação de referências (D-P29/D-P30). Recorte com o MESMO layout dos arquivos SINAPI
 * (cabeçalhos e linhas copiados da base MT 12/2024 desonerada; dados públicos).
 */

function insumosXlsx(string $mes = '12/2024', string $encargos = 'DESONERADOS', array $extra = []): string
{
    return XlsxFixture::write([
        ['A' => 'PRECOS DE INSUMOS - BANCO NACIONAL'],
        ['A' => "MES DE COLETA: {$mes}"],
        ['A' => 'LOCALIDADE: 4160 - CUIABA'],
        ['A' => "ENCARGOS SOCIAIS {$encargos} (%) HORISTA 79,65 MENSALISTA 43,45"],
        ['A' => 'CODIGO', 'B' => 'DESCRICAO DO INSUMO', 'C' => 'UNIDADE DE MEDIDA', 'D' => 'ORIGEM DO PRECO', 'E' => 'PRECO MEDIANO R$'],
        ['A' => '11270', 'B' => 'ABRACADEIRA DE LATAO PARA FIXACAO DE CABO PARA-RAIO, DIMENSOES 32 X 24 X 24 MM', 'C' => 'UN', 'D' => 'CR', 'E' => '2,66'],
        ['A' => '20078', 'B' => 'PASTA LUBRIFICANTE PARA TUBOS E CONEXOES COM JUNTA ELASTICA, EMBALAGEM DE *400* GR', 'C' => 'UN', 'D' => 'CR', 'E' => '31,75'],
        ['A' => '4750', 'B' => 'PEDREIRO (HORISTA)', 'C' => 'H', 'D' => 'CR', 'E' => '1.234,56'],
        ...$extra,
    ]);
}

const ANALITICO_HEADER = [
    'A' => 'DESCRICAO DA CLASSE', 'B' => 'SIGLA DA CLASSE', 'C' => 'DESCRICAO DO TIPO 1', 'D' => 'SIGLA DO TIPO 1', 'E' => 'CODIGO DO AGRUPADOR',
    'F' => 'DESCRICAO DO AGRUPADOR', 'G' => 'CODIGO DA COMPOSICAO', 'H' => 'DESCRICAO DA COMPOSICAO', 'I' => 'UNIDADE', 'J' => 'ORIGEM DE PREÇO',
    'K' => 'CUSTO TOTAL', 'L' => 'TIPO ITEM', 'M' => 'CODIGO ITEM', 'N' => 'DESCRIÇÃO ITEM', 'O' => 'UNIDADE ITEM', 'P' => 'ORIGEM DE PREÇO ITEM',
    'Q' => 'COEFICIENTE', 'R' => 'PRECO UNITARIO', 'S' => 'CUSTO TOTAL', 'T' => 'CUSTO MAO DE OBRA', 'U' => '% MAO DE OBRA', 'V' => 'CUSTO MATERIAL',
    'W' => '% MATERIAL', 'X' => 'CUSTO EQUIPAMENTO', 'Y' => '% EQUIPAMENTO', 'Z' => 'CUSTO SERVICOS TERCEIROS', 'AA' => '% SERVICOS TERCEIROS',
    'AB' => 'CUSTO OUTROS', 'AC' => '% OUTROS', 'AD' => 'VINCULO',
];

function analiticoXlsx(bool $comFilha = true, string $encargos = 'DESONERADOS'): string
{
    $comp = fn (string $code, string $desc, string $unit, string $total) => ['A' => 'ASSENTAMENTO DE TUBOS E PECAS', 'B' => 'ASTU', 'C' => 'FORNEC E/OU ASSENT DE TUBO', 'D' => '45',
        'G' => $code, 'H' => $desc, 'I' => $unit, 'J' => 'ATRIBUÍDO SÃO PAULO', 'K' => $total];
    $rows = [
        ['A' => 'PCI.818.01 - CUSTOS DE COMPOSIÇÕES ANALÍTICO DATA DE EMISSÃO:14/01/2025'],
        ['A' => "ENCARGOS SOCIAIS {$encargos}: 79,65%(HORA) 43,45%(MÊS)"],
        ['A' => 'ABRANGENCIA: NACIONAL LOCALIDADE : CUIABA DATA DE PREÇO : 12/2024'],
        ANALITICO_HEADER,
        // composição-pai: resumo de custos + itens (a filha 88316 só aparece DEPOIS no arquivo)
        $comp('97141', 'ASSENTAMENTO DE TUBO DE FERRO FUNDIDO PARA REDE DE ÁGUA, DN 80 MM', 'M', '3,84') + ['T' => '1,49', 'V' => '0,93', 'X' => '1,42', 'Z' => '0,00', 'AB' => '0,00'],
        $comp('97141', '…', 'M', '3,84') + ['L' => 'INSUMO', 'M' => '20078', 'N' => 'PASTA LUBRIFICANTE', 'O' => 'UN', 'P' => 'COEFICIENTE DE REPRESENTATIVIDADE', 'Q' => '0,0046000', 'R' => '31,75', 'S' => '0,14'],
        $comp('97141', '…', 'M', '3,84') + ['L' => 'INSUMO', 'M' => '99999', 'N' => 'INSUMO SÓ CITADO NA COMPOSIÇÃO', 'O' => 'KG', 'P' => 'COLETADO', 'Q' => '1,5000000', 'R' => '2,00', 'S' => '3,00'],
    ];
    if ($comFilha) {
        $rows[] = $comp('97141', '…', 'M', '3,84') + ['L' => 'COMPOSICAO', 'M' => '88316', 'N' => 'SERVENTE COM ENCARGOS COMPLEMENTARES', 'O' => 'H', 'P' => 'COLETADO', 'Q' => '0,0320000', 'R' => '18,34', 'S' => '0,58'];
    } else {
        $rows[] = $comp('97141', '…', 'M', '3,84') + ['L' => 'COMPOSICAO', 'M' => '77777', 'N' => 'FILHA AUSENTE', 'O' => 'H', 'Q' => '1,0000000'];
    }
    $rows[] = $comp('88316', 'SERVENTE COM ENCARGOS COMPLEMENTARES', 'H', '18,34') + ['T' => '18,34'];
    $rows[] = $comp('88316', '…', 'H', '18,34') + ['L' => 'INSUMO', 'M' => '4750', 'N' => 'PEDREIRO (HORISTA)', 'O' => 'H', 'P' => 'COLETADO', 'Q' => '1,0000000', 'R' => '18,34', 'S' => '18,34'];

    return XlsxFixture::write($rows);
}

function familiasXlsx(): string
{
    return XlsxFixture::write([
        ['A' => 'CAIXA ECONÔMICA FEDERAL SINAPI - Relatório de Família de Insumos'],
        ['A' => 'Cód. Insumo Representativo', 'B' => 'Cód. Insumo Representado', 'C' => 'Descrição do Insumo', 'D' => 'Unidade de Cálculo', 'E' => 'Coeficiente', 'F' => 'Categoria', 'G' => 'Macro-Classe', 'H' => 'Vínculo'],
        ['A' => '11270', 'B' => '11270', 'C' => 'ABRACADEIRA', 'D' => 'UN', 'E' => '1', 'F' => 'REPRESENTATIVO', 'G' => 'MATERIAL', 'H' => 'NACIONAL CAIXA'],
        ['A' => '4750', 'B' => '4750', 'C' => 'PEDREIRO', 'D' => 'H', 'E' => '1', 'F' => 'REPRESENTATIVO', 'G' => 'MÃO DE OBRA', 'H' => 'NACIONAL CAIXA'],
        ['A' => '99999', 'B' => '99999', 'C' => 'X', 'D' => 'KG', 'E' => '1', 'F' => 'REPRESENTATIVO', 'G' => 'EQUIPAMENTO (LOCAÇÃO)', 'H' => 'NACIONAL CAIXA'],
        ['A' => '20078', 'B' => '20078', 'C' => 'PASTA', 'D' => 'UN', 'E' => '1', 'F' => 'REPRESENTATIVO', 'G' => 'ESPECIAIS', 'H' => 'NACIONAL CAIXA'],
    ]);
}

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
    $code = importRefs(['--resources' => insumosXlsx(), '--compositions' => analiticoXlsx(), '--families' => familiasXlsx(), '--activate' => true]);
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
    $insumos = insumosXlsx();
    importRefs(['--resources' => $insumos]);
    $antes = refCounts();

    expect(importRefs(['--resources' => $insumos]))->toBe(0)
        ->and(Artisan::output())->toContain('já importado')
        ->and(refCounts())->toBe($antes);

    $outro = insumosXlsx(extra: [['A' => '1', 'B' => 'OUTRO', 'C' => 'UN', 'D' => 'CR', 'E' => '1,00']]);
    expect(importRefs(['--resources' => $outro]))->toBe(1)
        ->and(Artisan::output())->toContain('Bases são imutáveis')
        ->and(refCounts())->toBe($antes);
});

it('recusa arquivo de outro regime de encargos ou de outra competência, sem gravar nada', function () {
    expect(importRefs(['--resources' => insumosXlsx(encargos: 'NAO DESONERADOS')]))->toBe(1)
        ->and(Artisan::output())->toContain('without_relief')
        ->and(importRefs(['--resources' => insumosXlsx(mes: '11/2024')]))->toBe(1)
        ->and(Artisan::output())->toContain('competência 2024-11')
        ->and(refCounts())->toBe(['ds' => 0, 'imp' => 0, 'res' => 0, 'comp' => 0, 'items' => 0]);
});

it('aborta tudo se uma composição filha citada não existe no arquivo', function () {
    expect(importRefs(['--resources' => insumosXlsx(), '--compositions' => analiticoXlsx(comFilha: false)]))->toBe(1)
        ->and(Artisan::output())->toContain('97141 → 77777')
        ->and(refCounts()['res'])->toBe(0); // nem os insumos do mesmo comando ficaram
});

it('--dry-run executa e desfaz', function () {
    expect(importRefs(['--resources' => insumosXlsx(), '--compositions' => analiticoXlsx(), '--activate' => true, '--dry-run' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('Simulação')
        ->and(refCounts())->toBe(['ds' => 0, 'imp' => 0, 'res' => 0, 'comp' => 0, 'items' => 0])
        ->and(AuditLog::query()->whereIn('event', AuditEvent::phase4())->count())->toBe(0);
});

it('ativar exige insumos e composições; nova competência ativa substitui a anterior (superseded)', function () {
    expect(importRefs(['--resources' => insumosXlsx(), '--activate' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('insumos e composições');

    importRefs(['--resources' => insumosXlsx(), '--compositions' => analiticoXlsx(), '--activate' => true]);
    // mesma fonte/UF/regime, competência seguinte (recorte com mês ajustado)
    $jan = XlsxFixture::write(array_map(fn ($r) => array_map(fn ($v) => str_replace('12/2024', '01/2025', $v), $r), [
        ['A' => 'MES DE COLETA: 12/2024'], ['A' => 'ENCARGOS SOCIAIS DESONERADOS'],
        ['A' => 'CODIGO', 'B' => 'DESCRICAO DO INSUMO', 'C' => 'UNIDADE DE MEDIDA', 'D' => 'ORIGEM DO PRECO', 'E' => 'PRECO MEDIANO R$'],
        ['A' => '4750', 'B' => 'PEDREIRO (HORISTA)', 'C' => 'H', 'D' => 'CR', 'E' => '19,00'],
    ]));
    $janComp = XlsxFixture::write([
        ['A' => 'ENCARGOS SOCIAIS DESONERADOS'], ['A' => 'DATA DE PREÇO : 01/2025'], ANALITICO_HEADER,
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
