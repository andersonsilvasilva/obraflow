<?php

namespace App\Modules\Catalog\Import;

use App\Models\Resource;
use App\Models\ResourceGroup;
use App\Models\Supplier;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Modules\Audit\AuditEvent;
use App\Modules\Audit\AuditLogger;
use App\Modules\Catalog\Services\ResourceCatalogService;
use App\Modules\References\Import\XlsxReader;
use App\Modules\Shared\Exceptions\DomainRuleException;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Importa as planilhas-modelo de insumos (D-P43): `product.xlsx` → materiais; `equipment.xlsx` → equipamentos
 * (custo, D-P38). Duas fases com o MESMO código: pré-visualização (nada é gravado) e execução confirmada (transação
 * única + auditoria). Grupo/subgrupo e unidade são criados se não existirem; fornecedor é só localizado (não se cria
 * fornecedor sem CNPJ); nome já existente no catálogo do mesmo tipo é ignorado. Modelo/Tipo/Condição do equipamento
 * vão para `model` e observações.
 */
final class ResourceSheetImporter
{
    private const MAX_ROWS = 5000;

    /** rótulo normalizado do cabeçalho => campo */
    private const COLUMNS = [
        'SUBGRUPO' => 'subgroup', 'GRUPO' => 'group', 'NOME' => 'name', 'MARCA' => 'brand', 'FORNECEDOR PRINCIPAL' => 'supplier',
        'UNIDADE DE MEDIDA' => 'unit', 'CODIGO' => 'code', 'MODELO' => 'model', 'TIPO' => 'type', 'CONDICAO' => 'condition',
    ];

