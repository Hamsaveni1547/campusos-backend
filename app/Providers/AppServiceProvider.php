<?php

namespace App\Providers;

use Dedoc\Scramble\Scramble;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Building the API docs live takes over 30 s and blocks the single-threaded dev server, so by default the
        // docs pages serve the exported file (php artisan scramble:export). Set API_DOCS_LIVE=true to build live.
        if (! config('scramble.live')) {
            Scramble::ignoreDefaultRoutes();
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
