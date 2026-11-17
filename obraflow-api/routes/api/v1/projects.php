<?php

use App\Modules\Projects\Http\Controllers\ProjectCalendarController;
use App\Modules\Projects\Http\Controllers\ProjectController;
use Illuminate\Support\Facades\Route;

/*
| Fase 3 — Obras. TODAS em `tenant.required`. A rota exige `projects.view` (criar/excluir: `projects.manage`);
| o nível na obra (view < edit < manage) é aplicado no controller pelo ProjectAccess (D-P24).
*/

Route::middleware('tenant.required')->group(function (): void {
    Route::get('projects', [ProjectController::class, 'index'])->middleware('can:projects.view')->name('projects.index');
    Route::post('projects', [ProjectController::class, 'store'])->middleware('can:projects.manage')->name('projects.store');
    Route::get('projects/next-code', [ProjectController::class, 'nextCode'])->middleware('can:projects.manage')->name('projects.next-code');

    // Horário-padrão da empresa (D-P63), herdado pelas obras dela
    Route::get('companies/{uuid}/work-schedule', [ProjectCalendarController::class, 'companySchedule'])->whereUuid('uuid')->middleware('can:companies.view')->name('companies.schedule.show');
    Route::put('companies/{uuid}/work-schedule', [ProjectCalendarController::class, 'updateCompanySchedule'])->whereUuid('uuid')->middleware('can:companies.manage')->name('companies.schedule.update');

    Route::prefix('projects/{uuid}')->whereUuid('uuid')->middleware('can:projects.view')->group(function (): void {
        Route::get('/', [ProjectController::class, 'show'])->name('projects.show');
        Route::put('/', [ProjectController::class, 'update'])->name('projects.update');
        Route::delete('/', [ProjectController::class, 'destroy'])->middleware('can:projects.manage')->name('projects.destroy');

        Route::post('status', [ProjectController::class, 'changeStatus'])->name('projects.status');
        Route::get('status-history', [ProjectController::class, 'statusHistory'])->name('projects.status-history');

        Route::get('addresses', [ProjectController::class, 'addresses'])->name('projects.addresses.index');
        Route::post('addresses', [ProjectController::class, 'storeAddress'])->name('projects.addresses.store');
        Route::put('addresses/{id}', [ProjectController::class, 'updateAddress'])->whereNumber('id')->name('projects.addresses.update');
        Route::delete('addresses/{id}', [ProjectController::class, 'destroyAddress'])->whereNumber('id')->name('projects.addresses.destroy');

        Route::post('phases', [ProjectController::class, 'storePhase'])->name('projects.phases.store');
        Route::put('phases/{id}', [ProjectController::class, 'updatePhase'])->whereNumber('id')->name('projects.phases.update');
        Route::delete('phases/{id}', [ProjectController::class, 'destroyPhase'])->whereNumber('id')->name('projects.phases.destroy');

        Route::post('members', [ProjectController::class, 'storeMember'])->name('projects.members.store');
        Route::put('members/{id}', [ProjectController::class, 'updateMember'])->whereNumber('id')->name('projects.members.update');
        Route::delete('members/{id}', [ProjectController::class, 'destroyMember'])->whereNumber('id')->name('projects.members.destroy');

        Route::get('access', [ProjectController::class, 'accessList'])->name('projects.access.index');
        Route::post('access', [ProjectController::class, 'grantAccess'])->name('projects.access.store');
        Route::delete('access/{userUuid}', [ProjectController::class, 'revokeAccess'])->whereUuid('userUuid')->name('projects.access.destroy');

        // Calendário (exceções) e horários da obra — CALENDARIO_OBRA_MODELO.md; nível na obra no controller
        Route::get('calendar', [ProjectCalendarController::class, 'show'])->name('projects.calendar.show');
        Route::get('calendar/exceptions', [ProjectCalendarController::class, 'exceptions'])->name('projects.calendar.exceptions.index');
        Route::post('calendar/exceptions', [ProjectCalendarController::class, 'storeException'])->name('projects.calendar.exceptions.store');
        Route::put('calendar/exceptions/{exceptionUuid}', [ProjectCalendarController::class, 'updateException'])->whereUuid('exceptionUuid')->name('projects.calendar.exceptions.update');
        Route::delete('calendar/exceptions/{exceptionUuid}', [ProjectCalendarController::class, 'destroyException'])->whereUuid('exceptionUuid')->name('projects.calendar.exceptions.destroy');
        Route::post('calendar/import-weekends', [ProjectCalendarController::class, 'importWeekends'])->name('projects.calendar.weekends');
        Route::post('calendar/import-holidays', [ProjectCalendarController::class, 'importHolidays'])->name('projects.calendar.holidays');
        Route::get('work-schedule', [ProjectCalendarController::class, 'schedule'])->name('projects.schedule.show');
        Route::put('work-schedule', [ProjectCalendarController::class, 'updateSchedule'])->name('projects.schedule.update');
        Route::delete('work-schedule', [ProjectCalendarController::class, 'resetSchedule'])->name('projects.schedule.reset');
    });
});
