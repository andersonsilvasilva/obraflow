<?php

namespace App\Modules\Catalog\Console;

use App\Models\ReferenceDataset;
use App\Models\Tenant;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Modules\Audit\AuditEvent;
use App\Modules\Audit\AuditLogger;
use App\Modules\References\Console\DryRunRollback;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Copia insumos de uma base de referência (ex.: SINAPI) para o catálogo da organização (Fase 4C, D-P37/D-08):
 * cada insumo vira uma CÓPIA editável (código, descrição, unidade e preço oficial da competência), com a origem nas
 * observações. A base de referência não é alterada.
 *
 * - Idempotente: insumo com o mesmo código (ativo) ou o mesmo nome no mesmo tipo é ignorado.
 * - Unidades de medida ausentes no tenant são criadas (código da base + nome por extenso).
 * - Descrição acima de 200 caracteres: o nome guarda o início e o FIM (onde o SINAPI põe as especificações que
 *   distinguem itens parecidos, ex.: "40/50 MM" × "70/80 MM") e a descrição completa vai para as observações.
 * - Tudo numa transação + auditoria `resource.imported`; `--dry-run` executa e desfaz.
 */
class CopySinapiResourcesCommand extends Command
{
    protected $signature = 'obraflow:catalog:copy-references
        {--tenant= : ID do tenant de destino}
        {--user= : ID do usuário responsável (auditoria; precisa ser membro do tenant)}
        {--dataset= : ID da base de referência de origem (o regime define o preço da mão de obra)}
        {--kinds=material,labor : Tipos a copiar (material, labor, equipment, service)}
        {--dry-run : Executa e desfaz (nada é gravado)}';

    protected $description = 'Copia insumos de uma base de referência (SINAPI) para o catálogo de insumos da organização.';

    /** Nomes por extenso das unidades publicadas pelo SINAPI (o código é o da base). */
    private const UNIT_NAMES = [
        'UN' => 'Unidade', 'M' => 'Metro', 'M2' => 'Metro quadrado', 'M3' => 'Metro cúbico', 'KG' => 'Quilograma',
        'H' => 'Hora', 'MES' => 'Mês', 'L' => 'Litro', 'CJ' => 'Conjunto', 'PAR' => 'Par', 'CENTO' => 'Cento',
        'JG' => 'Jogo', 'T' => 'Tonelada', 'SC25KG' => 'Saco de 25 kg', 'MIL' => 'Milheiro', '310ML' => '310 ml',
        '100M' => '100 metros', 'KM' => 'Quilômetro', 'DIA' => 'Dia', 'CHP' => 'Hora produtiva', 'CHI' => 'Hora improdutiva',
    ];

    private const NAME_MAX = 200;

