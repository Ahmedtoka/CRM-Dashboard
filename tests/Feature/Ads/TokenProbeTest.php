<?php

use App\Ads\Platforms\Fake\FakeAdsDriver;
use App\Ads\Sync\AdsSyncService;
use App\Enums\UserRole;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdAction;
use App\Models\AdDailyMetric;
use App\Models\AdPlatformConnection;
use App\Models\AdsAuditLog;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    Cache::forget('ads-fake-writer');
    config(['crm.ads.drivers.meta' => 'fake', 'crm.meta.app_id' => '111', 'crm.meta.app_secret' => 'appsecretvalue']);
    $this->withoutVite();
});

/** A fresh debug_token fake. */
function tpDebug(array $scopes, int $expiresIn = 0, int $dataAccessIn = 0, bool $valid = true): void
{
    Http::swap(new HttpFactory);
    Http::preventStrayRequests();
    Http::fake(['graph.facebook.com/*/debug_token*' => Http::response(['data' => [
        'is_valid' => $valid, 'type' => 'SYSTEM_USER', 'scopes' => $scopes,
        'expires_at' => $expiresIn > 0 ? now()->addSeconds($expiresIn)->timestamp : 0,
        'data_access_expires_at' => $dataAccessIn > 0 ? now()->addSeconds($dataAccessIn)->timestamp : 0,
    ]])]);
}

function tpConnection(): AdPlatformConnection
{
    return AdPlatformConnection::factory()->meta()->create(['credentials' => ['access_token' => 'EAABprobetoken999']]);
}

function tpAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
}

it('marks a connection read-only once when ads_management is missing and clears it when the scope returns', function () {
    $admin = tpAdmin();
    $admin2 = tpAdmin();
    $supervisor = User::factory()->create(['role' => UserRole::Supervisor, 'is_active' => true]);
    $c = tpConnection();

    tpDebug(['ads_read']);
    expect(Artisan::call('ads:token-probe'))->toBe(0);
    $out = Artisan::output();
    $c->refresh();

    expect($c->read_only)->toBeTrue()->and($c->token_valid)->toBeTrue()->and($c->token_type)->toBe('SYSTEM_USER')
        ->and($c->token_scopes)->toBe(['ads_read'])->and($c->token_checked_at)->not->toBeNull()
        ->and(UserNotification::where('type', 'ads.token_scope_missing')->where('user_id', $admin->id)->count())->toBe(1)
        ->and(UserNotification::where('type', 'ads.token_scope_missing')->where('user_id', $admin2->id)->count())->toBe(1)
        ->and(UserNotification::where('user_id', $supervisor->id)->count())->toBe(0)
        ->and($out)->not->toContain('EAABprobetoken999')
        ->and(json_encode(AdsAuditLog::all()))->not->toContain('EAABprobetoken999');

    Artisan::call('ads:token-probe'); // same state: nothing new
    expect(UserNotification::where('type', 'ads.token_scope_missing')->count())->toBe(2);

    tpDebug(['ads_read', 'ads_management']);
    Artisan::call('ads:token-probe');
    expect($c->refresh()->read_only)->toBeFalse()->and(UserNotification::where('type', 'ads.token_scope_missing')->count())->toBe(2);
});

it('refuses Stop on a read-only connection with no writer call', function () {
    tpAdmin();
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $c = tpConnection();
    $acc = AdAccount::factory()->meta()->create(['connection_id' => $c->id]);
    $ad = Ad::factory()->for($acc, 'account')->create();
    tpDebug(['ads_read']);
    Artisan::call('ads:token-probe');

    $res = $this->actingAs($admin)->postJson('/ads/actions/status', ['account_id' => $acc->id, 'level' => 'ad', 'external_id' => $ad->external_id, 'status' => 'paused', 'reason' => 'x']);

    $res->assertStatus(422)->assertJsonValidationErrors('status');
    expect($res->json('errors.status.0'))->toBe(__('ads.errors.connection_read_only'))
        ->and(AdAction::first()->error)->toBe('connection_read_only')
        ->and(Cache::get('ads-fake-writer'))->toBeNull()->and($ad->refresh()->status)->toBe('ACTIVE');
});

it('refuses a write on a connection waiting for a new token', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $c = tpConnection();
    $c->update(['status' => 'needs_reconnect']);
    $acc = AdAccount::factory()->meta()->create(['connection_id' => $c->id]);
    $ad = Ad::factory()->for($acc, 'account')->create();

    $res = $this->actingAs($admin)->postJson('/ads/actions/status', ['account_id' => $acc->id, 'level' => 'ad', 'external_id' => $ad->external_id, 'status' => 'paused']);

    expect($res->status())->toBe(422)->and($res->json('errors.status.0'))->toBe(__('ads.errors.connection_needs_reconnect'))
        ->and(Cache::get('ads-fake-writer'))->toBeNull();
});

it('notifies once a day when the token or the data access expires within 7 days', function () {
    $admin = tpAdmin();
    $c = tpConnection();

    tpDebug(['ads_management'], dataAccessIn: 5 * 86400);
    Artisan::call('ads:token-probe');
    Artisan::call('ads:token-probe');
    expect(UserNotification::where('type', 'ads.token_expiring')->where('user_id', $admin->id)->count())->toBe(1)
        ->and($c->refresh()->data_access_expires_at)->not->toBeNull();

    $this->travel(1)->days();
    tpDebug(['ads_management'], dataAccessIn: 4 * 86400);
    Artisan::call('ads:token-probe');
    expect(UserNotification::where('type', 'ads.token_expiring')->count())->toBe(2);

    tpDebug(['ads_management'], expiresIn: 40 * 86400, dataAccessIn: 60 * 86400);
    Artisan::call('ads:token-probe');
    expect(UserNotification::where('type', 'ads.token_expiring')->count())->toBe(2);
});

