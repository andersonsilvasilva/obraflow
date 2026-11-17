<?php

namespace App\Modules\References\Import;

use App\Models\ReferenceDataset;
use App\Models\ReferenceImport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Importa arquivos oficiais do SINAPI numa base (D-P30). Valores entram EXATAMENTE como publicados: nada é
 * calculado, deduzido ou corrigido. O cabeçalho de cada arquivo é conferido contra a base (competência, regime)
 * para impedir misturar desonerado × não desonerado ou meses diferentes.
 *
 * Deve ser chamado dentro de uma transação (o comando cuida disso).
 */
final class SinapiImporter
{
    private const BATCH = 1000;

    /** Macro-Classe do relatório de famílias → tipo do insumo (EQUIPAMENTO (AQUISIÇÃO|LOCAÇÃO) por prefixo). Outras (ex.: ESPECIAIS) ficam com kind NULL. */
    private const KIND_BY_CLASS = [
        'MATERIAL' => 'material',
        'MAO DE OBRA' => 'labor',
        'EQUIPAMENTO' => 'equipment',
        'SERVICOS' => 'service',
        'SERVICO' => 'service',
    ];

    /** @var array<string, int> */
    private array $units = [];

    public function __construct(private readonly ReferenceDataset $dataset) {}

    /** Arquivo de PREÇOS DE INSUMOS: código, descrição, unidade, origem do preço e preço. @return array{read:int, imported:int} */
    public function resources(string $path): array
    {
        $reader = new XlsxReader($path);
        $header = null;
        $read = 0;
        $batchResources = [];
        $prices = [];

        foreach ($reader->rows() as $cells) {
            if ($header === null) {
                $this->checkMetadata($cells);
                if (! in_array('DESCRICAO DO INSUMO', array_map([$this, 'norm'], $cells), true)) {
                    continue;
                }
                $header = $this->header($cells, ['CODIGO' => 'code', 'DESCRICAO DO INSUMO' => 'description', 'UNIDADE DE MEDIDA' => 'unit', 'ORIGEM DO PRECO' => 'origin', 'PRECO MEDIANO R$' => 'price']);

                continue;
            }
            $row = $this->pick($cells, $header);
            if (($row['code'] ?? '') === '' || ($row['description'] ?? '') === '') {
                continue;
            }
            $read++;
            $batchResources[$row['code']] = [
                'dataset_id' => $this->dataset->id, 'code' => $row['code'], 'description' => $row['description'],
                'unit_id' => $this->unit($row['unit'] ?? 'UN'), 'created_at' => now(), 'updated_at' => now(),
            ];
            $prices[$row['code']] = ['price' => Number::decimal($row['price'] ?? null), 'origin' => $row['origin'] ?? null];
        }

        $this->requireHeader($header, 'insumos');
        foreach (array_chunk($batchResources, self::BATCH) as $chunk) {
            DB::table('reference_resources')->insert($chunk);
        }

        $ids = $this->resourceIds();
        $rows = [];
        foreach ($prices as $code => $p) {
            if ($p['price'] === null) {
                continue;
            }
            $rows[] = ['resource_id' => $ids[$code], 'price' => $p['price'], 'price_origin' => $p['origin'], 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, self::BATCH) as $chunk) {
            DB::table('reference_resource_prices')->insert($chunk);
        }

        return ['read' => $read, 'imported' => count($batchResources)];
    }

