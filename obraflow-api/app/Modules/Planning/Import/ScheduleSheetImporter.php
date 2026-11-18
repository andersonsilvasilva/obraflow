<?php

namespace App\Modules\Planning\Import;

use App\Models\Project;
use App\Models\Schedule;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Modules\Audit\AuditEvent;
use App\Modules\Audit\AuditLogger;
use App\Modules\Planning\Services\Scheduler;
use App\Modules\Planning\Services\WorkingCalendar;
use App\Modules\References\Import\XlsxReader;
use App\Modules\Shared\Exceptions\DomainRuleException;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Importação do planejamento por planilha (D-P68b), no formato da planilha-modelo (baseada na `planning.xlsx` do
 * Brickup): Tipo, Item (1, 1.1, 1.1.1…), Descrição, Data de início/entrega (planejado), Início/Conclusão (executado),
 * Valor unit., Quantidade, Und., HH prevista, Comentário.
 * - linhas "Etapa" viram etapas (se têm filhas) ou atividades; a hierarquia vem do Item;
 * - linhas de recurso (Material, Serviços…) ficam para a 6B: aparecem como ignoradas na pré-visualização;
 * - duas fases com o MESMO código: pré-visualização (nada gravado) e importação (transação única + auditoria);
 * - datas: número de série do Excel, AAAA-MM-DD ou DD/MM/AAAA (MM/DD/AAAA se o 2º número passar de 12).
 */
final class ScheduleSheetImporter
{
    private const MAX_ROWS = 3000;

    /** palavra-chave do cabeçalho normalizado => campo (a primeira que bater) */
    private const COLUMNS = [
        'TIPO' => 'type', 'ITEM' => 'item', 'DESCRICAO' => 'name', 'DATA DE INICIO' => 'start', 'DATA DE ENTREGA' => 'end',
        'INICIO DA ETAPA' => 'actual_start', 'CONCLUSAO DA ETAPA' => 'actual_end', 'VALOR UNIT' => 'unit_price',
        'QUANTIDADE' => 'quantity', 'UND' => 'unit', 'HH PREVISTA' => 'hh', 'COMENTARIO' => 'comment',
    ];

    public const TEMPLATE_HEADERS = ['Tipo*', 'Item*', 'Descrição*', 'Data de início (Planejado)', 'Data de entrega (Planejado)',
        'Início da Etapa (Executado)', 'Conclusão da Etapa (Executado)', 'Valor unit.', 'Quantidade', 'Und.', 'HH prevista', 'Comentário'];

