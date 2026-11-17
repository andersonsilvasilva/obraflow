<?php

namespace App\Models;

use App\Modules\Audit\ImmutableBuilder;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Histórico de status do orçamento — append-only. Só o BudgetWorkflow cria. */
class BudgetStatusHistory extends Model
{
    use BelongsToTenant;

    public const CREATED_AT = null;

    public const UPDATED_AT = null;

    protected $table = 'budget_status_history';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['changed_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('budget_status_history é append-only: atualização proibida.'));
        static::deleting(fn () => throw new LogicException('budget_status_history é append-only: exclusão proibida.'));
    }

    public function newEloquentBuilder($query): ImmutableBuilder
    {
        return new ImmutableBuilder($query);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(BudgetVersion::class, 'budget_version_id');
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
