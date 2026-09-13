<?php

namespace App\Http\Controllers;

use App\Enums\Platform;
use App\Models\ChannelAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Staging health check for Cloudways (spec §11.4 / live-test phase-1 task 4).
 *
 * Deliberately outside auth and stateless: `GET /up/crm` with the correct
 * `X-Health-Token` header. A missing/wrong token — or an empty
 * `crm.health.token` config value — returns a plain 404 so the endpoint is
 * not discoverable, rather than a 401 that would confirm it exists.
 *
 * Every sub-check is defensive (never throws): this route must stay usable
 * even when Redis is unreachable, Reverb is down, or the Shopify integration
 * table/model doesn't exist yet (it is being built by a concurrent task).
 */
class HealthController extends Controller
{
    private const QUEUES = ['outbound', 'webhooks', 'bot', 'commerce', 'default', 'analytics'];

    /** Fully-qualified class name as a string so a missing class never breaks autoloading/parsing. */
    private const SHOPIFY_INTEGRATION_CLASS = 'App\\Shopify\\Connection\\ShopifyIntegration';

    public function __invoke(Request $request): JsonResponse
    {
        $token = (string) config('crm.health.token');

        if ($token === '' || ! hash_equals($token, (string) $request->header('X-Health-Token'))) {
            abort(404);
        }

        $queues = $this->queueSizes();

        return response()->json([
            'db' => $this->dbStatus(),
            'redis' => $this->redisStatus(),
            'queues' => $queues['sizes'],
            'queues_error' => $queues['error'],
            'oldest_job_seconds' => $this->oldestJobSeconds(),
            'reverb' => $this->reverbStatus(),
            'scheduler_last_run' => Cache::get('crm:scheduler_heartbeat'),
            'shopify' => $this->shopifyStatus(),
            'channels' => $this->channelStatuses(),
        ]);
    }

    private function dbStatus(): string
    {
        try {
            DB::select('select 1');

            return 'ok';
        } catch (Throwable) {
            return 'error';
        }
    }

    private function redisStatus(): string
    {
        try {
            $host = config('database.redis.default.host');

            if (empty($host)) {
                return 'n/a';
            }

            Redis::connection()->ping();

            return 'ok';
        } catch (Throwable) {
            return 'n/a';
        }
    }

    /**
     * @return array{sizes: array<string, int|null>, error: bool}
     *
     * A queue whose size can't be read reports `null` (never `0` — that would look empty)
     * and flips the top-level `queues_error` flag so a monitor can tell "empty" from "unknown".
     */
    private function queueSizes(): array
    {
        $sizes = [];
        $error = false;

        foreach (self::QUEUES as $queue) {
            try {
                $sizes[$queue] = Queue::size($queue);
            } catch (Throwable) {
                $sizes[$queue] = null;
                $error = true;
            }
        }

        return ['sizes' => $sizes, 'error' => $error];
    }

    /**
     * Age (seconds) of the oldest pending job across the monitored queues.
     * - `database` driver: `min(created_at)` from the jobs table.
     * - `redis` driver: max age across queues from each list's head payload `pushedAt`
     *   (null if no payload carries `pushedAt` or Redis isn't reachable).
     * - any other driver: `0` (unchanged/no introspection available).
     */
    private function oldestJobSeconds(): ?int
    {
        $driver = config('queue.default');

        if ($driver === 'database') {
            try {
                $table = config('queue.connections.database.table', 'jobs');

                $oldest = DB::table($table)->whereIn('queue', self::QUEUES)->min('created_at');

                if (! $oldest) {
                    return 0;
                }

                return max(0, now()->getTimestamp() - (int) $oldest);
            } catch (Throwable) {
                return 0;
            }
        }

        if ($driver === 'redis') {
            return $this->oldestJobSecondsRedis();
        }

        return 0;
    }

    private function oldestJobSecondsRedis(): ?int
    {
        $connectionName = config('queue.connections.redis.connection', 'default');
        $maxAge = null;

        foreach (self::QUEUES as $queue) {
            try {
                $payload = Redis::connection($connectionName)->lindex('queues:'.$queue, 0);

                if (! $payload) {
                    continue;
                }

                $decoded = json_decode($payload, true);

                if (! is_array($decoded) || ! isset($decoded['pushedAt'])) {
                    continue;
                }

                $age = (int) round(max(0, microtime(true) - (float) $decoded['pushedAt']));
                $maxAge = $maxAge === null ? $age : max($maxAge, $age);
            } catch (Throwable) {
                // Unreachable/unreadable for this queue — try the others; null if none work.
            }
        }

        return $maxAge;
    }

    private function reverbStatus(): string
    {
        $host = config('reverb.servers.reverb.host');

        if (empty($host) || $host === '0.0.0.0') {
            $host = '127.0.0.1';
        }

        $port = (int) (config('reverb.servers.reverb.port') ?: 8080);

        $connection = @fsockopen($host, $port, $errno, $errstr, 0.3);

        if ($connection) {
            fclose($connection);

            return 'ok';
        }

        return 'down';
    }

    /** connected|error|disconnected|none — `none` when the (concurrently-built) Shopify integration isn't present yet. */
    private function shopifyStatus(): string
    {
        $class = self::SHOPIFY_INTEGRATION_CLASS;

        if (! Schema::hasTable('shopify_integrations') || ! class_exists($class)) {
            return 'none';
        }

        try {
            $integration = $class::query()->first();

            return $integration?->status ?? 'disconnected';
        } catch (Throwable) {
            return 'error';
        }
    }

    /** @return array<string, string> */
    private function channelStatuses(): array
    {
        $statuses = [];

        try {
            foreach (ChannelAccount::query()->get(['platform', 'status']) as $account) {
                $platform = $account->platform instanceof Platform ? $account->platform->value : (string) $account->platform;
                $statuses[$platform] = $account->status;
            }
        } catch (Throwable) {
            // Leave whatever was collected before the failure; never throw from health.
        }

        return $statuses;
    }
}