    /**
     * Arquivo de CUSTOS DE COMPOSIÇÕES ANALÍTICO. Linha sem "TIPO ITEM" = cabeçalho da composição (custos);
     * com "TIPO ITEM" = item (INSUMO ou COMPOSICAO). Composições filhas são resolvidas depois de ler o arquivo todo.
     * Insumo citado que não está no arquivo de preços é criado sem preço (como publicado na linha).
     *
     * @return array{read:int, imported:int}
     */
    public function compositions(string $path): array
    {
        $reader = new XlsxReader($path);
        $header = null;
        $read = 0;
        $compositions = [];
        $items = [];
        $map = [
            'DESCRICAO DA CLASSE' => 'class_name', 'SIGLA DA CLASSE' => 'class_code', 'DESCRICAO DO TIPO 1' => 'type_name',
            'SIGLA DO TIPO 1' => 'type_code', 'CODIGO DO AGRUPADOR' => 'group_code', 'DESCRICAO DO AGRUPADOR' => 'group_name',
            'CODIGO DA COMPOSICAO' => 'code', 'DESCRICAO DA COMPOSICAO' => 'description', 'UNIDADE' => 'unit',
            'ORIGEM DE PRECO' => 'origin', 'CUSTO TOTAL' => 'total', 'TIPO ITEM' => 'item_type', 'CODIGO ITEM' => 'item_code',
            'DESCRICAO ITEM' => 'item_description', 'UNIDADE ITEM' => 'item_unit', 'ORIGEM DE PRECO ITEM' => 'item_origin',
            'COEFICIENTE' => 'coefficient', 'PRECO UNITARIO' => 'item_price', 'CUSTO MAO DE OBRA' => 'labor',
            'CUSTO MATERIAL' => 'material', 'CUSTO EQUIPAMENTO' => 'equipment', 'CUSTO SERVICOS TERCEIROS' => 'services',
            'CUSTO OUTROS' => 'other',
        ];

        foreach ($reader->rows() as $cells) {
            if ($header === null) {
                $this->checkMetadata($cells);
                if (in_array('CODIGO DA COMPOSICAO', array_map([$this, 'norm'], $cells), true)) {
                    $header = $this->header($cells, $map, itemCostColumn: true);
                }

                continue;
            }
            $row = $this->pick($cells, $header);
            $code = $row['code'] ?? '';
            if ($code === '') {
                continue;
            }
            $read++;
            $compositions[$code] ??= [
                'dataset_id' => $this->dataset->id, 'code' => $code, 'description' => $row['description'] ?? '',
                'unit_id' => $this->unit($row['unit'] ?? 'UN'),
                'class_code' => $row['class_code'] ?? null, 'class_name' => $row['class_name'] ?? null,
                'type_code' => $row['type_code'] ?? null, 'type_name' => $row['type_name'] ?? null,
                'group_code' => $row['group_code'] ?? null, 'group_name' => $row['group_name'] ?? null,
                'price_origin' => $row['origin'] ?? null,
                'total_cost' => Number::decimal($row['total'] ?? null) ?? throw new RuntimeException("Composição {$code} sem custo total no arquivo."),
                'labor_cost' => null, 'material_cost' => null, 'equipment_cost' => null, 'services_cost' => null, 'other_cost' => null,
                'created_at' => now(), 'updated_at' => now(),
            ];

            $type = $this->norm($row['item_type'] ?? '');
            if ($type === '') { // linha-resumo da composição: decomposição de custos
                foreach (['labor' => 'labor_cost', 'material' => 'material_cost', 'equipment' => 'equipment_cost', 'services' => 'services_cost', 'other' => 'other_cost'] as $from => $to) {
                    $compositions[$code][$to] = Number::decimal($row[$from] ?? null);
                }

                continue;
            }
            if (! in_array($type, ['INSUMO', 'COMPOSICAO'], true)) {
                throw new RuntimeException("Tipo de item desconhecido \"{$row['item_type']}\" na composição {$code}.");
            }
            $items[] = [
                'composition' => $code, 'type' => $type, 'code' => $row['item_code'] ?? '', 'description' => $row['item_description'] ?? '',
                'unit' => $row['item_unit'] ?? 'UN', 'coefficient' => Number::decimal($row['coefficient'] ?? null, 7) ?? '0.0000000',
                'unit_price' => Number::decimal($row['item_price'] ?? null), 'cost' => Number::decimal($row['item_cost'] ?? null),
                'origin' => $row['item_origin'] ?? null,
            ];
        }

        $this->requireHeader($header, 'composições');
        foreach (array_chunk(array_values($compositions), self::BATCH) as $chunk) {
            DB::table('reference_compositions')->insert($chunk);
        }

        $compIds = DB::table('reference_compositions')->where('dataset_id', $this->dataset->id)->pluck('id', 'code')->all();
        $resIds = $this->resourceIds();

        // insumos citados que não estão no arquivo de preços: criados sem preço, com os dados da linha
        $missing = [];
        foreach ($items as $it) {
            if ($it['type'] === 'INSUMO' && ! isset($resIds[$it['code']]) && ! isset($missing[$it['code']])) {
                $missing[$it['code']] = ['dataset_id' => $this->dataset->id, 'code' => $it['code'], 'description' => $it['description'],
                    'unit_id' => $this->unit($it['unit']), 'created_at' => now(), 'updated_at' => now()];
            }
        }
        foreach (array_chunk(array_values($missing), self::BATCH) as $chunk) {
            DB::table('reference_resources')->insert($chunk);
        }
        if ($missing) {
            $resIds = $this->resourceIds();
        }

        $orphans = [];
        $rows = [];
        $order = [];
        foreach ($items as $it) {
            $childId = $it['type'] === 'COMPOSICAO' ? ($compIds[$it['code']] ?? null) : null;
            if ($it['type'] === 'COMPOSICAO' && $childId === null) {
                $orphans[] = "{$it['composition']} → {$it['code']}";

                continue;
            }
            $order[$it['composition']] = ($order[$it['composition']] ?? 0) + 1;
            $rows[] = [
                'dataset_id' => $this->dataset->id, 'composition_id' => $compIds[$it['composition']],
                'resource_id' => $it['type'] === 'INSUMO' ? $resIds[$it['code']] : null, 'child_composition_id' => $childId,
                'coefficient' => $it['coefficient'], 'unit_price' => $it['unit_price'], 'cost' => $it['cost'],
                'price_origin' => $it['origin'], 'sort_order' => $order[$it['composition']], 'created_at' => now(), 'updated_at' => now(),
            ];
        }
        if ($orphans) {
            throw new RuntimeException('Composições filhas ausentes no arquivo (importação abortada, nada gravado): '.implode('; ', array_slice($orphans, 0, 10)));
        }
        foreach (array_chunk($rows, self::BATCH) as $chunk) {
            DB::table('reference_composition_items')->insert($chunk);
        }

        return ['read' => $read, 'imported' => count($compositions)];
    }

