<?php

namespace App\Modules\Budgets\Report;

/**
 * Fonte (Geist, SIL OFL 1.1 — resources/report/fonts) e marca do ObraFlow embutidas no HTML do relatório: o Chrome
 * gera o PDF sem buscar nada na rede.
 */
final class ReportAssets
{
    public static function fontFace(): string
    {
        $css = '';
        foreach (['geist-latin-wght-normal.woff2' => 'U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD',
            'geist-latin-ext-wght-normal.woff2' => 'U+0100-02BA,U+02BD-02C5,U+02C7-02CC,U+02CE-02D7,U+02DD-02FF,U+0304,U+0308,U+0329,U+1D00-1DBF,U+1E00-1E9F,U+1EF2-1EFF,U+2020,U+20A0-20AB,U+20AD-20C0,U+2113,U+2C60-2C7F,U+A720-A7FF'] as $file => $range) {
            $data = base64_encode((string) file_get_contents(resource_path("report/fonts/{$file}")));
            $css .= "@font-face{font-family:'Geist';font-style:normal;font-weight:100 900;src:url(data:font/woff2;base64,{$data}) format('woff2');unicode-range:{$range};}";
        }

        return $css;
    }

    public static function logo(string $name = 'logo-completa'): string
    {
        return 'data:image/png;base64,'.base64_encode((string) file_get_contents(resource_path("report/brand/{$name}.png")));
    }
}
