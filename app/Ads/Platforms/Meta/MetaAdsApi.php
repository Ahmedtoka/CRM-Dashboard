<?php

namespace App\Ads\Platforms\Meta;

use App\Ads\Platforms\AdsApiException;
use App\Ads\Platforms\MissingPermission;
use App\Ads\Platforms\PlatformUnreachable;
use App\Ads\Platforms\RateLimited;
use App\Ads\Platforms\SecretScrubber;
use App\Ads\Platforms\TokenInvalid;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin Graph API client: URL building, paging, error mapping, quota guard. Never sleeps.
 *
 * Quota guard: a READ answered 2xx with usage above the limit keeps its rows and stops paging (stoppedAt); sync runs
 * are admitted once before their first read (admit). A WRITE answered 2xx already changed
 * the platform (an ad paused, a chunk accepted, an ad created), so it never throws: the high usage is logged and
 * remembered per token, and the writer's next operation backs off before sending (backOffIfBusy).
 */
class MetaAdsApi
{
    private const HOST = 'https://graph.facebook.com';

    /** Meta error codes that mean "slow down" (80000-80014: business use case limits, e.g. 80004 ads management). */
    private const RATE_CODES = [4, 17, 32, 613, 80000, 80001, 80002, 80003, 80004, 80005, 80006, 80008, 80009, 80014];

    /** Meta error codes for a missing permission: 200 (permission), 10 (application permission), 294 (managing ads). */
    private const PERMISSION_CODES = [200, 10, 294];

    /** error_subcode values that mean the token itself is dead (expired 463, password changed 460, ...). */
    private const TOKEN_SUBCODES = [458, 460, 463, 467];

    /** A page Meta calls too large is asked again with half the `limit`, down to this. */
    private const MIN_LIMIT = 5;

    public const USAGE_LIMIT = 85;

    /** Usage % above the limit reported by the last 2xx read, else null. */
    private ?int $lastReadPct = null;

    private ?int $stoppedAt = null;

    public function version(): string
    {
        $v = trim((string) config('crm.ads.meta.graph_version', 'v23.0')) ?: 'v23.0';

        return str_starts_with($v, 'v') ? $v : 'v'.$v;
    }

    public function url(string $path = ''): string
    {
        return self::HOST.'/'.$this->version().'/'.ltrim($path, '/');
    }

    /**
     * GET one page.
     *
     * @param  int|null  $timeout  seconds; null = the default 90
     * @return array<string, mixed>
     */
    public function get(string $token, string $path, array $query = [], ?int $timeout = null): array
    {
        return $this->handle(fn () => Http::withToken($token)->timeout($timeout ?? 90)->connectTimeout(min(15, $timeout ?? 15))
            ->get($this->url($path), $query), null, $path);
    }

    /**
     * POST form fields (a write: never throws after a 2xx).
     *
     * @param  int|null  $timeout  seconds; null = the default 90
     * @return array<string, mixed>
     */
    public function post(string $token, string $path, array $data = [], ?int $timeout = null): array
    {
        return $this->handle(fn () => Http::withToken($token)->timeout($timeout ?? 90)->connectTimeout(min(15, $timeout ?? 15))
            ->asForm()->post($this->url($path), $data), $token, $path);
    }

    /**
     * POST multipart: plain fields plus one file part (a chunk or a whole small file).
     *
     * @return array<string, mixed>
     */
    public function postMultipart(string $token, string $path, array $fields, string $fileField, string $contents, string $filename): array
    {
        return $this->handle(fn () => Http::withToken($token)->timeout(300)->connectTimeout(15)
            ->attach($fileField, $contents, $filename)->post($this->url($path), $fields), $token, $path);
    }

    /**
     * Quota admission (A5), called ONCE per sync run (per backfill chunk) before its first read, never by a write:
     * while the busiest usage Meta reported for this ad account in the last 15 minutes is at or above
     * crm.ads.sync.admission_pct, RateLimited says when to try again: the latest of 5 minutes, Meta's regain time, and
     * the moment that reading leaves the 15-minute window. The later reads of the run rely on the response headers.
     */
    public function admit(?string $actExternalId): void
    {
        if ($actExternalId === null || ! config('crm.ads.sync.admission_enabled', true)) {
            return;
        }
        $recorder = app(UsageRecorder::class);
        $accountId = $recorder->accountIdFor($actExternalId);
        if ($accountId === null) {
            return;
        }
        $busiest = $recorder->busiest($accountId, 15);
        $limit = (float) config('crm.ads.sync.admission_pct', 75);
        if ($busiest === null || $busiest['max_pct'] < $limit) {
            return;
        }
        $leavesWindow = (int) ceil($busiest['recorded_at']->addMinutes(15)->getTimestamp() - now()->getTimestamp());
        $wait = max(300, $busiest['regain_minutes'] * 60, $leavesWindow);
        throw new RateLimited(sprintf('Sync deferred by Meta quota: usage was %d%% in the last 15 minutes; retry in %d min.',
            (int) $busiest['max_pct'], (int) ceil($wait / 60)), $wait);
    }

