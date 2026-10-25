<?php

use App\Modules\HR\Http\Controllers\DesignationController;
use App\Modules\HR\Http\Controllers\EmployeeController;
use App\Modules\Institution\Http\Controllers\AcademicCalendarController;
use App\Modules\Institution\Http\Controllers\BatchController;
use App\Modules\Institution\Http\Controllers\CurrentInstitutionController;
use App\Modules\Institution\Http\Controllers\DepartmentController;
use App\Modules\Institution\Http\Controllers\PlatformInstitutionController;
use App\Modules\Institution\Http\Controllers\ProgrammeController;
use App\Modules\Institution\Http\Controllers\RoomController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'resolve.institution', 'account.ready'])->group(function (): void {
    Route::get('institution', [CurrentInstitutionController::class, 'show'])->name('institution.show');
    Route::patch('institution', [CurrentInstitutionController::class, 'update'])->name('institution.update');

    // Academic structure (read: every member; write: permission checked in controllers).
    Route::apiResource('departments', DepartmentController::class);
    Route::post('departments/{department}/hod', [DepartmentController::class, 'assignHod'])->name('departments.hod.assign');
    Route::delete('departments/{department}/hod', [DepartmentController::class, 'removeHod'])->name('departments.hod.remove');

    Route::apiResource('programmes', ProgrammeController::class);

    Route::get('academic-context', [AcademicCalendarController::class, 'context'])->name('academic-context');
    Route::get('academic-years', [AcademicCalendarController::class, 'years'])->name('academic-years.index');
    Route::post('academic-years', [AcademicCalendarController::class, 'storeYear'])->name('academic-years.store');
    Route::get('academic-years/{academicYear}', [AcademicCalendarController::class, 'showYear'])->name('academic-years.show');
    Route::patch('academic-years/{academicYear}', [AcademicCalendarController::class, 'updateYear'])->name('academic-years.update');
    Route::delete('academic-years/{academicYear}', [AcademicCalendarController::class, 'destroyYear'])->name('academic-years.destroy');
    Route::post('academic-years/{academicYear}/make-current', [AcademicCalendarController::class, 'makeYearCurrent'])->name('academic-years.make-current');
    Route::post('academic-years/{academicYear}/close', [AcademicCalendarController::class, 'closeYear'])->name('academic-years.close');

    Route::get('terms', [AcademicCalendarController::class, 'terms'])->name('terms.index');
    Route::post('terms', [AcademicCalendarController::class, 'storeTerm'])->name('terms.store');
    Route::patch('terms/{term}', [AcademicCalendarController::class, 'updateTerm'])->name('terms.update');
    Route::delete('terms/{term}', [AcademicCalendarController::class, 'destroyTerm'])->name('terms.destroy');
    Route::post('terms/{term}/make-current', [AcademicCalendarController::class, 'makeTermCurrent'])->name('terms.make-current');
    Route::post('terms/{term}/close', [AcademicCalendarController::class, 'closeTerm'])->name('terms.close');

    Route::get('calendar-events', [AcademicCalendarController::class, 'events'])->name('calendar-events.index');
    Route::post('calendar-events', [AcademicCalendarController::class, 'storeEvent'])->name('calendar-events.store');
    Route::patch('calendar-events/{calendarEvent}', [AcademicCalendarController::class, 'updateEvent'])->name('calendar-events.update');
    Route::delete('calendar-events/{calendarEvent}', [AcademicCalendarController::class, 'destroyEvent'])->name('calendar-events.destroy');

    Route::apiResource('batches', BatchController::class);
    Route::post('batches/{batch}/sections', [BatchController::class, 'storeSection'])->name('sections.store');
    Route::patch('sections/{section}', [BatchController::class, 'updateSection'])->name('sections.update');
    Route::delete('sections/{section}', [BatchController::class, 'destroySection'])->name('sections.destroy');

    Route::apiResource('rooms', RoomController::class);

    // Staff (M12 foundation)
    Route::get('designations', [DesignationController::class, 'index'])->name('designations.index');
    Route::post('designations', [DesignationController::class, 'store'])->name('designations.store');
    Route::patch('designations/{designation}', [DesignationController::class, 'update'])->name('designations.update');
    Route::delete('designations/{designation}', [DesignationController::class, 'destroy'])->name('designations.destroy');

    Route::get('me/employee-profile', [EmployeeController::class, 'me'])->name('me.employee-profile');
    Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');
    Route::post('employees', [EmployeeController::class, 'store'])->name('employees.store');
    Route::get('employees/{employee}', [EmployeeController::class, 'show'])->name('employees.show');
    Route::patch('employees/{employee}', [EmployeeController::class, 'update'])->name('employees.update');
    Route::post('employees/{employee}/status', [EmployeeController::class, 'changeStatus'])->name('employees.status');
    Route::post('employees/{employee}/qualifications', [EmployeeController::class, 'storeQualification'])->name('employees.qualifications.store');
    Route::patch('employee-qualifications/{qualification}', [EmployeeController::class, 'updateQualification'])->name('employee-qualifications.update');
    Route::delete('employee-qualifications/{qualification}', [EmployeeController::class, 'destroyQualification'])->name('employee-qualifications.destroy');
});

Route::middleware(['auth:sanctum', 'platform.admin', 'resolve.institution:optional', 'account.ready'])
    ->prefix('platform')
    ->name('platform.')
    ->group(function (): void {
        Route::get('institutions', [PlatformInstitutionController::class, 'index'])->name('institutions.index');
        Route::post('institutions', [PlatformInstitutionController::class, 'store'])->name('institutions.store');
        Route::get('institutions/{institution}', [PlatformInstitutionController::class, 'show'])->name('institutions.show');
        Route::patch('institutions/{institution}', [PlatformInstitutionController::class, 'update'])->name('institutions.update');
        Route::post('institutions/{institution}/suspend', [PlatformInstitutionController::class, 'suspend'])->name('institutions.suspend');
        Route::post('institutions/{institution}/reactivate', [PlatformInstitutionController::class, 'reactivate'])->name('institutions.reactivate');
    });
