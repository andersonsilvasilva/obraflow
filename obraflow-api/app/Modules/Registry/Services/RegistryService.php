<?php

namespace App\Modules\Registry\Services;

use App\Models\User;
use App\Modules\Audit\AuditEvent;
use App\Modules\Audit\AuditLogger;
use App\Modules\Shared\Exceptions\ApiException;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Escrita dos cadastros da Fase 2 (unidades, empresas, filiais, clientes, fornecedores, endereços, contatos).
 * Cada mudança e seu evento de auditoria são gravados na MESMA transação (falha de auditoria desfaz a mudança).
 * Violação de unicidade que escape da validação (corrida) vira 409, nunca 500.
 */
final class RegistryService
{
    /** Campos nunca copiados para a auditoria (gerados/técnicos). */
    private const NOT_AUDITED = ['id', 'tenant_id', 'uuid', 'created_at', 'updated_at', 'deleted_at', 'active_marker', 'main_marker', 'primary_marker'];

    public function __construct(private readonly AuditLogger $audit, private readonly TenantContext $context) {}

    /** @param  callable(Model): void|null  $before  executado na transação antes de salvar (ex.: rebaixar contato principal) */
    public function create(Model $model, array $data, User $actor, ?callable $before = null): Model
    {
        return $this->transaction(function () use ($model, $data, $actor, $before) {
            $model->fill($data);
            if ($before) {
                $before($model);
            }
            $model->save();

            $this->record(AuditEvent::REGISTRY_CREATED, $model, $actor, null, $this->auditable($model->getAttributes()));

            return $model;
        });
    }

    public function update(Model $model, array $data, User $actor, ?callable $before = null): Model
    {
        return $this->transaction(function () use ($model, $data, $actor, $before) {
            $model->fill($data);
            if ($before) {
                $before($model);
            }
            if (! $model->isDirty()) {
                return $model;
            }

            $changed = $this->auditable($model->getDirty());
            $old = array_intersect_key($this->auditable($model->getOriginal()), $changed);
            $model->save();

            $this->record(AuditEvent::REGISTRY_UPDATED, $model, $actor, $old, $changed);

            return $model;
        });
    }

    /** Soft delete nos cadastros mestres; exclusão física em endereços/contatos. */
    public function delete(Model $model, User $actor): void
    {
        $this->transaction(function () use ($model, $actor) {
            $snapshot = $this->auditable($model->getAttributes());
            $model->delete();
            $this->record(AuditEvent::REGISTRY_DELETED, $model, $actor, $snapshot, null);
        });
    }

    private function record(string $event, Model $model, User $actor, ?array $old, ?array $new): void
    {
        $this->audit->record($event, tenantId: $this->context->idOrFail(), userId: $actor->getKey(),
            entityType: $model->getMorphClass(), entityId: $model->getKey(), old: $old, new: $new);
    }

    private function auditable(array $attributes): array
    {
        return array_diff_key($attributes, array_flip(self::NOT_AUDITED));
    }

    private function transaction(callable $fn): mixed
    {
        try {
            return DB::transaction($fn);
        } catch (QueryException $e) {
            $code = (int) ($e->errorInfo[1] ?? 0);
            if ($code === 1062) {
                throw ApiException::conflict('duplicate', 'Já existe um cadastro ativo com estes dados.');
            }
            if ($code === 1451) {
                throw ApiException::conflict('in_use', 'O registro está em uso e não pode ser excluído.');
            }
            throw $e;
        }
    }
}
