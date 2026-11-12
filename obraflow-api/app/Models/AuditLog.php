<?php

namespace App\Models;

use App\Modules\Audit\ImmutableBuilder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Trilha APPEND-ONLY. A aplicação só consegue INSERIR e LER:
 *  - update/delete por instância → exceção;
 *  - update/delete/truncate por query builder → exceção (ImmutableBuilder).
 * Em produção o usuário do banco também só tem INSERT/SELECT nesta tabela (D-22: sem triggers).
 */
class AuditLog extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('audit_logs é append-only: atualização proibida.'));
        static::deleting(fn () => throw new LogicException('audit_logs é append-only: exclusão proibida.'));
    }

    public function newEloquentBuilder($query): ImmutableBuilder
    {
        return new ImmutableBuilder($query);
    }

    /** IP legível (a coluna guarda o endereço binário). */
    public function ipText(): ?string
    {
        $raw = $this->attributes['ip_address'] ?? null;

        return $raw === null ? null : (inet_ntop($raw) ?: null);
    }
}
