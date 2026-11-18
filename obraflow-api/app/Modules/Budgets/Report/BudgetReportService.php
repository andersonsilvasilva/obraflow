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
    public const AVAILABLE = ['xlsx'];

    public function __construct(private readonly BudgetReportBuilder $builder, private readonly AuditLogger $audit) {}

    /** @return array{path: string, filename: string, mime: string} */
    public function generate(BudgetVersion $version, ReportOptions $options, User $user): array
    {
        $data = $this->builder->build($version, $options, $user);
        $path = tempnam(sys_get_temp_dir(), 'orc').'.'.$options->format;

        match ($options->format) {
            'xlsx' => app(XlsxReportRenderer::class)->render($data, $path),
        };

        $budget = $version->budget;
        $this->audit->record(AuditEvent::BUDGET_REPORT_EXPORTED, tenantId: $budget->tenant_id, userId: $user->getKey(), entityType: 'budget', entityId: $budget->id,
            metadata: ['version' => $version->version_number, 'format' => $options->format, 'parts' => $options->parts, 'hide_bdi' => $options->hideBdi]);

        return ['path' => $path, 'filename' => $data['meta']['filename'], 'mime' => match ($options->format) {
            'pdf' => 'application/pdf',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        }];
    }
}
