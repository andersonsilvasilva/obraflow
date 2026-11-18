<?php

namespace App\Modules\Budgets\Report;

use App\Models\BudgetVersion;
use App\Models\User;
use App\Modules\Audit\AuditEvent;
use App\Modules\Audit\AuditLogger;

/**
 * Gera o arquivo do relatório (FASE4D_MODELO §3): monta o ReportData, entrega ao renderizador do formato e audita a
 * exportação (D-P81). O arquivo é temporário: quem chama envia e apaga (nada fica guardado no servidor).
 */
final class BudgetReportService
{
    /** Formatos já disponíveis (os demais entram nas próximas etapas da 4D). */
    public const AVAILABLE = ReportOptions::FORMATS;

    public function __construct(private readonly BudgetReportBuilder $builder, private readonly AuditLogger $audit) {}

    /**
     * Gráficos da curva ABC em PNG (capturados do mesmo SVG do PDF) para o XLSX e o DOCX. Sem Chrome no servidor, o
     * arquivo sai sem o gráfico (as tabelas continuam) — D-P78.
     *
     * @param  list<string>  $tmp  arquivos temporários criados (apagados por quem chama)
     * @return array<string, string>
     */
    private function charts(array $data, array &$tmp): array
    {
        $chrome = app(ChromeRenderer::class);
        if (! isset($data['abc']) || ! $chrome->available()) {
            return [];
        }
        $out = [];
        foreach (['resources' => 'Curva ABC de insumos', 'services' => 'Curva ABC de serviços'] as $key => $title) {
            $png = self::tempFile('png');
            $tmp[] = $png;
            try {
                $chrome->png(AbcChart::page(AbcChart::svg($data['abc'][$key], $title)), $png, AbcChart::WIDTH, AbcChart::HEIGHT);
                $out[$key] = $png;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $out;
    }

    /**
     * Marca d'água "RASCUNHO" em PNG transparente para o DOCX (versão não aprovada). Sem Chrome, sai sem ela.
     *
     * @param  list<string>  $tmp
     * @return array<string, string>
     */
    private function watermark(array $data, array &$tmp): array
    {
        $chrome = app(ChromeRenderer::class);
        if (! $data['meta']['draft'] || ! $chrome->available()) {
            return [];
        }
        $png = self::tempFile('png');
        $tmp[] = $png;
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="920" height="460"><text x="460" y="260" text-anchor="middle" font-family="Geist, Arial" font-weight="800" font-size="150" letter-spacing="18" fill="rgba(180,83,9,0.10)" transform="rotate(-28 460 230)">RASCUNHO</text></svg>';
        try {
            $chrome->png('<!doctype html><html><head><meta charset="utf-8"><style>'.ReportAssets::fontFace().'html,body{margin:0;background:transparent}</style></head><body>'.$svg.'</body></html>', $png, 920, 460, transparent: true);

            return ['watermark' => $png];
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /** @return array{path: string, filename: string, mime: string} */
    public function generate(BudgetVersion $version, ReportOptions $options, User $user): array
    {
        $data = $this->builder->build($version, $options, $user);
        $path = self::tempFile($options->format);
        $tmp = [];

        try {
            match ($options->format) {
                'pdf' => app(PdfReportRenderer::class)->render($data, $path),
                'xlsx' => app(XlsxReportRenderer::class)->render($data, $path, $this->charts($data, $tmp)),
                'docx' => app(DocxReportRenderer::class)->render($data, $path, $this->charts($data, $tmp) + $this->watermark($data, $tmp)),
            };
        } finally {
            foreach ($tmp as $file) {
                @unlink($file);
            }
        }

        $budget = $version->budget;
        $this->audit->record(AuditEvent::BUDGET_REPORT_EXPORTED, tenantId: $budget->tenant_id, userId: $user->getKey(), entityType: 'budget', entityId: $budget->id,
            metadata: ['version' => $version->version_number, 'format' => $options->format, 'parts' => $options->parts, 'hide_bdi' => $options->hideBdi]);

        return ['path' => $path, 'filename' => $data['meta']['filename'], 'mime' => match ($options->format) {
            'pdf' => 'application/pdf',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        }];
    }

    /** Caminho temporário único com a extensão (sem deixar o arquivo-base do tempnam para trás). */
    private static function tempFile(string $ext): string
    {
        return sys_get_temp_dir().DIRECTORY_SEPARATOR.'obraflow-'.bin2hex(random_bytes(8)).'.'.$ext;
    }
}
