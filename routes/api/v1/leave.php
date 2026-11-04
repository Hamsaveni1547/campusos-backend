<?php

use App\Modules\Leave\Http\Controllers\LeaveController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'resolve.institution', 'account.ready'])->group(function (): void {
    Route::get('leave-types', [LeaveController::class, 'types'])->name('leave-types.index');
    Route::post('leave-types', [LeaveController::class, 'storeType'])->name('leave-types.store');
    Route::patch('leave-types/{type}', [LeaveController::class, 'updateType'])->name('leave-types.update');

    Route::post('leave-balances/allot', [LeaveController::class, 'allot'])->name('leave-balances.allot');
    Route::get('employees/{employee}/leave-balances', [LeaveController::class, 'employeeBalances'])->name('employees.leave-balances');
    Route::put('employees/{employee}/leave-balances', [LeaveController::class, 'adjust'])->name('employees.leave-balances.adjust');

    Route::get('leave-applications', [LeaveController::class, 'index'])->name('leave-applications.index');
    Route::post('leave-applications/{application}/decide', [LeaveController::class, 'decide'])->name('leave-applications.decide');

    Route::get('me/leave', [LeaveController::class, 'mine'])->name('me.leave');
    Route::post('me/leave-applications', [LeaveController::class, 'apply'])->name('me.leave-applications.store');
    Route::post('me/leave-applications/{application}/cancel', [LeaveController::class, 'cancel'])->name('me.leave-applications.cancel');
});
