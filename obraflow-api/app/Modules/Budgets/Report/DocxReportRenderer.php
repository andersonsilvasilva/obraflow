<?php

namespace App\Modules\Budgets\Report;

use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\TblWidth;
use PhpOffice\PhpWord\Style\Language;

/**
 * Relatório em .docx (D-P79, PHPWord): as mesmas partes do PDF, para editar antes de enviar. Retrato e paisagem por
 * seção, cabeçalho e rodapé com "Página X de Y", marca d'água em versão não aprovada e os gráficos da curva ABC
 * como imagem. O documento final continua sendo o PDF (o Word não repete o layout com fidelidade — FASE4D §6).
 */
final class DocxReportRenderer
{
    private const DEEP = '0A2E66';

    private const SAPPHIRE = '0F52BA';

    private const SOFT = 'EAF1FF';

    private const LINE = 'E5E7EB';

    private const MUTED = '6B7280';

    private const GRAPHITE = '252A35';

    private const FONT = 'Calibri';

    private PhpWord $word;

    private array $data;

    /** @var array<string, string> */
    private array $assets;

    /** @param array<string, string> $assets PNG: `resources`, `services` (gráficos) e `watermark` */
    public function render(array $data, string $path, array $assets = []): void
    {
        $this->data = $data;
        $this->assets = $assets;
        $this->word = new PhpWord;
        $this->word->setDefaultFontName(self::FONT);
        $this->word->setDefaultFontSize(9);
        $this->word->getSettings()->setThemeFontLang(new Language('pt-BR'));
        $m = $data['meta'];
        $info = $this->word->getDocInfo();
        $info->setTitle("Orçamento {$m['code']} · v{$m['version']}");
        $info->setCreator('ObraFlow');
        $info->setCompany((string) ($data['company']['name'] ?? ''));

        $this->cover();
        $this->summary();
        $this->synthetic();
        if (isset($data['analytic'])) {
            $this->analytic();
        }
        if (isset($data['bdi'])) {
            $this->bdi();
        }
        if (isset($data['abc'])) {
            $this->abc();
        }
        if (isset($data['closing'])) {
            $this->closing();
        }

        IOFactory::createWriter($this->word, 'Word2007')->save($path);
    }

    // ------------------------------------------------------------------ partes

