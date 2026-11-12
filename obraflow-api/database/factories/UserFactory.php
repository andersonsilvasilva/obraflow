<?php

namespace Database\Factories;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => Str::lower(fake()->unique()->userName()).fake()->unique()->numerify('###').'@example.test',
            'email_verified_at' => now(),
            // Senha de teste conhecida; usuários fictícios — nunca usada fora de desenvolvimento/testes.
            'password' => static::$password ??= Hash::make('Senha-de-Teste-123'),
            'remember_token' => Str::random(10),
            'status' => UserStatus::Active,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }

    public function blocked(): static
    {
        return $this->state(fn () => ['status' => UserStatus::Blocked]);
    }
}