    public function handle(AuditLogger $audit, TenantContext $context): int
    {
        $tenant = Tenant::query()->find($this->option('tenant'));
        $dataset = ReferenceDataset::query()->with('source')->find($this->option('dataset'));
        $kinds = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('kinds')))));
        if (! $tenant || ! $dataset || $kinds === [] || array_diff($kinds, ['material', 'labor', 'equipment', 'service'])) {
            $this->error('Informe --tenant, --dataset e --kinds válidos.');

            return self::FAILURE;
        }
        $user = User::query()->find($this->option('user'));
        $member = $user && DB::table('tenant_users')->where('tenant_id', $tenant->getKey())->where('user_id', $user->getKey())->exists();
        if (! $member) {
            $this->error('Informe --user de um membro do tenant.');

            return self::FAILURE;
        }

        $label = sprintf('%s %s %s (%s), %s', $dataset->source?->code ?? 'Base', $dataset->state, $dataset->version,
            $dataset->location_name, $dataset->charges_regime === 'with_relief' ? 'desonerado' : 'não desonerado');
        $priceDate = $dataset->version.'-01';
        $dry = (bool) $this->option('dry-run');

        try {
            $summary = $context->run($tenant, fn () => DB::transaction(function () use ($tenant, $user, $dataset, $kinds, $label, $priceDate, $audit, $dry) {
                $tenantId = $tenant->getKey();
                $source = DB::table('reference_resources as r')
                    ->join('reference_units as u', 'u.id', '=', 'r.unit_id')
                    ->leftJoin('reference_resource_prices as p', 'p.resource_id', '=', 'r.id')
                    ->where('r.dataset_id', $dataset->getKey())->whereIn('r.kind', $kinds)
                    ->orderBy('r.kind')->orderBy('r.code')
                    ->get(['r.code', 'r.description', 'r.kind', 'u.code as unit', 'p.price']);

                $existingCodes = DB::table('resources')->where('tenant_id', $tenantId)->whereNull('deleted_at')->whereNotNull('code')
                    ->pluck('code')->flip();
                $existingNames = DB::table('resources')->where('tenant_id', $tenantId)->whereNull('deleted_at')->get(['kind', 'name'])
                    ->mapWithKeys(fn ($r) => [$r->kind.'|'.$this->key($r->name) => true]);

                $units = [];
                foreach (UnitOfMeasure::query()->get(['id', 'code']) as $u) {
                    $units[Str::upper($u->code)] = $u->id;
                }
                $unitsCreated = [];
                $now = now();
                $rows = [];
                $skipped = 0;
                $abbreviated = 0;
                $byKind = [];

                foreach ($source as $r) {
                    $name = trim(preg_replace('/\s+/', ' ', $r->description));
                    $short = $this->abbreviate($name);
                    if (isset($existingCodes[$r->code]) || isset($existingNames[$r->kind.'|'.$this->key($short)])) {
                        $skipped++;

                        continue;
                    }
                    $unitCode = Str::upper(trim($r->unit));
                    if (! isset($units[$unitCode])) {
                        $units[$unitCode] = UnitOfMeasure::query()->create([
                            'code' => Str::limit($unitCode, 10, ''), 'name' => self::UNIT_NAMES[$unitCode] ?? $unitCode,
                        ])->id;
                        $unitsCreated[] = $unitCode;
                    }
                    $notes = "Origem: {$label}, código {$r->code}.";
                    if ($short !== $name) {
                        $abbreviated++;
                        $notes .= "\nDescrição completa: {$name}";
                    }
                    $rows[] = [
                        'tenant_id' => $tenantId, 'uuid' => (string) Str::uuid7(), 'kind' => $r->kind, 'code' => $r->code,
                        'name' => $short, 'unit_id' => $units[$unitCode], 'price' => $r->price, 'price_date' => $r->price !== null ? $priceDate : null,
                        'notes' => $notes, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
                    ];
                    $existingCodes[$r->code] = true;
                    $existingNames[$r->kind.'|'.$this->key($short)] = true;
                    $byKind[$r->kind] = ($byKind[$r->kind] ?? 0) + 1;
                }

                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table('resources')->insert($chunk);
                }

                $summary = ['total' => count($source), 'created' => count($rows), 'skip' => $skipped, 'abbreviated' => $abbreviated,
                    'by_kind' => $byKind, 'units_created' => $unitsCreated];
                $audit->record(AuditEvent::RESOURCE_IMPORTED, tenantId: $tenantId, userId: $user->getKey(), entityType: 'resource',
                    metadata: ['source' => 'reference', 'dataset_id' => $dataset->getKey(), 'dataset' => $label, 'kinds' => $kinds] + $summary);

                if ($dry) {
                    throw new DryRunRollback(json_encode($summary));
                }

                return $summary;
            }), $user);
        } catch (DryRunRollback $e) {
            $summary = json_decode($e->getMessage(), true);
            $this->warn('--dry-run: nada foi gravado.');
        }

        $this->info("Origem: {$label}");
        $this->table(['Lidos', 'Criados', 'Ignorados (já existem)', 'Nome abreviado'], [[$summary['total'], $summary['created'], $summary['skip'], $summary['abbreviated']]]);
        $this->line('Por tipo: '.json_encode($summary['by_kind']));
        $this->line('Unidades criadas: '.($summary['units_created'] ? implode(', ', $summary['units_created']) : 'nenhuma'));

        return self::SUCCESS;
    }

    /** Até NAME_MAX caracteres: início + " … " + fim (o fim distingue variantes da mesma descrição). */
    private function abbreviate(string $name): string
    {
        if (mb_strlen($name) <= self::NAME_MAX) {
            return $name;
        }
        $tail = 57;

        return rtrim(mb_substr($name, 0, self::NAME_MAX - $tail - 3)).' … '.ltrim(mb_substr($name, -$tail));
    }

    private function key(string $s): string
    {
        return Str::lower(trim(preg_replace('/\s+/', ' ', Str::ascii($s))));
    }
}