    /** Usage % at which the last paginate() stopped early (rows kept), or null when it read every page. */
    public function stoppedAt(): ?int
    {
        return $this->stoppedAt;
    }

    /**
     * Throws RateLimited (nothing sent) while a recent write reported usage above the limit for this token.
     * Writers call it at the START of an operation, never between the steps of one.
     */
    public function backOffIfBusy(string $token): void
    {
        $busy = Cache::get($this->busyKey($token));
        if ($busy !== null) {
            throw new RateLimited('Meta usage was at '.(int) $busy.'% after the last change; retry later.');
        }
    }

    /**
     * GET and follow paging.next.
     *
     * @return list<array<string, mixed>> the merged data[] rows
     */
    public function paginate(string $token, string $path, array $query = [], int $maxPages = 200): array
    {
        $this->stoppedAt = null;
        $rows = [];
        $limit = (int) ($query['limit'] ?? 0);
        $page = $this->shrinking(fn (int $l) => $this->get($token, $path, $l > 0 ? ['limit' => $l] + $query : $query), $limit);
        $pages = 1;

        while (true) {
            $rows = array_merge($rows, $page['data'] ?? []);
            $next = $page['paging']['next'] ?? null;
            if (! $next) {
                return $rows;
            }
            if ($this->lastReadPct !== null) {
                // The page Meta just answered says usage is above the limit: keep what was read, ask for no more.
                $this->stoppedAt = $this->lastReadPct;

                return $rows;
            }
            if ($pages++ >= $maxPages) {
                throw new AdsApiException('Meta result too large - narrow the date range.');
            }
            $next = $this->stripToken((string) $next);
            $page = $this->shrinking(fn (int $l) => $this->handle(fn () => Http::withToken($token)->timeout(90)->connectTimeout(15)
                ->get($l > 0 ? $this->withLimit($next, $l) : $next), null, $path), $limit);
        }
    }

    /**
     * Big accounts (many ads with full creative specs, many ad-days) can make Meta refuse a page as too
     * large. The same page is asked again with half the limit, which then stays for the next pages.
     *
     * @param  callable(int): array<string, mixed>  $fetch
     */
    private function shrinking(callable $fetch, int &$limit): array
    {
        while (true) {
            try {
                return $fetch($limit);
            } catch (TooMuchData $e) {
                if ($limit <= self::MIN_LIMIT) {
                    throw $e;
                }
                $limit = max(self::MIN_LIMIT, intdiv($limit, 2));
            }
        }
    }

    private function withLimit(string $url, int $limit): string
    {
        return preg_match('/([?&])limit=\d+/', $url)
            ? (string) preg_replace('/([?&])limit=\d+/', '${1}limit='.$limit, $url)
            : $url.(str_contains($url, '?') ? '&' : '?').'limit='.$limit;
    }

    /**
     * Graph batch call, at most 50 sub-requests per HTTP call.
     *
     * @param  list<string>  $relativeUrls
     * @return array<int, array{code: int, body: array<string, mixed>}> keyed like $relativeUrls
     */
    public function batch(string $token, array $relativeUrls): array
    {
        $out = [];
        foreach (array_chunk(array_values($relativeUrls), 50, true) as $chunk) {
            $keys = array_keys($chunk);
            $items = array_map(fn ($u) => ['method' => 'GET', 'relative_url' => $u], array_values($chunk));
            $results = $this->handle(fn () => Http::timeout(45)->asForm()->post($this->url(), [
                'access_token' => $token,
                'batch' => json_encode($items),
            ]));

            foreach ($results as $i => $res) {
                if (! isset($keys[$i]) || ! is_array($res)) {
                    continue;
                }
                $body = json_decode((string) ($res['body'] ?? '{}'), true);
                $out[$keys[$i]] = ['code' => (int) ($res['code'] ?? 0), 'body' => is_array($body) ? $body : []];
            }
        }

        return $out;
    }

