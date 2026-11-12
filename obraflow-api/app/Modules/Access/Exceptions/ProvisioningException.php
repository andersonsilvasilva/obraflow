<?php

namespace App\Modules\Access\Exceptions;

use RuntimeException;

/** Erro de validação do provisionamento inicial (duplicidade, senha fraca etc.). A mensagem é segura para exibir. */
class ProvisioningException extends RuntimeException {}
