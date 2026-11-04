<?php

use App\Modules\Mentoring\Http\Controllers\MentoringController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'resolve.institution', 'account.ready'])->group(function (): void {
    Route::get('mentoring/assignments', [MentoringController::class, 'assignments'])->name('mentoring.assignments');
    Route::post('mentoring/allocate', [MentoringController::class, 'allocate'])->name('mentoring.allocate');
    Route::post('students/{student}/mentor', [MentoringController::class, 'reassign'])->name('students.mentor');
    Route::get('mentoring/at-risk', [MentoringController::class, 'atRisk'])->name('mentoring.at-risk');

    Route::get('me/mentees', [MentoringController::class, 'mentees'])->name('me.mentees');
    Route::get('mentees/{student}', [MentoringController::class, 'overview'])->name('mentees.show');
    Route::post('mentees/{student}/notes', [MentoringController::class, 'addNote'])->name('mentees.notes');
    Route::post('mentees/{student}/concerns', [MentoringController::class, 'addConcern'])->name('mentees.concerns');
    Route::patch('student-concerns/{concern}', [MentoringController::class, 'updateConcern'])->name('student-concerns.update');

    Route::get('mentoring-meetings', [MentoringController::class, 'meetings'])->name('mentoring-meetings.index');
    Route::post('mentoring-meetings', [MentoringController::class, 'schedule'])->name('mentoring-meetings.store');
    Route::patch('mentoring-meetings/{meeting}', [MentoringController::class, 'updateMeeting'])->name('mentoring-meetings.update');

    Route::get('me/mentor', [MentoringController::class, 'myMentor'])->name('me.mentor');
    Route::post('me/mentor/meeting-requests', [MentoringController::class, 'requestMeeting'])->name('me.mentor.meeting-requests');
});
