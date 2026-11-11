<?php

use App\Modules\Finance\Http\Controllers\FinanceController;
use App\Modules\Finance\Http\Controllers\OnlinePaymentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'resolve.institution', 'account.ready'])->group(function (): void {
    Route::get('finance/options', [FinanceController::class, 'options'])->name('finance.options');

    Route::get('fee-heads', [FinanceController::class, 'heads'])->name('fee-heads.index');
    Route::post('fee-heads', [FinanceController::class, 'storeHead'])->name('fee-heads.store');
    Route::patch('fee-heads/{head}', [FinanceController::class, 'updateHead'])->name('fee-heads.update');

    Route::get('fee-structures', [FinanceController::class, 'structures'])->name('fee-structures.index');
    Route::post('fee-structures', [FinanceController::class, 'storeStructure'])->name('fee-structures.store');
    Route::patch('fee-structures/{structure}', [FinanceController::class, 'updateStructure'])->name('fee-structures.update');
    Route::post('fee-structures/{structure}/generate', [FinanceController::class, 'generate'])->name('fee-structures.generate');

    Route::get('fee-demands', [FinanceController::class, 'demands'])->name('fee-demands.index');
    Route::post('fee-demands', [FinanceController::class, 'raise'])->name('fee-demands.store');
    Route::post('fee-demands/{demand}/cancel', [FinanceController::class, 'cancelDemand'])->name('fee-demands.cancel');
    Route::post('fee-demands/{demand}/concessions', [FinanceController::class, 'requestConcession'])->name('fee-concessions.store');

    Route::get('fee-concessions', [FinanceController::class, 'concessions'])->name('fee-concessions.index');
    Route::post('fee-concessions/{concession}/decide', [FinanceController::class, 'decideConcession'])->name('fee-concessions.decide');

    Route::get('fee-payments', [FinanceController::class, 'payments'])->name('fee-payments.index');
    Route::post('fee-payments', [FinanceController::class, 'collect'])->name('fee-payments.store');
    Route::get('fee-payments/{payment}', [FinanceController::class, 'receiptShow'])->name('fee-payments.show');
    Route::get('fee-payments/{payment}/pdf', [FinanceController::class, 'receiptPdf'])->name('fee-payments.pdf');
    Route::post('fee-payments/{payment}/cancel', [FinanceController::class, 'cancelPayment'])->name('fee-payments.cancel');

    Route::get('students/{student}/fee-account', [FinanceController::class, 'account'])->name('students.fee-account');
    Route::get('students/{student}/no-dues', [FinanceController::class, 'noDues'])->name('students.no-dues');
    Route::get('finance/reports/collections', [FinanceController::class, 'collections'])->name('finance.reports.collections');
    Route::get('finance/reports/dues', [FinanceController::class, 'dues'])->name('finance.reports.dues');

    Route::get('me/fees', [FinanceController::class, 'mine'])->name('me.fees');
    Route::get('me/online-payments', [OnlinePaymentController::class, 'index'])->name('me.online-payments.index');
    Route::post('me/online-payments', [OnlinePaymentController::class, 'start'])->name('me.online-payments.start');
    Route::post('me/online-payments/{onlinePayment}/complete', [OnlinePaymentController::class, 'complete'])->name('me.online-payments.complete');
});
