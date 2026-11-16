<?php

namespace App\Modules\Shared\Exceptions;

use LogicException;

/**
 * Regra de negócio verificada pelo modelo (ex.: "cliente da obra deve ser da mesma empresa", níveis de árvore).
 * Na API vira 422 `domain_rule` com a mensagem; fora dela continua sendo uma LogicException.
 * Violações de append-only NÃO usam esta classe (são erro de programação → 500).
 */
final class DomainRuleException extends LogicException {}
