<?php

namespace App\Ads\Doctor\Checks;

use App\Ads\Audit\AdsAudit;
use App\Ads\Doctor\DoctorRow;
use App\Ads\Platforms\Meta\MetaAdsApi;
use App\Ads\Platforms\SecretScrubber;
use App\Models\AdAccount;
use App\Models\AdPlatformConnection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Asks Meta (GET only) what each stored user token can do. A token is only ever shown as an 8-hex fingerprint. */
class TokenCheck extends DoctorCheck
{
    protected function section(): string
    {
        return 'Token';
    }

    public function run(): array
    {
        $connections = AdPlatformConnection::query()->where('platform', 'meta')->where('status', '<>', 'disabled')
            ->when($this->ctx->accounts !== [], fn ($q) => $q->whereIn('id', $this->scopeAccounts(AdAccount::query())->select('connection_id')))
            ->orderBy('id')->get();

        if ($connections->isEmpty()) {
            return [DoctorRow::skip('Token', 'meta connections', 'none')];
        }

        $rows = [];
        $appId = (string) config('crm.meta.app_id');
        $secret = (string) config('crm.meta.app_secret');
        $api = app(MetaAdsApi::class);

        foreach ($connections as $c) {
            $label = "#{$c->id} {$c->name}";
            $token = (string) ($c->credentials['access_token'] ?? '');
            if ($token === '') {
                $rows[] = DoctorRow::fail('Token', "{$label} token", 'missing', 'The connection has no access token stored.');

                continue;
            }
            $rows[] = DoctorRow::ok('Token', "{$label} fingerprint", AdsAudit::fingerprint($token));
            $rows[] = DoctorRow::by($c->status !== 'needs_reconnect', 'fail', 'Token', "{$label} connection", (string) $c->status, 'Meta rejected this token: reconnect the account with a new token.');

            if (! $this->ctx->network) {
                $rows[] = DoctorRow::skip('Token', "{$label} debug_token", '--no-network');

                continue;
            }

            try {
                if ($appId !== '' && $secret !== '') {
                    $data = (array) Http::withToken($appId.'|'.$secret)->acceptJson()->timeout(20)->connectTimeout(10)
                        ->get($api->url('debug_token'), ['input_token' => $token])->throw()->json('data', []);
                    $scopes = array_map('strval', (array) ($data['scopes'] ?? []));
                    $valid = (bool) ($data['is_valid'] ?? false);
                    $rows[] = DoctorRow::by($valid, 'fail', 'Token', "{$label} is_valid", $valid ? 'true' : 'false', 'Meta says the token is not valid.');
                    $rows[] = DoctorRow::ok('Token', "{$label} type", (string) ($data['type'] ?? '?'));
                    $rows[] = $this->expiry("{$label} expires_at", $data['expires_at'] ?? null);
                    $rows[] = $this->expiry("{$label} data_access_expires_at", $data['data_access_expires_at'] ?? null);
                } else {
                    $rows[] = DoctorRow::warn('Token', "{$label} app secret", 'missing', 'META_APP_ID or META_APP_SECRET is empty: debug_token cannot run, showing granted scopes only.');
                    $granted = (array) Http::withToken($token)->acceptJson()->timeout(20)->connectTimeout(10)
                        ->get($api->url('me/permissions'))->throw()->json('data', []);
                    $scopes = [];
                    foreach ($granted as $p) {
                        if (is_array($p) && ($p['status'] ?? '') === 'granted') {
                            $scopes[] = (string) ($p['permission'] ?? '');
                        }
                    }
                }
                sort($scopes);
                $rows[] = DoctorRow::by(in_array('ads_management', $scopes, true), 'fail', 'Token', "{$label} scopes", implode(', ', $scopes), 'ads_management is missing: Run, Stop and publishing will be refused by Meta.');
            } catch (Throwable $e) {
                $rows[] = DoctorRow::warn('Token', "{$label} debug_token", 'call failed', self::clean(SecretScrubber::scrub($e->getMessage(), [$token, $secret])));
            }
        }

        return $rows;
    }

    private function expiry(string $check, mixed $ts): DoctorRow
    {
        $n = (int) $ts;
        if ($n <= 0) {
            return DoctorRow::ok('Token', $check, 'never');
        }
        $at = CarbonImmutable::createFromTimestamp($n);
        $soon = $at->lt(now()->addDays(7));

        return DoctorRow::by(! $soon, 'warn', 'Token', $check, $at->toIso8601String(), $at->isPast() ? 'Already expired.' : 'Expires within 7 days.');
    }
}
