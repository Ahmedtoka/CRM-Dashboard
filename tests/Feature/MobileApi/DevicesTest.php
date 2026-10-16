<?php

use App\Models\DeviceToken;
use App\Models\User;
use App\Push\Drivers\FakePushSender;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function () {
    config(['crm.push.driver' => 'fake']);
    $this->user = User::factory()->create(['role' => 'moderator']);
    $this->token = $this->user->createToken('pixel', ['mobile'], now()->addDays(30));
});

function mbAs(string $plain)
{
    app('auth')->forgetGuards();

    return test()->withToken($plain);
}

it('registers a device bound to the current token, then updates it in place', function () {
    $res = mbAs($this->token->plainTextToken)->postJson('/api/v1/devices', [
        'token' => 'fcm-abc', 'platform' => 'android', 'app_version' => '1.0.0', 'locale' => 'ar',
    ])->assertCreated()->assertJsonStructure(['data' => ['id', 'platform', 'app_version', 'last_registered_at']]);

    $device = DeviceToken::find($res->json('data.id'));
    expect($device->user_id)->toBe($this->user->id)
        ->and($device->personal_access_token_id)->toBe($this->token->accessToken->id)
        ->and($device->token_hash)->toBe(hash('sha256', 'fcm-abc'))
        ->and($res->json())->not->toHaveKey('data.token');

    $device->forceFill(['disabled_at' => now(), 'failure_count' => 4])->save();

    mbAs($this->token->plainTextToken)->postJson('/api/v1/devices', ['token' => 'fcm-abc', 'platform' => 'android', 'app_version' => '1.1.0'])
        ->assertOk()->assertJsonPath('data.id', $device->id)->assertJsonPath('data.app_version', '1.1.0');

    expect(DeviceToken::count())->toBe(1)
        ->and($device->fresh()->disabled_at)->toBeNull()
        ->and($device->fresh()->failure_count)->toBe(0);
});

it('rebinds a token registered by another user or another login to the caller', function () {
    $other = User::factory()->create();
    $row = DeviceToken::factory()->create(['user_id' => $other->id, 'token' => 'shared-phone', 'token_hash' => hash('sha256', 'shared-phone')]);

    mbAs($this->token->plainTextToken)->postJson('/api/v1/devices', ['token' => 'shared-phone', 'platform' => 'android'])->assertOk();

    expect($row->fresh()->user_id)->toBe($this->user->id)
        ->and($row->fresh()->personal_access_token_id)->toBe($this->token->accessToken->id);
});

it('validates the registration', function () {
    mbAs($this->token->plainTextToken)->postJson('/api/v1/devices', ['token' => '', 'platform' => 'windows', 'locale' => 'fr'])
        ->assertUnprocessable()->assertJsonValidationErrors(['token', 'platform', 'locale']);
});

it('deletes only the caller\'s own device and always answers 204', function () {
    $mine = DeviceToken::factory()->create(['user_id' => $this->user->id, 'token' => 'mine', 'token_hash' => hash('sha256', 'mine')]);
    $theirs = DeviceToken::factory()->create(['token' => 'theirs', 'token_hash' => hash('sha256', 'theirs')]);

    mbAs($this->token->plainTextToken)->deleteJson('/api/v1/devices', ['token' => 'theirs'])->assertNoContent();
    mbAs($this->token->plainTextToken)->deleteJson('/api/v1/devices', ['token' => 'mine'])->assertNoContent();
    mbAs($this->token->plainTextToken)->deleteJson('/api/v1/devices', ['token' => 'never-seen'])->assertNoContent();

    expect(DeviceToken::find($mine->id))->toBeNull()->and(DeviceToken::find($theirs->id))->not->toBeNull();
});

it('sends a test push to the caller\'s active devices and reports counts', function () {
    DeviceToken::factory()->count(2)->create(['user_id' => $this->user->id]);
    DeviceToken::factory()->disabled()->create(['user_id' => $this->user->id]);
    DeviceToken::factory()->create();

    mbAs($this->token->plainTextToken)->postJson('/api/v1/devices/test')->assertOk()
        ->assertExactJson(['data' => ['driver' => 'fake', 'devices' => 2, 'sent' => 2, 'failed' => 0]]);

    $fake = app(FakePushSender::class);
    expect($fake->sent)->toHaveCount(2)
        ->and($fake->sent[0]['message']->title)->toBe('تجربة إشعار');
});

it('reports the null driver without sending', function () {
    config(['crm.push.driver' => null]);
    DeviceToken::factory()->create(['user_id' => $this->user->id]);

    mbAs($this->token->plainTextToken)->postJson('/api/v1/devices/test')->assertOk()
        ->assertExactJson(['data' => ['driver' => null, 'devices' => 1, 'sent' => 0, 'failed' => 0]]);
});

it('requires a token', function () {
    $this->postJson('/api/v1/devices', ['token' => 'x', 'platform' => 'android'])->assertUnauthorized();
    $this->deleteJson('/api/v1/devices', ['token' => 'x'])->assertUnauthorized();
});

it('drops the token\'s devices when the token itself is deleted', function () {
    DeviceToken::factory()->create(['user_id' => $this->user->id, 'personal_access_token_id' => $this->token->accessToken->id]);

    PersonalAccessToken::find($this->token->accessToken->id)->delete();

    expect(DeviceToken::count())->toBe(0);
});

it('sends the test push from the CLI', function () {
    DeviceToken::factory()->create(['user_id' => $this->user->id]);

    $this->artisan('crm:push-test', ['user' => (string) $this->user->id])->assertSuccessful();
    $this->artisan('crm:push-test', ['user' => $this->user->email])->assertSuccessful();
    $this->artisan('crm:push-test', ['user' => 'nobody@example.test'])->assertFailed();

    expect(app(FakePushSender::class)->sent)->toHaveCount(2);
});