    public function __construct(
        private readonly Scheduler $scheduler,
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    /** @return array{rows: list<array<string, mixed>>, summary: array<string, int>} */
    public function preview(string $path, Project $project): array
    {
        $rows = $this->parse($path);
        $codes = [];
        foreach ($rows as $r) {
            if ($this->isStage($r) && ($key = $this->key($r['item'] ?? '')) !== null) {
                $codes[$key] = true;
            }
        }
        // modo do planejamento existente (vazio) ou o padrão, dias corridos (A-51)
        $calendar = WorkingCalendar::forMode($project, (string) (Schedule::query()->where('project_id', $project->id)->value('duration_mode') ?? 'calendar'));
        $seen = [];
        $out = [];
        $units = $this->unitIndex();

        $lastTask = null; // última etapa/atividade válida (dona dos recursos logo abaixo)
        foreach ($rows as $line => $r) {
            $errors = [];
            $warnings = [];
            if (! $this->isStage($r)) {
                $out[] = $this->resourceRow($line, $r, $lastTask, $seen, $codes, $units);

                continue;
            }
            $key = $this->key($r['item'] ?? '');
            if ($key === null) {
                $errors[] = 'Item inválido: use números separados por ponto (ex.: 1.2.3).';
            } elseif (isset($seen[$key])) {
                $errors[] = "Item {$key} repetido.";
            } elseif (str_contains($key, '.') && ! isset($seen[Str::beforeLast($key, '.')])) {
                $errors[] = 'A etapa-mãe ('.Str::beforeLast($key, '.').') deve vir antes, na planilha.';
            }
            if (trim($r['name'] ?? '') === '') {
                $errors[] = 'Descrição obrigatória.';
            } elseif (mb_strlen($r['name']) > 250) {
                $errors[] = 'Descrição com mais de 250 caracteres.';
            }
            $dates = [];
            foreach (['start', 'end', 'actual_start', 'actual_end'] as $f) {
                if (($r[$f] ?? '') === '') {
                    continue;
                }
                $d = $this->date($r[$f]);
                $d === null ? $errors[] = "Data inválida: \"{$r[$f]}\"." : $dates[$f] = $d;
            }
            if (isset($dates['start'], $dates['end']) && $dates['end']->lt($dates['start'])) {
                $errors[] = 'Entrega antes do início.';
            }
            $summary = $key !== null && $this->hasChildren($key, $codes);
            $start = $dates['start'] ?? CarbonImmutable::parse($project->start_date ?? now()->toDateString());
            $duration = isset($dates['end']) && ! $errors ? max(1, $calendar->count($calendar->next($start), $dates['end'])) : 1;
            if (! isset($dates['start'])) {
                $warnings[] = 'Sem data de início: usa a data inicial da obra.';
            }
            $unit = null;
            if (($r['unit'] ?? '') !== '') {
                $unit = $units[$this->unitKey($r['unit'])] ?? null;
                if ($unit === null) {
                    $warnings[] = "Unidade \"{$r['unit']}\" não cadastrada: fica como está (cadastre em Unidades de medida).";
                }
            }
            if ($key !== null) {
                $seen[$key] = true;
                $lastTask = $errors ? null : $key;
            }
            $out[] = ['line' => $line, 'data' => $r, 'status' => $errors ? 'error' : 'ok', 'errors' => $errors, 'warnings' => $warnings,
                'task' => $errors ? null : ['wbs' => $key, 'kind' => $summary ? 'summary' : 'task', 'start' => $start->toDateString(),
                    'duration_days' => $duration, 'unit' => $unit ?? ($r['unit'] ?? '' ?: null), 'actual_start' => ($dates['actual_start'] ?? null)?->toDateString(), 'actual_end' => ($dates['actual_end'] ?? null)?->toDateString()]];
        }

        return ['rows' => $out, 'summary' => [
            'total' => count($out),
            'ok' => count(array_filter($out, fn ($r) => $r['status'] === 'ok')),
            'skip' => count(array_filter($out, fn ($r) => $r['status'] === 'skip')),
            'resources' => count(array_filter($out, fn ($r) => $r['status'] === 'ok' && isset($r['resource']))),
            'error' => count(array_filter($out, fn ($r) => $r['status'] === 'error')),
        ]];
    }

    /** Importa as linhas válidas num planejamento NOVO (origem `spreadsheet`). Erro em alguma linha → nada é gravado. */
    public function import(string $path, string $fileName, Project $project, User $actor): array
    {
        $preview = $this->preview($path, $project);
        if ($preview['summary']['error'] > 0) {
            throw new DomainRuleException('A planilha tem '.$preview['summary']['error'].' linha(s) com erro: corrija e envie de novo.');
        }
        if ($preview['summary']['ok'] === 0) {
            throw new DomainRuleException('Nenhuma etapa ou atividade para importar.');
        }

        return DB::transaction(function () use ($preview, $fileName, $project, $actor) {
            // planejamento já existente e VAZIO (ex.: criado do zero, talvez com linha de base) é reaproveitado
            $schedule = Schedule::query()->where('project_id', $project->id)->lockForUpdate()->first();
            if ($schedule !== null && DB::table('schedule_tasks')->where('schedule_id', $schedule->id)->exists()) {
                throw new DomainRuleException('A obra já tem planejamento com atividades. Para importar, apague o atual ("Deletar tudo").');
            }
            if ($schedule === null) {
                $schedule = new Schedule;
                $schedule->forceFill(['uuid' => (string) Str::uuid7(), 'project_id' => $project->id, 'created_by' => $actor->getKey()]);
            }
            $schedule->forceFill(['origin' => 'spreadsheet', 'budget_version_id' => null])->save();
            $calendar = WorkingCalendar::forMode($project, (string) ($schedule->duration_mode ?? 'calendar'));
            $ids = [];
            $order = [];
            $now = now();

            foreach ($preview['rows'] as $row) {
                if ($row['status'] !== 'ok' || ! isset($row['task'])) {
                    continue;
                }
                $t = $row['task'];
                $parentKey = str_contains($t['wbs'], '.') ? Str::beforeLast($t['wbs'], '.') : null;
                $start = $calendar->next(CarbonImmutable::parse($t['start']));
                $end = $t['kind'] === 'summary' ? $start : $calendar->finish($start, $t['duration_days']);
                $order[$parentKey ?? ''] = ($order[$parentKey ?? ''] ?? 0) + 1;
                $ids[$t['wbs']] = DB::table('schedule_tasks')->insertGetId([
                    'tenant_id' => $schedule->tenant_id, 'schedule_id' => $schedule->id, 'parent_id' => $parentKey ? $ids[$parentKey] : null,
                    'wbs_code' => $t['wbs'], 'name' => trim($row['data']['name']), 'kind' => $t['kind'],
                    'unit_code' => $t['unit'] !== null ? Str::limit($t['unit'], 20, '') : null,
                    'quantity' => is_numeric($q = str_replace(',', '.', (string) ($row['data']['quantity'] ?? ''))) ? $q : null,
                    'planned_start' => $start->toDateString(), 'planned_end' => $end->toDateString(), 'duration_days' => $t['kind'] === 'summary' ? 1 : $t['duration_days'],
                    'actual_start' => $t['actual_start'], 'actual_end' => $t['actual_end'] !== null && $t['actual_start'] !== null && $t['actual_end'] < $t['actual_start'] ? null : $t['actual_end'],
                    'sort_order' => $order[$parentKey ?? ''], 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            // recursos das atividades (6B, D-P94): cópia do valor da planilha, todo no tipo da linha
            $items = [];
            $sort = [];
            foreach ($preview['rows'] as $row) {
                if ($row['status'] !== 'ok' || ! isset($row['resource'])) {
                    continue;
                }
                $res = $row['resource'];
                $taskId = $ids[$res['task']];
                $sort[$taskId] = ($sort[$taskId] ?? 0) + 1;
                $items[] = [
                    'tenant_id' => $schedule->tenant_id, 'schedule_id' => $schedule->id, 'task_id' => $taskId, 'source' => 'manual',
                    'source_ref' => json_encode(['spreadsheet' => $fileName, 'line' => $row['line']]), 'kind' => $res['kind'], 'code' => null,
                    'description' => Str::limit($res['description'], 500, ''), 'unit_code' => Str::limit($res['unit'], 20, ''), 'quantity_per_unit' => $res['quantity_per_unit'],
                    'unit_cost' => $res['unit_cost'], 'cost_material' => 0, 'cost_labor' => 0, 'cost_equipment' => 0, 'cost_service' => 0, 'cost_other' => 0,
                    "cost_{$res['kind']}" => $res['unit_cost'], 'sort_order' => $sort[$taskId], 'created_at' => $now, 'updated_at' => $now,
                ];
            }
            foreach (array_chunk($items, 500) as $chunk) {
                DB::table('schedule_task_items')->insert($chunk);
            }
            $this->scheduler->recompute($schedule);

            $summary = $preview['summary'] + ['created' => count($ids), 'resources_created' => count($items)];
            $this->audit->record(AuditEvent::SCHEDULE_CREATED, tenantId: $this->context->idOrFail(), userId: $actor->getKey(),
                entityType: 'project', entityId: $project->id, metadata: ['origin' => 'spreadsheet', 'file' => $fileName] + $summary);

            return $summary;
        });
    }

    /** Planilha-modelo (.xlsx) com o cabeçalho e um exemplo, gerada pelo OpenSpout (D-P73). */
    public function template(string $path): void
    {
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(self::TEMPLATE_HEADERS));
        foreach ([
            ['Etapa', '1', 'Fundação', '02/03/2026', '13/03/2026', '', '', '', '', '', '', ''],
            ['Etapa', '1.1', 'Escavação', '02/03/2026', '04/03/2026', '', '', '', '120', 'm³', '', ''],
            ['Etapa', '1.2', 'Concreto das sapatas', '05/03/2026', '13/03/2026', '', '', '', '18', 'm³', '', ''],
            ['Material', '1.2.1', 'Concreto usinado (recurso: entra na 6B)', '', '', '', '', '450,00', '18', 'm³', '', ''],
            ['Etapa', '2', 'Alvenaria', '16/03/2026', '10/04/2026', '', '', '', '', '', '', ''],
        ] as $example) {
            $writer->addRow(Row::fromValues($example));
        }
        $writer->close();
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
                    $norm = Str::upper(trim(preg_replace('/\s+/', ' ', str_replace('*', '', Str::ascii((string) $label)))));
                    foreach (self::COLUMNS as $keyword => $field) {
                        if (str_starts_with($norm, $keyword) && ! in_array($field, $map, true)) {
                            $map[$col] = $field;
                            break;
                        }
                    }
                }
                if (in_array('item', $map, true) && in_array('name', $map, true)) {
                    $header = $map;
                }

                continue;
            }
            $row = [];
            foreach ($header as $col => $field) {
                $row[$field] = trim((string) ($cells[$col] ?? ''));
            }
            if (implode('', $row) === '') {
                continue;
            }
            $rows[$line] = $row;
            if (count($rows) > self::MAX_ROWS) {
                throw new DomainRuleException('A planilha tem mais de '.self::MAX_ROWS.' linhas.');
            }
        }
        if ($header === null) {
            throw new DomainRuleException('Cabeçalho não encontrado: use a planilha-modelo (colunas Tipo, Item, Descrição…).');
        }

        return $rows;
    }

