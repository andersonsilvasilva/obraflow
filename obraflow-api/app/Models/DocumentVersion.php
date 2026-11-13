<?php

namespace App\Models;

use App\Modules\Audit\ImmutableBuilder;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Versão de documento — append-only: update/delete pelo modelo ou pelo builder lançam exceção.
 * Só o DocumentStorage cria versões (nada é mass-assignable).
 */
class DocumentVersion extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['version_number' => 'integer', 'size_bytes' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('document_versions é append-only: atualização proibida.'));
        static::deleting(fn () => throw new LogicException('document_versions é append-only: exclusão proibida.'));
    }

    public function newEloquentBuilder($query): ImmutableBuilder
    {
        return new ImmutableBuilder($query);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
