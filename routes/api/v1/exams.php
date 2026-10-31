<?php

use App\Modules\Exams\Http\Controllers\ExamController;
use App\Modules\Exams\Http\Controllers\MyExamController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'resolve.institution', 'account.ready'])->group(function (): void {
    Route::get('exams/options', [ExamController::class, 'options'])->name('exams.options');

    // Cycles
    Route::get('exam-cycles', [ExamController::class, 'index'])->name('exam-cycles.index');
    Route::post('exam-cycles', [ExamController::class, 'store'])->name('exam-cycles.store');
    Route::get('exam-cycles/{cycle}', [ExamController::class, 'show'])->name('exam-cycles.show');
    Route::patch('exam-cycles/{cycle}', [ExamController::class, 'update'])->name('exam-cycles.update');
    Route::post('exam-cycles/{cycle}/{action}', [ExamController::class, 'transition'])
        ->whereIn('action', ['open-registration', 'close-registration', 'eligibility', 'process', 'verify', 'publish'])->name('exam-cycles.transition');
    Route::get('exam-cycles/{cycle}/registrations', [ExamController::class, 'registrations'])->name('exam-cycles.registrations');
    Route::get('exam-cycles/{cycle}/results', [ExamController::class, 'results'])->name('exam-cycles.results');
    Route::post('exam-registrations/{registration}/override', [ExamController::class, 'override'])->name('exam-registrations.override');

    // Timetable and marks
    Route::post('exam-cycles/{cycle}/schedules', [ExamController::class, 'storeSchedule'])->name('exam-schedules.store');
    Route::patch('exam-schedules/{schedule}', [ExamController::class, 'updateSchedule'])->name('exam-schedules.update');
    Route::delete('exam-schedules/{schedule}', [ExamController::class, 'destroySchedule'])->name('exam-schedules.destroy');
    Route::get('exam-schedules/{schedule}/scripts', [ExamController::class, 'scripts'])->name('exam-schedules.scripts');
    Route::put('exam-schedules/{schedule}/marks', [ExamController::class, 'saveMarks'])->name('exam-schedules.marks');

    // Revaluation
    Route::get('revaluation-requests', [ExamController::class, 'revaluations'])->name('revaluation-requests.index');
    Route::post('revaluation-requests/{revaluation}/decide', [ExamController::class, 'decideRevaluation'])->name('revaluation-requests.decide');

    // Academic history on the student record
    Route::get('students/{student}/academic-history', [MyExamController::class, 'studentHistory'])->name('students.academic-history');

    // Students
    Route::get('me/exams', [MyExamController::class, 'index'])->name('me.exams');
    Route::post('me/exam-cycles/{cycle}/backlogs', [MyExamController::class, 'registerBacklogs'])->name('me.exams.backlogs');
    Route::get('me/hall-ticket/{cycle}', [MyExamController::class, 'hallTicket'])->name('me.hall-ticket');
    Route::get('me/results', [MyExamController::class, 'results'])->name('me.results');
    Route::post('me/revaluation-requests', [MyExamController::class, 'requestRevaluation'])->name('me.revaluation-requests.store');
});
