<?php

namespace App\Analytics\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Generates sustained signed-webhook traffic against a staging deployment so
 * crm:latency-report has real p95 numbers to grade (spec §11.3: 10 events/s
 * for 10 minutes, no growing backlog, no failed jobs).
 */
class LoadTestCommand extends Command
{
    protected $signature = 'crm:loadtest
        {--url= : Base URL of the target deployment, e.g. https://staging.example.com}
        {--platform=facebook : Platform slug the webhook route expects, e.g. facebook|instagram|whatsapp}
        {--rate=10 : Events per second to send}
        {--duration=600 : How many seconds to run}
        {--secret-env=META_APP_SECRET : Env var name holding the Meta app secret used to sign requests}
        {--backlog-url= : Optional URL returning {"backlog": n} instead of reading the local queue}
        {--force : Allow running against APP_ENV=production}
        {--search-token= : Sanctum token used for /api/v1/search requests}
        {--search-rate=0 : Search requests per second (0 = off)}
        {--search-queries=فستان,01001234567,#1001 : Comma separated search terms, rotated}';

    protected $description = 'Send signed Meta-format webhook traffic at a fixed rate to load-test a staging deployment';

    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('Refusing to run crm:loadtest against production. Pass --force to override.');

            return self::FAILURE;
        }

        $url = rtrim((string) $this->option('url'), '/');

        if ($url === '') {
            $this->error('--url is required.');

            return self::FAILURE;
        }

        $platform = (string) $this->option('platform');
        $rate = max(1, (int) $this->option('rate'));
        $duration = max(1, (int) $this->option('duration'));
        $secret = $this->resolveSecret((string) $this->option('secret-env'));
        $endpoint = "{$url}/webhooks/{$platform}";

        $searchRate = max(0, (int) $this->option('search-rate'));
        $searchToken = (string) $this->option('search-token');
        $searchQueries = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('search-queries')))));

        if ($searchRate > 0 && $searchToken === '') {
            $this->error('--search-token is required when --search-rate is greater than 0.');

            return self::FAILURE;
        }

        if ($searchRate > 1) {
            $this->warn(
                "--search-rate={$searchRate} exceeds 1 request/s for a single token — ".
                'the /search endpoints are throttled to 60/min per user, so sustained runs above 1/s '.
                'will start hitting 429s and understate the real p95. Use --search-rate=1, or run '.
                'several instances with different --search-token values and sum the results.'
            );
        }

        /** @var callable(int):void $sleeper */
        $sleeper = app('crm.loadtest.sleeper');

        $this->info("crm:loadtest -> {$endpoint} at {$rate}/s for {$duration}s");

        $totalSent = 0;
        $total2xx = 0;
        $totalNon2xx = 0;
        $minuteSent = 0;
        $minute2xx = 0;
        $minuteNon2xx = 0;
        $searchDurations = [];
        $searchSent = 0;
        $searchOk = 0;
        $searchBad = 0;

        for ($second = 0; $second < $duration; $second++) {
            $batchStartedAt = microtime(true);

            [$sent, $ok, $bad] = $this->sendBatch($endpoint, $platform, $secret, $rate, $second);

            $totalSent += $sent;
            $total2xx += $ok;
            $totalNon2xx += $bad;
            $minuteSent += $sent;
            $minute2xx += $ok;
            $minuteNon2xx += $bad;

            if ($searchRate > 0) {
                $searchBatch = $this->searchBatch($url, $searchToken, $searchQueries, $searchRate, $second);
                // Only successful (2xx) requests count toward the p95 — a 429/500 has no
                // meaningful "response time" to grade against the 500ms target.
                $searchDurations = array_merge($searchDurations, $searchBatch['durations']);
                $searchSent += $searchBatch['sent'];
                $searchOk += $searchBatch['ok'];
                $searchBad += $searchBatch['bad'];
            }

            $isLast = $second === $duration - 1;
            $isMinuteBoundary = ($second + 1) % 60 === 0;

            if ($isMinuteBoundary || $isLast) {
                $minuteNumber = intdiv($second, 60) + 1;
                $backlog = $this->backlogSize();
                $backlogNote = $backlog === null ? '' : " backlog={$backlog}";

                $this->info("[minute {$minuteNumber}] sent={$minuteSent} 2xx={$minute2xx} non2xx={$minuteNon2xx}{$backlogNote}");

                $minuteSent = 0;
                $minute2xx = 0;
                $minuteNon2xx = 0;
            }

            if (! $isLast) {
                // Pace to a real 1 request/second rate per unit of `rate`: sleep only the
                // remainder of the second, not a flat 1s, so a slow batch (network latency,
                // a large --rate) doesn't push the sustained rate below what was asked for.
                $elapsedMicros = (int) round((microtime(true) - $batchStartedAt) * 1_000_000);
                $sleeper(max(0, 1_000_000 - $elapsedMicros));
            }
        }

        $this->info("Done. total sent={$totalSent} 2xx={$total2xx} non2xx={$totalNon2xx}");

        if ($searchRate > 0) {
            sort($searchDurations);
            $p95 = $searchDurations === [] ? 0 : $searchDurations[(int) max(0, ceil(count($searchDurations) * 0.95) - 1)];
            $this->info(sprintf('[search] requests=%d 2xx=%d non2xx=%d p95=%dms target=500ms', $searchSent, $searchOk, $searchBad, $p95));
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $queries
     * @return array{durations: list<int>, sent: int, ok: int, bad: int} durations in ms, 2xx responses only
     */
    private function searchBatch(string $url, string $token, array $queries, int $rate, int $second): array
    {
        if ($queries === []) {
            return ['durations' => [], 'sent' => 0, 'ok' => 0, 'bad' => 0];
        }

        $started = [];
        $responses = Http::pool(function (Pool $pool) use ($url, $token, $queries, $rate, $second, &$started) {
            $requests = [];

            for ($i = 0; $i < $rate; $i++) {
                $q = $queries[($second * $rate + $i) % count($queries)];
                $started[$i] = microtime(true);
                $requests[] = $pool->withToken($token)->acceptJson()->get("{$url}/api/v1/search", ['q' => $q]);
            }

            return $requests;
        });

        $now = microtime(true);
        $durations = [];
        $ok = 0;
        $bad = 0;

        foreach ($responses as $i => $response) {
            $successful = ! ($response instanceof Throwable) && method_exists($response, 'successful') && $response->successful();

            if ($successful) {
                $ok++;
                $durations[] = (int) round(($now - $started[$i]) * 1000);
            } else {
                $bad++;
            }
        }

        return ['durations' => $durations, 'sent' => count($responses), 'ok' => $ok, 'bad' => $bad];
    }

    /**
     * @return array{0:int,1:int,2:int} [sent, ok, bad]
     */
    private function sendBatch(string $endpoint, string $platform, ?string $secret, int $rate, int $second): array
    {
        $responses = Http::pool(function (Pool $pool) use ($endpoint, $secret, $rate, $second) {
            $requests = [];

            for ($i = 0; $i < $rate; $i++) {
                $n = $second * $rate + $i;
                $body = json_encode($this->buildPayload($n));
                $signature = 'sha256='.hash_hmac('sha256', $body, (string) $secret);

                $requests[] = $pool->withHeaders([
                    'X-Hub-Signature-256' => $signature,
                    'Content-Type' => 'application/json',
                ])->withBody($body, 'application/json')->post($endpoint);
            }

            return $requests;
        });

        $sent = count($responses);
        $ok = 0;
        $bad = 0;

        foreach ($responses as $response) {
            if ($response instanceof Throwable) {
                $bad++;

                continue;
            }

            if (method_exists($response, 'successful') && $response->successful()) {
                $ok++;
            } else {
                $bad++;
            }
        }

        return [$sent, $ok, $bad];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(int $n): array
    {
        $customerId = 'loadtest-'.($n % 2000);
        $messageId = 'loadtest-msg-'.$n;
        $nowMs = (int) round(microtime(true) * 1000);

        return [
            'object' => 'page',
            'entry' => [[
                'id' => 'loadtest-page',
                'time' => $nowMs,
                'messaging' => [[
                    'sender' => ['id' => $customerId],
                    'recipient' => ['id' => 'loadtest-page'],
                    'timestamp' => $nowMs,
                    'message' => ['mid' => $messageId, 'text' => "Load test message {$n}"],
                ]],
            ]],
        ];
    }

    private function resolveSecret(string $envName): ?string
    {
        $secret = env($envName) ?: config('crm.meta.app_secret');

        if (! $secret) {
            $this->warn("No Meta app secret found in env [{$envName}] or crm.meta.app_secret; signatures will not verify.");
        }

        return $secret;
    }

    private function backlogSize(): ?int
    {
        $backlogUrl = $this->option('backlog-url');

        if ($backlogUrl) {
            try {
                $response = Http::timeout(5)->get((string) $backlogUrl);

                return (int) ($response->json('backlog') ?? $response->body());
            } catch (Throwable) {
                return null;
            }
        }

        try {
            return (int) Queue::connection()->size('webhooks');
        } catch (Throwable) {
            return null;
        }
    }
}