    /** Apelidos comuns → forma normalizada do código cadastrado (o SINAPI usa UN, M2, M3, VB…). */
    private const UNIT_ALIASES = ['und' => 'un', 'unid' => 'un', 'unidade' => 'un', 'pc' => 'un', 'peca' => 'un', 'verba' => 'vb'];

    /** "m²", "M2", "m 2" → "m2"; "Metro quadrado" → "metroquadrado"; apelidos (und → un). */
    private function unitKey(string $label): string
    {
        $k = Str::lower(preg_replace('/[^A-Za-z0-9]+/', '', Str::ascii(str_replace(['²', '³'], ['2', '3'], trim($label)))));

        return self::UNIT_ALIASES[$k] ?? $k;
    }

    /** @return array<string, string> chave normalizada (código ou nome) => código cadastrado */
    private function unitIndex(): array
    {
        $index = [];
        foreach (UnitOfMeasure::query()->where('status', 'active')->get(['code', 'name']) as $u) {
            $index[$this->unitKey($u->code)] ??= $u->code;
            $index[$this->unitKey($u->name)] ??= $u->code;
        }

        return $index;
    }

    /** Tipo da linha da planilha → tipo de custo (como no planning.xlsx: Material, Serviços, Equipamento, Mão de obra, Indiretos…). */
    private const RESOURCE_KINDS = [
        'MATERIAL' => 'material', 'MATERIAIS' => 'material', 'SERVICO' => 'service', 'SERVICOS' => 'service',
        'EQUIPAMENTO' => 'equipment', 'EQUIPAMENTOS' => 'equipment', 'MAO DE OBRA' => 'labor', 'CARGO/FUNCAO' => 'labor', 'CARGO' => 'labor',
        'INDIRETOS' => 'other', 'INDIRETO' => 'other', 'OUTROS' => 'other', 'OUTRO' => 'other',
    ];

