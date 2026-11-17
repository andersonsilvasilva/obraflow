<?php

namespace App\Models;

use App\Modules\Audit\ImmutableBuilder;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Histórico do avanço de uma atividade (6A; o RDO aprovado também grava — D-P75). Só inserção. */
class ScheduleProgress extends Model
{
    use BelongsToTenant;

    public const CREATED_AT = null;

    public const UPDATED_AT = null;

    protected $table = 'schedule_progress_history';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['percent_complete' => 'decimal:2', 'recorded_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('schedule_progress_history é só inserção: atualização proibida.'));
        static::deleting(fn () => throw new LogicException('schedule_progress_history é só inserção: exclusão proibida.'));
    }

    public function newEloquentBuilder($query): ImmutableBuilder
    {
        return new ImmutableBuilder($query);
    }
}
