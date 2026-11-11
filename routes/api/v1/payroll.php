<?php

use App\Modules\Payroll\Http\Controllers\PayrollController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'resolve.institution', 'account.ready'])->group(function (): void {
    Route::get('pay-components', [PayrollController::class, 'components'])->name('pay-components.index');
    Route::post('pay-components', [PayrollController::class, 'storeComponent'])->name('pay-components.store');
    Route::patch('pay-components/{component}', [PayrollController::class, 'updateComponent'])->name('pay-components.update');

    Route::get('payroll/salaries', [PayrollController::class, 'salaries'])->name('payroll.salaries');
    Route::get('employees/{employee}/salary', [PayrollController::class, 'salary'])->name('employees.salary');
    Route::put('employees/{employee}/salary', [PayrollController::class, 'saveSalary'])->name('employees.salary.save');

    Route::get('payroll-runs', [PayrollController::class, 'runs'])->name('payroll-runs.index');
    Route::post('payroll-runs', [PayrollController::class, 'storeRun'])->name('payroll-runs.store');
    Route::get('payroll-runs/{run}', [PayrollController::class, 'showRun'])->name('payroll-runs.show');
    Route::delete('payroll-runs/{run}', [PayrollController::class, 'destroyRun'])->name('payroll-runs.destroy');
    Route::post('payroll-runs/{run}/{action}', [PayrollController::class, 'transition'])->whereIn('action', ['compute', 'approve', 'finalize'])->name('payroll-runs.transition');

    Route::get('payslips/{payslip}', [PayrollController::class, 'payslipShow'])->name('payslips.show');
    Route::get('payslips/{payslip}/pdf', [PayrollController::class, 'payslipPdf'])->name('payslips.pdf');
    Route::put('payslips/{payslip}/inputs', [PayrollController::class, 'saveInputs'])->name('payslips.inputs');
    Route::get('me/payslips', [PayrollController::class, 'mine'])->name('me.payslips');
});
