<?php

use App\Ads\Control\AdWriteService;
use App\Ads\Control\WritableAccounts;
use App\Ads\Sync\AdsSyncService;
use App\Enums\UserRole;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdCampaign;
use App\Models\AdDailyMetric;
use App\Models\AdPlatformConnection;
use App\Models\AdsAuditLog;
use App\Models\AdsSetting;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    Cache::forget('ads-fake-writer');
    config(['crm.ads.drivers.meta' => 'fake', 'crm.ads.drivers.tiktok' => 'fake', 'crm.ads.drivers.google' => 'fake', 'crm.ads.write_sandbox_accounts' => []]);
    $this->withoutVite();
});

function weMigration(): object
{
    return require database_path('migrations/2026_10_07_100030_add_write_enabled_to_ad_accounts.php');
}

function weStop($test, User $u, AdAccount $acc, Ad $ad)
{
    return $test->actingAs($u)->postJson('/ads/actions/status', ['account_id' => $acc->id, 'level' => 'ad', 'external_id' => $ad->external_id, 'status' => 'paused', 'reason' => 'x']);
}

it('turns write_enabled on for every existing account, active and inactive', function () {
    $active = AdAccount::factory()->meta()->create();
    $inactive = AdAccount::factory()->meta()->create(['is_active' => false]);
    $m = weMigration();
    $m->down();
    $m->up();

    expect($active->fresh()->write_enabled)->toBeTrue()
        ->and($inactive->fresh()->write_enabled)->toBeTrue()
        ->and(AdsAuditLog::where('action', 'settings.writable_accounts_migrated')->count())->toBe(0);
});

it('migrates a non-empty slice-1 writable list into the column once and keeps the setting row', function () {
    $a = AdAccount::factory()->meta()->create(['external_id' => 'act_A']);
    $b = AdAccount::factory()->meta()->create(['external_id' => 'act_B']);
    $m = weMigration();
    $m->down();
    AdsSetting::query()->create(['key' => 'writable_account_ids', 'value' => ['act_A']]);

    $m->up();
    $m->up(); // guarded: nothing more happens

    expect($a->fresh()->write_enabled)->toBeTrue()->and($b->fresh()->write_enabled)->toBeFalse();
    $audit = AdsAuditLog::where('action', 'settings.writable_accounts_migrated')->sole();
    expect($audit->before)->toBe(['writable' => ['act_A']])
        ->and($audit->after)->toBe(['write_enabled_false' => [$b->id]])
        ->and($audit->actor_type)->toBe('cli')
        ->and(AdsSetting::where('key', 'writable_account_ids')->value('value'))->toBe(['act_A']);
});

it('leaves every account writable when the slice-1 setting is null or empty', function () {
    $a = AdAccount::factory()->meta()->create();
    $m = weMigration();
    $m->down();
    AdsSetting::query()->create(['key' => 'writable_account_ids', 'value' => []]);
    $m->up();

    expect($a->fresh()->write_enabled)->toBeTrue()->and(AdsAuditLog::count())->toBe(0);
});

it('makes a newly discovered account writable by default (D3)', function () {
    $c = AdPlatformConnection::factory()->create(['platform' => 'meta']);
    app(AdsSyncService::class)->syncAccounts($c);

    $acc = AdAccount::where('external_id', 'act_demo_main')->firstOrFail();
    expect($acc->write_enabled)->toBeTrue()->and(WritableAccounts::allows($acc))->toBeTrue();
});

it('never allows writes on an inactive account even when write_enabled', function () {
    $off = AdAccount::factory()->meta()->create(['is_active' => false]);

    expect($off->write_enabled)->toBeTrue()->and(WritableAccounts::allows($off))->toBeFalse()
        ->and(WritableAccounts::allowsIn($off, ['ignored']))->toBeFalse();
});

