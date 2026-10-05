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

        $series = [];
        $accountIds = $this->ctx->accounts === [] ? null : $this->scopeAccounts(AdAccount::query())->pluck('id')->all();
        $q = AdsApiUsage::query()->where('recorded_at', '>=', $since)->orderBy('recorded_at')
            ->when($accountIds !== null, fn ($w) => $w->whereIn('ad_account_id', $accountIds));
        foreach ($q->cursor() as $u) {
            $series[($u->ad_account_id ?? 0).'|'.$u->header][] = ['pct' => (float) $u->max_pct, 'at' => $u->recorded_at];
        }

        if ($series === []) {
            $rows[] = DoctorRow::warn('Quota', 'usage rows, 7 days', '0', 'Nothing recorded yet: usage is saved from the first Meta call after this version is deployed.');
        }
        $names = AdAccount::query()->pluck('name', 'id');
        ksort($series);
        foreach ($series as $key => $points) {
            [$accId, $header] = explode('|', $key, 2);
            $values = array_column($points, 'pct');
            sort($values);
            $p95 = $values[(int) max(0, ceil(0.95 * count($values)) - 1)];
            $last = end($points)['pct'];
            $label = ($accId === '0' ? 'no account' : ($names[(int) $accId] ?? "account {$accId}")).' '.$header;
            $rows[] = DoctorRow::by($p95 < self::P95_LIMIT, 'fail', 'Quota', $label,
                sprintf('last %.1f, max %.1f, p95 %.1f, rows %d', $last, max($values), $p95, count($values)),
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
