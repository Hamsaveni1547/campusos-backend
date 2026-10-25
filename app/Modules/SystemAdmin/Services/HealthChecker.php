<?php

namespace App\Modules\SystemAdmin\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Checks the services CampusOS depends on.
 */
class HealthChecker
{
    public const SCHEDULER_HEARTBEAT_KEY = 'system:scheduler:heartbeat';

    /**
     * @return array{status: string, checks: array<string, array{status: string, message: string|null, latency_ms: float|null}>}
     */
    public function run(): array
    {
        $checks = [
            'database' => $this->check(fn () => DB::select('select 1')),
            'cache' => $this->check(function (): void {
                $key = 'health:'.Str::random(8);
                Cache::put($key, 'ok', 10);
                if (Cache::pull($key) !== 'ok') {
                    throw new \RuntimeException('Cache read-back failed.');
                }
            }),
            'storage' => $this->check(function (): void {
                $disk = Storage::disk((string) config('campusos.files.disk'));
                $path = 'health/'.Str::random(8).'.txt';
                $disk->put($path, 'ok');
                $disk->delete($path);
            }),
            'queue' => $this->check(fn () => Queue::size()),
            'scheduler' => $this->schedulerCheck(),
        ];

        $failed = collect($checks)->contains(fn (array $check): bool => $check['status'] === 'fail');
        $degraded = collect($checks)->contains(fn (array $check): bool => $check['status'] === 'warn');

        return ['status' => $failed ? 'fail' : ($degraded ? 'degraded' : 'ok'), 'checks' => $checks];
    }

    /**
     * @return array{status: string, message: string|null, latency_ms: float|null}
     */
    private function check(callable $probe): array
    {
        $start = microtime(true);

        try {
            $probe();

            return ['status' => 'ok', 'message' => null, 'latency_ms' => round((microtime(true) - $start) * 1000, 2)];
        } catch (Throwable $exception) {
            report($exception);

            return ['status' => 'fail', 'message' => class_basename($exception).': '.Str::limit($exception->getMessage(), 200), 'latency_ms' => null];
        }
    }

    /**
     * @return array{status: string, message: string|null, latency_ms: float|null}
     */
    private function schedulerCheck(): array
    {
        $last = Cache::get(self::SCHEDULER_HEARTBEAT_KEY);

        if ($last === null) {
            return ['status' => 'warn', 'message' => 'The scheduler has not reported yet. Is `php artisan schedule:work` (or cron) running?', 'latency_ms' => null];
        }

        $age = now()->diffInMinutes(Carbon::parse($last), true);

        return $age > 5
            ? ['status' => 'warn', 'message' => "Last scheduler run was {$age} minutes ago.", 'latency_ms' => null]
            : ['status' => 'ok', 'message' => 'Last run '.Carbon::parse($last)->toIso8601String(), 'latency_ms' => null];
    }
}
