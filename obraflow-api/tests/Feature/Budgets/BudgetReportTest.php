<?php

use App\Models\Company;
use App\Models\ReferenceDataset;
use App\Modules\Budgets\Report\BudgetReportBuilder;
use App\Modules\Budgets\Report\ChromeRenderer;
use App\Modules\Budgets\Report\PdfReportRenderer;
use App\Modules\Budgets\Report\ReportFormat;
use App\Modules\Budgets\Report\ReportOptions;
use App\Modules\Budgets\Services\BudgetService;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\Scenario;
use Tests\Support\SinapiFixture;

/*
 * Fase 4D — relatório do orçamento. Etapa 1: o ReportData único (os três formatos só formatam o que sai daqui).
 * Os totais precisam ser exatamente os da tela (snapshot gravado pelo BudgetCalculator).
 */

beforeEach(function () {
    Scenario::catalog();
    Artisan::call('obraflow:references:import', ['--competency' => '2024-12', '--state' => 'MT', '--regime' => 'with_relief',
        '--resources' => SinapiFixture::insumos(), '--compositions' => SinapiFixture::analitico(), '--families' => SinapiFixture::familias(), '--activate' => true]);
    $this->ds = ReferenceDataset::query()->sole();
    $this->A = Scenario::tenant();
    $this->user = Scenario::member($this->A, 'admin');
    $this->svc = app(BudgetService::class);
    $this->in = fn (callable $fn) => app(TenantContext::class)->run($this->A, $fn, $this->user);

    // DEMO: etapa 1 (SINAPI) ⊃ 1.1 (manual); etapa 2 (manual com BDI próprio); BDI global pelo TCU; indireto; desconto
    [$this->budget, $this->ids] = ($this->in)(function () {
        $co = Company::create(['legal_name' => 'DEMO Construtora', 'document_number' => '11222333000181']);
        $b = $this->svc->create(['company_id' => $co->id, 'code' => 'ORC-007/2026', 'title' => 'DEMO Reforma', 'mode' => 'reference', 'reference_dataset_id' => $this->ds->id], $this->user);
        $v = $b->currentVersion;
        $g1 = $this->svc->addGroup($v, ['code' => '1', 'name' => 'DEMO Rede'], $this->user);
        $g11 = $this->svc->addGroup($v, ['code' => '1.1', 'name' => 'DEMO Acabamento', 'parent_id' => $g1->id], $this->user);
        $g2 = $this->svc->addGroup($v, ['code' => '2', 'name' => 'DEMO Limpeza'], $this->user);
        $comp = $this->svc->addReferenceItem($v, ['reference_dataset_id' => $this->ds->id, 'type' => 'composition', 'code' => '97141', 'group_id' => $g1->id, 'quantity' => '10'], $this->user);
        $res = $this->svc->addReferenceItem($v, ['reference_dataset_id' => $this->ds->id, 'type' => 'resource', 'code' => '11270', 'group_id' => $g1->id, 'quantity' => '3'], $this->user);
        $man = $this->svc->addItem($v, ['group_id' => $g11->id, 'code' => '1.1.01', 'description' => 'DEMO Pintura', 'unit_code' => 'M2', 'quantity' => '12.5', 'unit_cost' => '18.34', 'discount_percent' => '10'], $this->user);
        $own = $this->svc->addItem($v, ['group_id' => $g2->id, 'code' => '2.01', 'description' => 'DEMO Limpeza final', 'unit_code' => 'VB', 'quantity' => '1', 'unit_cost' => '350.00', 'bdi_percent' => '30'], $this->user);
        $this->svc->setBdi($v, 'global', null, 'tcu', null, ['ac' => '4', 's' => '0.8', 'r' => '1.27', 'g' => '0', 'df' => '1.23', 'l' => '7.4', 'i' => '8.65'], $this->user);
        $this->svc->setBdi($v, 'group', $g11->id, 'informed', '15', null, $this->user);
        $this->svc->addIndirect($v, ['description' => 'DEMO Canteiro', 'method' => 'fixed', 'amount' => '200.00'], $this->user);
        $this->svc->addIndirect($v, ['description' => 'DEMO Administração local', 'method' => 'percent_of_direct', 'percent' => '5'], $this->user);
        $this->svc->updateVersion($v, ['discount_percent' => '2'], $this->user);

        return [$b->refresh(), compact('g1', 'g11', 'g2', 'comp', 'res', 'man', 'own')];
    });
    $this->v = ($this->in)(fn () => $this->budget->currentVersion()->first());
    $this->snap = fn () => ($this->in)(fn () => $this->v->fresh()->totals_snapshot);
    $this->build = fn (array $o = []) => ($this->in)(fn () => app(BudgetReportBuilder::class)->build($this->v->fresh(), ReportOptions::from($o), $this->user));
});

