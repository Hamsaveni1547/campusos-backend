<?php

use App\Modules\Assessment\Http\Controllers\AssessmentController;
use App\Modules\Assessment\Http\Controllers\AssignmentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'resolve.institution', 'account.ready'])->group(function (): void {
    Route::get('assessment/options', [AssessmentController::class, 'options'])->name('assessment.options');

    // Course outcomes
    Route::get('courses/{course}/outcomes', [AssessmentController::class, 'outcomes'])->name('course-outcomes.index');
    Route::post('courses/{course}/outcomes', [AssessmentController::class, 'storeOutcome'])->name('course-outcomes.store');
    Route::patch('course-outcomes/{outcome}', [AssessmentController::class, 'updateOutcome'])->name('course-outcomes.update');
    Route::delete('course-outcomes/{outcome}', [AssessmentController::class, 'destroyOutcome'])->name('course-outcomes.destroy');

    // Scheme
    Route::get('offerings/{offering}/scheme', [AssessmentController::class, 'scheme'])->name('assessment.scheme');
    Route::post('offerings/{offering}/assessment-groups', [AssessmentController::class, 'storeGroup'])->name('assessment-groups.store');
    Route::patch('assessment-groups/{group}', [AssessmentController::class, 'updateGroup'])->name('assessment-groups.update');
    Route::delete('assessment-groups/{group}', [AssessmentController::class, 'destroyGroup'])->name('assessment-groups.destroy');
    Route::post('assessment-groups/{group}/assessments', [AssessmentController::class, 'storeAssessment'])->name('assessments.store');
    Route::patch('assessments/{assessment}', [AssessmentController::class, 'updateAssessment'])->name('assessments.update');
    Route::delete('assessments/{assessment}', [AssessmentController::class, 'destroyAssessment'])->name('assessments.destroy');

    // Marks and internal marks
    Route::get('assessments/{assessment}/marks', [AssessmentController::class, 'marks'])->name('assessments.marks');
    Route::put('assessments/{assessment}/marks', [AssessmentController::class, 'saveMarks'])->name('assessments.marks.save');
    Route::get('classes/{class}/internal-marks', [AssessmentController::class, 'internal'])->name('internal-marks.show');
    Route::post('classes/{class}/internal-marks/compute', [AssessmentController::class, 'compute'])->name('internal-marks.compute');
    Route::post('classes/{class}/internal-marks/submit', [AssessmentController::class, 'submit'])->name('internal-marks.submit');
    Route::get('classes/{class}/attainment', [AssessmentController::class, 'attainment'])->name('assessment.attainment');
    Route::get('internal-mark-sheets', [AssessmentController::class, 'sheets'])->name('internal-mark-sheets.index');
    Route::post('internal-mark-sheets/{sheet}/verify', [AssessmentController::class, 'verify'])->name('internal-mark-sheets.verify');
    Route::post('internal-mark-sheets/{sheet}/lock', [AssessmentController::class, 'lock'])->name('internal-mark-sheets.lock');

    // Corrections
    Route::get('mark-corrections', [AssessmentController::class, 'corrections'])->name('mark-corrections.index');
    Route::post('mark-corrections', [AssessmentController::class, 'requestCorrection'])->name('mark-corrections.store');
    Route::post('mark-corrections/{correction}/decide', [AssessmentController::class, 'decideCorrection'])->name('mark-corrections.decide');

    // Assignments
    Route::get('offerings/{offering}/assignments', [AssignmentController::class, 'index'])->name('assignments.index');
    Route::post('offerings/{offering}/assignments', [AssignmentController::class, 'store'])->name('assignments.store');
    Route::patch('assignments/{assignment}', [AssignmentController::class, 'update'])->name('assignments.update');
    Route::delete('assignments/{assignment}', [AssignmentController::class, 'destroy'])->name('assignments.destroy');
    Route::get('assignments/{assignment}/submissions', [AssignmentController::class, 'submissions'])->name('assignments.submissions');
    Route::post('assignment-submissions/{submission}/evaluate', [AssignmentController::class, 'evaluate'])->name('assignment-submissions.evaluate');

    // Students
    Route::get('me/internal-marks', [AssessmentController::class, 'mine'])->name('me.internal-marks');
    Route::get('me/assignments', [AssignmentController::class, 'mine'])->name('me.assignments');
    Route::post('me/assignments/{assignment}/submit', [AssignmentController::class, 'submit'])->name('me.assignments.submit');
});
