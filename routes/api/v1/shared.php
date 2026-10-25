<?php

use App\Shared\Files\FileController;
use App\Shared\Imports\ImportController;
use Illuminate\Support\Facades\Route;

Route::get('files/{file}/download', [FileController::class, 'download'])
    ->middleware('signed')
    ->name('files.download');

Route::middleware(['auth:sanctum', 'resolve.institution:optional', 'account.ready'])->group(function (): void {
    Route::post('files', [FileController::class, 'store'])->middleware('throttle:uploads')->name('files.store');
    Route::get('files/{file}', [FileController::class, 'show'])->name('files.show');
    Route::get('files/{file}/download-url', [FileController::class, 'downloadUrl'])->name('files.download-url');
    Route::delete('files/{file}', [FileController::class, 'destroy'])->name('files.destroy');
});

Route::middleware(['auth:sanctum', 'resolve.institution', 'account.ready'])->group(function (): void {
    Route::get('imports', [ImportController::class, 'index'])->name('imports.index');
    Route::post('imports', [ImportController::class, 'store'])->name('imports.store');
    Route::get('imports/templates/{type}', [ImportController::class, 'template'])->name('imports.template');
    Route::get('imports/{import}', [ImportController::class, 'show'])->name('imports.show');
    Route::post('imports/{import}/commit', [ImportController::class, 'commit'])->name('imports.commit');
    Route::post('imports/{import}/cancel', [ImportController::class, 'cancel'])->name('imports.cancel');
});
