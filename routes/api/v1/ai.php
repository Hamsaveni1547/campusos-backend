<?php

use App\Modules\AI\Http\Controllers\AiFeatureController;
use App\Modules\AI\Http\Controllers\AiModelsController;
use App\Modules\AI\Http\Controllers\AiUsageController;
use App\Modules\AI\Http\Controllers\AssistantController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'resolve.institution', 'account.ready', 'throttle:30,1'])->prefix('ai')->name('ai.')->group(function (): void {
    Route::get('status', [AiFeatureController::class, 'status'])->name('status');
    Route::get('usage', AiUsageController::class)->name('usage');
    Route::get('models', [AiModelsController::class, 'index'])->name('models');
    Route::post('models/test', [AiModelsController::class, 'test'])->name('models.test');

    // A1: helpers on existing screens
    Route::post('announcements/draft', [AiFeatureController::class, 'draftAnnouncement'])->name('announcements.draft');
    Route::post('announcements/rewrite', [AiFeatureController::class, 'rewriteAnnouncement'])->name('announcements.rewrite');
    Route::get('announcements/{announcement}/summary', [AiFeatureController::class, 'summariseAnnouncement'])->name('announcements.summary');
    Route::post('complaints/suggest-category', [AiFeatureController::class, 'suggestComplaintCategory'])->name('complaints.suggest');
    Route::get('complaints/{complaint}/triage', [AiFeatureController::class, 'triageComplaint'])->name('complaints.triage');
    Route::get('surveys/{survey}/insights', [AiFeatureController::class, 'surveyInsights'])->name('surveys.insights');

    // A2: Ask CampusOS assistant
    Route::get('assistant', [AssistantController::class, 'index'])->name('assistant.index');
    Route::post('assistant/messages', [AssistantController::class, 'ask'])->name('assistant.ask');
    Route::get('assistant/conversations/{conversation}', [AssistantController::class, 'show'])->name('assistant.show');
    Route::delete('assistant/conversations/{conversation}', [AssistantController::class, 'destroy'])->name('assistant.destroy');
});
