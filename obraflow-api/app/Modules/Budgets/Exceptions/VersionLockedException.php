<?php

namespace App\Modules\Budgets\Exceptions;

use App\Modules\Shared\Exceptions\DomainRuleException;

/** Escrita numa versão aprovada/bloqueada (D-08, D-P31). Na API: 422 domain_rule. */
final class VersionLockedException extends DomainRuleException
{
    public function __construct()
    {
        parent::__construct('Versão aprovada é somente leitura. Crie uma nova versão para alterar o orçamento.');
    }
}