    /**
     * @param  callable(): Response  $send
     * @param  string|null  $writeToken  set for a write: a 2xx is then never turned into an exception
     * @param  string  $path  the request path (resolves the ad account of the usage header)
     */
    private function handle(callable $send, ?string $writeToken = null, string $path = ''): array
    {
        try {
            $response = $send();
        } catch (ConnectionException $e) {
            // The request may or may not have reached Meta: callers that wrote must read back before deciding.
            throw new PlatformUnreachable($this->scrub('Meta is unreachable: '.$e->getMessage()));
        }

        app(UsageRecorder::class)->record($response, $path);

        if (! $response->successful()) {
            $message = (string) ($response->json('error.message') ?? 'Meta API error (HTTP '.$response->status().')');
            $code = (int) $response->json('error.code', 0);
            if (in_array($code, self::RATE_CODES, true)) {
                throw new RateLimited($this->scrub($message), $this->regainSeconds($response));
            }
            if ($code === 190 || in_array((int) $response->json('error.error_subcode', 0), self::TOKEN_SUBCODES, true)) {
                throw new TokenInvalid($this->scrub($message));
            }
            if (in_array($code, self::PERMISSION_CODES, true) || str_contains(strtolower($message), 'ads_management')) {
                throw new MissingPermission($this->scrub('Meta permission missing: '.$message));
            }
            if (str_contains(strtolower($message), 'reduce the amount of data')) {
                throw new TooMuchData($this->scrub($message));
            }
            throw new AdsApiException($this->scrub($message));
        }

        if ($writeToken === null) {
            $this->guardUsage($response);
        } else {
            $this->noteWriteUsage($response, $writeToken);
        }

        return $response->json() ?? [];
    }

    private function scrub(string $text): string
    {
        return SecretScrubber::scrub($text);
    }

    /** The bearer header carries the token, so drop it from paging URLs. */
    private function stripToken(string $url): string
    {
        $url = (string) preg_replace('/([?&])access_token=[^&]*(&|$)/', '$1', $url);

        return rtrim($url, '?&');
    }

    /**
     * A READ answered 2xx above the limit is not thrown away: its rows are returned, the level is remembered so
     * paginate() stops before the next page (stoppedAt), and the next run's admission waits on the recorded usage.
     */
    private function guardUsage(Response $response): void
    {
        $high = $this->highUsage($response);
        $this->lastReadPct = $high['pct'] ?? null;
        if ($high !== null) {
            Log::warning('Meta usage high on a read', ['metric' => $high['metric'], 'pct' => $high['pct'], 'regain_minutes' => $high['minutes']]);
        }
    }

    /** The write succeeded: log the level and make the next write operation wait, but never fail this one. */
    private function noteWriteUsage(Response $response, string $token): void
    {
        $high = $this->highUsage($response);
        if ($high === null) {
            return;
        }
        Log::warning('Meta usage high after a successful write', ['metric' => $high['metric'], 'pct' => $high['pct'], 'regain_minutes' => $high['minutes']]);
        Cache::put($this->busyKey($token), $high['pct'], now()->addMinutes(max(5, min(60, $high['minutes']))));
    }

    /** @return array{metric: string, pct: int, minutes: int}|null the first metric above the limit */
    private function highUsage(Response $response): ?array
    {
        $header = $response->header('x-business-use-case-usage');
        if ($header === '') {
            return null;
        }
        $decoded = json_decode($header, true);
        if (! is_array($decoded)) {
            return null;
        }
        foreach ($decoded as $entries) {
            foreach ((array) $entries as $entry) {
                foreach (['call_count', 'total_time', 'total_cputime'] as $metric) {
                    if ((float) ($entry[$metric] ?? 0) > self::USAGE_LIMIT) {
                        return ['metric' => $metric, 'pct' => (int) $entry[$metric], 'minutes' => (int) ($entry['estimated_time_to_regain_access'] ?? 0)];
                    }
                }
            }
        }

        return null;
    }

    /** Meta's longest estimated_time_to_regain_access in the usage header, in seconds; null when it gave none. */
    private function regainSeconds(Response $response): ?int
    {
        $decoded = json_decode($response->header('x-business-use-case-usage'), true);
        $minutes = 0;
        foreach (is_array($decoded) ? $decoded : [] as $entries) {
            foreach ((array) $entries as $entry) {
                $minutes = max($minutes, (int) (is_array($entry) ? ($entry['estimated_time_to_regain_access'] ?? 0) : 0));
            }
        }

        return $minutes > 0 ? $minutes * 60 : null;
    }

    private function busyKey(string $token): string
    {
        return 'ads-meta-busy:'.hash('sha256', $token);
    }
}