    /**
     * Linha de recurso (D-P94): fica na atividade indicada pelo item (pai do número) ou na etapa/atividade logo acima.
     * Valor unit. = custo unitário; Quantidade = consumo por unidade da atividade (vazio = 1).
     *
     * @param  array<string, true>  $seen
     * @param  array<string, true>  $codes
     */
    private function resourceRow(int $line, array $r, ?string $lastTask, array $seen, array $codes, array $units): array
    {
        $errors = [];
        $warnings = [];
        $type = Str::upper(Str::ascii(trim($r['type'] ?? '')));
        $kind = self::RESOURCE_KINDS[$type] ?? null;
        if ($kind === null) {
            $errors[] = "Tipo \"{$r['type']}\" não reconhecido (use Etapa, Material, Serviços, Equipamento, Mão de obra ou Outros).";
        }
        $owner = $lastTask;
        if (($key = $this->key($r['item'] ?? '')) !== null && str_contains($key, '.') && isset($seen[Str::beforeLast($key, '.')])) {
            $owner = Str::beforeLast($key, '.');
        }
        if ($owner === null) {
            $errors[] = 'Recurso sem etapa/atividade acima dele.';
        } elseif ($this->hasChildren($owner, $codes)) {
            $errors[] = "O recurso precisa ficar numa atividade, não na etapa {$owner} (que tem sub atividades).";
        }
        if (trim($r['name'] ?? '') === '') {
            $errors[] = 'Descrição obrigatória.';
        }
        $price = str_replace(',', '.', trim((string) ($r['unit_price'] ?? '')));
        if ($price === '' || ! is_numeric($price) || (float) $price < 0) {
            $errors[] = 'Valor unitário inválido ou vazio.';
        }
        $qty = str_replace(',', '.', trim((string) ($r['quantity'] ?? '')));
        if ($qty !== '' && (! is_numeric($qty) || (float) $qty < 0)) {
            $errors[] = "Quantidade inválida: \"{$r['quantity']}\".";
        }
        $unit = null;
        if (($r['unit'] ?? '') !== '') {
            $unit = $units[$this->unitKey($r['unit'])] ?? null;
            if ($unit === null) {
                $warnings[] = "Unidade \"{$r['unit']}\" não cadastrada: fica como está.";
            }
        }

        return ['line' => $line, 'data' => $r, 'status' => $errors ? 'error' : 'ok', 'errors' => $errors, 'warnings' => $warnings,
            'resource' => $errors ? null : ['task' => $owner, 'kind' => $kind, 'description' => trim($r['name']), 'unit' => $unit ?? (trim($r['unit'] ?? '') ?: 'UN'),
                'unit_cost' => number_format((float) $price, 2, '.', ''), 'quantity_per_unit' => $qty === '' ? '1' : $qty]];
    }

