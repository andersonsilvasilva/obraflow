<?php

namespace App\Modules\Budgets\Report;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\ImageCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderName;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\Common\Entity\Sheet;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Options\PageOrientation;
use OpenSpout\Writer\XLSX\Options\PageSetup;
use OpenSpout\Writer\XLSX\Options\PaperSize;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Relatório em .xlsx (D-P84): uma aba por parte, valores numéricos fixos (sem fórmulas) com formato de moeda e %,
 * nas cores do ObraFlow. O gráfico da curva ABC entra como imagem PNG, quando fornecido.
 */
final class XlsxReportRenderer
{
    private const SAPPHIRE = '0F52BA';

    private const DEEP = '0A2E66';

    private const SOFT = 'EAF1FF';

    private const GRAY = 'F3F4F6';

    private const GRAPHITE = '252A35';

    private const MUTED = '6B7280';

    private const MONEY = '"R$" #,##0.00';

    private const QTY = '#,##0.00##';

    private const COEF = '#,##0.0000###';

    private const PCT = '0.00"%"';

    private Writer $writer;

    private Options $options;

    /** @param array<string, string> $assets gráficos da curva ABC em PNG: `resources` e `services` */
    public function render(array $data, string $path, array $assets = []): void
    {
        $this->options = new Options(pageSetup: new PageSetup(PageOrientation::LANDSCAPE, PaperSize::A4, fitToHeight: 0, fitToWidth: 1));
        $this->writer = new Writer($this->options);
        $this->writer->openToFile($path);

        $this->summarySheet($data);
        $this->syntheticSheet($data);
        if (isset($data['analytic'])) {
            $this->analyticSheet($data);
        }
        if (isset($data['bdi'])) {
            $this->bdiSheet($data);
        }
        if (isset($data['abc'])) {
            $this->abcSheet($data, $assets);
        }
        if (isset($data['closing'])) {
            $this->closingSheet($data);
        }

        $this->writer->close();
    }

    // ------------------------------------------------------------------ abas

