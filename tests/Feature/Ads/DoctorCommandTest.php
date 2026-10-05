<?php

use App\Models\AdAccount;
use App\Models\AdPlatformConnection;
use App\Models\AdsSyncRun;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

const DOCTOR_TOKEN = 'EAATESTTOKEN123';

beforeEach(function () {
    Http::preventStrayRequests();
    config(['crm.meta.app_id' => '111', 'crm.meta.app_secret' => 'appsecretvalue', 'crm.ads.drivers.meta' => 'fake']);
    Cache::put('crm:scheduler_heartbeat', now()->toISOString());
});

/** @return array{0: int, 1: string} exit code and output */
function doctor(array $args = []): array
{
    $code = Artisan::call('ads:doctor', ['--markdown' => true] + $args);

    return [$code, Artisan::output()];
}

function doctorConnection(): AdPlatformConnection
{
    $c = AdPlatformConnection::factory()->meta()->create(['name' => 'LV', 'credentials' => ['access_token' => DOCTOR_TOKEN]]);
    AdAccount::factory()->meta()->create(['connection_id' => $c->id, 'external_id' => 'act_77', 'name' => 'LV Main']);

    return $c;
}

function doctorRow(string $out, string $section, string $check): ?string
{
    foreach (explode("\n", $out) as $line) {
        if (str_starts_with($line, "| {$section} | ") && str_contains($line, "| {$check}")) {
            return $line;
        }
    }

    return null;
}

it('prints a Markdown table that starts with the Section header', function () {
    [, $out] = doctor(['--no-network' => true]);

    expect(ltrim($out))->toStartWith('| Section |')->and($out)->toContain('| App | APP_ENV | ok | testing');
});

it('runs without a single write statement', function () {
    doctorConnection();
    AdsSyncRun::factory()->create(['status' => 'running', 'started_at' => now()->subHours(3)]);
    Http::fake([
        'graph.facebook.com/*/debug_token*' => Http::response(['data' => ['is_valid' => true, 'type' => 'USER', 'scopes' => ['ads_management', 'ads_read'], 'expires_at' => 0, 'data_access_expires_at' => time() + 86400 * 60]]),
        'graph.facebook.com/*/insights*' => Http::response(['data' => []], 200, ['x-ad-account-usage' => json_encode(['acc_id_util_pct' => 4])]),
    ]);
    $writes = [];
    DB::listen(function ($q) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete|replace|create|alter|drop)\b/i', $q->sql)) {
            $writes[] = $q->sql;
        }
    });

    doctor();

    expect($writes)->toBe([]);
    Http::assertSent(fn ($r) => $r->method() === 'GET');
});

it('fails the token row when ads_management is missing', function () {
    doctorConnection();
    Http::fake(['graph.facebook.com/*/debug_token*' => Http::response(['data' => ['is_valid' => true, 'type' => 'USER', 'scopes' => ['ads_read'], 'expires_at' => 0, 'data_access_expires_at' => 0]]),
        '*' => Http::response(['data' => []])]);

    [$code, $out] = doctor();

    expect(doctorRow($out, 'Token', '#1 LV scopes'))->toContain('| fail |')->and($code)->toBe(1);
    Http::assertSent(fn ($r) => str_contains($r->url(), 'debug_token') && $r->hasHeader('Authorization', 'Bearer 111|appsecretvalue'));
});

it('falls back to me/permissions with a warning when the app secret is missing', function () {
    config(['crm.meta.app_secret' => '']);
    doctorConnection();
    Http::fake([
        'graph.facebook.com/*/me/permissions*' => Http::response(['data' => [['permission' => 'ads_management', 'status' => 'granted'], ['permission' => 'ads_read', 'status' => 'declined']]]),
        '*' => Http::response(['data' => []]),
    ]);

    [, $out] = doctor();

    expect(doctorRow($out, 'Token', '#1 LV app secret'))->toContain('| warn |')
        ->and(doctorRow($out, 'Token', '#1 LV scopes'))->toContain('| ok |')->toContain('ads_management')->not->toContain('ads_read');
    Http::assertSent(fn ($r) => str_contains($r->url(), 'me/permissions'));
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'debug_token'));
});

it('never prints the token, only its fingerprint', function () {
    doctorConnection();
    Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid OAuth access token '.DOCTOR_TOKEN]], 400)]);

    [, $out] = doctor();

    expect($out)->not->toContain(DOCTOR_TOKEN)->not->toContain('appsecretvalue')
        ->and($out)->toContain(substr(hash('sha256', DOCTOR_TOKEN), 0, 8));
});

it('sends nothing with --no-network', function () {
    doctorConnection();

    doctor(['--no-network' => true]);

    Http::assertNothingSent();
});

it('fails Sync runs and exits 1 on a run stuck for two hours', function () {
    $acc = AdAccount::factory()->meta()->create();
    AdsSyncRun::factory()->create(['ad_account_id' => $acc->id, 'status' => 'running', 'started_at' => now()->subHours(2)]);

    [$code, $out] = doctor(['--no-network' => true]);

    expect(doctorRow($out, 'Sync runs', 'stuck running rows'))->toContain('| fail |')->and($code)->toBe(1);
});

it('exits 0 when nothing fails', function () {
    [$code, $out] = doctor(['--no-network' => true]);

    expect($code)->toBe(0)->and($out)->not->toContain('| fail |');
});

it('fails the Queue section when retry_after is not above the sync timeout', function () {
    config(['queue.default' => 'redis', 'queue.connections.redislong.retry_after' => 90]);

    [$code, $out] = doctor(['--no-network' => true]);

    expect(doctorRow($out, 'Queue', 'retry_after vs SyncAdAccount timeout'))->toContain('| fail |')->and($code)->toBe(1);
});