it('marks a token Meta reports invalid as needing a reconnect', function () {
    $admin = tpAdmin();
    $c = tpConnection();

    tpDebug(['ads_management'], valid: false);
    Artisan::call('ads:token-probe');

    expect($c->refresh()->token_valid)->toBeFalse()->and($c->status)->toBe('needs_reconnect')
        ->and(UserNotification::where('type', 'ads.token_invalid')->where('user_id', $admin->id)->count())->toBe(1);
});

it('reactivates accounts a re-added connection finds again, but never one switched off by hand', function () {
    $c = AdPlatformConnection::factory()->meta()->create(['status' => 'connected']);
    $archived = AdAccount::factory()->meta()->create(['connection_id' => $c->id, 'external_id' => FakeAdsDriver::META_MAIN, 'is_active' => true]);
    $manual = AdAccount::factory()->meta()->create(['connection_id' => $c->id, 'external_id' => FakeAdsDriver::META_CLOTING, 'is_active' => true]);
    AdDailyMetric::factory()->create(['ad_id' => Ad::factory()->for($archived, 'account')->create()->id, 'spend' => 100]);
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->delete("/ads/connections/{$c->id}")->assertRedirect();
    $manual->update(['is_active' => false, 'deactivated_reason' => null]);
    expect($archived->refresh()->is_active)->toBeFalse()->and($archived->deactivated_reason)->toBe('connection_archived');

    app(AdsSyncService::class)->syncAccounts($c);

    expect($archived->refresh()->is_active)->toBeTrue()->and($archived->deactivated_reason)->toBeNull()
        ->and($manual->refresh()->is_active)->toBeFalse()
        ->and(AdsAuditLog::where('action', 'account.reactivated')->where('ad_account_id', $archived->id)->count())->toBe(1)
        ->and(AdsAuditLog::where('action', 'account.reactivated')->count())->toBe(1);
});

it('keeps the accounts page and the sync alive when stored credentials cannot be decrypted', function () {
    $c = tpConnection();
    $acc = AdAccount::factory()->meta()->create(['connection_id' => $c->id]);
    DB::table('ad_platform_connections')->where('id', $c->id)->update(['credentials' => 'eyJpdiI6ImJyb2tlbiJ9-not-a-valid-payload']);
    config(['crm.ads.drivers.meta' => 'live']);
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->get('/ads/accounts')->assertOk()
        ->assertInertia(fn ($page) => $page->where('connections.0.credentials_unreadable', true)->where('connections.0.has_token', false));

    $run = app(AdsSyncService::class)->syncAccount($acc, now()->subDay()->toImmutable(), now()->toImmutable());
    expect($run->status)->toBe('error')->and($run->error)->toContain('credentials unreadable');

    // Re-entering the token repairs the connection.
    $this->actingAs($admin)->put("/ads/connections/{$c->id}", ['credentials' => ['access_token' => 'EAABnewtoken777']])->assertRedirect();
    expect(AdPlatformConnection::find($c->id)->credentials['access_token'])->toBe('EAABnewtoken777');
});

it('does not touch any connection when Meta rejects the app token with 190', function () {
    $admin = tpAdmin();
    $c = tpConnection();
    Http::swap(new HttpFactory);
    Http::preventStrayRequests();
    Http::fake(['graph.facebook.com/*/debug_token*' => Http::response(['error' => ['code' => 190, 'message' => 'Invalid OAuth access token']], 400)]);

    expect(Artisan::call('ads:token-probe'))->toBe(0);

    expect(Artisan::output())->toContain('App credentials invalid')
        ->and($c->refresh()->status)->toBe('connected')->and($c->token_valid)->toBeNull()
        ->and(UserNotification::where('user_id', $admin->id)->count())->toBe(0);
});

it('marks the connection needs_reconnect when the user token itself gets a 190 from me/permissions', function () {
    config(['crm.meta.app_secret' => '']);
    $admin = tpAdmin();
    $c = tpConnection();
    Http::swap(new HttpFactory);
    Http::preventStrayRequests();
    Http::fake(['graph.facebook.com/*/me/permissions*' => Http::response(['error' => ['code' => 190, 'message' => 'Session has expired']], 400)]);

    Artisan::call('ads:token-probe');

    expect($c->refresh()->status)->toBe('needs_reconnect')
        ->and(UserNotification::where('type', 'ads.token_invalid')->where('user_id', $admin->id)->count())->toBe(1);
});

it('stores token_valid as unchecked when only the permissions were read', function () {
    config(['crm.meta.app_secret' => '']);
    $c = tpConnection();
    Http::swap(new HttpFactory);
    Http::preventStrayRequests();
    Http::fake(['graph.facebook.com/*/me/permissions*' => Http::response(['data' => [['permission' => 'ads_management', 'status' => 'granted']]])]);

    Artisan::call('ads:token-probe');

    expect($c->refresh()->token_valid)->toBeNull()->and($c->token_scopes)->toBe(['ads_management'])->and($c->read_only)->toBeFalse();
});
