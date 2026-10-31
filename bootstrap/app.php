<?php

use App\Modules\Identity\Http\Middleware\EnsureAccountReady;
use App\Modules\SystemAdmin\Services\HealthChecker;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Http\ApiExceptionRenderer;
use App\Shared\Http\Middleware\AssignTraceId;
use App\Shared\Http\Middleware\EnsurePlatformAdmin;
use App\Shared\Http\Middleware\ForceJsonResponse;
use App\Shared\Http\Middleware\ResolveInstitution;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Cache;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            AssignTraceId::class,
            ForceJsonResponse::class,
        ]);

        $middleware->throttleApi('api');

        $middleware->alias([
            'resolve.institution' => ResolveInstitution::class,
            'account.ready' => EnsureAccountReady::class,
            'platform.admin' => EnsurePlatformAdmin::class,
        ]);

        // The institution must be known before route model binding runs,
        // so bound models are resolved inside the right tenant.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: ResolveInstitution::class);

        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/');
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->call(fn () => Cache::forever(HealthChecker::SCHEDULER_HEARTBEAT_KEY, now()->toIso8601String()))
            ->name('scheduler-heartbeat')
            ->everyMinute();

        $schedule->command('campusos:sync-role-assignments')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('campusos:prune-auth-data')->dailyAt('02:00');
        $schedule->command('admissions:expire-offers')->hourly()->withoutOverlapping();
        $schedule->command('sanctum:prune-expired --hours=24')->daily();
        $schedule->command('activitylog:clean')->weekly();
        $schedule->command('queue:prune-failed --hours=720')->daily();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(fn (Throwable $exception, Request $request) => app(ApiExceptionRenderer::class)($exception, $request));

        $exceptions->dontReport([
            BusinessRuleException::class,
        ]);
    })->create();
