<?php

namespace App\Modules\Audit;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

/** Bloqueia alterações em massa na trilha de auditoria feitas pela aplicação. */
class ImmutableBuilder extends Builder
{
    public function update(array $values)
    {
        throw new LogicException('audit_logs é append-only: atualização proibida.');
    }

    public function upsert(array $values, $uniqueBy, $update = null)
    {
        throw new LogicException('audit_logs é append-only: upsert proibido.');
    }

    public function delete()
    {
        throw new LogicException('audit_logs é append-only: exclusão proibida.');
    }

    public function forceDelete()
    {
        throw new LogicException('audit_logs é append-only: exclusão proibida.');
    }

    public function truncate()
    {
        throw new LogicException('audit_logs é append-only: truncate proibido.');
    }

    public function increment($column, $amount = 1, array $extra = [])
    {
        throw new LogicException('audit_logs é append-only: atualização proibida.');
    }

    public function decrement($column, $amount = 1, array $extra = [])
    {
        throw new LogicException('audit_logs é append-only: atualização proibida.');
    }
}
