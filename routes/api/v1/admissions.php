<?php

use App\Modules\Admissions\Http\Controllers\AdmissionCycleController;
use App\Modules\Admissions\Http\Controllers\ApplicationController;
use App\Modules\Admissions\Http\Controllers\MyApplicationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'resolve.institution', 'account.ready'])->group(function (): void {
    // Applicant self-service
    Route::get('admissions/options', [MyApplicationController::class, 'options'])->name('admissions.options');
    Route::get('admissions/cycles/open', [MyApplicationController::class, 'openCycles'])->name('admissions.cycles.open');
    Route::get('me/applications', [MyApplicationController::class, 'index'])->name('me.applications.index');
    Route::post('me/applications', [MyApplicationController::class, 'store'])->name('me.applications.store');
    Route::get('me/applications/{application}', [MyApplicationController::class, 'show'])->name('me.applications.show');
    Route::put('me/applications/{application}', [MyApplicationController::class, 'update'])->name('me.applications.update');
    Route::post('me/applications/{application}/documents', [MyApplicationController::class, 'attachDocument'])->name('me.applications.documents');
    Route::post('me/applications/{application}/submit', [MyApplicationController::class, 'submit'])->name('me.applications.submit');
    Route::post('me/applications/{application}/accept-offer', [MyApplicationController::class, 'acceptOffer'])->name('me.applications.accept-offer');
    Route::post('me/applications/{application}/withdraw', [MyApplicationController::class, 'withdraw'])->name('me.applications.withdraw');

    // Cycles and seat matrix
    Route::apiResource('admission-cycles', AdmissionCycleController::class);
    Route::post('admission-cycles/{admission_cycle}/open', [AdmissionCycleController::class, 'open'])->name('admission-cycles.open');
    Route::post('admission-cycles/{admission_cycle}/close', [AdmissionCycleController::class, 'close'])->name('admission-cycles.close');
    Route::post('admission-cycles/{admission_cycle}/seats', [AdmissionCycleController::class, 'storeSeat'])->name('admission-seats.store');
    Route::patch('admission-seats/{admissionSeat}', [AdmissionCycleController::class, 'updateSeat'])->name('admission-seats.update');
    Route::delete('admission-seats/{admissionSeat}', [AdmissionCycleController::class, 'destroySeat'])->name('admission-seats.destroy');

    // Admissions desk
    Route::get('applications/summary', [ApplicationController::class, 'summary'])->name('applications.summary');
    Route::get('applications', [ApplicationController::class, 'index'])->name('applications.index');
    Route::get('applications/{application}', [ApplicationController::class, 'show'])->name('applications.show');
    Route::post('applications/{application}/documents/{document}/review', [ApplicationController::class, 'reviewDocument'])->name('applications.documents.review');
    Route::post('applications/{application}/request-correction', [ApplicationController::class, 'requestCorrection'])->name('applications.request-correction');
    Route::post('applications/{application}/complete-verification', [ApplicationController::class, 'completeVerification'])->name('applications.complete-verification');
    Route::post('applications/{application}/approve', [ApplicationController::class, 'approve'])->name('applications.approve');
    Route::post('applications/{application}/reject', [ApplicationController::class, 'reject'])->name('applications.reject');
    Route::post('applications/{application}/offer', [ApplicationController::class, 'offer'])->name('applications.offer');
    Route::post('applications/{application}/confirm', [ApplicationController::class, 'confirm'])->name('applications.confirm');
});
