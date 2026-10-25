<?php

namespace App\Modules\SystemAdmin\Http\Controllers;

use App\Modules\SystemAdmin\Services\HealthChecker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Platform health and background jobs. Failed jobs are platform-wide, so these
 * endpoints are for platform administrators.
 *
 * @tags Platform
 */
class MonitoringController extends Controller
{
    /**
     * Public liveness probe (for load balancers). No details are exposed.
     *
     * @unauthenticated
     */
    public function ping(): JsonResponse
    {
        try {
            DB::select('select 1');
            $healthy = true;
        } catch (\Throwable) {
            $healthy = false;
        }

        return response()->json(['status' => $healthy ? 'ok' : 'fail'], $healthy ? 200 : 503);
    }

    /**
     * Detailed health of database, cache, storage, queue and scheduler.
     */
    public function health(HealthChecker $health): JsonResponse
    {
        return response()->json(['data' => $health->run()]);
    }

    /**
     * Pending jobs per queue.
     */
    public function queues(): JsonResponse
    {
        $queues = collect(['default', 'notifications', 'imports', 'ai'])
            ->map(fn (string $queue): array => ['queue' => $queue, 'pending' => Queue::size($queue)])
            ->values();

        return response()->json(['data' => $queues]);
    }

    /**
     * Failed background jobs.
     */
    public function failedJobs(Request $request, FailedJobProviderInterface $failer): JsonResponse
    {
        $jobs = collect($failer->all())
            ->take(min((int) $request->integer('limit', 100), 500))
            ->map(function (\stdClass $job): array {
                $payload = json_decode((string) $job->payload, true);

                return [
                    'id' => (string) ($job->uuid ?? $job->id),
                    'queue' => $job->queue,
                    'job' => $payload['displayName'] ?? null,
                    'exception' => Str::limit(Str::before((string) $job->exception, "\n"), 500),
                    'failed_at' => (string) $job->failed_at,
                ];
            })
            ->values();

        return response()->json(['data' => $jobs]);
    }

    public function retryFailedJob(string $job): JsonResponse
    {
        Artisan::call('queue:retry', ['id' => [$job]]);

        return response()->json(['message' => 'The job was pushed back onto the queue.']);
    }

    public function forgetFailedJob(string $job, FailedJobProviderInterface $failer): JsonResponse
    {
        $failer->forget($job);

        return response()->json(['message' => 'The failed job was removed.']);
    }
}
