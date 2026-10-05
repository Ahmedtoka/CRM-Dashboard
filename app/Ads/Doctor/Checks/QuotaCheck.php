<?php

namespace App\Ads\Doctor\Checks;

use App\Ads\Doctor\DoctorRow;
use App\Ads\Platforms\Meta\MetaAdsApi;
use App\Ads\Platforms\SecretScrubber;
use App\Models\AdAccount;
use App\Models\AdPlatformConnection;
use App\Models\AdsApiUsage;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Meta usage from ads_api_usage (R-17: the 7-day p95 must leave 25 % headroom) plus one optional live probe per connection. */
class QuotaCheck extends DoctorCheck
{
    private const P95_LIMIT = 75.0;

    protected function section(): string
    {
        return 'Quota';
    }

    public function run(): array
    {
        $rows = [];
        $since = now()->subDays(7);

        $accountIds = $this->ctx->accounts === [] ? null : $this->scopeAccounts(AdAccount::query())->pluck('id')->all();
        $base = fn () => AdsApiUsage::query()->where('recorded_at', '>=', $since)
            ->when($accountIds !== null, fn ($w) => $w->whereIn('ad_account_id', $accountIds));
        $groups = $base()->selectRaw('ad_account_id, header, COUNT(*) as n, MAX(max_pct) as mx')->groupBy('ad_account_id', 'header')
            ->orderBy('ad_account_id')->orderBy('header')->get();

        if ($groups->isEmpty()) {
            $rows[] = DoctorRow::warn('Quota', 'usage rows, 7 days', '0', 'Nothing recorded yet: usage is saved from the first Meta call after this version is deployed.');
        }
        $names = AdAccount::query()->pluck('name', 'id');
        foreach ($groups as $g) {
            $scope = fn () => $base()->where('header', $g->header)->when($g->ad_account_id === null, fn ($w) => $w->whereNull('ad_account_id'), fn ($w) => $w->where('ad_account_id', $g->ad_account_id));
            $n = (int) $g->n;
            // p95 by position in the sorted values: one row read, never the whole series.
            $p95 = (float) $scope()->orderBy('max_pct')->offset((int) max(0, ceil(0.95 * $n) - 1))->limit(1)->value('max_pct');
            $last = (float) $scope()->orderByDesc('recorded_at')->orderByDesc('id')->value('max_pct');
            $label = ($g->ad_account_id === null ? 'no account' : ($names[(int) $g->ad_account_id] ?? "account {$g->ad_account_id}")).' '.$g->header;
            $rows[] = DoctorRow::by($p95 < self::P95_LIMIT, 'fail', 'Quota', $label,
                sprintf('last %.1f, max %.1f, p95 %.1f, rows %d', $last, (float) $g->mx, $p95, $n),
                'The 7-day p95 is at or above 75 %: less than 25 % headroom (R-17). Move the other app off this quota or slow the sync.');
        }

        if (! $this->ctx->network) {
            $rows[] = DoctorRow::skip('Quota', 'live probe', '--no-network');

            return $rows;
        }

        $api = app(MetaAdsApi::class);
        $connections = AdPlatformConnection::query()->where('platform', 'meta')->where('status', '<>', 'disabled')->orderBy('id')->get();
        foreach ($connections as $c) {
            $acc = $this->scopeAccounts(AdAccount::query()->where('connection_id', $c->id)->where('is_active', true))->orderBy('id')->first();
            $token = (string) ($c->credentials['access_token'] ?? '');
            if ($acc === null || $token === '') {
                continue;
            }
            $ext = str_starts_with($acc->external_id, 'act_') ? $acc->external_id : 'act_'.$acc->external_id;
            try {
                $r = Http::withToken($token)->acceptJson()->timeout(30)->connectTimeout(10)
                    ->get($api->url($ext.'/insights'), ['date_preset' => 'yesterday', 'fields' => 'spend', 'limit' => 1]);
                $bits = array_filter([
                    $r->header('x-business-use-case-usage') !== '' ? 'bucu '.$r->header('x-business-use-case-usage') : null,
                    $r->header('x-ad-account-usage') !== '' ? 'acc '.$r->header('x-ad-account-usage') : null,
                ]);
                $rows[] = new DoctorRow('Quota', "live probe #{$c->id}", $r->successful() ? 'ok' : 'warn', 'HTTP '.$r->status().($bits === [] ? ', no usage headers' : ', '.implode('; ', $bits)),
                    $r->successful() ? '' : self::clean(SecretScrubber::scrub((string) $r->json('error.message', ''), [$token])));
            } catch (Throwable $e) {
                $rows[] = DoctorRow::warn('Quota', "live probe #{$c->id}", 'call failed', self::clean(SecretScrubber::scrub($e->getMessage(), [$token])));
            }
        }

        return $rows;
    }
}
