<?php

namespace Database\Factories;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    public function definition(): array
    {
        $name = 'Construtora '.fake()->unique()->lexify('????').' Demo';

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('####'),
            'status' => TenantStatus::Active,
            'timezone' => 'America/Sao_Paulo',
            'locale' => 'pt_BR',
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => TenantStatus::Suspended]);
    }

    public function canceled(): static
    {
        return $this->state(fn () => ['status' => TenantStatus::Canceled]);
    }
}