    private function cover(): void
    {
        $m = $this->data['meta'];
        $t = $this->data['totals'];
        $s = $this->word->addSection(['paperSize' => 'A4', 'marginTop' => 1134, 'marginBottom' => 1134, 'marginLeft' => 1134, 'marginRight' => 1134]);
        $this->watermark($s);

        $s->addImage(resource_path('report/brand/logo-completa.png'), ['width' => 150, 'height' => 34]);
        $s->addTextBreak(4);
        $s->addText('PROPOSTA ORÇAMENTÁRIA', ['size' => 9, 'color' => self::SAPPHIRE, 'bold' => true, 'spacing' => 60]);
        $s->addText('Orçamento', ['size' => 34, 'bold' => true, 'color' => self::DEEP], ['spaceAfter' => 0]);
        $label = $m['version_label'] && mb_strtolower($m['version_label']) !== 'versão '.$m['version'] ? ' — '.$m['version_label'] : '';
        $s->addText("{$m['code']} · versão {$m['version']}{$label}", ['size' => 14, 'color' => self::GRAPHITE]);
        $s->addText($m['title'], ['size' => 12, 'color' => self::MUTED]);
        $s->addText($m['draft'] ? 'RASCUNHO · versão não aprovada' : $m['status_label'].($m['approved_at'] ? ' em '.$m['approved_at'] : ''),
            ['size' => 9, 'bold' => true, 'color' => $m['draft'] ? 'B45309' : '16A36A'], ['spaceBefore' => 120]);
        $s->addTextBreak(2);

        $grid = $s->addTable(['width' => 5000, 'unit' => TblWidth::PERCENT, 'cellMargin' => 80]);
        $fields = [
            ['Empresa', $this->data['company']['name'] ?? '—', $this->data['company']['document'] ? 'CNPJ '.$this->data['company']['document'] : null],
            ['Cliente', $this->data['customer']['name'] ?? '—', $this->data['customer']['document'] ?? null],
            ['Obra', $this->data['project'] ? $this->data['project']['code'].' — '.$this->data['project']['name'] : '—', $this->data['project']['address'] ?? null],
            ['Base de referência', $m['reference'] ?? 'Sem base de referência', 'Encargos sociais: '.$m['regime'].($m['reference_date'] ? ' · data-base '.$m['reference_date'] : '')],
        ];
        foreach (array_chunk($fields, 2) as $pair) {
            $grid->addRow();
            foreach ($pair as [$k, $v, $sub]) {
                $cell = $grid->addCell(2500, ['valign' => 'top']);
                $cell->addText(mb_strtoupper($k), ['size' => 7.5, 'color' => self::MUTED, 'spacing' => 20], ['spaceAfter' => 20]);
                $cell->addText((string) $v, ['size' => 10.5, 'bold' => true], ['spaceAfter' => 0]);
                if ($sub) {
                    $cell->addText($sub, ['size' => 8.5, 'color' => self::MUTED]);
                }
            }
        }
        $s->addTextBreak(2);

        $box = $s->addTable(['width' => 5000, 'unit' => TblWidth::PERCENT, 'cellMargin' => 200, 'borderSize' => 6, 'borderColor' => 'D6E3FF']);
        $box->addRow();
        $l = $box->addCell(2500, ['bgColor' => self::SOFT, 'valign' => 'center']);
        $l->addText('VALOR FINAL', ['size' => 8, 'bold' => true, 'color' => self::DEEP, 'spacing' => 30], ['spaceAfter' => 0]);
        $l->addText($t['item_count'].' itens'.($t['global_discount'] !== '0.00' ? ' · desconto global de '.ReportFormat::percent($t['global_discount_percent']) : ''), ['size' => 8.5, 'color' => self::MUTED]);
        $box->addCell(2500, ['bgColor' => self::SOFT, 'valign' => 'center'])->addText(ReportFormat::money($t['final_price']), ['size' => 22, 'bold' => true, 'color' => self::DEEP], ['alignment' => Jc::END]);

        $s->addTextBreak(6);
        $s->addText("Emitido em {$m['issued_at']} por {$m['issued_by']} · Gerado pelo ObraFlow", ['size' => 8, 'color' => self::MUTED]);
    }

    private function summary(): void
    {
        $t = $this->data['totals'];
        $hide = $this->data['options']['hide_bdi'];
        $s = $this->section(false, '01', 'Resumo');

        $rows = $hide ? [] : [['Custo direto (soma dos itens)', $t['direct_cost']], ['BDI', $t['bdi_amount']]];
        if (! $hide && $t['items_discount'] !== '0.00') {
            $rows[] = ['Desconto nos itens', '-'.$t['items_discount']];
        }
        $rows[] = ['Preço dos serviços', $t['items_sale']];
        $rows[] = ['Custos indiretos (fora do BDI)', $t['indirect_cost']];
        if ($t['global_discount'] !== '0.00') {
            $rows[] = ['Desconto global ('.ReportFormat::percent($t['global_discount_percent']).')', '-'.$t['global_discount']];
        }
        $this->h3($s, 'Composição do preço');
        $table = $this->table($s);
        foreach ($rows as [$label, $value]) {
            $this->row($table, [[$label, 7000], [$this->money($value), 2600, 'r']]);
        }
        $this->row($table, [['Valor final', 7000], [ReportFormat::money($t['final_price']), 2600, 'r']], style: 'final');

        $this->h3($s, 'Totais por etapa');
        $table = $this->table($s);
        $this->header($table, [['Etapa', 1000], ['Descrição', 5200], ['Valor', 2200, 'r'], ['%', 1200, 'r']]);
        foreach ($this->data['summary']['stages'] as $st) {
            $this->row($table, [[$st['code'], 1000], [$st['name'], 5200], [ReportFormat::money($st['sale']), 2200, 'r'], [ReportFormat::percent($st['percent']), 1200, 'r']]);
        }

        if ($this->data['summary']['by_kind'] !== []) {
            $this->h3($s, 'Insumos por tipo');
            $table = $this->table($s);
            $this->header($table, [['Tipo', 6200], ['Valor', 2200, 'r'], ['%', 1200, 'r']]);
            foreach ($this->data['summary']['by_kind'] as $k) {
                $this->row($table, [[$k['label'], 6200], [ReportFormat::money($k['total']), 2200, 'r'], [ReportFormat::percent($k['percent']), 1200, 'r']]);
            }
            $s->addText('Distribuição calculada a partir das composições dos itens (insumos finais).', ['size' => 8, 'color' => self::MUTED]);
        }
    }

