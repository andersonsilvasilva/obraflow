<?php

namespace App\Modules\Planning\Http\Requests;

use App\Models\ScheduleDependency;
use App\Models\ScheduleTask;
use App\Modules\Teams\Http\Requests\TenantRequest;
use Illuminate\Validation\Rule;

/** Planejamento (6A) — regras pela rota (nome sem o prefixo v1.). Pertencimento ao planejamento: no ScheduleService. */
class ScheduleRequest extends TenantRequest
{
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return match (preg_replace('/^v1\./', '', (string) $this->route()?->getName())) {
            'schedules.store' => [
                'origin' => ['required', Rule::in(['blank', 'budget'])],
                'budget_uuid' => ['required_if:origin,budget', 'nullable', 'uuid'],
                'version' => ['required_if:origin,budget', 'nullable', 'integer', 'min:1'],
            ],
            'schedules.tasks.store', 'schedules.tasks.update' => [
                'parent_id' => [$creating ? 'nullable' : 'prohibited', 'integer'],
                'kind' => [$creating ? 'sometimes' : 'prohibited', Rule::in(ScheduleTask::KINDS)],
                'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:250'],
                'unit_code' => ['nullable', 'string', 'max:20'],
                'quantity' => ['nullable', 'numeric', 'min:0', 'max:99999999999.9999', 'decimal:0,4'],
                'planned_start' => ['sometimes', 'date_format:Y-m-d'],
                'planned_end' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
                'duration_days' => ['sometimes', 'nullable', 'integer', 'between:1,3650'],
                'percent_complete' => [$creating ? 'prohibited' : 'sometimes', 'numeric', 'between:0,100', 'decimal:0,2'],
                'actual_start' => [$creating ? 'prohibited' : 'sometimes', 'nullable', 'date_format:Y-m-d'],
                'actual_end' => [$creating ? 'prohibited' : 'sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:actual_start'],
            ],
            'schedules.tasks.move' => [
                'parent_id' => ['present', 'nullable', 'integer'],
                'position' => ['required', 'integer', 'min:0'],
            ],
            'schedules.dependencies.store' => [
                'predecessor_id' => ['required', 'integer', 'different:successor_id'],
                'successor_id' => ['required', 'integer'],
                'type' => ['sometimes', Rule::in(ScheduleDependency::TYPES)],
                'lag_days' => ['sometimes', 'integer', 'between:-365,365'],
            ],
            'schedules.baselines.store' => ['name' => ['required', 'string', 'max:100']],
            default => [],
        };
    }

    public function attributes(): array
    {
        return [
            'name' => 'atividade', 'planned_start' => 'início', 'planned_end' => 'término', 'duration_days' => 'duração (dias úteis)',
            'percent_complete' => 'avanço (%)', 'predecessor_id' => 'predecessora', 'successor_id' => 'sucessora', 'lag_days' => 'atraso (dias)',
        ];
    }
}
