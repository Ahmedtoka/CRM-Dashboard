<?php

namespace App\Ads\Platforms\TikTok;

use App\Ads\Platforms\AdsApiException;
use App\Ads\Platforms\MissingPermission;
use App\Ads\Platforms\RateLimited;
use App\Ads\Platforms\SecretScrubber;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin TikTok Business API v1.3 client shared by the read driver and the writer: base URL, the Access-Token
 * header (never a query parameter), envelope handling (non-zero `code` becomes AdsApiException, rate-limit
 * codes RateLimited) and secret scrubbing. Never sleeps.
 *
 * Throttling (A8, documented): after 40100 for the per-minute quota TikTok says to wait 5 minutes; for the per-day
 * quota until 00:00 UTC. RateLimited carries that wait, so every caller that honours retryAfterSeconds (sync release,
 * Stop retry, Run's Retry-After, the comment poller's cooldown) waits at least that long.
 */
class TikTokApi
{
    private const PAGE_SIZE = 1000;

    private const MAX_PAGES = 200;

    /** TikTok codes that mean "slow down". */
    private const RATE_CODES = [40100];

    /** The documented wait after a per-minute throttle. */
    public const THROTTLE_WAIT_SECONDS = 300;

    /** TikTok codes for a missing permission or authorization scope (40001 no permission, 40002 scope not granted). */
    private const PERMISSION_CODES = [40001, 40002];

    /** GET one call, envelope checked. @return array<string, mixed> the envelope data */
    public function get(string $token, string $path, array $query = []): array
    {
        return $this->handle($token, fn () => Http::withHeaders(['Access-Token' => $token])->timeout(90)->connectTimeout(15)
            ->get($this->url($path), $query));
    }

    /** POST a JSON body. @return array<string, mixed> the envelope data */
    public function post(string $token, string $path, array $body = []): array
    {
        return $this->handle($token, fn () => Http::withHeaders(['Access-Token' => $token])->timeout(90)->connectTimeout(15)
            ->asJson()->post($this->url($path), $body));
    }

    /**
     * POST multipart: plain fields plus one file part streamed from an open handle.
     *
     * @param  resource  $stream
     * @return array<string, mixed> the envelope data
     */
    public function postMultipart(string $token, string $path, array $fields, string $fileField, $stream, string $filename): array
    {
        return $this->handle($token, fn () => Http::withHeaders(['Access-Token' => $token])->timeout(600)->connectTimeout(15)
            ->attach($fileField, $stream, $filename)->post($this->url($path), $fields));
    }

    /** @return list<array<string, mixed>> merged data.list rows across pages ($pageSize: the endpoint's maximum, 1000 by default) */
    public function paginate(string $token, string $path, array $query = [], int $pageSize = self::PAGE_SIZE): array
    {
        $rows = [];
        $page = 1;

        while (true) {
            $data = $this->get($token, $path, $query + ['page' => $page, 'page_size' => $pageSize]);
            $rows = array_merge($rows, $data['list'] ?? []);
            $total = (int) ($data['page_info']['total_page'] ?? 1);
            if ($page >= $total) {
                return $rows;
            }
            if ($page >= self::MAX_PAGES) {
                throw new AdsApiException('TikTok result too large - narrow the date range.');
            }
            $page++;
        }
    }

    /** Seconds to wait: until 00:00 UTC when the message names the daily quota, else the 5-minute throttle wait. */
    private static function waitFor(string $message): int
    {
        if (preg_match('/QPD|daily|per day/i', $message) === 1) {
            $now = now()->utc();

            return max(self::THROTTLE_WAIT_SECONDS, (int) $now->diffInSeconds($now->copy()->addDay()->startOfDay(), true));
        }

        return self::THROTTLE_WAIT_SECONDS;
    }

    private function url(string $path): string
    {
        return rtrim((string) config('crm.ads.tiktok.base_url'), '/').'/'.ltrim($path, '/');
    }

    /** @param  callable(): Response  $send */
    private function handle(string $token, callable $send): array
    {
        try {
            $response = $send();
        } catch (ConnectionException $e) {
            throw new AdsApiException(SecretScrubber::scrub('TikTok is unreachable: '.$e->getMessage(), [$token]));
        }

        $code = $response->json('code');
        if ($response->status() === 429) {
            throw new RateLimited('TikTok rate limit reached; retry later.', self::THROTTLE_WAIT_SECONDS);
        }
        if ($code === null) {
            throw new AdsApiException('TikTok API error: unexpected response (HTTP '.$response->status().')');
        }
        if ((int) $code !== 0) {
            $message = SecretScrubber::scrub((string) ($response->json('message') ?: 'TikTok API error (code '.$code.')'), [$token]);
            if (in_array((int) $code, self::RATE_CODES, true)) {
                throw new RateLimited($message, self::waitFor($message));
            }
            // The platform's own text, no claim about which permission: TikTok reuses these codes for scope problems.
            throw in_array((int) $code, self::PERMISSION_CODES, true) ? new MissingPermission($message) : new AdsApiException($message);
        }

        return $response->json('data') ?? [];
    }
}
