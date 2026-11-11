<?php

use App\Modules\AI\Http\Controllers\AiFeatureController;
use App\Modules\AI\Http\Controllers\AiUsageController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'resolve.institution', 'account.ready', 'throttle:30,1'])->prefix('ai')->name('ai.')->group(function (): void {
    Route::get('status', [AiFeatureController::class, 'status'])->name('status');
    Route::get('usage', AiUsageController::class)->name('usage');

    // A1: helpers on existing screens
    Route::post('announcements/draft', [AiFeatureController::class, 'draftAnnouncement'])->name('announcements.draft');
    Route::post('announcements/rewrite', [AiFeatureController::class, 'rewriteAnnouncement'])->name('announcements.rewrite');
    Route::get('announcements/{announcement}/summary', [AiFeatureController::class, 'summariseAnnouncement'])->name('announcements.summary');
    Route::post('complaints/suggest-category', [AiFeatureController::class, 'suggestComplaintCategory'])->name('complaints.suggest');
    Route::get('complaints/{complaint}/triage', [AiFeatureController::class, 'triageComplaint'])->name('complaints.triage');
    Route::get('surveys/{survey}/insights', [AiFeatureController::class, 'surveyInsights'])->name('surveys.insights');
});