    private function synthetic(): void
    {
        $t = $this->data['totals'];
        $hide = $this->data['options']['hide_bdi'];
        $s = $this->section(true, '02', 'Orçamento sintético');
        $cols = $hide
            ? [['Item', 900], ['Código', 1000], ['Fonte', 1200], ['Descrição', 6200], ['Un.', 600, 'c'], ['Quant.', 1100, 'r'], ['Preço unit.', 1400, 'r'], ['Total', 1700, 'r']]
            : [['Item', 900], ['Código', 1000], ['Fonte', 1200], ['Descrição', 4600], ['Un.', 600, 'c'], ['Quant.', 1100, 'r'], ['Custo unit.', 1300, 'r'], ['BDI', 800, 'r'], ['Preço unit.', 1300, 'r'], ['Total', 1700, 'r']];
        $table = $this->table($s);
        $this->header($table, $cols);
        $descWidth = array_sum(array_map(fn ($c) => $c[1], array_slice($cols, 1, count($cols) - 2)));

        foreach ($this->data['rows'] as $r) {
            if ($r['type'] === 'group') {
                $this->row($table, [[$r['code'], 900], [$r['description'], $descWidth, 'l', count($cols) - 2], [ReportFormat::money($r['total_sale']), 1700, 'r']], style: $r['level'] === 0 ? 'g0' : 'g1');

                continue;
            }
            $cells = [[$r['code'], 900], [(string) $r['reference_code'], 1000], [$r['source'], 1200], [$r['description'], $hide ? 6200 : 4600], [$r['unit'], 600, 'c'], [ReportFormat::quantity($r['quantity']), 1100, 'r']];
            if (! $hide) {
                $cells[] = [ReportFormat::money($r['unit_cost']), 1300, 'r'];
                $cells[] = [ReportFormat::percent($r['bdi_percent']), 800, 'r'];
            }
            $cells[] = [ReportFormat::money($r['unit_sale']), $hide ? 1400 : 1300, 'r'];
            $cells[] = [ReportFormat::money($r['total_sale']), 1700, 'r'];
            $this->row($table, $cells);
        }
        $span = count($cols) - 1;
        $all = array_sum(array_map(fn ($c) => $c[1], array_slice($cols, 0, -1)));
        $this->row($table, [['Total dos serviços', $all, 'l', $span], [ReportFormat::money($t['items_sale']), 1700, 'r']], style: 'total');
        if ($t['indirect_cost'] !== '0.00') {
            $this->row($table, [['Custos indiretos', $all, 'l', $span], [ReportFormat::money($t['indirect_cost']), 1700, 'r']]);
        }
        if ($t['global_discount'] !== '0.00') {
            $this->row($table, [['Desconto global ('.ReportFormat::percent($t['global_discount_percent']).')', $all, 'l', $span], [$this->money('-'.$t['global_discount']), 1700, 'r']]);
        }
        $this->row($table, [['Valor final', $all, 'l', $span], [ReportFormat::money($t['final_price']), 1700, 'r']], style: 'final');
    }

