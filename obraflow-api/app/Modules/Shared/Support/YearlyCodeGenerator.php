<?php

namespace App\Modules\Shared\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * Códigos automáticos PREFIXO-NNN/AAAA (ex.: OB-001/2026, ORC-001/2026): sequência POR TENANT (escopo do modelo)
 * e POR ANO, recomeçando em 001 a cada ano. Conta registros excluídos (soft delete) para nunca reaproveitar um
 * código. Chamar dentro de transação: trava as linhas do ano (sem corrida).
 */
final class YearlyCodeGenerator
{
    /** @param class-string<Model> $model */
    public function next(string $model, string $prefix, int $year): string
    {
        // withTrashed é macro do escopo de soft delete (não é método real): detecta pelo trait
        $query = in_array(SoftDeletes::class, class_uses_recursive($model), true) ? $model::withTrashed() : $model::query();
        $codes = $query->where('code', 'like', "{$prefix}-%/{$year}")->lockForUpdate()->pluck('code');

        $pattern = '/^'.preg_quote($prefix, '/').'-(\d{3,})\/'.$year.'$/';
        $max = 0;
        foreach ($codes as $code) {
            if (preg_match($pattern, $code, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return sprintf('%s-%03d/%d', $prefix, $max + 1, $year);
    }

    /** Sugestão para a tela (sem manter a trava). @param class-string<Model> $model */
    public function suggest(string $model, string $prefix, int $year): string
    {
        return DB::transaction(fn () => $this->next($model, $prefix, $year));
    }

    /** Ano de uma data AAAA-MM-DD, ou o ano atual. */
    public static function yearOf(?string $date): int
    {
        return $date && preg_match('/^(\d{4})-/', $date, $m) ? (int) $m[1] : (int) now()->format('Y');
    }
}
