<?php

namespace App\Modules\Budgets\Report;

use App\Modules\Shared\Exceptions\DomainRuleException;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Chrome headless pela linha de comando (A-55: sem biblioteca, sem Node, sem a extensão sockets). O HTML é
 * autossuficiente (fontes e imagens embutidas): o Chrome abre um arquivo local, com perfil temporário próprio,
 * sem extensões e sem rede. Cabeçalho, rodapé e "página X de Y" vêm do CSS de impressão (@page, Chrome 131+).
 */
final class ChromeRenderer
{
    /** Gera o PDF do HTML. */
    public function pdf(string $html, string $outPath): void
    {
        $this->run($html, ['--no-pdf-header-footer', '--print-to-pdf='.$outPath], $outPath);
    }

    /** Captura o HTML como PNG (gráfico da curva ABC para o DOCX e o XLSX), em resolução dobrada. */
    public function png(string $html, string $outPath, int $width, int $height): void
    {
        $this->run($html, ['--screenshot='.$outPath, "--window-size={$width},{$height}", '--force-device-scale-factor=2', '--hide-scrollbars'], $outPath);
    }

    public function available(): bool
    {
        return $this->binary() !== null;
    }

    /** @param list<string> $args */
    private function run(string $html, array $args, string $outPath): void
    {
        $binary = $this->binary() ?? throw new DomainRuleException('PDF indisponível: o Chrome não foi encontrado no servidor (configure CHROME_PATH). O XLSX e o DOCX continuam disponíveis.');
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'obraflow-chrome-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($dir);
        $input = $dir.DIRECTORY_SEPARATOR.'report.html';
        file_put_contents($input, $html);

        $cmd = array_merge([
            $binary,
            '--headless=new',
            '--disable-gpu',
            '--disable-extensions',
            '--disable-background-networking',
            '--disable-component-update',
            '--disable-default-apps',
            '--disable-sync',
            '--no-first-run',
            '--no-default-browser-check',
            '--mute-audio',
            '--run-all-compositor-stages-before-draw',
            '--virtual-time-budget=5000',
            '--user-data-dir='.$dir.DIRECTORY_SEPARATOR.'profile',
            '--proxy-server=127.0.0.1:9', // sem rede: o HTML não busca nada fora (tudo embutido)
        ], config('obraflow.reports.chrome_no_sandbox') ? ['--no-sandbox'] : [], $args, ['file:///'.str_replace('\\', '/', $input)]);

        try {
            $process = new Process($cmd, $dir, null, null, (float) config('obraflow.reports.timeout', 60));
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new DomainRuleException('O relatório demorou demais para ser gerado. Tente sem o analítico.');
        } finally {
            File::deleteDirectory($dir);
        }

        if (! is_file($outPath) || filesize($outPath) === 0) {
            report(new \RuntimeException('Chrome não gerou o arquivo: '.mb_substr($process->getErrorOutput(), 0, 500)));
            throw new DomainRuleException('Não foi possível gerar o arquivo pelo Chrome. Tente novamente.');
        }
    }

    private function binary(): ?string
    {
        $configured = config('obraflow.reports.chrome_path');
        if (is_string($configured) && $configured !== '') {
            return is_file($configured) ? $configured : null;
        }
        $candidates = PHP_OS_FAMILY === 'Windows'
            ? [getenv('ProgramFiles').'\Google\Chrome\Application\chrome.exe', getenv('ProgramFiles(x86)').'\Google\Chrome\Application\chrome.exe',
                getenv('LOCALAPPDATA').'\Google\Chrome\Application\chrome.exe', getenv('ProgramFiles(x86)').'\Microsoft\Edge\Application\msedge.exe']
            : ['/usr/bin/chromium', '/usr/bin/chromium-browser', '/usr/bin/google-chrome', '/usr/bin/google-chrome-stable',
                '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'];
        foreach ($candidates as $c) {
            if ($c && is_file($c)) {
                return $c;
            }
        }

        return null;
    }
}
