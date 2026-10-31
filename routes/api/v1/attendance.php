<?php

use App\Modules\Attendance\Http\Controllers\AttendanceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'resolve.institution', 'account.ready'])->group(function (): void {
    Route::get('attendance/options', [AttendanceController::class, 'options'])->name('attendance.options');

    // Teachers
    Route::get('me/attendance/pending', [AttendanceController::class, 'pending'])->name('me.attendance.pending');
    Route::get('attendance/sheet', [AttendanceController::class, 'sheet'])->name('attendance.sheet');
    Route::post('attendance/sheet', [AttendanceController::class, 'mark'])->name('attendance.mark');
    Route::get('attendance/classes/{class}/summary', [AttendanceController::class, 'classSummary'])->name('attendance.class-summary');

    // Monitoring
    Route::get('attendance/shortage', [AttendanceController::class, 'shortage'])->name('attendance.shortage');
    Route::post('attendance/condonations', [AttendanceController::class, 'condone'])->name('attendance.condonations.store');
    Route::get('attendance-corrections', [AttendanceController::class, 'corrections'])->name('attendance-corrections.index');
    Route::post('attendance-corrections', [AttendanceController::class, 'requestCorrection'])->name('attendance-corrections.store');
    Route::post('attendance-corrections/{correction}/decide', [AttendanceController::class, 'decideCorrection'])->name('attendance-corrections.decide');

    // Students
    Route::get('me/attendance', [AttendanceController::class, 'mine'])->name('me.attendance');
    Route::get('me/attendance/{class}', [AttendanceController::class, 'mineForClass'])->name('me.attendance.class');
});
