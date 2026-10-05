<?php

namespace App\Ads\Platforms\Google;

use App\Ads\Platforms\AdPlatformDriver;
use App\Ads\Platforms\AdsApiException;
use App\Ads\Platforms\Data\AccountInfo;
use App\Ads\Platforms\Data\AdRow;
use App\Ads\Platforms\Data\DailyAdMetric;
use App\Ads\Platforms\RateLimited;
use App\Ads\Platforms\SecretScrubber;
use App\Models\AdAccount;
use App\Models\AdPlatformConnection;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Google Ads REST (searchStream). Secrets travel in headers or the POST body, never the URL. */
class GoogleAdsDriver implements AdPlatformDriver
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const TOKEN_TTL_MINUTES = 50;

    public function accounts(AdPlatformConnection $c): array
    {
        $list = $this->request($c, 'GET', 'customers:listAccessibleCustomers');
        $out = [];

        foreach ($list['resourceNames'] ?? [] as $resource) {
            $cid = $this->cid((string) $resource);
            try {
                $rows = $this->search($c, $cid, 'SELECT customer.id, customer.descriptive_name, customer.currency_code, customer.time_zone, customer.status FROM customer');
            } catch (RateLimited $e) {
                throw $e;
            } catch (AdsApiException) {
                continue;   // one inaccessible customer must not hide the others
            }
            foreach ($rows as $r) {
                $cu = $r['customer'] ?? [];
                $out[] = new AccountInfo(
                    externalId: (string) ($cu['id'] ?? $cid),
                    name: (string) ($cu['descriptiveName'] ?? $cid),
                    currency: (string) ($cu['currencyCode'] ?? 'USD'),
                    timezone: $cu['timeZone'] ?? null,
                    status: match ((string) ($cu['status'] ?? '')) {
                        'ENABLED' => 'active',
                        'CANCELED', 'CLOSED', 'SUSPENDED' => 'disabled',
                        default => strtolower((string) ($cu['status'] ?? 'unknown')),
                    },
                    balance: null,
                );
            }
        }

        return $out;
    }

    public function ads(AdAccount $a): array
    {
        $rows = $this->search($a->connection, $this->cid($a->external_id),
            'SELECT ad_group_ad.ad.id, ad_group_ad.ad.name, ad_group_ad.status, ad_group_ad.ad.type, ad_group_ad.ad.final_urls, '
            .'campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type, '
            .'ad_group.id, ad_group.name, ad_group.status FROM ad_group_ad WHERE ad_group_ad.status != \'REMOVED\'');

        $out = [];
        foreach ($rows as $r) {
            $ad = $r['adGroupAd']['ad'] ?? [];
            if (empty($ad['id'])) {
                continue;
            }
            $out[] = new AdRow(
                externalId: (string) $ad['id'],
                name: (string) (($ad['name'] ?? '') !== '' ? $ad['name'] : $ad['id']),
                status: $r['adGroupAd']['status'] ?? null,
                effectiveStatus: null,
                campaignId: isset($r['campaign']['id']) ? (string) $r['campaign']['id'] : null,
                campaignName: $r['campaign']['name'] ?? null,
                campaignStatus: $r['campaign']['status'] ?? null,
                objective: $r['campaign']['advertisingChannelType'] ?? null,
                adSetId: isset($r['adGroup']['id']) ? (string) $r['adGroup']['id'] : null,
                adSetName: $r['adGroup']['name'] ?? null,
                adSetStatus: $r['adGroup']['status'] ?? null,
                type: str_contains((string) ($ad['type'] ?? ''), 'VIDEO') ? 'video' : 'image',
                headline: null, body: null, thumbnailUrl: null, imageUrl: null, videoId: null,
                objectStoryId: null, instagramPermalinkUrl: null, urlTags: null, carousel: null,
                createdTime: null,
                raw: $r,
            );
        }

        return $out;
    }

    public function dailyMetrics(AdAccount $a, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = $this->search($a->connection, $this->cid($a->external_id),
            'SELECT ad_group_ad.ad.id, segments.date, metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value, '
            .'campaign.id, campaign.name, ad_group.id, ad_group.name FROM ad_group_ad '
            ."WHERE segments.date BETWEEN '{$from->toDateString()}' AND '{$to->toDateString()}'");

        $out = [];
        foreach ($rows as $r) {
            $adId = $r['adGroupAd']['ad']['id'] ?? null;
            $date = $r['segments']['date'] ?? null;
            if (! $adId || ! $date) {
                continue;
            }
            $m = $r['metrics'] ?? [];
            $out[] = new DailyAdMetric(
                adExternalId: (string) $adId,
                date: (string) $date,
                spend: round(((float) ($m['costMicros'] ?? 0)) / 1_000_000, 4),
                impressions: (int) ($m['impressions'] ?? 0),
                clicks: (int) ($m['clicks'] ?? 0),
                reach: 0,   // not available per ad on Google
                purchases: (float) ($m['conversions'] ?? 0),
                purchaseValue: (float) ($m['conversionsValue'] ?? 0),
                adName: null,
                campaignId: isset($r['campaign']['id']) ? (string) $r['campaign']['id'] : null,
                campaignName: $r['campaign']['name'] ?? null,
                adSetId: isset($r['adGroup']['id']) ? (string) $r['adGroup']['id'] : null,
                adSetName: $r['adGroup']['name'] ?? null,
            );
        }

        return $out;
    }

    /** No account-level control until this platform is live: an empty list means no control, and no request is made. */
    public function accountDaily(AdAccount $a, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [];
    }

    public function creativeMedia(AdAccount $a, array $adExternalIds): array
    {
        return [];   // no creative preview for search ads; image assets are out of scope
    }

    public function test(AdPlatformConnection $c): ?string
    {
        try {
            $this->request($c, 'GET', 'customers:listAccessibleCustomers');

            return null;
        } catch (AdsApiException $e) {
            return $e->getMessage();
        }
    }

    /** Run a GAQL query and flatten the streamed batches. @return list<array<string, mixed>> */
    private function search(AdPlatformConnection $c, string $customerId, string $gaql): array
    {
        $batches = $this->request($c, 'POST', "customers/{$customerId}/googleAds:searchStream", ['query' => $gaql]);
        if (isset($batches['results'])) {   // tolerate a single, unwrapped batch
            $batches = [$batches];
        }

        $rows = [];
        foreach ($batches as $batch) {
            if (is_array($batch)) {
                $rows = array_merge($rows, $batch['results'] ?? []);
            }
        }

        return $rows;
    }

    private function request(AdPlatformConnection $c, string $method, string $path, array $body = []): array
    {
        $secrets = $this->secrets($c);
        $headers = [
            'Authorization' => 'Bearer '.$this->accessToken($c),
            'developer-token' => $this->developerToken($c),
        ];
        if ($login = $this->cid((string) ($c->credentials['login_customer_id'] ?? ''))) {
            $headers['login-customer-id'] = $login;
        }
        $url = rtrim((string) config('crm.ads.google.base_url'), '/').'/'.ltrim($path, '/');

        try {
            $pending = Http::withHeaders($headers)->timeout(90)->connectTimeout(15);
            $response = $method === 'GET' ? $pending->get($url) : $pending->post($url, $body);
        } catch (ConnectionException $e) {
            throw new AdsApiException($this->scrub('Google Ads is unreachable: '.$e->getMessage(), $secrets));
        }

        if (! $response->successful()) {
            if ($response->status() === 401) {
                Cache::forget($this->tokenKey($c));   // next call refreshes
            }
            throw $this->error($response, $secrets);
        }

        return $response->json() ?? [];
    }

    /** Refreshed once per ~50 minutes per connection; falls back to a stored access token. */
    private function accessToken(AdPlatformConnection $c): string
    {
        $cred = $c->credentials ?? [];
        if (! empty($cred['refresh_token']) && ! empty($cred['client_id']) && ! empty($cred['client_secret'])) {
            $key = $this->tokenKey($c);
            $cached = Cache::get($key);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }

            $secrets = $this->secrets($c);
            try {
                $response = Http::asForm()->timeout(30)->connectTimeout(15)->post(self::TOKEN_URL, [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $cred['refresh_token'],
                    'client_id' => $cred['client_id'],
                    'client_secret' => $cred['client_secret'],
                ]);
            } catch (ConnectionException $e) {
                throw new AdsApiException($this->scrub('Google sign-in is unreachable: '.$e->getMessage(), $secrets));
            }
            $token = $response->successful() ? (string) $response->json('access_token', '') : '';
            if ($token === '') {
                $message = (string) ($response->json('error_description') ?: $response->json('error') ?: 'Google token refresh failed (HTTP '.$response->status().')');
                throw new AdsApiException($this->scrub($message, $secrets));
            }
            Cache::put($key, $token, now()->addMinutes(self::TOKEN_TTL_MINUTES));

            return $token;
        }

        $token = $cred['access_token'] ?? null;
        if (! $token) {
            throw new AdsApiException('Google Ads access token is missing.');
        }

        return (string) $token;
    }

    private function tokenKey(AdPlatformConnection $c): string
    {
        return 'ads:google:token:'.$c->id.':'.sha1((string) ($c->credentials['refresh_token'] ?? ''));
    }

    private function developerToken(AdPlatformConnection $c): string
    {
        $token = $c->credentials['developer_token'] ?? config('crm.ads.google.developer_token');
        if (! $token) {
            throw new AdsApiException('Google Ads developer token is missing.');
        }

        return (string) $token;
    }

    private function cid(string $value): string
    {
        return str_replace('-', '', basename($value));
    }

    private function error(Response $response, array $secrets): AdsApiException
    {
        $json = $response->json();
        $err = $json['error'] ?? $json[0]['error'] ?? [];
        $message = $this->scrub((string) (($err['message'] ?? '') ?: 'Google Ads API error (HTTP '.$response->status().')'), $secrets);

        if ($response->status() === 429 || ($err['status'] ?? '') === 'RESOURCE_EXHAUSTED') {
            return new RateLimited($message);
        }

        return new AdsApiException($message);
    }

    /** @return list<string> every secret value this connection holds */
    private function secrets(AdPlatformConnection $c): array
    {
        $cred = $c->credentials ?? [];
        $values = [];
        foreach (['access_token', 'refresh_token', 'client_secret', 'developer_token'] as $k) {
            if (! empty($cred[$k])) {
                $values[] = (string) $cred[$k];
            }
        }
        if ($dev = config('crm.ads.google.developer_token')) {
            $values[] = (string) $dev;
        }
        if ($cached = Cache::get($this->tokenKey($c))) {
            $values[] = (string) $cached;
        }

        return $values;
    }

    private function scrub(string $text, array $secrets): string
    {
        return SecretScrubber::scrub($text, $secrets);
    }
}
