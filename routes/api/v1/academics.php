<?php

use App\Modules\Academics\Http\Controllers\CourseController;
use App\Modules\Academics\Http\Controllers\CurriculumController;
use App\Modules\Academics\Http\Controllers\OfferingController;
use App\Modules\Academics\Http\Controllers\RegistrationController;
use App\Modules\Academics\Http\Controllers\TeachingController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'resolve.institution', 'account.ready'])->group(function (): void {
    Route::get('academics/options', [CourseController::class, 'options'])->name('academics.options');

    // Catalogue and syllabus
    Route::apiResource('courses', CourseController::class);
    Route::put('courses/{course}/syllabus', [CourseController::class, 'syllabus'])->name('courses.syllabus');

    // Curriculum versions
    Route::get('curricula', [CurriculumController::class, 'index'])->name('curricula.index');
    Route::post('curricula', [CurriculumController::class, 'store'])->name('curricula.store');
    Route::get('curricula/{curriculum}', [CurriculumController::class, 'show'])->name('curricula.show');
    Route::delete('curricula/{curriculum}', [CurriculumController::class, 'destroy'])->name('curricula.destroy');
    Route::put('curricula/{curriculum}/entries', [CurriculumController::class, 'entries'])->name('curricula.entries');
    Route::post('curricula/{curriculum}/submit', [CurriculumController::class, 'submit'])->name('curricula.submit');
    Route::post('curricula/{curriculum}/decide', [CurriculumController::class, 'decide'])->name('curricula.decide');

    // Offerings and allocation
    Route::get('faculty-workload', [OfferingController::class, 'workload'])->name('faculty-workload');
    Route::post('course-offerings/generate', [OfferingController::class, 'generate'])->name('course-offerings.generate');
    Route::apiResource('course-offerings', OfferingController::class);
    Route::post('offering-sections/{offeringSection}/allocations', [OfferingController::class, 'allocate'])->name('offering-sections.allocate');
    Route::delete('faculty-allocations/{facultyAllocation}', [OfferingController::class, 'deallocate'])->name('faculty-allocations.destroy');

    // Term registration
    Route::get('me/term-registration', [RegistrationController::class, 'mine'])->name('me.term-registration');
    Route::post('me/term-registration', [RegistrationController::class, 'register'])->name('me.term-registration.store');
    Route::get('term-registrations', [RegistrationController::class, 'index'])->name('term-registrations.index');
    Route::post('term-registrations/decide', [RegistrationController::class, 'decide'])->name('term-registrations.decide');
    Route::get('offering-sections/{offeringSection}/students', [RegistrationController::class, 'roster'])->name('offering-sections.students');

    // Teaching: classes, syllabus progress and materials
    Route::get('me/teaching', [TeachingController::class, 'myTeaching'])->name('me.teaching');
    Route::get('me/courses', [TeachingController::class, 'myCourses'])->name('me.courses');
    Route::get('offering-sections/{offeringSection}', [TeachingController::class, 'show'])->name('offering-sections.show');
    Route::post('offering-sections/{offeringSection}/coverage', [TeachingController::class, 'cover'])->name('offering-sections.cover');
    Route::delete('offering-sections/{offeringSection}/coverage/{topic}', [TeachingController::class, 'uncover'])->name('offering-sections.uncover');
    Route::post('offering-sections/{offeringSection}/materials', [TeachingController::class, 'addMaterial'])->name('offering-sections.materials.store');
    Route::delete('offering-sections/{offeringSection}/materials/{material}', [TeachingController::class, 'removeMaterial'])->name('offering-sections.materials.destroy');
});
