<?php

namespace App\Modules\Audit;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

/** Bloqueia alterações em massa em tabelas append-only (audit_logs, document_versions) feitas pela aplicação. */
class ImmutableBuilder extends Builder
{
    public function update(array $values)
    {
        throw new LogicException($this->model->getTable().' é append-only: atualização proibida.');
    }

    public function upsert(array $values, $uniqueBy, $update = null)
    {
        throw new LogicException($this->model->getTable().' é append-only: upsert proibido.');
    }

    public function delete()
    {
        throw new LogicException($this->model->getTable().' é append-only: exclusão proibida.');
    }

    public function forceDelete()
    {
        throw new LogicException($this->model->getTable().' é append-only: exclusão proibida.');
    }

    public function truncate()
    {
        throw new LogicException($this->model->getTable().' é append-only: truncate proibido.');
    }

    public function increment($column, $amount = 1, array $extra = [])
    {
        throw new LogicException($this->model->getTable().' é append-only: atualização proibida.');
    }

    public function decrement($column, $amount = 1, array $extra = [])
    {
        throw new LogicException($this->model->getTable().' é append-only: atualização proibida.');
    }
}
