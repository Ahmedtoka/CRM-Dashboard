<?php

use App\Ads\AdsSettings;
use App\Enums\UserRole;
use App\Models\AdAccount;
use App\Models\AdPlatformConnection;
use App\Models\AdsApiUsage;
use App\Models\AdsSyncRun;
use App\Models\AdWriteAction;
use App\Models\AdWriteStep;
use App\Models\User;
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
    User::factory()->adsAuthority()->create(['role' => UserRole::Admin]); // a Writes section without an Ads-authority holder fails
    [$code, $out] = doctor(['--no-network' => true]);

    expect($code)->toBe(0)->and($out)->not->toContain('| fail |');
});

it('fails the Queue section when retry_after is not above the sync timeout', function () {
    config(['queue.default' => 'redis', 'queue.connections.redislong.retry_after' => 90]);

    [$code, $out] = doctor(['--no-network' => true]);

    expect(doctorRow($out, 'Queue', 'retry_after vs SyncAdAccount timeout'))->toContain('| fail |')->and($code)->toBe(1);
});

it('computes the quota p95 from the stored readings', function () {
    $acc = AdAccount::factory()->meta()->create(['name' => 'LV Main']);
    foreach (range(1, 20) as $i) {
        AdsApiUsage::create(['ad_account_id' => $acc->id, 'header' => 'x-ad-account-usage', 'max_pct' => $i * 4, 'recorded_at' => now()->subHours($i)]);
    }

    [$code, $out] = doctor(['--no-network' => true]);

    // 20 values 4..80: the 19th sorted value (76) is the p95, above the 75 % limit.
    expect(doctorRow($out, 'Quota', 'LV Main x-ad-account-usage'))->toContain('| fail |')->toContain('p95 76.0')->toContain('max 80.0')->toContain('last 4.0')->and($code)->toBe(1);
});

// Writes section (Phase B exit checks)

it('fails the Writes section when nobody holds Ads authority, and names the holders otherwise', function () {
    [$code, $out] = doctor(['--no-network' => true]);
    expect(doctorRow($out, 'Writes', 'Ads-authority holders'))->toContain('| fail |')->and($code)->toBe(1);

    User::factory()->adsAuthority()->create(['role' => UserRole::Admin, 'name' => 'Owner']);
    [, $out] = doctor(['--no-network' => true]);
    expect(doctorRow($out, 'Writes', 'Ads-authority holders'))->toContain('| ok |')->toContain('Owner');
});

it('fails the exit query on a succeeded step whose action has no confirmer (non-legacy)', function () {
    User::factory()->adsAuthority()->create(['role' => UserRole::Admin]);
    $x = AdWriteAction::factory()->succeeded()->create(['confirmed_by_id' => null]);
    AdWriteStep::create(['ad_write_action_id' => $x->id, 'seq' => 1, 'op' => 'set_status', 'level' => 'ad', 'target_external_id' => '1', 'state' => 'succeeded']);
    $legacy = AdWriteAction::factory()->succeeded()->create(['confirmed_by_id' => null, 'source' => 'legacy']);
    AdWriteStep::create(['ad_write_action_id' => $legacy->id, 'seq' => 1, 'op' => 'set_status', 'level' => 'ad', 'target_external_id' => '1', 'state' => 'succeeded']);

    [$code, $out] = doctor(['--no-network' => true]);

    expect(doctorRow($out, 'Writes', 'exit query'))->toContain('| fail |')->toContain('| 1 ')->and($code)->toBe(1);
});

it('fails on an unknown action and on an executing one stuck for 10 minutes without a retry', function () {
    User::factory()->adsAuthority()->create(['role' => UserRole::Admin]);
    AdWriteAction::factory()->unknown()->create();
    [, $out] = doctor(['--no-network' => true]);
    expect(doctorRow($out, 'Writes', 'unresolved writes'))->toContain('| fail |')->toContain('ads:write-resolve');

    AdWriteAction::query()->update(['state' => 'executing', 'executing_at' => now()->subMinutes(11), 'retry_at' => null]);
    [, $out] = doctor(['--no-network' => true]);
    expect(doctorRow($out, 'Writes', 'unresolved writes'))->toContain('| fail |');

    AdWriteAction::query()->update(['executing_at' => now()->subMinutes(2)]);
    [, $out] = doctor(['--no-network' => true]);
    expect(doctorRow($out, 'Writes', 'unresolved writes'))->toContain('| ok |');
});

it('compares the legacy copy with ad_actions', function () {
    User::factory()->adsAuthority()->create(['role' => UserRole::Admin]);
    DB::table('ad_actions')->insert(['platform' => 'meta', 'level' => 'ad', 'external_id' => '1', 'to_status' => 'PAUSED', 'result' => 'ok', 'created_at' => now(), 'updated_at' => now()]);
    [, $out] = doctor(['--no-network' => true]);
    expect(doctorRow($out, 'Writes', 'legacy copy'))->toContain('| fail |');

    (require database_path('migrations/2026_10_07_100050_copy_ad_actions_to_ad_write_actions.php'))->up();
    [, $out] = doctor(['--no-network' => true]);
    expect(doctorRow($out, 'Writes', 'legacy copy'))->toContain('| ok |')->toContain('ad_actions 1, legacy 1');
});

it('warns when the kill switch is off or the limits were never set, and skips the legacy endpoint count', function () {
    User::factory()->adsAuthority()->create(['role' => UserRole::Admin]);
    app(AdsSettings::class)->set('writes_enabled', false);

    [$code, $out] = doctor(['--no-network' => true]);

    expect(doctorRow($out, 'Writes', 'kill switch'))->toContain('| warn |')
        ->and(doctorRow($out, 'Writes', 'write limits'))->toContain('| warn |')
        ->and(doctorRow($out, 'Writes', 'legacy endpoint calls'))->toContain('| skip |')->toContain('ads.legacy_write_endpoint')
        ->and($code)->toBe(0);
});

it('lists active accounts switched off for writes and warns on an overdue Stop retry', function () {
    User::factory()->adsAuthority()->create(['role' => UserRole::Admin]);
    AdAccount::factory()->meta()->create(['name' => 'Locked', 'write_enabled' => false]);
    AdWriteAction::factory()->stop()->create(['state' => 'executing', 'executing_at' => now()->subMinutes(8), 'retry_at' => now()->subMinutes(6), 'attempts' => 1]);

    [, $out] = doctor(['--no-network' => true]);

    expect(doctorRow($out, 'Writes', 'accounts not writable'))->toContain('Locked')
        ->and(doctorRow($out, 'Writes', 'retry queue'))->toContain('| warn |');
});
