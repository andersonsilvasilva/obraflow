<?php

namespace App\Models;

use App\Modules\Audit\ImmutableBuilder;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Linha de base (6A, D-P71): fotografia congelada das datas. Só inserção; até 10 por planejamento. */
class ScheduleBaseline extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    public const MAX = 10;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'number' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('schedule_baselines é só inserção: atualização proibida.'));
        static::deleting(fn () => throw new LogicException('schedule_baselines é só inserção: exclusão proibida.'));
    }

    public function newEloquentBuilder($query): ImmutableBuilder
    {
        return new ImmutableBuilder($query);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