    private function analytic(): void
    {
        $s = $this->section(true, '03', 'Orçamento analítico');
        foreach ($this->data['analytic'] as $a) {
            $table = $this->table($s);
            $this->row($table, [[$a['code'], 1300], [$a['source'].($a['composition'] ? ' '.$a['composition']['code'] : ''), 1700], [$a['description'], 8400], [$a['unit'].' · '.ReportFormat::money($a['unit_cost']), 2600, 'r']], style: 'g1');
            if ($a['composition'] === null) {
                $this->row($table, [['Item sem composição: custo unitário informado (preço direto).', 14000, 'l', 4]], style: 'muted');
            } else {
                $this->header($table, [['Tipo', 1300], ['Código', 1100], ['Descrição', 6300], ['Un.', 700, 'c'], ['Coeficiente', 1500, 'r'], ['Preço unit.', 1500, 'r'], ['Custo', 1600, 'r']], soft: true);
                foreach ($a['composition']['items'] as $ci) {
                    $this->row($table, [[$ci['type'], 1300], [$ci['code'], 1100], [$ci['description'], 6300], [$ci['unit'], 700, 'c'], [ReportFormat::coefficient($ci['coefficient']), 1500, 'r'],
                        [$ci['unit_price'] !== null ? ReportFormat::money($ci['unit_price']) : '—', 1500, 'r'], [$ci['cost'] !== null ? ReportFormat::money($ci['cost']) : '—', 1600, 'r']]);
                }
            }
            $s->addTextBreak(1, ['size' => 4]);
        }
    }

    private function bdi(): void
    {
        $b = $this->data['bdi'];
        $s = $this->section(false, '04', 'BDI e encargos');
        $this->h3($s, 'BDI global');
        if ($b['global'] === null) {
            $s->addText('Sem BDI global (0%).');
        } else {
            $run = $s->addTextRun();
            $run->addText('Método: ');
            $run->addText($b['global']['method'], ['bold' => true]);
            if ($b['global']['components'] !== []) {
                $s->addText($b['formula'], ['color' => self::DEEP, 'size' => 9.5], ['shading' => ['fill' => 'F3F4F6'], 'spaceBefore' => 80, 'spaceAfter' => 80]);
            }
            $table = $this->table($s);
            if ($b['global']['components'] !== []) {
                $this->header($table, [['Parcela', 7000], ['%', 2600, 'r']]);
                foreach ($b['global']['components'] as $c) {
                    $this->row($table, [[$c['label'], 7000], [ReportFormat::percent($c['percent']), 2600, 'r']]);
                }
            }
            $this->row($table, [['BDI resultante', 7000], [ReportFormat::percent($b['global']['percent']), 2600, 'r']], style: 'final');
        }
        foreach (['by_group' => 'BDI diferenciado por etapa', 'by_item' => 'BDI diferenciado por item'] as $key => $label) {
            if ($b[$key] === []) {
                continue;
            }
            $this->h3($s, $label);
            $table = $this->table($s);
            $this->header($table, [['Código', 1200], ['Descrição', 4600], ['Método', 2400], ['BDI', 1400, 'r']]);
            foreach ($b[$key] as $r) {
                $this->row($table, [[(string) $r['code'], 1200], [(string) $r['name'], 4600], [$r['method'], 2400], [ReportFormat::percent($r['percent']), 1400, 'r']]);
            }
        }
        $this->h3($s, 'Custos indiretos (fora do BDI)');
        if ($b['indirect'] === []) {
            $s->addText('Nenhum.');
        } else {
            $table = $this->table($s);
            $this->header($table, [['Descrição', 4800], ['Base', 2600], ['Valor', 2200, 'r']]);
            foreach ($b['indirect'] as $c) {
                $this->row($table, [[$c['description'], 4800], [$c['basis'], 2600], [ReportFormat::money($c['amount']), 2200, 'r']]);
            }
            $this->row($table, [['Total', 7400, 'l', 2], [ReportFormat::money($this->data['totals']['indirect_cost']), 2200, 'r']], style: 'total');
        }
        $this->h3($s, 'Encargos sociais');
        $s->addText('Regime: '.$b['regime'].($this->data['meta']['reference'] ? ' · base '.$this->data['meta']['reference'] : '').'.');
    }

