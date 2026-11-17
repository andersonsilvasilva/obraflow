<?php

namespace App\Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Project;
use App\Models\ProjectCalendarException;
use App\Modules\Projects\Http\Requests\CalendarRequest;
use App\Modules\Projects\Services\ProjectAccess;
use App\Modules\Projects\Services\ProjectCalendar;
use App\Modules\Projects\Services\WorkScheduleService;
use App\Modules\Registry\Services\RegistryService;
use App\Modules\Shared\Exceptions\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Calendário (exceções) e horários da obra; horário-padrão da empresa (CALENDARIO_OBRA_MODELO.md).
 * Acesso (D-P62): ver exige ver a obra; alterar exige nível `edit` na obra (D-P24). Obra invisível → 404.
 */
class ProjectCalendarController extends Controller
{
    public function __construct(
        private readonly ProjectAccess $access,
        private readonly ProjectCalendar $calendar,
        private readonly WorkScheduleService $schedules,
        private readonly RegistryService $registry,
    ) {}

    /** Ocorrências no período (o que o calendário desenha) + dias da semana sem horário. */
    public function show(CalendarRequest $request, string $uuid): JsonResponse
    {
        $project = $this->find($request, $uuid, 'view');

        return response()->json(['data' => $this->calendar->between($project,
            CarbonImmutable::parse($request->validated('from')), CarbonImmutable::parse($request->validated('to')))]);
    }

    public function exceptions(Request $request, string $uuid): JsonResponse
    {
        $project = $this->find($request, $uuid, 'view');
        $rows = ProjectCalendarException::query()->where('project_id', $project->id)->with('creator:id,uuid,name')
            ->orderBy('date')->orderBy('description')->get();

        return response()->json(['data' => $rows->map(fn ($e) => $this->present($e))]);
    }

    public function storeException(CalendarRequest $request, string $uuid): JsonResponse
    {
        $project = $this->find($request, $uuid, 'edit');
        $exception = new ProjectCalendarException;
        $exception->forceFill(['project_id' => $project->id, 'created_by' => $request->user()->getKey()]);
        $this->registry->create($exception, $this->normalized($request), $request->user());

        return response()->json(['data' => $this->present($exception->load('creator:id,uuid,name'))], 201);
    }

    public function updateException(CalendarRequest $request, string $uuid, string $exceptionUuid): JsonResponse
    {
        $exception = $this->exception($request, $uuid, $exceptionUuid);
        $this->registry->update($exception, $this->normalized($request), $request->user());

        return response()->json(['data' => $this->present($exception->load('creator:id,uuid,name'))]);
    }

    public function destroyException(Request $request, string $uuid, string $exceptionUuid): JsonResponse
    {
        $this->registry->delete($this->exception($request, $uuid, $exceptionUuid), $request->user());

        return response()->json(null, 204);
    }

    public function importWeekends(Request $request, string $uuid): JsonResponse
    {
        $created = $this->calendar->importWeekends($this->find($request, $uuid, 'edit'), $request->user());

        return response()->json(['data' => ['created' => $created]]);
    }

    public function importHolidays(CalendarRequest $request, string $uuid): JsonResponse
    {
        $created = $this->calendar->importHolidays($this->find($request, $uuid, 'edit'), (int) $request->validated('year'), $request->user());

        return response()->json(['data' => ['created' => $created]]);
    }

    // ------------------------------------------------------------ horários

    public function schedule(Request $request, string $uuid): JsonResponse
    {
        return response()->json(['data' => $this->schedules->forProject($this->find($request, $uuid, 'view'))]);
    }

    public function updateSchedule(CalendarRequest $request, string $uuid): JsonResponse
    {
        $project = $this->find($request, $uuid, 'edit');
        $this->schedules->save($project, $request->validated('days'), $request->user());

        return response()->json(['data' => $this->schedules->forProject($project)]);
    }

    /** Volta a herdar o horário da empresa. */
    public function resetSchedule(Request $request, string $uuid): JsonResponse
    {
        $project = $this->find($request, $uuid, 'edit');
        $this->schedules->reset($project, $request->user());

        return response()->json(['data' => $this->schedules->forProject($project)]);
    }

    public function companySchedule(string $uuid): JsonResponse
    {
        return response()->json(['data' => $this->schedules->forCompany(Company::query()->where('uuid', $uuid)->firstOrFail())]);
    }

    public function updateCompanySchedule(CalendarRequest $request, string $uuid): JsonResponse
    {
        $company = Company::query()->where('uuid', $uuid)->firstOrFail();
        $this->schedules->save($company, $request->validated('days'), $request->user());

        return response()->json(['data' => $this->schedules->forCompany($company)]);
    }

    // ------------------------------------------------------------ apoio

    private function find(Request $request, string $uuid, string $ability): Project
    {
        $project = Project::query()->where('uuid', $uuid)->firstOrFail();
        $level = $this->access->level($request->user(), $project);
        if ($level === null) {
            throw ApiException::notFound();
        }
        if (ProjectAccess::RANK[$level] < ProjectAccess::RANK[$ability]) {
            throw ApiException::forbidden('forbidden', 'Seu nível de acesso nesta obra não permite esta ação.');
        }

        return $project;
    }

    private function exception(Request $request, string $uuid, string $exceptionUuid): ProjectCalendarException
    {
        $project = $this->find($request, $uuid, 'edit');

        return ProjectCalendarException::query()->where('project_id', $project->id)->where('uuid', $exceptionUuid)->firstOrFail();
    }

    /** Única não guarda repetição (CHECK). */
    private function normalized(CalendarRequest $request): array
    {
        $data = $request->validated();
        if ($data['kind'] === 'single') {
            $data = array_merge($data, ['repeat_every' => null, 'repeat_unit' => null, 'repeat_until' => null]);
        } else {
            $data['repeat_until'] ??= null;
        }

        return $data;
    }

    private function present(ProjectCalendarException $e): array
    {
        return [
            'uuid' => $e->uuid, 'description' => $e->description, 'date' => $e->date->toDateString(), 'kind' => $e->kind,
            'repeat_every' => $e->repeat_every, 'repeat_unit' => $e->repeat_unit, 'repeat_until' => $e->repeat_until?->toDateString(),
            'created_by' => $e->creator?->only(['uuid', 'name']), 'created_at' => $e->created_at?->toIso8601String(),
        ];
    }
}
