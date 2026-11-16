<?php

namespace App\Modules\Teams\Http\Requests;

use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Base dos requests das Fases 3+: referências chegam por uuid público (ou id, para catálogos) e são conferidas
 * NO TENANT ATIVO (e sem soft delete). payload() troca `x_uuid` pelo `x_id` interno para o modelo.
 */
abstract class TenantRequest extends FormRequest
{
    /** @var array<string, array{0: class-string<Model>, 1: string}> campo da requisição => [modelo, coluna destino] */
    protected array $uuidRefs = [];

    protected function tenantId(): int
    {
        return app(TenantContext::class)->idOrFail();
    }

    protected function existsInTenant(string $table, string $column = 'uuid', bool $softDeletes = true): Exists
    {
        $rule = Rule::exists($table, $column)->where('tenant_id', $this->tenantId());

        return $softDeletes ? $rule->whereNull('deleted_at') : $rule;
    }

    /** Dados validados com as referências por uuid convertidas para ids internos do tenant ativo. */
    public function payload(): array
    {
        $data = $this->validated();
        foreach ($this->uuidRefs as $field => [$model, $target]) {
            if (array_key_exists($field, $data)) {
                $data[$target] = $data[$field] === null ? null : $model::query()->where('uuid', $data[$field])->value('id');
                unset($data[$field]);
            }
        }

        return $data;
    }

    /**
     * Regra "data ≥ início" que funciona também em edição parcial: compara com o campo enviado ou, se ele não
     * veio, com o valor já gravado. Sem nenhum dos dois, não restringe.
     */
    protected function notBefore(string $startField, mixed $currentStart): ?string
    {
        if ($this->has($startField)) {
            return "after_or_equal:{$startField}";
        }
        if ($currentStart === null) {
            return null;
        }

        return 'after_or_equal:'.(is_string($currentStart) ? $currentStart : $currentStart->toDateString());
    }

    protected function digitsOnly(string ...$fields): void
    {
        foreach ($fields as $field) {
            if ($this->filled($field)) {
                $this->merge([$field => preg_replace('/\D+/', '', (string) $this->input($field))]);
            }
        }
    }
}
