<?php

use App\Maintenance\FreshStart;
use App\Models\DeviceToken;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Laravel\Sanctum\PersonalAccessToken;

it('hashes a push token to sha256 hex', function () {
    expect(DeviceToken::hashToken('abc'))->toBe(hash('sha256', 'abc'))
        ->and(strlen(DeviceToken::hashToken(str_repeat('x', 300))))->toBe(64);
});

it('creates a device token from the factory with its relations', function () {
    $user = User::factory()->create();
    $access = $user->createToken('mobile', ['mobile'])->accessToken;

    $device = DeviceToken::factory()->for($user)->create(['personal_access_token_id' => $access->id]);

    expect($device->token_hash)->toBe(DeviceToken::hashToken($device->token))
        ->and($device->failure_count)->toBe(0)
        ->and($device->last_registered_at)->toBeInstanceOf(CarbonInterface::class)
        ->and($device->user->is($user))->toBeTrue()
        ->and($device->accessToken)->toBeInstanceOf(PersonalAccessToken::class)
        ->and($user->deviceTokens()->count())->toBe(1);
});

it('keeps a long FCM token intact', function () {
    $token = str_repeat('a1B2-c3_', 40); // 320 chars
    $device = DeviceToken::factory()->create(['token' => $token, 'token_hash' => DeviceToken::hashToken($token)]);

    expect($device->fresh()->token)->toBe($token);
});

it('rejects a duplicate token_hash', function () {
    $device = DeviceToken::factory()->create();

    DeviceToken::factory()->create(['token' => $device->token, 'token_hash' => $device->token_hash]);
})->throws(UniqueConstraintViolationException::class);

it('is deleted with its user', function () {
    $device = DeviceToken::factory()->create();

    $device->user->delete();

    expect(DeviceToken::query()->count())->toBe(0);
});

it('is deleted with its access token', function () {
    $user = User::factory()->create();
    $access = $user->createToken('mobile', ['mobile'])->accessToken;
    DeviceToken::factory()->for($user)->create(['personal_access_token_id' => $access->id]);
    $other = DeviceToken::factory()->for($user)->create(['personal_access_token_id' => null]);

    $access->delete();

    expect(DeviceToken::query()->pluck('id')->all())->toBe([$other->id]);
});

it('scopes to active (not disabled) devices', function () {
    $active = DeviceToken::factory()->create();
    DeviceToken::factory()->disabled()->create();

    expect(DeviceToken::query()->active()->pluck('id')->all())->toBe([$active->id]);
});

it('is wiped by a fresh start', function () {
    expect(FreshStart::WIPE)->toContain('device_tokens')
        ->and(FreshStart::KEEP)->not->toContain('device_tokens');
});

it('exposes the mobile and push config', function () {
    expect(config('crm.mobile.token_ttl_days'))->toBe(30)
        ->and(config('crm.mobile.token_refresh_days'))->toBe(7)
        ->and(config('crm.mobile.media_url_ttl_minutes'))->toBe(120)
        ->and(config('crm.mobile.client_id_ttl_hours'))->toBe(48)
        ->and(config('crm.push'))->toHaveKeys(['driver', 'fcm', 'types'])
        ->and(config('crm.push.fcm'))->toHaveKeys(['project_id', 'credentials'])
        ->and(config('crm.push.types')['queue.assigned'])->toBe(['channel' => 'queue', 'priority' => 'high'])
        ->and(config('crm.push.types')['note.mention'])->toBe(['channel' => 'mentions', 'priority' => 'normal'])
        ->and(config('crm.push.types')['conversation.handover_urgent'])->toBe(['channel' => 'handover', 'priority' => 'high'])
        ->and(config('crm.push.types')['queue.waiting_no_desk'])->toBe(['channel' => 'queue', 'priority' => 'high']) // round 2026-10-24: a customer nobody logged in can take
        ->and(config('crm.push.types'))->toHaveCount(10);
});