    private function isStage(array $r): bool
    {
        $type = Str::upper(Str::ascii(trim($r['type'] ?? '')));

        return $type === '' || $type === 'ETAPA' || $type === 'ATIVIDADE';
    }

    /** "1.1.2", "1-1-2", "1 / 1 / 2" → "1.1.2"; inválido → null. */
    private function key(string $item): ?string
    {
        $parts = preg_split('/[^0-9A-Za-z]+/', trim($item), -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === [] || $parts === false || array_filter($parts, fn ($p) => ! ctype_digit($p))) {
            return null;
        }

        return implode('.', array_map(fn ($p) => (string) (int) $p, $parts));
    }

    private function hasChildren(string $key, array $codes): bool
    {
        foreach (array_keys($codes) as $code) {
            if (str_starts_with((string) $code, $key.'.')) {
                return true;
            }
        }

        return false;
    }

    private function date(string $value): ?CarbonImmutable
    {
        $v = trim($value);
        if (is_numeric($v) && (float) $v > 20000 && (float) $v < 80000) { // número de série do Excel
            return CarbonImmutable::create(1899, 12, 30)->addDays((int) $v);
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return CarbonImmutable::create((int) $m[1], (int) $m[2], (int) $m[3]);
        }
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $v, $m)) {
            [$a, $b, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            [$day, $month] = $b > 12 ? [$b, $a] : [$a, $b]; // MM/DD só quando o 2º número não pode ser mês
            if (checkdate($month, $day, $y)) {
                return CarbonImmutable::create($y, $month, $day);
            }
        }

        return null;
    }
}
