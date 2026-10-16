<?php

use App\Models\DeviceToken;
use App\Models\QueueSetting;
use App\Models\Shift;
use App\Models\User;
use App\Queue\ShiftService;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/** A fresh request cycle: the test app keeps the resolved guard (and its user) between calls otherwise. */
function mbFresh(): void
{
    app('auth')->forgetGuards();
}

function mbLogin(User $u, string $device = 'pixel'): array
{
    return test()->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'secret123', 'device_name' => $device])
        ->assertOk()->json();
}

function mbDevice(User $u, ?int $tokenId, string $raw = 'fcm-token-1'): DeviceToken
{
    return DeviceToken::query()->create([
        'user_id' => $u->id, 'personal_access_token_id' => $tokenId, 'platform' => 'android',
        'token' => $raw, 'token_hash' => hash('sha256', $raw), 'last_registered_at' => now(),
    ]);
}

beforeEach(function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'pub-key',
        'broadcasting.connections.reverb.secret' => 'very-secret',
        'broadcasting.connections.reverb.app_id' => '42',
        'broadcasting.connections.reverb.options.host' => 'ws.example.test',
        'broadcasting.connections.reverb.options.port' => 443,
        'broadcasting.connections.reverb.options.scheme' => 'https',
        'crm.mobile.reverb' => ['host' => null, 'port' => null, 'scheme' => null],
    ]);
    $this->user = User::factory()->create(['password' => bcrypt('secret123'), 'role' => 'moderator']);
});

it('issues an expiring mobile token with realtime settings on login', function () {
    Carbon::setTestNow('2026-10-16 12:00:00');

    $res = mbLogin($this->user);

    expect($res)->toHaveKeys(['token', 'token_type', 'expires_at', 'user', 'realtime'])
        ->and($res['token_type'])->toBe('Bearer')
        ->and(Carbon::parse($res['expires_at'])->equalTo(now()->addDays(30)))->toBeTrue()
        ->and($res['user']['id'])->toBe($this->user->id)
        ->and($res['realtime'])->toBe(['driver' => 'reverb', 'key' => 'pub-key', 'host' => 'ws.example.test', 'port' => 443, 'scheme' => 'https'])
        ->and(json_encode($res))->not->toContain('very-secret');

    $token = PersonalAccessToken::findToken($res['token']);
    expect($token->abilities)->toBe(['mobile']);
});

it('uses the configured token lifetime', function () {
    config(['crm.mobile.token_ttl_days' => 5]);
    Carbon::setTestNow('2026-10-16 12:00:00');

    expect(Carbon::parse(mbLogin($this->user)['expires_at'])->equalTo(now()->addDays(5)))->toBeTrue();
});

it('reports no realtime driver when broadcasting is not reverb', function () {
    config(['broadcasting.default' => 'null']);

    expect(mbLogin($this->user)['realtime'])->toBe(['driver' => null]);
});

it('rejects an expired token with 401', function () {
    $token = mbLogin($this->user)['token'];

    $this->travel(31)->days();
    mbFresh();

    $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
});

it('rotates the token on refresh and moves its devices', function () {
    $old = mbLogin($this->user)['token'];
    $oldId = PersonalAccessToken::findToken($old)->id;
    $device = mbDevice($this->user, $oldId);

    $this->travel(25)->days();
    $res = $this->withToken($old)->postJson('/api/v1/auth/refresh')->assertOk()->json();

    expect($res)->toHaveKeys(['token', 'token_type', 'expires_at', 'user', 'realtime'])
        ->and($res['token'])->not->toBe($old)
        ->and(Carbon::parse($res['expires_at'])->equalTo(now()->addDays(30)->startOfSecond()))->toBeTrue();

    $new = PersonalAccessToken::findToken($res['token']);
    expect(PersonalAccessToken::find($oldId))->toBeNull()
        ->and($new->name)->toBe('pixel')
        ->and($new->abilities)->toBe(['mobile'])
        ->and($device->fresh()->personal_access_token_id)->toBe($new->id);

    mbFresh();
    $this->withToken($old)->getJson('/api/v1/me')->assertUnauthorized();
    mbFresh();
    $this->withToken($res['token'])->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.id', $this->user->id);
});

it('keeps legacy tokens without an expiry working, and lets them refresh into an expiring one', function () {
    $legacy = $this->user->createToken('old-app')->plainTextToken;

    $this->travel(400)->days();
    $this->withToken($legacy)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.token_expires_at', null);

    mbFresh();
    $res = $this->withToken($legacy)->postJson('/api/v1/auth/refresh')->assertOk()->json();

    expect($res['expires_at'])->not->toBeNull()
        ->and(PersonalAccessToken::findToken($res['token'])->name)->toBe('old-app')
        ->and($this->user->tokens()->count())->toBe(1);
});

it('logs out: deletes the token and its devices and checks her out of the queue', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true]);
    Shift::factory()->create();
    foreach (['facebook', 'instagram', 'whatsapp', 'tiktok'] as $p) {
        $this->user->userPlatforms()->create(['platform' => $p]);
    }
    $this->user->forceFill(['last_seen_at' => now()])->save();
    $member = app(ShiftService::class)->checkIn($this->user->fresh());

    $token = mbLogin($this->user)['token'];
    $tokenId = PersonalAccessToken::findToken($token)->id;
    $mine = mbDevice($this->user, $tokenId, 'mine');
    $otherToken = $this->user->createToken('tablet', ['mobile'], now()->addDays(30));
    $other = mbDevice($this->user, $otherToken->accessToken->id, 'other');

    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk()->assertExactJson(['data' => ['ok' => true]]);

    expect(PersonalAccessToken::find($tokenId))->toBeNull()
        ->and(DeviceToken::find($mine->id))->toBeNull()
        ->and(DeviceToken::find($other->id))->not->toBeNull()
        ->and($member->fresh()->status)->toBe('left');

    mbFresh();
    $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
});

