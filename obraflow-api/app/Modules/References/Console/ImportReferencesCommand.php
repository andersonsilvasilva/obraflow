<?php

namespace App\Modules\References\Console;

use App\Models\ReferenceDataset;
use App\Models\ReferenceImport;
use App\Models\ReferenceSource;
use App\Modules\Audit\AuditEvent;
use App\Modules\Audit\AuditLogger;
use App\Modules\References\Import\SinapiImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Importa uma base de referência oficial (D-P30). Única porta de escrita das tabelas reference_* (D-P29).
 *
 * - Base = fonte + competência + UF + regime. Arquivos: insumos (preços), composições (analítico), famílias (tipos).
 * - Idempotente: o mesmo arquivo (SHA-256) não é importado de novo; arquivo DIFERENTE para um tipo já importado
 *   é recusado (base é imutável — publique nova competência como nova base).
 * - Tudo numa transação: falha em qualquer ponto não deixa nada gravado.
 * - `--activate` torna a base ativa e marca como `superseded` a ativa anterior da mesma fonte/UF/regime.
 * - `--dry-run` executa tudo e desfaz no fim (mostra contagens).
 */
class ImportReferencesCommand extends Command
{
    protected $signature = 'obraflow:references:import
        {--source=SINAPI : Código da fonte}
        {--source-name=SINAPI - Sistema Nacional de Pesquisa de Custos e Índices da Construção Civil : Nome da fonte (na criação)}
        {--publisher=CAIXA / IBGE : Publicador (na criação)}
        {--competency= : Competência AAAA-MM (ex.: 2024-12)}
        {--state= : UF (ex.: MT)}
        {--location= : Localidade publicada (ex.: CUIABA)}
        {--regime= : with_relief (desonerado) ou without_relief (não desonerado)}
        {--resources= : Arquivo XLSX de preços de insumos}
        {--compositions= : Arquivo XLSX de custos de composições (analítico)}
        {--families= : Arquivo XLSX do relatório de famílias de insumos}
        {--activate : Ativa a base ao final}
        {--dry-run : Executa e desfaz (nada é gravado)}';

    protected $description = 'Importa uma base de referência técnica oficial (ex.: SINAPI) com rastreio de arquivo e competência.';

    public function handle(AuditLogger $audit): int
    {
        $version = (string) $this->option('competency');
        $state = strtoupper((string) $this->option('state'));
        $regime = (string) $this->option('regime');

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $version) || ! preg_match('/^[A-Z]{2}$/', $state)
            || ! in_array($regime, ['with_relief', 'without_relief'], true)) {
            $this->error('Informe --competency=AAAA-MM, --state=UF e --regime=with_relief|without_relief.');

            return self::FAILURE;
        }
        $files = array_filter(['resources' => $this->option('resources'), 'compositions' => $this->option('compositions'), 'families' => $this->option('families')]);
        if (isset($files['compositions']) && ! isset($files['resources'])) {
            // composições citam insumos: importe os preços antes (ou junto)
            $this->warn('Composições sem o arquivo de insumos: os insumos citados entram sem preço, a menos que a base já tenha os preços.');
        }

        try {
            $summary = DB::transaction(function () use ($files, $version, $state, $regime, $audit) {
                $source = ReferenceSource::query()->firstOrCreate(
                    ['code' => strtoupper((string) $this->option('source'))],
                    ['name' => (string) $this->option('source-name'), 'publisher' => (string) $this->option('publisher')],
                );
                $dataset = ReferenceDataset::query()->firstOrCreate(
                    ['source_id' => $source->id, 'version' => $version, 'state' => $state, 'charges_regime' => $regime],
                    ['location_name' => $this->option('location'), 'status' => 'importing'],
                );

                $importer = new SinapiImporter($dataset);
                $summary = [];
                foreach (['resources', 'compositions', 'families'] as $kind) {
                    if (! isset($files[$kind])) {
                        continue;
                    }
                    $path = (string) $files[$kind];
                    if (! is_file($path)) {
                        throw new RuntimeException("Arquivo não encontrado: {$path}");
                    }
                    $sha = SinapiImporter::sha256($path);
                    $existing = ReferenceImport::query()->where('dataset_id', $dataset->id)->where('kind', $kind)->first();
                    if ($existing && $existing->file_sha256 === $sha) {
                        $summary[] = [$kind, basename($path), 'já importado', $existing->rows_imported];

                        continue;
                    }
                    if ($existing) {
                        throw new RuntimeException("A base já tem {$kind} de outro arquivo ({$existing->file_name}). Bases são imutáveis.");
                    }
                    if ($kind === 'families' && ! $dataset->resources()->exists()) {
                        throw new RuntimeException('Importe os insumos antes do relatório de famílias.');
                    }

                    $counts = $importer->{$kind}($path);
                    $import = $importer->record($kind, $path, $counts);
                    $audit->record(AuditEvent::REFERENCE_IMPORTED, entityType: 'reference_dataset', entityId: $dataset->id, metadata: [
                        'source' => $source->code, 'version' => $version, 'state' => $state, 'regime' => $regime,
                        'kind' => $kind, 'file' => $import->file_name, 'sha256' => $sha, 'rows' => $counts['imported'],
                    ]);
                    $summary[] = [$kind, basename($path), 'importado', $counts['imported']];
                }

                if ($this->option('activate')) {
                    $kinds = $dataset->imports()->pluck('kind')->all();
                    if (! in_array('resources', $kinds, true) || ! in_array('compositions', $kinds, true)) {
                        throw new RuntimeException('Só é possível ativar uma base com insumos e composições importados.');
                    }
                    if ($dataset->status !== 'active') {
                        ReferenceDataset::query()->where('source_id', $source->id)->where('state', $state)->where('charges_regime', $regime)
                            ->where('status', 'active')->whereKeyNot($dataset->id)->update(['status' => 'superseded']);
                        $dataset->forceFill(['status' => 'active', 'activated_at' => now()])->save();
                        $audit->record(AuditEvent::REFERENCE_ACTIVATED, entityType: 'reference_dataset', entityId: $dataset->id,
                            metadata: ['source' => $source->code, 'version' => $version, 'state' => $state, 'regime' => $regime]);
                    }
                    $summary[] = ['base', "{$source->code} {$version} {$state} {$regime}", 'ativa', ''];
                }

                if ($this->option('dry-run')) {
                    $this->table(['Tipo', 'Arquivo', 'Situação', 'Registros'], $summary);
                    throw new DryRunRollback;
                }

                return $summary;
            });
        } catch (DryRunRollback) {
            $this->info('Simulação: nada foi gravado.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Importação abortada, nada foi gravado: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Tipo', 'Arquivo', 'Situação', 'Registros'], $summary);

        return self::SUCCESS;
    }
}