it('os totais do relatório são exatamente os gravados para a tela (snapshot do cálculo)', function () {
    $r = ($this->build)();
    $snap = ($this->snap)();

    foreach (['direct_cost', 'indirect_cost', 'total_cost', 'bdi_amount', 'items_discount', 'items_sale', 'global_discount', 'final_price'] as $k) {
        expect($r['totals'][$k])->toBe($snap[$k]);
    }
    // a soma das linhas de item bate com os totais
    $items = array_filter($r['rows'], fn ($row) => $row['type'] === 'item');
    expect(bcadd('0', array_reduce($items, fn ($c, $i) => bcadd($c, $i['total_sale'], 2), '0'), 2))->toBe($snap['items_sale'])
        ->and(array_reduce($items, fn ($c, $i) => bcadd($c, $i['total_cost'], 2), '0'))->toBe($snap['direct_cost']);
});

it('sintético na ordem da árvore, com subtotal da etapa (incluindo subetapas), BDI efetivo e preço unitário com BDI', function () {
    $r = ($this->build)();
    expect(array_map(fn ($row) => [$row['type'], $row['code'], $row['level']], $r['rows']))->toBe([
        ['group', '1', 0], ['item', '97141', 1], ['item', '11270', 1], ['group', '1.1', 1], ['item', '1.1.01', 2], ['group', '2', 0], ['item', '2.01', 1],
    ]);
    $byCode = collect($r['rows'])->keyBy('code');
    $snap = ($this->snap)();
    expect($byCode['1']['total_sale'])->toBe($snap['groups'][$this->ids['g1']->id]['sale'])
        ->and($byCode['97141']['source'])->toBe('SINAPI')
        ->and($byCode['1.1.01'])->toMatchArray(['bdi_percent' => '15.0000', 'unit_cost' => '18.34', 'unit_sale' => '18.98']) // 18,34 × 1,15 × 0,90
        ->and($byCode['2.01'])->toMatchArray(['bdi_percent' => '30.0000', 'unit_sale' => '455.00', 'total_sale' => '455.00', 'source' => 'Próprio'])
        ->and($byCode['97141']['bdi_percent'])->toBe($r['bdi']['global']['percent']); // sem BDI próprio: o global
});

it('capa e resumo: empresa, base SINAPI, regime, situação (rascunho → marca d\'água) e etapas com %', function () {
    $r = ($this->build)();
    expect($r['meta'])->toMatchArray(['code' => 'ORC-007/2026', 'version' => 1, 'status_label' => 'Rascunho', 'draft' => true, 'regime' => 'Desonerado',
        'reference' => 'SINAPI MT 12/2024 · Desonerado', 'filename' => 'ORC-007-2026-v1-orcamento.pdf'])
        ->and($r['company'])->toMatchArray(['name' => 'DEMO Construtora', 'document' => '11.222.333/0001-81'])
        ->and(array_column($r['summary']['stages'], 'code'))->toBe(['1', '2'])
        ->and(bcadd($r['summary']['stages'][0]['percent'], $r['summary']['stages'][1]['percent'], 2))->toBe('100.00')
        ->and(array_column($r['summary']['by_kind'], 'kind'))->toContain('labor');
});

it('analítico: composição copiada com insumos e auxiliares; item sem composição sai como preço direto', function () {
    $r = ($this->build)();
    $a = collect($r['analytic'])->keyBy('code');
    expect($a['97141']['composition']['items'])->toHaveCount(3)
        ->and($a['97141']['composition']['items'][2])->toMatchArray(['type' => 'Composição', 'code' => '88316', 'coefficient' => '0.0320000', 'cost' => '0.58'])
        ->and($a['1.1.01']['composition'])->toBeNull()
        ->and(array_column($r['analytic'], 'code'))->toBe(['97141', '11270', '1.1.01', '2.01']);
});

