<?php

use App\Ads\Platforms\AdsApiException;
use App\Ads\Platforms\Meta\MetaAdsApi;
use App\Ads\Platforms\MissingPermission;
use App\Ads\Platforms\TokenInvalid;
use App\Enums\UserRole;
use App\Models\AdAccount;
use App\Models\AdPlatformConnection;
use App\Models\AdsAuditLog;
use App\Models\AdsSyncRun;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['crm.ads.drivers.meta' => 'live']);
});

/** A fresh Http fake (also forgets recorded requests). */
function metaFake(array $body, int $status = 200): void
{
    Http::swap(new HttpFactory);
    Http::preventStrayRequests();
    Http::fake(['graph.facebook.com/*' => Http::response($body, $status)]);
}

function expiredTokenFake(): void
{
    metaFake(['error' => ['code' => 190, 'error_subcode' => 463, 'message' => 'Session has expired']], 400);
}

function tokenSetup(): array
{
    $c = AdPlatformConnection::factory()->meta()->create(['credentials' => ['access_token' => 'EAABsecrettoken123']]);
    $a = AdAccount::factory()->meta()->create(['connection_id' => $c->id, 'external_id' => 'act_9']);

    return [$c, $a];
}

it('maps code 190 to TokenInvalid and keeps code 200 as a missing permission', function () {
    expiredTokenFake();
    expect(fn () => app(MetaAdsApi::class)->get('tok', 'me'))->toThrow(TokenInvalid::class);

    metaFake(['error' => ['code' => 190, 'error_subcode' => 460, 'message' => 'Password changed']], 400);
    expect(fn () => app(MetaAdsApi::class)->get('tok', 'me'))->toThrow(TokenInvalid::class);

    metaFake(['error' => ['code' => 200, 'message' => 'Permissions error']], 400);
    expect(fn () => app(MetaAdsApi::class)->get('tok', 'me'))->toThrow(MissingPermission::class);

    expect(is_subclass_of(TokenInvalid::class, AdsApiException::class))->toBeTrue()
        ->and(is_subclass_of(TokenInvalid::class, MissingPermission::class))->toBeFalse();
});

it('marks the connection needs_reconnect and notifies each active admin once', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
    $admin2 = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
    $supervisor = User::factory()->create(['role' => UserRole::Supervisor, 'is_active' => true]);
    [$c] = tokenSetup();
    expiredTokenFake();

    Artisan::call('ads:sync', ['--days' => 3, '--now' => true]);

    $c->refresh();
    expect($c->status)->toBe('needs_reconnect')->and($c->needs_reconnect_at)->not->toBeNull()
        ->and(AdsSyncRun::latest('id')->first()->status)->toBe('error')
        ->and(UserNotification::where('type', 'ads.token_invalid')->where('user_id', $admin->id)->count())->toBe(1)
        ->and(UserNotification::where('type', 'ads.token_invalid')->where('user_id', $admin2->id)->count())->toBe(1)
        ->and(UserNotification::where('user_id', $supervisor->id)->count())->toBe(0)
        ->and(AdsAuditLog::where('action', 'connection.needs_reconnect')->count())->toBe(1)
        ->and(json_encode(AdsAuditLog::all()))->not->toContain('EAABsecrettoken123')
        ->and((string) $c->last_error)->not->toContain('EAABsecrettoken123');
});

it('stops calling Meta while the connection needs reconnect and probes once after an hour', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
    [$c] = tokenSetup();
    expiredTokenFake();
    Artisan::call('ads:sync', ['--days' => 3, '--now' => true]);

    $this->travel(20)->minutes();
    metaFake(['data' => []]);
    Artisan::call('ads:sync', ['--days' => 3, '--now' => true]);
    Http::assertSentCount(0);

    $this->travel(41)->minutes(); // 61 minutes after the failure
    expiredTokenFake();
    Artisan::call('ads:sync', ['--days' => 3, '--now' => true]);
    Http::assertSentCount(1);

    expect($c->fresh()->status)->toBe('needs_reconnect')
        ->and(UserNotification::where('type', 'ads.token_invalid')->where('user_id', $admin->id)->count())->toBe(1);
});

it('returns to connected when the probe succeeds', function () {
    [$c] = tokenSetup();
    expiredTokenFake();
    Artisan::call('ads:sync', ['--days' => 3, '--now' => true]);

    $this->travel(61)->minutes();
    metaFake(['data' => []]);
    Artisan::call('ads:sync', ['--days' => 3, '--now' => true]);

    $c->refresh();
    expect($c->status)->toBe('connected')->and($c->needs_reconnect_at)->toBeNull();
});

it('clears needs_reconnect_at when the token is replaced', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
    [$c] = tokenSetup();
    expiredTokenFake();
    Artisan::call('ads:sync', ['--days' => 3, '--now' => true]);
    expect($c->fresh()->needs_reconnect_at)->not->toBeNull();

    $this->actingAs($admin)->put("/ads/connections/{$c->id}", ['credentials' => ['access_token' => 'EAABnewtoken456']])->assertRedirect();

    $c->refresh();
    expect($c->needs_reconnect_at)->toBeNull()->and($c->status)->toBe('pending');
});

it('stores a masked message when the 190 text carries a token', function () {
    [$c] = tokenSetup();
    metaFake(['error' => ['code' => 190, 'error_subcode' => 463, 'message' => 'Invalid OAuth access_token=EAAsecret987654321 expired']], 400);

    Artisan::call('ads:sync', ['--days' => 3, '--now' => true]);

    expect((string) $c->fresh()->last_error)->not->toContain('EAAsecret987654321')
        ->and((string) AdsSyncRun::latest('id')->first()->error)->not->toContain('EAAsecret987654321');
});

it('keeps needs_reconnect and notifies once even after failed Test clicks', function (int $status, array $body) {
    $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
    [$c] = tokenSetup();
    expiredTokenFake();
    Artisan::call('ads:sync', ['--days' => 3, '--now' => true]);

    metaFake($body, $status);
    $this->actingAs($admin)->post("/ads/connections/{$c->id}/test");
    expect($c->fresh()->status)->toBe('needs_reconnect');

    $this->travel(61)->minutes();
    expiredTokenFake();
    Artisan::call('ads:sync', ['--days' => 3, '--now' => true]);

    expect($c->fresh()->status)->toBe('needs_reconnect')
        ->and(UserNotification::where('type', 'ads.token_invalid')->where('user_id', $admin->id)->count())->toBe(1)
        ->and(AdsAuditLog::where('action', 'connection.needs_reconnect')->count())->toBe(1);
})->with([
    'non-190 failure' => [500, ['error' => ['code' => 1, 'message' => 'Unknown error']]],
    '190 failure' => [400, ['error' => ['code' => 190, 'error_subcode' => 463, 'message' => 'Session has expired']]],
]);
