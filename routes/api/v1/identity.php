<?php

use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\MeController;
use App\Modules\Identity\Http\Controllers\MfaController;
use App\Modules\Identity\Http\Controllers\RoleAssignmentController;
use App\Modules\Identity\Http\Controllers\RoleController;
use App\Modules\Identity\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
| Sign-in and account recovery (public).
*/
Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::middleware('throttle:auth')->group(function (): void {
        Route::post('login', [AuthController::class, 'login'])->name('login');
        Route::post('register', [AuthController::class, 'register'])->name('register');
        Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->name('forgot-password');
        Route::post('reset-password', [AuthController::class, 'resetPassword'])->name('reset-password');
        Route::post('setup-account', [AuthController::class, 'setupAccount'])->name('setup-account');
    });

    Route::post('mfa/verify', [AuthController::class, 'verifyMfa'])->middleware('throttle:mfa')->name('mfa.verify');
    Route::post('mfa/send-code', [AuthController::class, 'sendMfaCode'])->middleware('throttle:otp')->name('mfa.send-code');

    Route::middleware(['auth:sanctum', 'resolve.institution:optional'])->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::post('verify-email', [AuthController::class, 'verifyEmail'])->middleware('throttle:auth')->name('verify-email');
        Route::post('verify-email/resend', [AuthController::class, 'resendEmailVerification'])->middleware('throttle:otp')->name('verify-email.resend');
    });
});

/*
| Account endpoints that stay available while the account still has a pending
| requirement (password change, MFA enrolment), so the user can fix it.
*/
Route::middleware(['auth:sanctum', 'resolve.institution:optional'])->prefix('me')->name('me.')->group(function (): void {
    Route::get('/', [MeController::class, 'show'])->name('show');
    Route::put('password', [MeController::class, 'changePassword'])->middleware('throttle:auth')->name('password');
    Route::get('institutions', [MeController::class, 'institutions'])->name('institutions');

    Route::prefix('mfa')->name('mfa.')->group(function (): void {
        Route::get('/', [MfaController::class, 'index'])->name('index');
        Route::post('totp', [MfaController::class, 'startTotp'])->name('totp.start');
        Route::post('totp/confirm', [MfaController::class, 'confirmTotp'])->middleware('throttle:auth')->name('totp.confirm');
        Route::post('code-method', [MfaController::class, 'startCodeMethod'])->middleware('throttle:otp')->name('code.start');
        Route::post('code-method/confirm', [MfaController::class, 'confirmCodeMethod'])->middleware('throttle:auth')->name('code.confirm');
        Route::post('recovery-codes', [MfaController::class, 'regenerateRecoveryCodes'])->name('recovery-codes');
        Route::post('{method}/primary', [MfaController::class, 'makePrimary'])->name('primary');
        Route::delete('{method}', [MfaController::class, 'destroy'])->name('destroy');
    });
});

Route::middleware(['auth:sanctum', 'resolve.institution:optional', 'account.ready'])->prefix('me')->name('me.')->group(function (): void {
    Route::put('/', [MeController::class, 'update'])->name('update');
    Route::put('default-institution', [MeController::class, 'setDefaultInstitution'])->name('default-institution');
    Route::get('sessions', [MeController::class, 'sessions'])->name('sessions');
    Route::delete('sessions', [MeController::class, 'revokeOtherSessions'])->name('sessions.revoke-others');
    Route::delete('sessions/{session}', [MeController::class, 'revokeSession'])->name('sessions.revoke');
    Route::get('trusted-devices', [MeController::class, 'trustedDevices'])->name('trusted-devices');
    Route::delete('trusted-devices/{device}', [MeController::class, 'revokeTrustedDevice'])->name('trusted-devices.revoke');
    Route::get('security-events', [MeController::class, 'securityEvents'])->name('security-events');
});

/*
| Institution administration of users, roles and permissions.
*/
Route::middleware(['auth:sanctum', 'resolve.institution', 'account.ready'])->group(function (): void {
    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::post('users', [UserController::class, 'store'])->name('users.store');
    Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');
    Route::patch('users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::post('users/{user}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');
    Route::post('users/{user}/activate', [UserController::class, 'activate'])->name('users.activate');
    Route::post('users/{user}/unlock', [UserController::class, 'unlock'])->name('users.unlock');
    Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
    Route::post('users/{user}/reset-mfa', [UserController::class, 'resetMfa'])->name('users.reset-mfa');
    Route::post('users/{user}/resend-invitation', [UserController::class, 'resendInvitation'])->name('users.resend-invitation');
    Route::get('users/{user}/security-events', [UserController::class, 'securityEvents'])->name('users.security-events');
    Route::post('users/{user}/role-assignments', [RoleAssignmentController::class, 'store'])->name('users.role-assignments.store');

    Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
    Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
    Route::get('roles/{role}', [RoleController::class, 'show'])->name('roles.show');
    Route::patch('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
    Route::delete('roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
    Route::get('permissions', [RoleController::class, 'permissions'])->name('permissions.index');

    Route::get('role-assignments', [RoleAssignmentController::class, 'index'])->name('role-assignments.index');
    Route::delete('role-assignments/{assignment}', [RoleAssignmentController::class, 'destroy'])->name('role-assignments.destroy');
});