it('returns preferences, token expiry and realtime on me', function () {
    $this->user->forceFill(['preferences' => ['notify_scope' => 'mine_and_handover']])->save();
    $login = mbLogin($this->user);

    $this->withToken($login['token'])->getJson('/api/v1/me')->assertOk()
        ->assertJsonPath('data.id', $this->user->id)
        ->assertJsonPath('data.preferences', ['notify_scope' => 'mine_and_handover'])
        ->assertJsonPath('data.token_expires_at', $login['expires_at'])
        ->assertJsonPath('realtime.driver', 'reverb')
        ->assertJsonPath('realtime.key', 'pub-key')
        ->assertJsonMissingPath('realtime.secret');
});

it('refuses an inactive user with 401 and revokes the token', function () {
    $token = mbLogin($this->user)['token'];
    $this->user->forceFill(['is_active' => false])->save();
    mbFresh();

    $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    expect($this->user->tokens()->count())->toBe(0);
});

it('requires a token to refresh', function () {
    $this->postJson('/api/v1/auth/refresh')->assertUnauthorized();
});

it('reports and expires legacy tokens from the CLI', function () {
    $this->user->createToken('old-app');
    $this->user->createToken('new', ['mobile'], now()->addDays(30));

    $this->artisan('crm:mobile-tokens', ['--expire-legacy' => true, '--dry-run' => true])->assertSuccessful();
    expect(PersonalAccessToken::query()->whereNull('expires_at')->count())->toBe(1);

    $this->artisan('crm:mobile-tokens', ['--expire-legacy' => true])->assertSuccessful();
    expect(PersonalAccessToken::query()->whereNull('expires_at')->count())->toBe(0);
});

it('refuses the second of two racing refreshes of the same token, leaving one live token', function () {
    $plain = mbLogin($this->user)['token'];
    $stale = PersonalAccessToken::findToken($plain);

    $this->withToken($plain)->postJson('/api/v1/auth/refresh')->assertOk();

    // The second request authenticated with the same token before the first one deleted it.
    $this->user->withAccessToken($stale);
    $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/auth/refresh')->assertUnauthorized();

    expect($this->user->tokens()->count())->toBe(1);
});

it('also deletes the device named in the logout body, only if it is hers', function () {
    $token = mbLogin($this->user)['token'];
    $orphan = mbDevice($this->user, null, 'phone-fcm');
    $foreign = mbDevice(User::factory()->create(), null, 'someone-else');

    $this->withToken($token)->postJson('/api/v1/auth/logout', ['device_token' => 'phone-fcm'])->assertOk();
    mbFresh();
    $t2 = mbLogin($this->user)['token'];
    $this->withToken($t2)->postJson('/api/v1/auth/logout', ['device_token' => 'someone-else'])->assertOk();

    expect(DeviceToken::find($orphan->id))->toBeNull()->and(DeviceToken::find($foreign->id))->not->toBeNull();
});

it('hands the app the public reverb endpoint, not the server-internal one', function () {
    config([
        'broadcasting.connections.reverb.options.host' => '127.0.0.1',
        'broadcasting.connections.reverb.options.port' => 8080,
        'broadcasting.connections.reverb.options.scheme' => 'http',
        'crm.mobile.reverb' => ['host' => 'ws.levoile.test', 'port' => null, 'scheme' => 'https'],
    ]);

    expect(mbLogin($this->user)['realtime'])->toBe(['driver' => 'reverb', 'key' => 'pub-key', 'host' => 'ws.levoile.test', 'port' => 443, 'scheme' => 'https']);

    config(['crm.mobile.reverb' => ['host' => 'ws.levoile.test', 'port' => '6001', 'scheme' => 'http']]);
    expect(mbLogin($this->user)['realtime'])->toMatchArray(['host' => 'ws.levoile.test', 'port' => 6001, 'scheme' => 'http']);
});

it('reads the public endpoint from CRM_MOBILE_REVERB_* then VITE_REVERB_*', function () {
    $config = fn () => (require base_path('config/crm.php'))['mobile']['reverb'];
    $set = function (array $vars) {
        foreach ($vars as $k => $v) {
            putenv($v === null ? $k : "{$k}={$v}");
            $_ENV[$k] = $_SERVER[$k] = $v;
            if ($v === null) {
                unset($_ENV[$k], $_SERVER[$k]);
            }
        }
    };

    $keys = ['CRM_MOBILE_REVERB_HOST', 'VITE_REVERB_HOST', 'CRM_MOBILE_REVERB_SCHEME', 'VITE_REVERB_SCHEME'];
    $original = array_combine($keys, array_map(fn ($k) => $_ENV[$k] ?? null, $keys));

    try {
        $set(['CRM_MOBILE_REVERB_HOST' => '', 'VITE_REVERB_HOST' => 'vite.example.test', 'CRM_MOBILE_REVERB_SCHEME' => null, 'VITE_REVERB_SCHEME' => 'https']);
        expect($config())->toMatchArray(['host' => 'vite.example.test', 'scheme' => 'https']);

        $set(['CRM_MOBILE_REVERB_HOST' => 'mobile.example.test']);
        expect($config()['host'])->toBe('mobile.example.test');
    } finally {
        $set($original);
    }
});