    private function summarySheet(array $d): void
    {
        $sheet = $this->writer->getCurrentSheet();
        $sheet->setName('Resumo');
        $sheet->setColumnWidth(34, 1);
        $sheet->setColumnWidth(22, 2);
        $sheet->setColumnWidth(18, 3);
        $sheet->setColumnWidth(12, 4);
        $m = $d['meta'];

        $logo = resource_path('report/brand/logo-completa.png');
        $this->row([new ImageCell($logo, 190, 43)], height: 40);
        $this->row([$this->text('Orçamento '.$m['code'].' · versão '.$m['version'], $this->style(bold: true, size: 16, color: self::DEEP))], height: 24);
        $this->row([$this->text($m['title'], $this->style(size: 12))]);
        $this->row([$this->text('Situação: '.$m['status_label'].($m['draft'] ? ' (RASCUNHO — versão não aprovada)' : ($m['approved_at'] ? ' em '.$m['approved_at'] : '')),
            $this->style(bold: $m['draft'], color: $m['draft'] ? 'B45309' : '16A36A'))]);
        $this->row([]);

        $this->sectionTitle('Identificação');
        $info = [
            ['Empresa', trim(($d['company']['name'] ?? '').($d['company']['document'] ? ' — CNPJ '.$d['company']['document'] : ''))],
            ['Cliente', $d['customer'] ? trim($d['customer']['name'].($d['customer']['document'] ? ' — '.$d['customer']['document'] : '')) : '—'],
            ['Obra', $d['project'] ? $d['project']['code'].' — '.$d['project']['name'] : '—'],
            ['Endereço da obra', $d['project']['address'] ?? '—'],
            ['Base de referência', $m['reference'] ?? '—'],
            ['Encargos sociais', $m['regime']],
            ['Data-base', $m['reference_date'] ?? '—'],
            ['Emitido em', $m['issued_at'].' por '.$m['issued_by']],
        ];
        foreach ($info as [$label, $value]) {
            $this->row([$this->text($label, $this->style(color: self::MUTED)), $this->text((string) $value)]);
        }
        $this->row([]);

        $this->sectionTitle('Valores');
        $t = $d['totals'];
        $lines = $d['options']['hide_bdi']
            ? [['Preço dos serviços', $t['items_sale']], ['Custos indiretos', $t['indirect_cost']]]
            : [['Custo direto', $t['direct_cost']], ['Custos indiretos', $t['indirect_cost']], ['Custo total', $t['total_cost']], ['BDI', $t['bdi_amount']], ['Desconto nos itens', $t['items_discount']]];
        $lines[] = ['Desconto global ('.ReportFormat::percent($t['global_discount_percent']).')', $t['global_discount']];
        foreach ($lines as [$label, $value]) {
            $this->row([$this->text($label), $this->money($value)]);
        }
        $this->row([$this->text('VALOR FINAL', $this->style(bold: true, size: 12, color: Style::DEFAULT_FONT_COLOR, bg: self::SOFT)),
            $this->money($t['final_price'], $this->style(bold: true, size: 12, bg: self::SOFT, format: self::MONEY))], height: 22);
        $this->row([]);

        $this->sectionTitle('Totais por etapa');
        $this->header(['Etapa', 'Descrição', 'Valor', '%']);
        foreach ($d['summary']['stages'] as $s) {
            $this->row([$this->text($s['code']), $this->text($s['name']), $this->money($s['sale']), $this->pct($s['percent'])]);
        }

        if ($d['summary']['by_kind'] !== []) {
            $this->row([]);
            $this->sectionTitle('Insumos por tipo (das composições)');
            $this->header(['Tipo', '', 'Valor', '%']);
            foreach ($d['summary']['by_kind'] as $k) {
                $this->row([$this->text($k['label']), $this->text(''), $this->money($k['total']), $this->pct($k['percent'])]);
            }
        }
    }

    private function syntheticSheet(array $d): void
    {
        $sheet = $this->newSheet('Sintético');
        $hide = $d['options']['hide_bdi'];
        $cols = $hide
            ? ['Item' => 10, 'Código' => 11, 'Fonte' => 13, 'Descrição' => 60, 'Un.' => 7, 'Quantidade' => 12, 'Preço unitário' => 15, 'Total' => 17]
            : ['Item' => 10, 'Código' => 11, 'Fonte' => 13, 'Descrição' => 60, 'Un.' => 7, 'Quantidade' => 12, 'Custo unitário' => 14, 'BDI' => 9, 'Preço unitário' => 15, 'Total' => 17];
        $this->widths($sheet, array_values($cols));
        $this->title('Orçamento sintético', $d);
        $this->header(array_keys($cols));

        foreach ($d['rows'] as $r) {
            if ($r['type'] === 'group') {
                $st = $this->style(bold: true, color: $r['level'] === 0 ? Color::WHITE : self::DEEP, bg: $r['level'] === 0 ? self::DEEP : self::SOFT);
                $cells = [$this->text($r['code'], $st), $this->text('', $st), $this->text('', $st), $this->text($r['description'], $st)];
                $cells = array_merge($cells, array_fill(0, count($cols) - 5, $this->text('', $st)));
                $cells[] = $this->money($r['total_sale'], $this->style(bold: true, color: $st->fontColor, bg: $st->backgroundColor, format: self::MONEY));
                $this->row($cells);

                continue;
            }
            $cells = [$this->text($r['code']), $this->text($r['reference_code'] ?? ''), $this->text($r['source']), $this->text($r['description'], $this->style(wrap: true)),
                $this->text($r['unit'], $this->style(align: CellAlignment::CENTER)), $this->num($r['quantity'], self::QTY)];
            if (! $hide) {
                $cells[] = $this->money($r['unit_cost']);
                $cells[] = $this->pct($r['bdi_percent']);
            }
            $cells[] = $this->money($r['unit_sale']);
            $cells[] = $this->money($r['total_sale']);
            $this->row($cells);
        }

        $st = $this->style(bold: true, size: 12, bg: self::SOFT);
        $this->row(array_merge([$this->text('TOTAL DOS SERVIÇOS', $st)], array_fill(0, count($cols) - 2, $this->text('', $st)), [$this->money($d['totals']['items_sale'], $this->style(bold: true, size: 12, bg: self::SOFT, format: self::MONEY))]));
        foreach ($this->closingTotals($d) as [$label, $value, $bold]) {
            $st = $this->style(bold: $bold);
            $this->row(array_merge([$this->text($label, $st)], array_fill(0, count($cols) - 2, $this->text('')), [$this->money($value, $this->style(bold: $bold, format: self::MONEY))]));
        }
    }