it('BDI: global pelo TCU com as parcelas, diferenciado por etapa e no item; indiretos com o valor calculado', function () {
    $r = ($this->build)();
    expect($r['bdi']['global']['percent'])->toBe('26.2400') // [(1,0607 × 1,0123 × 1,074) ÷ 0,9135] − 1
        ->and(array_column($r['bdi']['global']['components'], 'key'))->toBe(['ac', 's', 'r', 'g', 'df', 'l', 'i'])
        ->and($r['bdi']['by_group'][0])->toMatchArray(['code' => '1.1', 'percent' => '15.0000', 'method' => 'Informado'])
        ->and($r['bdi']['by_item'][0])->toMatchArray(['code' => '2.01', 'percent' => '30.0000'])
        ->and(array_column($r['bdi']['indirect'], 'amount'))->toBe(['200.00', bcmul($r['totals']['direct_cost'], '0.05', 2)]);
});

it('opções: ocultar o BDI tira o detalhamento; partes desmarcadas não entram; curva ABC dos dois tipos', function () {
    $r = ($this->build)(['hide_bdi' => true, 'parts' => ['bdi', 'abc']]);
    expect($r)->not->toHaveKeys(['bdi', 'analytic', 'closing'])->and($r['options']['hide_bdi'])->toBeTrue()
        ->and(array_column($r['abc']['services'], 'code'))->toContain('97141')
        ->and(end($r['abc']['resources'])['cumulative_percent'])->toBe('100.0000');
});

it('fechamento: observações e responsável digitados na hora (nada é gravado)', function () {
    $r = ($this->build)(['notes' => '  DEMO Validade de 30 dias.  ', 'place' => 'Cuiabá/MT', 'technician_name' => 'DEMO Eng.', 'technician_registry' => 'CREA DEMO']);
    expect($r['closing'])->toMatchArray(['notes' => 'DEMO Validade de 30 dias.', 'place' => 'Cuiabá/MT'])
        ->and($r['closing']['technician'])->toMatchArray(['name' => 'DEMO Eng.', 'registry' => 'CREA DEMO', 'art' => null]);
});

it('formatação brasileira sem float: moeda, quantidade, coeficiente, percentual e CNPJ', function () {
    expect(ReportFormat::money('1234567.5'))->toBe('R$ 1.234.567,50')
        ->and(ReportFormat::money('0.005'))->toBe('R$ 0,01')
        ->and(ReportFormat::money('-12.30'))->toBe('R$ -12,30')
        ->and(ReportFormat::quantity('12.5000'))->toBe('12,50')
        ->and(ReportFormat::quantity('3.3333'))->toBe('3,3333')
        ->and(ReportFormat::coefficient('0.0320000'))->toBe('0,0320')
        ->and(ReportFormat::percent('22.1200'))->toBe('22,12%')
        ->and(ReportFormat::cnpj('11222333000181'))->toBe('11.222.333/0001-81')
        ->and(ReportFormat::money('99999999999.99'))->toBe('R$ 99.999.999.999,99');
});

it('PDF pelo Chrome headless: arquivo PDF com várias páginas, marca d\'água e gráfico; HTML sem nenhum recurso externo', function () {
    $chrome = app(ChromeRenderer::class);
    if (! $chrome->available()) {
        $this->markTestSkipped('Chrome não instalado neste ambiente.');
    }
    $data = ($this->build)(['format' => 'pdf', 'notes' => "DEMO Validade: 30 dias.\nDEMO Pagamento por medição."]);
    $renderer = app(PdfReportRenderer::class);
    $html = $renderer->html($data);
    expect($html)->toContain('RASCUNHO')->toContain('<svg')->toContain('counter(pages)')
        ->and(preg_match('/(src|href)=["\']https?:/i', $html))->toBe(0)
        ->and(preg_match('/url\(["\']?https?:/i', $html))->toBe(0);

    $path = sys_get_temp_dir().'/obraflow-test-'.uniqid().'.pdf';
    $renderer->render($data, $path);
    $pdf = file_get_contents($path);
    expect(substr($pdf, 0, 5))->toBe('%PDF-')->and(preg_match_all('/\/Type\s*\/Page[^s]/', $pdf))->toBeGreaterThanOrEqual(6);
    if ($dir = getenv('REPORT_SAMPLE_DIR')) {
        copy($path, $dir.'/amostra-orcamento.pdf');
    }
    @unlink($path);
});
