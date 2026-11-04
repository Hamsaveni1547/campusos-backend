<?php

use App\Modules\Assets\Http\Controllers\AssetController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'resolve.institution', 'account.ready'])->group(function (): void {
    Route::get('assets', [AssetController::class, 'index'])->name('assets.index');
    Route::get('assets/summary', [AssetController::class, 'summary'])->name('assets.summary');
    Route::post('assets', [AssetController::class, 'store'])->name('assets.store');
    Route::get('assets/{asset}', [AssetController::class, 'show'])->name('assets.show');
    Route::patch('assets/{asset}', [AssetController::class, 'update'])->name('assets.update');

    Route::get('maintenance-requests', [AssetController::class, 'requests'])->name('maintenance-requests.index');
    Route::post('maintenance-requests', [AssetController::class, 'raise'])->name('maintenance-requests.store');
    Route::patch('maintenance-requests/{maintenance}', [AssetController::class, 'updateRequest'])->name('maintenance-requests.update');

    Route::get('room-bookings', [AssetController::class, 'bookings'])->name('room-bookings.index');
    Route::post('room-bookings', [AssetController::class, 'book'])->name('room-bookings.store');
    Route::post('room-bookings/{booking}/cancel', [AssetController::class, 'cancelBooking'])->name('room-bookings.cancel');
});
