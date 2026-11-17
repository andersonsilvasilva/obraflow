<?php

namespace App\Modules\Projects\Services;

use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Código automático da obra: OB-NNN/AAAA — sequência POR TENANT e POR ANO (recomeça em 001 a cada ano).
 * Ano = ano da data de início; sem data, o ano atual. Conta também obras excluídas (soft delete), para nunca
 * reaproveitar um código já usado. Chamar dentro de transação: trava as linhas do ano (sem corrida).
 */
final class ProjectCodeGenerator
{
    public const PATTERN = '/^OB-(\d{3,})\/(\d{4})$/';

    public function next(int $year): string
    {
        $codes = Project::withTrashed()->where('code', 'like', "OB-%/{$year}")->lockForUpdate()->pluck('code');

        $max = 0;
        foreach ($codes as $code) {
            if (preg_match(self::PATTERN, $code, $m) && (int) $m[2] === $year) {
                $max = max($max, (int) $m[1]);
            }
        }

        return sprintf('OB-%03d/%d', $max + 1, $year);
    }

    /** Ano da obra: o da data de início (AAAA-MM-DD) ou o atual. */
    public static function yearOf(?string $startDate): int
    {
        return $startDate && preg_match('/^(\d{4})-/', $startDate, $m) ? (int) $m[1] : (int) now()->format('Y');
    }

    /** Sugestão para a tela (sem travar). */
    public function suggest(int $year): string
    {
        return DB::transaction(fn () => $this->next($year));
    }
}
