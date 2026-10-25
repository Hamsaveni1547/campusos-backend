<?php

use App\Modules\Communication\Http\Controllers\MyNotificationController;
use App\Modules\Communication\Http\Controllers\NotificationTemplateController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'resolve.institution:optional', 'account.ready'])->prefix('me')->name('me.')->group(function (): void {
    Route::get('notifications', [MyNotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifications/unread-count', [MyNotificationController::class, 'unreadCount'])->name('notifications.unread-count');
    Route::post('notifications/read-all', [MyNotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::post('notifications/{notification}/read', [MyNotificationController::class, 'markRead'])->name('notifications.read');
    Route::delete('notifications/{notification}', [MyNotificationController::class, 'destroy'])->name('notifications.destroy');
    Route::get('notification-preferences', [MyNotificationController::class, 'preferences'])->name('notification-preferences.show');
    Route::put('notification-preferences', [MyNotificationController::class, 'updatePreferences'])->name('notification-preferences.update');
});

Route::middleware(['auth:sanctum', 'resolve.institution', 'account.ready'])->group(function (): void {
    Route::get('notification-templates', [NotificationTemplateController::class, 'index'])->name('notification-templates.index');
    Route::get('notification-templates/{key}', [NotificationTemplateController::class, 'show'])->name('notification-templates.show');
    Route::put('notification-templates/{key}/{channel}', [NotificationTemplateController::class, 'update'])->name('notification-templates.update');
    Route::delete('notification-templates/{key}/{channel}', [NotificationTemplateController::class, 'reset'])->name('notification-templates.reset');
    Route::get('notification-deliveries', [NotificationTemplateController::class, 'deliveries'])->name('notification-deliveries.index');
});
