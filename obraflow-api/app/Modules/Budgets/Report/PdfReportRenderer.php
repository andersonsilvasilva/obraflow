<?php

namespace App\Modules\Budgets\Report;

/**
 * Relatório em PDF (FASE4D_MODELO §2): HTML premium (Blade `reports.budget`, fonte e marca embutidas, gráfico da
 * curva ABC em SVG) impresso pelo Chrome headless.
 */
final class PdfReportRenderer
{
    public function __construct(private readonly ChromeRenderer $chrome) {}

    public function html(array $data): string
    {
        $charts = isset($data['abc']) ? [
            'resources' => AbcChart::svg($data['abc']['resources'], 'Curva ABC de insumos'),
            'services' => AbcChart::svg($data['abc']['services'], 'Curva ABC de serviços'),
        ] : [];

        return view('reports.budget', [
            'data' => $data,
            'charts' => $charts,
            'fontFace' => ReportAssets::fontFace(),
            'logo' => ReportAssets::logo('logo-completa'),
            'logoMark' => ReportAssets::logo('logo-simbolo'),
        ])->render();
    }

    public function render(array $data, string $path): void
    {
        $this->chrome->pdf($this->html($data), $path);
    }
}
