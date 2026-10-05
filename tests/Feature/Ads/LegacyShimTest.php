<?php

use App\Ads\AdsSettings;
use App\Ads\Platforms\Fake\FakeAdsDriver;
use App\Enums\UserRole;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdAction;
use App\Models\AdWriteAction;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Http::preventStrayRequests();
    Cache::forget('ads-fake-writer');
    FakeAdsDriver::reset();
    config(['crm.ads.drivers.meta' => 'fake', 'crm.ads.drivers.tiktok' => 'fake', 'crm.ads.drivers.google' => 'fake', 'crm.ads.write_sandbox_accounts' => []]);
    $this->acc = AdAccount::factory()->meta()->create();
    $this->ad = Ad::factory()->for($this->acc, 'account')->create(['status' => 'ACTIVE']);
    $this->admin = User::factory()->create(['role' => UserRole::Admin]);
});

function lsPost($test, string $status = 'paused', array $headers = [], ?User $u = null)
{
    return $test->actingAs($u ?? $test->admin)->postJson('/ads/actions/status',
        ['account_id' => $test->acc->id, 'level' => 'ad', 'external_id' => $test->ad->external_id, 'status' => $status, 'reason' => 'x'], $headers);
}

function lsCalls(): int
{
    return count(Cache::get('ads-fake-writer')['statuses'] ?? []);
}

it('replays a double click: two identical posts within 10 s give one action and one platform call', function () {
    $first = lsPost($this)->assertOk();
    $second = lsPost($this)->assertOk()->assertJsonPath('ok', true);

    expect(AdWriteAction::count())->toBe(1)->and(lsCalls())->toBe(1)
        ->and($second->json('action_id'))->toBe($first->json('action_id'))
        ->and(AdWriteAction::sole()->idempotency_key)->toStartWith('legacy:');
});

it('treats a deliberate repeat after the 10-second window as a new action', function () {
    lsPost($this)->assertOk();
    $this->travel(11)->seconds();
    lsPost($this)->assertOk();

    expect(AdWriteAction::count())->toBe(2)->and(lsCalls())->toBe(2);
});

it('uses the Idempotency-Key header when the client sends one', function () {
    lsPost($this, 'paused', ['Idempotency-Key' => 'dialog-key-0001'])->assertOk();
    lsPost($this, 'paused', ['Idempotency-Key' => 'dialog-key-0002'])->assertOk();
    lsPost($this, 'paused', ['Idempotency-Key' => 'dialog-key-0001'])->assertOk();

    expect(AdWriteAction::pluck('idempotency_key')->sort()->values()->all())->toBe(['dialog-key-0001', 'dialog-key-0002'])->and(lsCalls())->toBe(2);
});

it('answers 202 pending when a Stop is throttled and will be retried', function () {
    Queue::fake();
    FakeAdsDriver::failNext('setStatus', 'rate');

    lsPost($this)->assertStatus(202)->assertJsonPath('ok', false)->assertJsonPath('pending', true)
        ->assertJsonPath('status', 'PAUSED')->assertJsonPath('message', __('ads.errors.stop_retrying'));

    expect(AdWriteAction::sole()->state)->toBe('executing');
});

it('answers 202 pending when the outcome is unknown', function () {
    FakeAdsDriver::failNext('setStatus', 'unreachable_after');
    FakeAdsDriver::failNext('readObject', 'unreachable_before');

    lsPost($this)->assertStatus(202)->assertJsonPath('pending', true)->assertJsonPath('message', __('ads.errors.unknown_outcome'));
});

it('refuses a Run with 503 while the kill switch is off, and still Stops', function () {
    app(AdsSettings::class)->set('writes_enabled', false);
    $this->ad->update(['status' => 'PAUSED']);

    lsPost($this, 'active')->assertStatus(503)->assertJsonPath('code', 'writes_disabled')->assertJsonPath('errors.status.0', __('ads.errors.writes_disabled'));
    lsPost($this, 'paused')->assertOk();
    expect(lsCalls())->toBe(1);
});

it('goes through the Run guard: a Run of an ad already ACTIVE is a noop, no platform call', function () {
    lsPost($this, 'active')->assertOk()->assertJsonPath('ok', true);

    expect(lsCalls())->toBe(0)->and(AdWriteAction::sole()->outcome['noop'])->toBeTrue();
});

it('logs ads.legacy_write_endpoint on every call, refusals included, without the body', function () {
    Log::spy();

    lsPost($this)->assertOk();
    lsPost($this, 'paused', [], User::factory()->create(['role' => UserRole::Supervisor, 'is_active' => true]))->assertOk(); // replay key differs per user
    $this->acc->update(['is_active' => false]);
    $this->travel(11)->seconds(); // outside the double-click window: a new request, refused
    lsPost($this)->assertForbidden(); // a refusal is logged too

    Log::shouldHaveReceived('info')->withArgs(fn ($msg, $ctx = []) => $msg === 'ads.legacy_write_endpoint' && isset($ctx['user_id']) && ! isset($ctx['reason']))->times(3);
});

it('keeps the old validation answer for a bad body', function () {
    $this->actingAs($this->admin)->postJson('/ads/actions/status', ['account_id' => $this->acc->id, 'level' => 'account', 'external_id' => 'x', 'status' => 'paused'])
        ->assertStatus(422)->assertJsonValidationErrors('level');
    expect(AdWriteAction::count())->toBe(0);
});

it('freezes ad_actions: the model refuses to create, update or delete', function () {
    expect(fn () => AdAction::create(['platform' => 'meta', 'level' => 'ad', 'external_id' => '1', 'to_status' => 'PAUSED', 'result' => 'ok']))
        ->toThrow(LogicException::class, 'ad_actions is frozen; use ad_write_actions');
});