    private function abc(): void
    {
        $s = $this->section(true, '05', 'Curva ABC');
        foreach (['resources' => 'Insumos (das composições)', 'services' => 'Serviços (pelo custo)'] as $key => $label) {
            $this->h3($s, $label);
            if (isset($this->assets[$key]) && is_file($this->assets[$key])) {
                $s->addImage($this->assets[$key], ['width' => 620, 'height' => 245, 'alignment' => Jc::CENTER]);
            }
            $table = $this->table($s);
            $this->header($table, [['Pos.', 700, 'r'], ['Código', 1100], ['Descrição', 5800], ['Un.', 700, 'c'], ['Quantidade', 1400, 'r'], ['Valor', 1700, 'r'], ['%', 1000, 'r'], ['% acum.', 1100, 'r'], ['Faixa', 800, 'c']]);
            foreach ($this->data['abc'][$key] as $i => $r) {
                $this->row($table, [[(string) ($i + 1), 700, 'r'], [(string) $r['code'], 1100], [(string) $r['description'], 5800], [(string) $r['unit'], 700, 'c'], [ReportFormat::quantity($r['quantity']), 1400, 'r'],
                    [ReportFormat::money($r['total']), 1700, 'r'], [ReportFormat::percent($r['percent']), 1000, 'r'], [ReportFormat::percent($r['cumulative_percent']), 1100, 'r'], [$r['class'], 800, 'c', 1, ['A' => 'DCFCE7', 'B' => 'FEF3C7', 'C' => 'F3F4F6'][$r['class']]]]);
            }
        }
        $s->addText('Faixas: A até 80% do valor acumulado, B até 95%, C o restante.', ['size' => 8, 'color' => self::MUTED]);
    }