    public function __construct(
        private readonly ResourceCatalogService $catalog,
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return array{rows: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function preview(string $path, string $kind): array
    {
        $rows = $this->parse($path);
        $existingNames = Resource::query()->where('kind', $kind)->pluck('name')->map(fn ($n) => $this->key($n))->flip();
        $units = $this->unitIndex();
        $groups = ResourceGroup::query()->where('kind', $kind)->get();
        $suppliers = $this->supplierIndex();
        $seen = [];
        $out = [];

        foreach ($rows as $line => $r) {
            $errors = [];
            $warnings = [];
            $actions = [];
            if (($r['name'] ?? '') === '') {
                $errors[] = 'Nome obrigatório.';
            }
            if (($r['group'] ?? '') === '') {
                $errors[] = 'Grupo obrigatório.';
            }
            if ($kind === 'material' && ($r['unit'] ?? '') === '') {
                $errors[] = 'Unidade de medida obrigatória.';
            }
            $nameKey = $this->key($r['name'] ?? '');
            if ($nameKey !== '' && (isset($existingNames[$nameKey]) || isset($seen[$nameKey]))) {
                $warnings[] = 'Já existe no catálogo (ou repetido na planilha): será ignorado.';
            }
            $seen[$nameKey] = true;
            if (($r['unit'] ?? '') !== '' && ! isset($units[$this->key($r['unit'])])) {
                $actions[] = "Criar unidade \"{$r['unit']}\"";
            }
            $group = $groups->first(fn ($g) => $g->parent_id === null && $this->key($g->name) === $this->key($r['group'] ?? ''));
            if (($r['group'] ?? '') !== '' && ! $group) {
                $actions[] = "Criar grupo \"{$r['group']}\"";
            }
            if (($r['subgroup'] ?? '') !== '' && (! $group || ! $groups->first(fn ($g) => $g->parent_id === $group->id && $this->key($g->name) === $this->key($r['subgroup'])))) {
                $actions[] = "Criar subgrupo \"{$r['subgroup']}\"";
            }
            if (($r['supplier'] ?? '') !== '' && ! isset($suppliers[$this->key($r['supplier'])])) {
                $warnings[] = "Fornecedor \"{$r['supplier']}\" não cadastrado: ficará em branco.";
            }

            $status = $errors ? 'error' : (str_contains(implode(' ', $warnings), 'ignorado') ? 'skip' : 'ok');
            $out[] = ['line' => $line, 'data' => $r, 'status' => $status, 'errors' => $errors, 'warnings' => $warnings, 'actions' => $actions];
        }

        return ['rows' => $out, 'summary' => [
            'total' => count($out),
            'ok' => count(array_filter($out, fn ($r) => $r['status'] === 'ok')),
            'skip' => count(array_filter($out, fn ($r) => $r['status'] === 'skip')),
            'error' => count(array_filter($out, fn ($r) => $r['status'] === 'error')),
        ]];
    }

    /** Executa: grava só as linhas `ok` da pré-visualização, numa transação. @return array<string, int> */
    public function import(string $path, string $kind, string $fileName, User $actor): array
    {
        $preview = $this->preview($path, $kind);

        return DB::transaction(function () use ($preview, $kind, $fileName, $actor) {
            $units = $this->unitIndex();
            $suppliers = $this->supplierIndex();
            $created = 0;

            foreach ($preview['rows'] as $row) {
                if ($row['status'] !== 'ok') {
                    continue;
                }
                $r = $row['data'];
                $unitId = $this->unitFor($r['unit'] ?? 'UN', $units);
                $groupId = $this->groupFor($kind, $r['group'], $r['subgroup'] ?? null);
                $notes = array_filter([
                    ($r['type'] ?? '') !== '' ? "Tipo: {$r['type']}" : null,
                    ($r['condition'] ?? '') !== '' ? "Condição: {$r['condition']}" : null,
                ]);
                $this->catalog->create([
                    'kind' => $kind, 'name' => $r['name'], 'code' => ($r['code'] ?? '') !== '' ? $r['code'] : null, 'unit_id' => $unitId,
                    'group_id' => $groupId, 'brand' => $r['brand'] ?? null, 'model' => $r['model'] ?? null,
                    'main_supplier_id' => $suppliers[$this->key($r['supplier'] ?? '')] ?? null,
                    'notes' => $notes ? implode(' · ', $notes) : null,
                ], $actor);
                $created++;
            }

            $summary = $preview['summary'] + ['created' => $created];
            $this->audit->record(AuditEvent::RESOURCE_IMPORTED, tenantId: $this->context->idOrFail(), userId: $actor->getKey(),
                entityType: 'resource', metadata: ['kind' => $kind, 'file' => $fileName] + $summary);

            return $summary;
        });
    }

    /** @return array<int, array<string, string>> linha => campos */
    private function parse(string $path): array
    {
        $reader = new XlsxReader($path);
        $header = null;
        $rows = [];
        foreach ($reader->rows() as $line => $cells) {
            if ($header === null) {
                $map = [];
                foreach ($cells as $col => $label) {
                    $key = Str::upper(trim(str_replace('*', '', Str::ascii($label))));
                    if (isset(self::COLUMNS[$key])) {
                        $map[$col] = self::COLUMNS[$key];
                    }
                }
                if (in_array('name', $map, true)) {
                    $header = $map;
                }

                continue;
            }
            $row = [];
            foreach ($header as $col => $field) {
                $row[$field] = trim((string) ($cells[$col] ?? ''));
            }
            if (implode('', $row) === '') {
                continue; // linha vazia
            }
            $rows[$line] = $row;
            if (count($rows) > self::MAX_ROWS) {
                throw new DomainRuleException('A planilha tem mais de '.self::MAX_ROWS.' linhas; divida o arquivo.');
            }
        }
        if ($header === null) {
            throw new DomainRuleException('Cabeçalho não encontrado: use a planilha-modelo (colunas Grupo, Nome, Unidade de medida…).');
        }

        return $rows;
    }

    private function key(string $s): string
    {
        return Str::lower(trim(preg_replace('/\s+/', ' ', Str::ascii($s))));
    }

    /** @return array<string, int> código e nome normalizados => id */
    private function unitIndex(): array
    {
        $index = [];
        foreach (UnitOfMeasure::query()->get(['id', 'code', 'name']) as $u) {
            $index[$this->key($u->code)] = $u->id;
            $index[$this->key($u->name)] ??= $u->id;
        }

        return $index;
    }

    /** @return array<string, int> */
    private function supplierIndex(): array
    {
        $index = [];
        foreach (Supplier::query()->get(['id', 'legal_name', 'trade_name']) as $s) {
            foreach (array_filter([$s->trade_name, $s->legal_name]) as $n) {
                $index[$this->key($n)] ??= $s->id;
            }
        }

        return $index;
    }

    private function unitFor(string $label, array &$units): int
    {
        $k = $this->key($label);
        if (! isset($units[$k])) {
            $u = UnitOfMeasure::query()->create(['code' => Str::limit(trim($label), 10, ''), 'name' => Str::limit(trim($label), 60, '')]);
            $units[$k] = $u->id;
        }

        return $units[$k];
    }

    private function groupFor(string $kind, string $group, ?string $subgroup): int
    {
        $find = fn (?int $parent, string $name) => ResourceGroup::query()->where('kind', $kind)->where('parent_id', $parent)->get()
            ->first(fn ($g) => $this->key($g->name) === $this->key($name));

        $g = $find(null, $group) ?? ResourceGroup::query()->create(['kind' => $kind, 'name' => Str::limit(trim($group), 100, '')]);
        if ($subgroup === null || trim($subgroup) === '') {
            return $g->id;
        }
        $s = $find($g->id, $subgroup) ?? ResourceGroup::query()->create(['kind' => $kind, 'parent_id' => $g->id, 'name' => Str::limit(trim($subgroup), 100, '')]);

        return $s->id;
    }
}
