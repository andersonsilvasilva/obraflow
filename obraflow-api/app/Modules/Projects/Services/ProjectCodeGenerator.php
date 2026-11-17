<?php

namespace App\Modules\Projects\Services;

use App\Models\Project;
use App\Modules\Shared\Support\YearlyCodeGenerator;

/** Código automático da obra: OB-NNN/AAAA, com o ano da data de início (sem data, o ano atual). */
final class ProjectCodeGenerator
{
    public function __construct(private readonly YearlyCodeGenerator $codes) {}

    public function next(int $year): string
    {
        return $this->codes->next(Project::class, 'OB', $year);
    }

    public function suggest(int $year): string
    {
        return $this->codes->suggest(Project::class, 'OB', $year);
    }

    public static function yearOf(?string $startDate): int
    {
        return YearlyCodeGenerator::yearOf($startDate);
    }
}