    private function closing(): void
    {
        $c = $this->data['closing'];
        $s = $this->section(false, '06', 'Fechamento');
        if ($c['notes']) {
            $this->h3($s, 'Observações');
            foreach (preg_split('/\R/', $c['notes']) as $line) {
                $s->addText($line, null, ['spaceAfter' => 0, 'indentation' => ['left' => 200], 'borderLeftSize' => 18, 'borderLeftColor' => self::SAPPHIRE]);
            }
        }
        $s->addTextBreak(1);
        $s->addText(($c['place'] ? $c['place'].', ' : '').$c['date'].'.');
        $s->addTextBreak(5);
        $table = $s->addTable(['width' => 5000, 'unit' => TblWidth::PERCENT]);
        $table->addRow();
        foreach ([[$c['technician']['name'] ?? '', 'Responsável técnico'.(($c['technician']['registry'] ?? null) ? ' · '.$c['technician']['registry'] : ''), ($c['technician']['art'] ?? null) ? 'ART/RRT '.$c['technician']['art'] : null], [$c['customer'] ?? '', 'Cliente — de acordo', null]] as $i => [$name, $role, $extra]) {
            $cell = $table->addCell(4500, ['borderTopSize' => 8, 'borderTopColor' => '374151']);
            $cell->addText($name ?: ' ', ['bold' => true], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
            $cell->addText($role, ['size' => 8, 'color' => self::MUTED], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
            if ($extra) {
                $cell->addText($extra, ['size' => 8, 'color' => self::MUTED], ['alignment' => Jc::CENTER]);
            }
            if ($i === 0) {
                $table->addCell(800);
            }
        }
    }

    // ------------------------------------------------------------------ apoio

    private function section(bool $landscape, string $number, string $title): Section
    {
        $m = $this->data['meta'];
        $s = $this->word->addSection(['paperSize' => 'A4', 'orientation' => $landscape ? 'landscape' : 'portrait',
            'marginTop' => 1134, 'marginBottom' => 1000, 'marginLeft' => $landscape ? 850 : 1000, 'marginRight' => $landscape ? 850 : 1000, 'headerHeight' => 500, 'footerHeight' => 500]);
        $header = $s->addHeader();
        $this->watermark($s, $header);
        $ht = $header->addTable(['width' => 5000, 'unit' => TblWidth::PERCENT]);
        $ht->addRow();
        $ht->addCell(5000)->addText("ObraFlow · Orçamento {$m['code']} · versão {$m['version']}", ['size' => 7.5, 'color' => self::MUTED]);
        $ht->addCell(5000)->addText($m['draft'] ? 'RASCUNHO · versão não aprovada' : $m['status_label'], ['size' => 7.5, 'bold' => true, 'color' => $m['draft'] ? 'B45309' : '16A36A'], ['alignment' => Jc::END]);
        $footer = $s->addFooter();
        $ft = $footer->addTable(['width' => 5000, 'unit' => TblWidth::PERCENT]);
        $ft->addRow();
        $ft->addCell(5000)->addText("Emitido em {$m['issued_at']} · Gerado pelo ObraFlow", ['size' => 7.5, 'color' => self::MUTED]);
        $ft->addCell(5000)->addPreserveText('Página {PAGE} de {NUMPAGES}', ['size' => 7.5, 'bold' => true, 'color' => self::DEEP], ['alignment' => Jc::END]);

        $s->addText($number, ['size' => 8, 'bold' => true, 'color' => self::SAPPHIRE, 'spacing' => 40], ['spaceAfter' => 0]);
        $s->addText($title, ['size' => 16, 'bold' => true, 'color' => self::DEEP], ['spaceAfter' => 160, 'borderBottomSize' => 12, 'borderBottomColor' => self::SAPPHIRE]);

        return $s;
    }

    private function watermark(Section $s, $header = null): void
    {
        if (! $this->data['meta']['draft'] || ! isset($this->assets['watermark']) || ! is_file($this->assets['watermark'])) {
            return;
        }
        $header ??= $s->addHeader();
        $header->addWatermark($this->assets['watermark'], ['width' => 460, 'height' => 230, 'posHorizontal' => 'center', 'posVertical' => 'center',
            'posHorizontalRel' => 'page', 'posVerticalRel' => 'page']);
    }

    private function h3(Section $s, string $text): void
    {
        $s->addText($text, ['size' => 10.5, 'bold' => true, 'color' => self::SAPPHIRE], ['spaceBefore' => 200, 'spaceAfter' => 80]);
    }

    private function table(Section $s): Table
    {
        return $s->addTable(['width' => 5000, 'unit' => TblWidth::PERCENT, 'cellMarginLeft' => 80, 'cellMarginRight' => 80, 'cellMarginTop' => 30, 'cellMarginBottom' => 30,
            'borderBottomSize' => 4, 'borderBottomColor' => self::LINE, 'borderInsideHSize' => 4, 'borderInsideHColor' => self::LINE]);
    }

    /** @param list<array{0: string, 1: int, 2?: string}> $cols */
    private function header(Table $table, array $cols, bool $soft = false): void
    {
        $table->addRow(320, ['tblHeader' => true, 'cantSplit' => true]);
        foreach ($cols as $c) {
            $table->addCell($c[1], ['bgColor' => $soft ? 'F8FAFC' : self::SAPPHIRE, 'valign' => 'center'])
                ->addText(mb_strtoupper($c[0]), ['size' => 7.5, 'bold' => true, 'color' => $soft ? '374151' : 'FFFFFF'], ['alignment' => $this->align($c[2] ?? 'l'), 'spaceAfter' => 0]);
        }
    }

    /**
     * @param  list<array{0: string, 1: int, 2?: string, 3?: int, 4?: string}>  $cells  texto, largura, alinhamento, colunas mescladas, fundo
     */
    private function row(Table $table, array $cells, string $style = ''): void
    {
        [$bg, $color, $bold, $size] = match ($style) {
            'g0' => [self::DEEP, 'FFFFFF', true, 8.6],
            'g1' => [self::SOFT, self::DEEP, true, 8.6],
            'total' => [self::SOFT, self::GRAPHITE, true, 9.5],
            'final' => [self::DEEP, 'FFFFFF', true, 10.5],
            'muted' => [null, self::MUTED, false, 8.4],
            default => [null, self::GRAPHITE, false, 8.6],
        };
        $table->addRow(null, ['cantSplit' => true]);
        foreach ($cells as $c) {
            $cellStyle = ['valign' => 'center'];
            if (($c[3] ?? 1) > 1) {
                $cellStyle['gridSpan'] = $c[3];
            }
            if ($bg || isset($c[4])) {
                $cellStyle['bgColor'] = $c[4] ?? $bg;
            }
            $table->addCell($c[1], $cellStyle)->addText($c[0], ['size' => $size, 'bold' => $bold || isset($c[4]), 'color' => $color, 'italic' => $style === 'muted'],
                ['alignment' => $this->align($c[2] ?? 'l'), 'spaceAfter' => 0]);
        }
    }

    private function align(string $a): string
    {
        return ['r' => Jc::END, 'c' => Jc::CENTER][$a] ?? Jc::START;
    }

    private function money(string $v): string
    {
        return str_starts_with($v, '-') ? '− '.ReportFormat::money(ltrim($v, '-')) : ReportFormat::money($v);
    }
}