    private function analyticSheet(array $d): void
    {
        $sheet = $this->newSheet('Analítico');
        $this->widths($sheet, [10, 13, 12, 58, 7, 14, 15, 16]);
        $this->title('Orçamento analítico (composições)', $d);
        $this->header(['Item', 'Tipo', 'Código', 'Descrição', 'Un.', 'Coeficiente', 'Preço unitário', 'Custo']);

        foreach ($d['analytic'] as $a) {
            $st = $this->style(bold: true, color: self::DEEP, bg: self::SOFT);
            $this->row([$this->text($a['code'], $st), $this->text($a['source'], $st), $this->text($a['composition']['code'] ?? '', $st), $this->text($a['description'], $this->style(bold: true, color: self::DEEP, bg: self::SOFT, wrap: true)),
                $this->text($a['unit'], $st), $this->text('', $st), $this->money($a['unit_cost'], $this->style(bold: true, color: self::DEEP, bg: self::SOFT, format: self::MONEY)), $this->text('', $st)]);
            if ($a['composition'] === null) {
                $this->row([$this->text(''), $this->text('Preço direto', $this->style(italic: true, color: self::MUTED)), $this->text(''), $this->text('Item sem composição: custo unitário informado.', $this->style(italic: true, color: self::MUTED))]);

                continue;
            }
            foreach ($a['composition']['items'] as $ci) {
                $this->row([$this->text(''), $this->text($ci['type']), $this->text($ci['code']), $this->text($ci['description'], $this->style(wrap: true)), $this->text($ci['unit'], $this->style(align: CellAlignment::CENTER)),
                    $this->num($ci['coefficient'], self::COEF), $ci['unit_price'] !== null ? $this->money($ci['unit_price']) : $this->text('—'), $ci['cost'] !== null ? $this->money($ci['cost']) : $this->text('—')]);
            }
        }
    }

