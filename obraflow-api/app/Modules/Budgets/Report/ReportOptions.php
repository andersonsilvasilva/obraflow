<?php

namespace App\Modules\Budgets\Report;

/**
 * O que entra no relatório (FASE4D_MODELO §2): partes opcionais, ocultar o BDI (D-P83) e os textos do fechamento,
 * digitados na hora e não gravados (D-P85).
 */
final class ReportOptions
{
    public const FORMATS = ['pdf', 'xlsx', 'docx'];

    /** Partes opcionais (capa, resumo e sintético sempre entram). */
    public const OPTIONAL_PARTS = ['analytic', 'bdi', 'abc', 'closing'];

    /** D-P86: acima disso, só sem o analítico. */
    public const MAX_ITEMS_WITH_ANALYTIC = 3000;

    /**
     * @param  list<string>  $parts
     * @param  array{name?: ?string, registry?: ?string, art?: ?string}  $technician
     */
    public function __construct(
        public readonly string $format = 'pdf',
        public readonly array $parts = self::OPTIONAL_PARTS,
        public readonly bool $hideBdi = false,
        public readonly ?string $notes = null,
        public readonly ?string $place = null,
        public readonly array $technician = [],
    ) {}

    /** @param array<string, mixed> $data dados validados do pedido */
    public static function from(array $data): self
    {
        $hideBdi = (bool) ($data['hide_bdi'] ?? false);
        $parts = array_values(array_intersect(self::OPTIONAL_PARTS, (array) ($data['parts'] ?? self::OPTIONAL_PARTS)));
        if ($hideBdi) { // o detalhamento do BDI contradiz "ocultar o BDI"
            $parts = array_values(array_diff($parts, ['bdi']));
        }

        return new self(
            format: (string) ($data['format'] ?? 'pdf'),
            parts: $parts,
            hideBdi: $hideBdi,
            notes: self::text($data['notes'] ?? null),
            place: self::text($data['place'] ?? null),
            technician: [
                'name' => self::text($data['technician_name'] ?? null),
                'registry' => self::text($data['technician_registry'] ?? null),
                'art' => self::text($data['technician_art'] ?? null),
            ],
        );
    }

    public function has(string $part): bool
    {
        return in_array($part, $this->parts, true);
    }

    private static function text(mixed $v): ?string
    {
        $v = is_string($v) ? trim($v) : '';

        return $v === '' ? null : $v;
    }
}
