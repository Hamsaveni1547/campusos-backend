<?php

use App\Modules\SystemAdmin\Authorization\SystemAdminPermissions;
use App\Modules\SystemAdmin\Http\Controllers\AuditController;
use App\Modules\SystemAdmin\Http\Controllers\MonitoringController;
use App\Modules\SystemAdmin\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::get('health', [MonitoringController::class, 'ping'])->name('health');

Route::middleware(['auth:sanctum', 'resolve.institution', 'account.ready'])->group(function (): void {
    Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::get('settings/{group}', [SettingsController::class, 'show'])->name('settings.show');
    Route::put('settings/{group}', [SettingsController::class, 'update'])->name('settings.update');

    Route::get('audit/activities', [AuditController::class, 'activities'])->name('audit.activities');
    Route::get('audit/activities/export', [AuditController::class, 'exportActivities'])->name('audit.activities.export');
    Route::get('audit/security-events', [AuditController::class, 'securityEvents'])->name('audit.security-events');

    Route::get('admin/health', [MonitoringController::class, 'health'])
        ->middleware('can:'.SystemAdminPermissions::MonitoringView)
        ->name('admin.health');
});

Route::middleware(['auth:sanctum', 'platform.admin', 'resolve.institution:optional', 'account.ready'])
    ->prefix('platform')
    ->name('platform.')
    ->group(function (): void {
        Route::get('settings', [SettingsController::class, 'platformIndex'])->name('settings.index');
        Route::put('settings/{group}', [SettingsController::class, 'platformUpdate'])->name('settings.update');

        Route::get('monitoring/health', [MonitoringController::class, 'health'])->name('monitoring.health');
        Route::get('monitoring/queues', [MonitoringController::class, 'queues'])->name('monitoring.queues');
        Route::get('monitoring/failed-jobs', [MonitoringController::class, 'failedJobs'])->name('monitoring.failed-jobs');
        Route::post('monitoring/failed-jobs/{job}/retry', [MonitoringController::class, 'retryFailedJob'])->name('monitoring.failed-jobs.retry');
        Route::delete('monitoring/failed-jobs/{job}', [MonitoringController::class, 'forgetFailedJob'])->name('monitoring.failed-jobs.forget');
    });
