<?php

namespace App\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Vínculo polimórfico documento ↔ cadastro (única tabela polimórfica da Fase 2).
 * Crie SEMPRE pelo DocumentStorage::link(), que confere o dono no tenant ativo.
 */
class DocumentLink extends Model
{
    use BelongsToTenant;

    public const LINKABLE_TYPES = ['company', 'company_branch', 'customer', 'supplier'];

    protected $guarded = ['*'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }
}
