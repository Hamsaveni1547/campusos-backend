<?php

use App\Modules\Students\Http\Controllers\MyStudentController;
use App\Modules\Students\Http\Controllers\StudentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'resolve.institution', 'account.ready'])->group(function (): void {
    // Student self-service
    Route::get('me/student', [MyStudentController::class, 'show'])->name('me.student.show');
    Route::patch('me/student', [MyStudentController::class, 'update'])->name('me.student.update');
    Route::post('me/student/documents', [MyStudentController::class, 'attachDocument'])->name('me.student.documents');
    Route::post('me/student/change-requests', [MyStudentController::class, 'requestChange'])->name('me.student.change-requests');

    // Staff
    Route::get('students/summary', [StudentController::class, 'summary'])->name('students.summary');
    Route::get('student-change-requests', [StudentController::class, 'changeRequests'])->name('student-change-requests.index');
    Route::post('student-change-requests/{changeRequest}/review', [StudentController::class, 'reviewChangeRequest'])->name('student-change-requests.review');
    Route::get('students', [StudentController::class, 'index'])->name('students.index');
    Route::post('students', [StudentController::class, 'store'])->name('students.store');
    Route::get('students/{student}', [StudentController::class, 'show'])->name('students.show');
    Route::patch('students/{student}', [StudentController::class, 'update'])->name('students.update');
    Route::post('students/{student}/placement', [StudentController::class, 'place'])->name('students.placement');
    Route::post('students/{student}/status', [StudentController::class, 'changeStatus'])->name('students.status');
    Route::put('students/{student}/guardians', [StudentController::class, 'guardians'])->name('students.guardians');
    Route::post('students/{student}/documents', [StudentController::class, 'attachDocument'])->name('students.documents');
    Route::post('students/{student}/documents/{document}/review', [StudentController::class, 'reviewDocument'])->name('students.documents.review');
});
