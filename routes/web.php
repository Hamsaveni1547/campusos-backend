<?php

use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
| API docs from the exported file (php artisan scramble:export), unless API_DOCS_LIVE=true.
| Building them live takes over 30 s and blocks every other request on the dev server.
*/
if (! config('scramble.live')) {
    Route::middleware(RestrictedDocsAccess::class)->group(function (): void {
        Route::get('docs/api.json', function () {
            $path = base_path((string) config('scramble.export_path'));
            abort_unless(is_file($path), 404, 'Run "php artisan scramble:export" to build the API docs.');

            return response()->file($path, ['Content-Type' => 'application/json']);
        })->name('docs.api.json');

        Route::get('docs/api', fn () => view('api-docs'))->name('docs.api');
    });
}
