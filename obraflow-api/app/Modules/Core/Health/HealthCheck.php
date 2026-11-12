<?php

namespace App\Modules\Core\Health;

interface HealthCheck
{
    public function name(): string;

    /** Retorna true se o componente está saudável. Nunca deve lançar exceção. */
    public function passes(): bool;
}
