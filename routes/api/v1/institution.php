<?php

use App\Modules\Institution\Http\Controllers\CurrentInstitutionController;
use App\Modules\Institution\Http\Controllers\PlatformInstitutionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'resolve.institution', 'account.ready'])->group(function (): void {
    Route::get('institution', [CurrentInstitutionController::class, 'show'])->name('institution.show');
    Route::patch('institution', [CurrentInstitutionController::class, 'update'])->name('institution.update');
});

Route::middleware(['auth:sanctum', 'platform.admin', 'resolve.institution:optional', 'account.ready'])
    ->prefix('platform')
    ->name('platform.')
    ->group(function (): void {
        Route::get('institutions', [PlatformInstitutionController::class, 'index'])->name('institutions.index');
        Route::post('institutions', [PlatformInstitutionController::class, 'store'])->name('institutions.store');
        Route::get('institutions/{institution}', [PlatformInstitutionController::class, 'show'])->name('institutions.show');
        Route::patch('institutions/{institution}', [PlatformInstitutionController::class, 'update'])->name('institutions.update');
        Route::post('institutions/{institution}/suspend', [PlatformInstitutionController::class, 'suspend'])->name('institutions.suspend');
        Route::post('institutions/{institution}/reactivate', [PlatformInstitutionController::class, 'reactivate'])->name('institutions.reactivate');
    });
