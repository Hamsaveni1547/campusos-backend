<?php

use App\Modules\Reports\Http\Controllers\DashboardController;
use App\Modules\Reports\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'resolve.institution', 'account.ready'])->group(function (): void {
    Route::get('me/dashboard', [DashboardController::class, 'show'])->name('me.dashboard');
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/{key}', [ReportController::class, 'run'])->name('reports.run');
});
