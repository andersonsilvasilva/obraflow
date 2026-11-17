<?php

namespace App\Modules\References\Import;

use Generator;
use RuntimeException;
use XMLReader;
use ZipArchive;

/**
 * Leitor mínimo de XLSX por streaming (sem dependências): lê a PRIMEIRA planilha linha a linha e devolve
 * cada linha como [coluna => texto] (ex.: ['A' => '97141', 'K' => '3,84']), só com células preenchidas.
 * Suporta strings compartilhadas, strings em linha e valores numéricos (devolvidos como no arquivo).
 */
final class XlsxReader
{
    /** @var list<string> */
    private array $shared = [];

    private string $sheetPath;

    public function __construct(private readonly string $path)
    {
        if (! is_file($path)) {
            throw new RuntimeException("Arquivo não encontrado: {$path}");
        }
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException("Não é um arquivo XLSX válido: {$path}");
        }
        $this->sheetPath = $this->firstSheet($zip);
        $hasShared = $zip->locateName('xl/sharedStrings.xml') !== false;
        $zip->close();

        if ($hasShared) {
            $this->loadSharedStrings();
        }
    }

    /** @return Generator<int, array<string, string>> número da linha => células */
    public function rows(): Generator
    {
        $xml = new XMLReader;
        if (! $xml->open('zip://'.$this->path.'#'.$this->sheetPath)) {
            throw new RuntimeException('Planilha ilegível.');
        }

        $line = 0;
        while ($xml->read()) {
            if ($xml->nodeType !== XMLReader::ELEMENT || $xml->name !== 'row') {
                continue;
            }
            $line++;
            $row = simplexml_load_string($xml->readOuterXml());
            $cells = [];
            foreach ($row->c as $c) {
                $value = $this->cellValue($c);
                if ($value !== '') {
                    $cells[preg_replace('/\d+/', '', (string) $c['r'])] = $value;
                }
            }
            yield $line => $cells;
        }
        $xml->close();
    }

    private function cellValue(\SimpleXMLElement $c): string
    {
        $type = (string) $c['t'];
        $raw = match ($type) {
            's' => $this->shared[(int) $c->v] ?? '',
            'inlineStr' => $this->text($c->is),
            default => (string) $c->v,
        };

        return trim(preg_replace('/\s+/u', ' ', $raw));
    }

    private function text(?\SimpleXMLElement $node): string
    {
        if ($node === null) {
            return '';
        }
        if (isset($node->t)) {
            return (string) $node->t;
        }
        $out = '';
        foreach ($node->r as $run) {
            $out .= (string) $run->t;
        }

        return $out;
    }

    private function loadSharedStrings(): void
    {
        $xml = new XMLReader;
        $xml->open('zip://'.$this->path.'#xl/sharedStrings.xml');
        while ($xml->read()) {
            if ($xml->nodeType === XMLReader::ELEMENT && $xml->name === 'si') {
                $this->shared[] = $this->text(simplexml_load_string($xml->readOuterXml()));
            }
        }
        $xml->close();
    }

    /** Caminho da primeira planilha, resolvido por workbook.xml + rels (o nome do arquivo pode variar). */
    private function firstSheet(ZipArchive $zip): string
    {
        $workbook = simplexml_load_string((string) $zip->getFromName('xl/workbook.xml'));
        $rels = simplexml_load_string((string) $zip->getFromName('xl/_rels/workbook.xml.rels'));
        if ($workbook === false || $rels === false) {
            return 'xl/worksheets/sheet1.xml';
        }
        $sheet = $workbook->sheets->sheet[0] ?? null;
        $rid = $sheet ? (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] : '';
        foreach ($rels->Relationship as $rel) {
            if ((string) $rel['Id'] === $rid) {
                $target = ltrim((string) $rel['Target'], '/');

                return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }
}