    private function bdiSheet(array $d): void
    {
        $sheet = $this->newSheet('BDI');
        $this->widths($sheet, [12, 50, 30, 14]);
        $this->title('BDI e encargos', $d);
        $b = $d['bdi'];

        $this->sectionTitle('BDI global');
        if ($b['global'] === null) {
            $this->row([$this->text('Sem BDI global (0%).')]);
        } else {
            $this->row([$this->text('Método'), $this->text($b['global']['method'])]);
            if ($b['global']['components'] !== []) {
                $this->row([$this->text('Fórmula'), $this->text($b['formula'])]);
                $this->header(['', 'Parcela', '', '%']);
                foreach ($b['global']['components'] as $c) {
                    $this->row([$this->text(''), $this->text($c['label']), $this->text(''), $this->pct($c['percent'])]);
                }
            }
            $this->row([$this->text('BDI', $this->style(bold: true, bg: self::SOFT)), $this->text('', $this->style(bg: self::SOFT)), $this->text('', $this->style(bg: self::SOFT)), $this->pct($b['global']['percent'], $this->style(bold: true, bg: self::SOFT, format: self::PCT))]);
        }

        foreach (['by_group' => 'BDI diferenciado por etapa', 'by_item' => 'BDI diferenciado por item'] as $key => $label) {
            if ($b[$key] === []) {
                continue;
            }
            $this->row([]);
            $this->sectionTitle($label);
            $this->header(['Código', 'Descrição', 'Método', 'BDI']);
            foreach ($b[$key] as $r) {
                $this->row([$this->text((string) $r['code']), $this->text((string) $r['name'], $this->style(wrap: true)), $this->text($r['method']), $this->pct($r['percent'])]);
            }
        }

        $this->row([]);
        $this->sectionTitle('Custos indiretos (fora do BDI)');
        if ($b['indirect'] === []) {
            $this->row([$this->text('Nenhum.')]);
        } else {
            $this->header(['', 'Descrição', 'Base', 'Valor']);
            foreach ($b['indirect'] as $c) {
                $this->row([$this->text(''), $this->text($c['description']), $this->text($c['basis']), $this->money($c['amount'])]);
            }
        }
        $this->row([]);
        $this->row([$this->text('Encargos sociais', $this->style(bold: true)), $this->text($b['regime'])]);
    }

    private function abcSheet(array $d, array $charts): void
    {
        $sheet = $this->newSheet('Curva ABC');
        $this->widths($sheet, [7, 11, 54, 7, 14, 16, 10, 12, 8]);
        $this->title('Curva ABC', $d);

        foreach (['resources' => 'Insumos (das composições)', 'services' => 'Serviços (pelo custo)'] as $key => $label) {
            $this->sectionTitle($label);
            if (isset($charts[$key]) && is_file($charts[$key])) {
                $this->row([new ImageCell($charts[$key], AbcChart::WIDTH, AbcChart::HEIGHT)], height: 230);
            }
            $this->header(['Pos.', 'Código', 'Descrição', 'Un.', 'Quantidade', 'Valor', '%', '% acum.', 'Faixa']);
            foreach ($d['abc'][$key] as $i => $r) {
                $bg = ['A' => 'DCFCE7', 'B' => 'FEF3C7', 'C' => self::GRAY][$r['class']];
                $this->row([$this->num((string) ($i + 1), '0'), $this->text((string) $r['code']), $this->text((string) $r['description'], $this->style(wrap: true)), $this->text((string) $r['unit'], $this->style(align: CellAlignment::CENTER)),
                    $this->num($r['quantity'], self::QTY), $this->money($r['total']), $this->pct($r['percent']), $this->pct($r['cumulative_percent']),
                    $this->text($r['class'], $this->style(bold: true, align: CellAlignment::CENTER, bg: $bg))]);
            }
            $this->row([]);
        }
        $this->row([$this->text('Faixas: A até 80% do valor acumulado, B até 95%, C o restante.', $this->style(italic: true, color: self::MUTED))]);
    }

    private function closingSheet(array $d): void
    {
        $sheet = $this->newSheet('Fechamento');
        $this->widths($sheet, [26, 70]);
        $this->title('Fechamento', $d);
        $c = $d['closing'];
        if ($c['notes']) {
            $this->sectionTitle('Observações');
            $this->row([$this->text($c['notes'], $this->style(wrap: true))], height: max(18, 15 * (substr_count($c['notes'], "\n") + 1 + intdiv(mb_strlen($c['notes']), 110))));
            $this->row([]);
        }
        $this->row([$this->text('Local e data', $this->style(color: self::MUTED)), $this->text(trim(($c['place'] ? $c['place'].', ' : '').$c['date']))]);
        $this->row([]);
        $this->sectionTitle('Responsável técnico');
        $this->row([$this->text('Nome', $this->style(color: self::MUTED)), $this->text($c['technician']['name'] ?? '')]);
        $this->row([$this->text('Registro (CREA/CAU)', $this->style(color: self::MUTED)), $this->text($c['technician']['registry'] ?? '')]);
        $this->row([$this->text('ART/RRT', $this->style(color: self::MUTED)), $this->text($c['technician']['art'] ?? '')]);
        $this->row([]);
        $this->sectionTitle('Cliente');
        $this->row([$this->text('Nome', $this->style(color: self::MUTED)), $this->text($c['customer'] ?? '')]);
    }

