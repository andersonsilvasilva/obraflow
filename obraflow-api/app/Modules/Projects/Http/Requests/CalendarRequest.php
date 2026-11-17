<?php

namespace App\Modules\Projects\Http\Requests;

use App\Models\ProjectCalendarException;
use App\Modules\Teams\Http\Requests\TenantRequest;
use Illuminate\Validation\Rule;

/**
 * Calendário e horários da obra (CALENDARIO_OBRA_MODELO.md). Uma classe por rota, decidida pelo nome da rota:
 * exceção (única/recorrente, D-P58), período consultado, ano dos feriados e a semana de horários (D-P63:
 * até 2 períodos por dia, saída depois da entrada, 2º período depois do 1º).
 */
class CalendarRequest extends TenantRequest
{
    public function rules(): array
    {
        $time = ['nullable', 'date_format:H:i'];

        return match (preg_replace('/^v1\./', '', (string) $this->route()?->getName())) {
            'projects.calendar.show' => [
                'from' => ['required', 'date_format:Y-m-d'],
                'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            ],
            'projects.calendar.holidays' => ['year' => ['required', 'integer', 'between:2000,2100']],
            'projects.schedule.update', 'companies.schedule.update' => [
                'days' => ['required', 'array', 'size:7'],
                'days.*' => ['array'],
                'days.*.start_1' => [...$time, 'required_with:days.*.end_1,days.*.start_2'],
                'days.*.end_1' => [...$time, 'required_with:days.*.start_1', 'after:days.*.start_1'],
                'days.*.start_2' => [...$time, 'required_with:days.*.end_2', 'after_or_equal:days.*.end_1'],
                'days.*.end_2' => [...$time, 'required_with:days.*.start_2', 'after:days.*.start_2'],
            ],
            default => [ // criar/editar exceção
                'description' => ['required', 'string', 'max:200'],
                'date' => ['required', 'date_format:Y-m-d'],
                'kind' => ['required', Rule::in(ProjectCalendarException::KINDS)],
                'repeat_every' => ['exclude_unless:kind,recurring', 'required', 'integer', 'between:1,365'],
                'repeat_unit' => ['exclude_unless:kind,recurring', 'required', Rule::in(ProjectCalendarException::UNITS)],
                'repeat_until' => ['exclude_unless:kind,recurring', 'nullable', 'date_format:Y-m-d', 'after_or_equal:date'],
            ],
        };
    }

    public function attributes(): array
    {
        return [
            'description' => 'descrição', 'date' => 'data', 'kind' => 'tipo da exceção', 'repeat_every' => 'repetir a cada',
            'repeat_unit' => 'unidade de repetição', 'repeat_until' => 'até', 'from' => 'de', 'to' => 'até', 'year' => 'ano',
            'days.*.start_1' => 'entrada 1', 'days.*.end_1' => 'saída 1', 'days.*.start_2' => 'entrada 2', 'days.*.end_2' => 'saída 2',
        ];
    }
}
