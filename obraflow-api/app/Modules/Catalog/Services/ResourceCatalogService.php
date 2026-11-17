<?php

namespace App\Modules\Catalog\Services;

use App\Models\Resource;
use App\Models\ResourceLaborCost;
use App\Models\User;
use App\Modules\Budgets\Support\Dec;
use App\Modules\Registry\Services\RegistryService;
use App\Modules\Shared\Exceptions\DomainRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Escrita do catálogo de insumos (Fase 4C). CRUD auditado (registry.*). Balanço da mão de obra (D-P39):
 * preço/hora = arred₂(salário base × (1 + encargos%) × (1 + administração%) ÷ carga horária mensal); o balanço
 * VIGENTE (maior data ≤ hoje) define o preço do insumo.
 */
final class ResourceCatalogService
{
    public function __construct(private readonly RegistryService $registry) {}

    public function create(array $data, User $actor): Resource
    {
        return $this->registry->create(new Resource, $data, $actor);
    }

    /** O tipo não muda depois de criado (grupo, cargo e vínculos dependem dele). */
    public function update(Resource $resource, array $data, User $actor): Resource
    {
        unset($data['kind']);

        return $this->registry->update($resource, $data, $actor);
    }

    public function delete(Resource $resource, User $actor): void
    {
        $this->registry->delete($resource, $actor);
    }

    public static function hourlyCost(string $salary, string $charges, string $admin, string $hours): string
    {
        if (Dec::cmp($hours, '0') <= 0) {
            throw new DomainRuleException('A carga horária mensal precisa ser maior que zero.');
        }

        return Dec::round(Dec::div(Dec::mul(Dec::mul($salary, Dec::factor($charges)), Dec::factor($admin)), $hours));
    }

    public function addLaborCost(Resource $resource, array $data, User $actor): ResourceLaborCost
    {
        if ($resource->kind !== 'labor') {
            throw new DomainRuleException('Balanço só se aplica a insumos de mão de obra.');
        }

        return DB::transaction(function () use ($resource, $data, $actor) {
            $cost = new ResourceLaborCost(['resource_id' => $resource->id]);
            $cost->hourly_cost = self::hourlyCost((string) $data['base_salary'], (string) ($data['charges_percent'] ?? '0'),
                (string) ($data['admin_percent'] ?? '0'), (string) $data['monthly_hours']);
            $cost->created_by = $actor->getKey();
            $this->registry->create($cost, $data, $actor);
            $this->syncPriceFromLabor($resource, $actor);

            return $cost;
        });
    }

    public function deleteLaborCost(ResourceLaborCost $cost, User $actor): void
    {
        DB::transaction(function () use ($cost, $actor) {
            $resource = $cost->resource;
            $this->registry->delete($cost, $actor);
            $this->syncPriceFromLabor($resource, $actor);
        });
    }

    /** Preço do insumo = custo/hora do balanço vigente (se houver; sem balanço vigente, o preço informado fica). */
    private function syncPriceFromLabor(Resource $resource, User $actor): void
    {
        $current = ResourceLaborCost::query()->where('resource_id', $resource->id)->whereDate('effective_date', '<=', now()->toDateString())
            ->orderByDesc('effective_date')->first();
        if ($current) {
            $this->registry->update($resource, ['price' => (string) $current->hourly_cost, 'price_date' => $current->effective_date->toDateString()], $actor);
        }
    }
}
