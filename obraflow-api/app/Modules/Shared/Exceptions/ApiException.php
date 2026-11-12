<?php

namespace App\Modules\Shared\Exceptions;

use RuntimeException;

/**
 * Erro de negócio/acesso com código estável para o cliente: { "error": { "code", "message", ... } }.
 * Renderizado por ApiExceptionRenderer.
 */
class ApiException extends RuntimeException
{
    /** @param  array<string, mixed>  $details */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function forbidden(string $code, string $message): self
    {
        return new self(403, $code, $message);
    }

    public static function conflict(string $code, string $message): self
    {
        return new self(409, $code, $message);
    }

    public static function unprocessable(string $code, string $message): self
    {
        return new self(422, $code, $message);
    }

    public static function notFound(string $message = 'Recurso não encontrado.'): self
    {
        return new self(404, 'not_found', $message);
    }
}
