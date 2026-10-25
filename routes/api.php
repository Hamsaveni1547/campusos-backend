<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| CampusOS API
|--------------------------------------------------------------------------
|
| Every route is versioned under /api/v1. Module route files live in
| routes/api/v1/{module}.php.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    require __DIR__.'/api/v1/identity.php';
    require __DIR__.'/api/v1/institution.php';
    require __DIR__.'/api/v1/communication.php';
    require __DIR__.'/api/v1/system.php';
    require __DIR__.'/api/v1/shared.php';
});
