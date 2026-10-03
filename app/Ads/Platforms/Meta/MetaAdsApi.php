<?php

namespace App\Ads\Platforms\Meta;

use App\Ads\Platforms\AdsApiException;
use App\Ads\Platforms\RateLimited;
use App\Ads\Platforms\SecretScrubber;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Thin Graph API client: URL building, paging, error mapping, quota guard. Never sleeps. */
class MetaAdsApi
{
    private const HOST = 'https://graph.facebook.com';

    /** Meta error codes that mean "slow down" (80000-80014: business use case limits, e.g. 80004 ads management). */
    private const RATE_CODES = [4, 17, 32, 613, 80000, 80001, 80002, 80003, 80004, 80005, 80006, 80008, 80009, 80014];

    /** A page Meta calls too large is asked again with half the `limit`, down to this. */
    private const MIN_LIMIT = 5;

    private const USAGE_LIMIT = 85;

    public function version(): string
    {
        $v = trim((string) config('crm.ads.meta.graph_version', 'v23.0')) ?: 'v23.0';

        return str_starts_with($v, 'v') ? $v : 'v'.$v;
    }

    public function url(string $path = ''): string
    {
        return self::HOST.'/'.$this->version().'/'.ltrim($path, '/');
    }

    /** GET one page. @return array<string, mixed> */
    public function get(string $token, string $path, array $query = []): array
    {
        return $this->handle(fn () => Http::withToken($token)->timeout(90)->connectTimeout(15)
            ->get($this->url($path), $query));
    }

    /**
     * GET and follow paging.next.
     *
     * @return list<array<string, mixed>> the merged data[] rows
     */
    public function paginate(string $token, string $path, array $query = [], int $maxPages = 200): array
    {
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
            if ($pages++ >= $maxPages) {
                throw new AdsApiException('Meta result too large - narrow the date range.');
            }
            $next = $this->stripToken((string) $next);
            $page = $this->shrinking(fn (int $l) => $this->handle(fn () => Http::withToken($token)->timeout(90)->connectTimeout(15)
                ->get($l > 0 ? $this->withLimit($next, $l) : $next)), $limit);
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

    /** @param  callable(): Response  $send */
    private function handle(callable $send): array
    {
        try {
            $response = $send();
        } catch (ConnectionException $e) {
            throw new AdsApiException($this->scrub('Meta is unreachable: '.$e->getMessage()));
        }

        if (! $response->successful()) {
            $message = (string) ($response->json('error.message') ?? 'Meta API error (HTTP '.$response->status().')');
            $code = (int) $response->json('error.code', 0);
            if (in_array($code, self::RATE_CODES, true)) {
                throw new RateLimited($this->scrub($message));
            }
            if (str_contains(strtolower($message), 'reduce the amount of data')) {
                throw new TooMuchData($this->scrub($message));
            }
            throw new AdsApiException($this->scrub($message));
        }

        $this->guardUsage($response);

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

    private function guardUsage(Response $response): void
    {
        $header = $response->header('x-business-use-case-usage');
        if ($header === '') {
            return;
        }
        $decoded = json_decode($header, true);
        if (! is_array($decoded)) {
            return;
        }
        foreach ($decoded as $entries) {
            foreach ((array) $entries as $entry) {
                foreach (['call_count', 'total_time', 'total_cputime'] as $metric) {
                    if ((float) ($entry[$metric] ?? 0) > self::USAGE_LIMIT) {
                        throw new RateLimited('Meta usage is at '.(int) $entry[$metric].'% ('.$metric.'); retry later.');
                    }
                }
            }
        }
    }
}
