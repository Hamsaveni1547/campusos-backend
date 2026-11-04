<?php

use App\Modules\Feedback\Http\Controllers\ComplaintController;
use App\Modules\Feedback\Http\Controllers\SurveyController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'resolve.institution', 'account.ready'])->group(function (): void {
    Route::get('surveys', [SurveyController::class, 'index'])->name('surveys.index');
    Route::post('surveys', [SurveyController::class, 'store'])->name('surveys.store');
    Route::get('surveys/{survey}', [SurveyController::class, 'show'])->name('surveys.show');
    Route::patch('surveys/{survey}', [SurveyController::class, 'update'])->name('surveys.update');
    Route::post('surveys/{survey}/{action}', [SurveyController::class, 'transition'])->whereIn('action', ['open', 'close'])->name('surveys.transition');
    Route::get('surveys/{survey}/results', [SurveyController::class, 'results'])->name('surveys.results');
    Route::get('me/surveys', [SurveyController::class, 'pending'])->name('me.surveys');
    Route::post('me/surveys/{survey}/answers', [SurveyController::class, 'answer'])->name('me.surveys.answer');
    Route::get('me/teaching-feedback', [SurveyController::class, 'teacher'])->name('me.teaching-feedback');

    Route::get('complaint-categories', [ComplaintController::class, 'categories'])->name('complaint-categories.index');
    Route::post('complaint-categories', [ComplaintController::class, 'storeCategory'])->name('complaint-categories.store');
    Route::patch('complaint-categories/{category}', [ComplaintController::class, 'updateCategory'])->name('complaint-categories.update');
    Route::get('complaints', [ComplaintController::class, 'index'])->name('complaints.index');
    Route::get('complaints/{complaint}', [ComplaintController::class, 'show'])->name('complaints.show');
    Route::post('complaints/{complaint}/assign', [ComplaintController::class, 'assign'])->name('complaints.assign');
    Route::post('complaints/{complaint}/respond', [ComplaintController::class, 'respond'])->name('complaints.respond');
    Route::get('me/complaints', [ComplaintController::class, 'mine'])->name('me.complaints');
    Route::post('me/complaints', [ComplaintController::class, 'raise'])->name('me.complaints.store');
    Route::post('me/complaints/{complaint}/messages', [ComplaintController::class, 'followUp'])->name('me.complaints.messages');
    Route::post('me/complaints/{complaint}/close', [ComplaintController::class, 'close'])->name('me.complaints.close');
});