    /** Relatório de FAMÍLIAS DE INSUMOS: define o tipo (Macro-Classe) dos insumos da base. @return array{read:int, imported:int} */
    public function families(string $path): array
    {
        $reader = new XlsxReader($path);
        $header = null;
        $read = 0;
        $classes = [];

        foreach ($reader->rows() as $cells) {
            if ($header === null) {
                if (in_array('MACRO-CLASSE', array_map([$this, 'norm'], $cells), true)) {
                    $header = $this->header($cells, ['COD. INSUMO REPRESENTADO' => 'code', 'MACRO-CLASSE' => 'class']);
                }

                continue;
            }
            $row = $this->pick($cells, $header);
            if (($row['code'] ?? '') === '' || ($row['class'] ?? '') === '') {
                continue;
            }
            $read++;
            $classes[$row['code']] = $row['class'];
        }
        $this->requireHeader($header, 'famílias');

        $updated = 0;
        foreach ($classes as $code => $class) {
            $updated += DB::table('reference_resources')->where('dataset_id', $this->dataset->id)->where('code', $code)
                ->update(['family_class' => Str::limit($class, 60, ''), 'kind' => $this->kindOf($class), 'updated_at' => now()]);
        }

        return ['read' => $read, 'imported' => $updated];
    }

    private function kindOf(string $class): ?string
    {
        $key = $this->norm($class);

        return self::KIND_BY_CLASS[$key] ?? (str_starts_with($key, 'EQUIPAMENTO') ? 'equipment' : null);
    }