    // ------------------------------------------------------------------ apoio

    /** @return list<array{string, string, bool}> */
    private function closingTotals(array $d): array
    {
        $t = $d['totals'];
        $out = [];
        if ($t['indirect_cost'] !== '0.00') {
            $out[] = ['Custos indiretos', $t['indirect_cost'], false];
        }
        if ($t['global_discount'] !== '0.00') {
            $out[] = ['Desconto global ('.ReportFormat::percent($t['global_discount_percent']).')', '-'.$t['global_discount'], false];
        }
        $out[] = ['VALOR FINAL', $t['final_price'], true];

        return $out;
    }

    private function newSheet(string $name): Sheet
    {
        $sheet = $this->writer->addNewSheetAndMakeItCurrent();
        $sheet->setName($name);

        return $sheet;
    }

    /** @param list<float|int> $widths */
    private function widths(Sheet $sheet, array $widths): void
    {
        foreach ($widths as $i => $w) {
            $sheet->setColumnWidth((float) $w, $i + 1);
        }
    }

    private function title(string $title, array $d): void
    {
        $this->row([$this->text($title, $this->style(bold: true, size: 14, color: self::DEEP))], height: 22);
        $m = $d['meta'];
        $this->row([$this->text($m['code'].' · v'.$m['version'].' · '.$m['title'].' · '.$m['status_label'].($m['draft'] ? ' (RASCUNHO)' : ''), $this->style(color: self::MUTED))]);
        $this->row([]);
    }

    private function sectionTitle(string $title): void
    {
        $this->row([$this->text($title, $this->style(bold: true, size: 12, color: self::SAPPHIRE))], height: 20);
    }

    /** @param list<string> $labels */
    private function header(array $labels): void
    {
        $st = $this->style(bold: true, color: Color::WHITE, bg: self::SAPPHIRE, valign: true);
        $this->row(array_map(fn ($l) => $this->text($l, $st), $labels), height: 20);
    }

    /** @param list<Cell> $cells */
    private function row(array $cells, ?float $height = null): void
    {
        $row = new Row($cells);
        if ($height !== null) {
            $row = $row->withHeight($height);
        }
        $this->writer->addRow($row);
    }

    private function text(string $v, ?Style $style = null): Cell
    {
        return Cell::fromValue($v, $style ?? $this->style());
    }

    private function money(string $v, ?Style $style = null): Cell
    {
        return Cell::fromValue((float) $v, $style ?? $this->style(format: self::MONEY));
    }

    private function pct(string $v, ?Style $style = null): Cell
    {
        return Cell::fromValue((float) $v, $style ?? $this->style(format: self::PCT));
    }

    private function num(string $v, string $format): Cell
    {
        return Cell::fromValue((float) $v, $this->style(format: $format));
    }

    private function style(bool $bold = false, bool $italic = false, int $size = 10, string $color = self::GRAPHITE, ?string $bg = null, ?string $format = null,
        bool $wrap = false, ?CellAlignment $align = null, bool $valign = false): Style
    {
        return new Style(
            fontBold: $bold,
            fontItalic: $italic,
            fontSize: $size,
            fontColor: $color,
            fontName: 'Calibri',
            cellAlignment: $align,
            cellVerticalAlignment: $valign || $wrap ? CellVerticalAlignment::CENTER : null,
            shouldWrapText: $wrap ?: null,
            border: new Border(new BorderPart(BorderName::BOTTOM, 'E5E7EB')),
            backgroundColor: $bg,
            format: $format,
        );
    }
}