it('disables and enables one account with ads:writable, audited, and the old endpoint follows', function () {
    $a = AdAccount::factory()->meta()->create(['external_id' => 'act_A']);
    $b = AdAccount::factory()->meta()->create(['external_id' => 'act_B']);
    $adB = Ad::factory()->for($b, 'account')->create();
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    expect(Artisan::call('ads:writable', ['--disable' => 'act_B']))->toBe(0);
    expect($b->fresh()->write_enabled)->toBeFalse()->and($a->fresh()->write_enabled)->toBeTrue();
    weStop($this, $admin, $b, $adB)->assertStatus(422)->assertJsonPath('errors.status.0', __('ads.errors.account_not_writable'));
    expect(Cache::get('ads-fake-writer'))->toBeNull();

    $audit = AdsAuditLog::where('action', 'account.write_enabled_changed')->sole();
    expect($audit->ad_account_id)->toBe($b->id)
        ->and($audit->before)->toBe(['write_enabled' => true])->and($audit->after)->toBe(['write_enabled' => false]);

    expect(Artisan::call('ads:writable', ['--disable' => 'act_B']))->toBe(0); // no-op: no audit row
    expect(AdsAuditLog::where('action', 'account.write_enabled_changed')->count())->toBe(1);

    expect(Artisan::call('ads:writable', ['--enable' => 'act_B']))->toBe(0);
    weStop($this, $admin, $b, $adB)->assertOk();
    expect($adB->refresh()->status)->toBe('PAUSED')
        ->and(AdsAuditLog::where('action', 'account.write_enabled_changed')->count())->toBe(2);
});

it('sets the writable accounts exactly with --set and turns every active one back on with --all', function () {
    $a = AdAccount::factory()->meta()->create(['external_id' => 'act_A']);
    $b = AdAccount::factory()->meta()->create(['external_id' => 'act_B']);
    $off = AdAccount::factory()->meta()->create(['external_id' => 'act_OFF', 'is_active' => false]);

    expect(Artisan::call('ads:writable', ['--set' => 'act_A']))->toBe(0);
    expect(Artisan::output())->toContain('writable by default');
    expect($a->fresh()->write_enabled)->toBeTrue()->and($b->fresh()->write_enabled)->toBeFalse()->and($off->fresh()->write_enabled)->toBeFalse();
    expect(AdsAuditLog::where('action', 'account.write_enabled_changed')->count())->toBe(2);

    expect(Artisan::call('ads:writable', ['--all' => true]))->toBe(0);
    expect($b->fresh()->write_enabled)->toBeTrue()
        ->and($off->fresh()->write_enabled)->toBeFalse() // --all touches active accounts only
        ->and(AdsAuditLog::where('action', 'account.write_enabled_changed')->count())->toBe(3);
});

it('refuses an unknown account and changes nothing', function () {
    $a = AdAccount::factory()->meta()->create(['external_id' => 'act_A']);
    $b = AdAccount::factory()->meta()->create(['external_id' => 'act_B']);

    expect(Artisan::call('ads:writable', ['--set' => 'act_A,act_unknown']))->toBe(1)
        ->and(Artisan::call('ads:writable', ['--disable' => 'act_unknown']))->toBe(1)
        ->and($a->fresh()->write_enabled)->toBeTrue()->and($b->fresh()->write_enabled)->toBeTrue()
        ->and(AdsAuditLog::count())->toBe(0);
});

it('refuses two modes at once', function () {
    AdAccount::factory()->meta()->create(['external_id' => 'act_A']);

    expect(Artisan::call('ads:writable', ['--all' => true, '--disable' => 'act_A']))->toBe(1);
});

it('lists every account with its write flag', function () {
    AdAccount::factory()->meta()->create(['external_id' => 'act_A', 'name' => 'AccA']);
    AdAccount::factory()->meta()->create(['external_id' => 'act_B', 'name' => 'AccB', 'write_enabled' => false]);

    expect(Artisan::call('ads:writable'))->toBe(0);
    $out = Artisan::output();
    expect($out)->toContain('act_A')->toContain('act_B')->toContain('write_enabled');
});

it('selects write_enabled on the campaigns page so can_write stays true for an admin', function () {
    $acc = AdAccount::factory()->meta()->create(['name' => 'AccA']);
    $camp = AdCampaign::factory()->for($acc, 'account')->create();
    $ad = Ad::factory()->for($acc, 'account')->create(['ad_campaign_id' => $camp->id]);
    AdDailyMetric::factory()->create(['ad_id' => $ad->id, 'ad_account_id' => $acc->id, 'date' => now('Africa/Cairo')->subDay()->toDateString(), 'spend' => 200]);
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $tree = $this->actingAs($admin)->get('/ads/campaigns')->assertOk()->viewData('page')['props']['tree'];
    expect($tree)->not->toBeEmpty()->and($tree[0]['can_write'])->toBeTrue();

    expect(app(AdWriteService::class)->canWriteMany($admin, [$acc->fresh()]))->toBe([$acc->id => true]);
});