    public static function sha256(string $path): string
    {
        return hash_file('sha256', $path);
    }

    /** Linhas antes do cabeçalho: confere competência (MM/AAAA) e regime de encargos contra a base. */
    private function checkMetadata(array $cells): void
    {
        $text = $this->norm(implode(' ', $cells));
        if (preg_match('/ENCARGOS SOCIAIS (NAO )?DESONERADOS/', $text, $m)) {
            $regime = ($m[1] ?? '') === '' ? 'with_relief' : 'without_relief';
            if ($regime !== $this->dataset->charges_regime) {
                throw new RuntimeException("O arquivo é de encargos {$regime} e a base é {$this->dataset->charges_regime}.");
            }
        }
        if (preg_match('/(?:MES DE COLETA|DATA DE PRECO)\s*:\s*(\d{2})\/(\d{4})/', $text, $m)) {
            $version = "{$m[2]}-{$m[1]}";
            if ($version !== $this->dataset->version) {
                throw new RuntimeException("O arquivo é da competência {$version} e a base é {$this->dataset->version}.");
            }
        }
    }

    /**
     * Mapeia rótulos do cabeçalho → colunas. Rótulos repetidos (ex.: "CUSTO TOTAL" da composição e do item):
     * o primeiro vai para o nome mapeado e o segundo para "item_cost".
     *
     * @return array<string, string> coluna => campo
     */
    private function header(array $cells, array $map, bool $itemCostColumn = false): array
    {
        $out = [];
        $seen = [];
        foreach ($cells as $col => $label) {
            $key = $this->norm($label);
            if (! isset($map[$key])) {
                continue;
            }
            if (isset($seen[$key])) {
                if ($itemCostColumn && $key === 'CUSTO TOTAL') {
                    $out[$col] = 'item_cost';
                }

                continue;
            }
            $seen[$key] = true;
            $out[$col] = $map[$key];
        }
        $missing = array_diff(array_values($map), array_values($out));
        if ($missing) {
            throw new RuntimeException('Cabeçalho inesperado; colunas ausentes: '.implode(', ', $missing));
        }

        return $out;
    }

    /** @return array<string, string> */
    private function pick(array $cells, array $header): array
    {
        $row = [];
        foreach ($header as $col => $field) {
            if (isset($cells[$col])) {
                $row[$field] = $cells[$col];
            }
        }

        return $row;
    }

    private function requireHeader(?array $header, string $kind): void
    {
        if ($header === null) {
            throw new RuntimeException("Cabeçalho do arquivo de {$kind} não encontrado: confira se é o arquivo certo.");
        }
    }

    private function unit(string $code): int
    {
        $code = Str::upper(trim($code)) ?: 'UN';
        if (! isset($this->units[$code])) {
            DB::table('reference_units')->insertOrIgnore(['code' => $code, 'created_at' => now(), 'updated_at' => now()]);
            $this->units[$code] = (int) DB::table('reference_units')->where('code', $code)->value('id');
        }

        return $this->units[$code];
    }

    /** @return array<string, int> */
    private function resourceIds(): array
    {
        return DB::table('reference_resources')->where('dataset_id', $this->dataset->id)->pluck('id', 'code')->all();
    }

    /** Maiúsculas, sem acentos e espaços normalizados (rótulos variam entre arquivos). */
    private function norm(string $s): string
    {
        return Str::upper(trim(preg_replace('/\s+/', ' ', Str::ascii($s))));
    }

    /** Usado pelo comando para registrar o arquivo. */
    public function record(string $kind, string $path, array $counts): ReferenceImport
    {
        return ReferenceImport::query()->create([
            'dataset_id' => $this->dataset->id, 'kind' => $kind, 'file_name' => basename($path),
            'file_sha256' => self::sha256($path), 'rows_read' => $counts['read'], 'rows_imported' => $counts['imported'], 'imported_at' => now(),
        ]);
    }
}
