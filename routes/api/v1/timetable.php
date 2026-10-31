<?php

use App\Modules\Timetable\Http\Controllers\ScheduleController;
use App\Modules\Timetable\Http\Controllers\SlotTemplateController;
use App\Modules\Timetable\Http\Controllers\TimetableController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'resolve.institution', 'account.ready'])->group(function (): void {
    Route::get('slot-templates', [SlotTemplateController::class, 'index'])->name('slot-templates.index');
    Route::post('slot-templates', [SlotTemplateController::class, 'store'])->name('slot-templates.store');
    Route::patch('slot-templates/{slotTemplate}', [SlotTemplateController::class, 'update'])->name('slot-templates.update');
    Route::delete('slot-templates/{slotTemplate}', [SlotTemplateController::class, 'destroy'])->name('slot-templates.destroy');

    Route::get('timetables', [TimetableController::class, 'index'])->name('timetables.index');
    Route::post('timetables', [TimetableController::class, 'store'])->name('timetables.store');
    Route::get('timetables/{timetable}', [TimetableController::class, 'show'])->name('timetables.show');
    Route::delete('timetables/{timetable}', [TimetableController::class, 'destroy'])->name('timetables.destroy');
    Route::post('timetables/{timetable}/entries', [TimetableController::class, 'storeEntry'])->name('timetables.entries.store');
    Route::post('timetables/{timetable}/publish', [TimetableController::class, 'publish'])->name('timetables.publish');
    Route::post('timetables/{timetable}/unpublish', [TimetableController::class, 'unpublish'])->name('timetables.unpublish');
    Route::patch('timetable-entries/{timetableEntry}', [TimetableController::class, 'updateEntry'])->name('timetable-entries.update');
    Route::delete('timetable-entries/{timetableEntry}', [TimetableController::class, 'destroyEntry'])->name('timetable-entries.destroy');

    Route::get('timetable-view/week', [ScheduleController::class, 'week'])->name('timetable-view.week');
    Route::get('timetable-view/day', [ScheduleController::class, 'day'])->name('timetable-view.day');
    Route::get('me/timetable', [ScheduleController::class, 'mine'])->name('me.timetable');
    Route::get('timetable-adjustments', [ScheduleController::class, 'adjustments'])->name('timetable-adjustments.index');
    Route::post('timetable-adjustments', [ScheduleController::class, 'adjust'])->name('timetable-adjustments.store');
    Route::post('timetable-adjustments/extra', [ScheduleController::class, 'extra'])->name('timetable-adjustments.extra');
    Route::delete('timetable-adjustments/{timetableAdjustment}', [ScheduleController::class, 'undo'])->name('timetable-adjustments.destroy');
});
