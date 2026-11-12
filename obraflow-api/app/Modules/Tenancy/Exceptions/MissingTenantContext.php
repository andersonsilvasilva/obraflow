<?php

namespace App\Modules\Tenancy\Exceptions;

use LogicException;

/**
 * Lançada quando código de negócio roda sem tenant ativo. É um erro de PROGRAMAÇÃO (falha fechada):
 * a alternativa seria retornar dados de todos os tenants.
 */
class MissingTenantContext extends LogicException
{
    public static function make(): self
    {
        return new self('Não há tenant ativo neste contexto. Acesso a dados de tenant recusado (falha fechada).');
    }
}
