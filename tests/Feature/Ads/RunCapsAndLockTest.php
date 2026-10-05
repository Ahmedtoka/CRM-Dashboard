<?php

use App\Ads\Platforms\Fake\FakeAdsDriver;
use App\Enums\UserRole;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdAccountAssignment;
use App\Models\AdsAuditLog;
use App\Models\AdWriteAction;
use App\Models\MediaBuyer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    Http::preventStrayRequests();
    Cache::forget('ads-fake-writer');
    FakeAdsDriver::reset();
    config(['crm.ads.drivers.meta' => 'fake', 'crm.ads.drivers.tiktok' => 'fake', 'crm.ads.drivers.google' => 'fake', 'crm.ads.write_sandbox_accounts' => []]);
    $this->acc = AdAccount::factory()->meta()->create();
    $this->admin = User::factory()->adsAuthority()->create(['role' => UserRole::Admin, 'name' => 'Owner']);
});

function clBuyer(AdAccount $acc): User
{
    $user = User::factory()->create(['role' => UserRole::MediaBuyer]);
    $buyer = MediaBuyer::factory()->create(['user_id' => $user->id]);
    AdAccountAssignment::factory()->create(['ad_account_id' => $acc->id, 'media_buyer_id' => $buyer->id, 'starts_on' => '2026-01-01', 'ends_on' => null]);

    return $user;
}

function clAd(AdAccount $acc, string $status = 'PAUSED'): Ad
{
    return Ad::factory()->for($acc, 'account')->create(['status' => $status]);
}

function clPropose($test, User $u, AdAccount $acc, Ad $ad, string $to = 'active')
{
    return $test->actingAs($u)->postJson('/ads/write-actions', [
        'type' => 'set_status', 'account_id' => $acc->id, 'target' => ['level' => 'ad', 'external_id' => $ad->external_id], 'params' => ['to' => $to],
    ], ['Idempotency-Key' => 'k-'.bin2hex(random_bytes(8))]);
}

function clConfirm($test, User $u, $proposal)
{
    $proposal->assertSuccessful();
    $x = AdWriteAction::where('public_id', $proposal->json('action.id'))->sole();

    return $test->actingAs($u)->postJson("/ads/write-actions/{$x->public_id}/confirm", ['diff_hash' => $x->diff_hash]);
}

/** Propose + confirm in one go. */
function clDo($test, User $u, AdAccount $acc, Ad $ad, string $to = 'active')
{
    return clConfirm($test, $u, clPropose($test, $u, $acc, $ad, $to));
}

function clCalls(): int
{
    return count(Cache::get('ads-fake-writer')['statuses'] ?? []);
}

it('caps Runs per user per day: noop and succeeded Runs count, a failed one does not', function () {
    $buyer = clBuyer($this->acc);
    Artisan::call('ads:write-limits', ['--user' => (string) $buyer->id, '--set' => ['activations_per_user_day=2']]);

    clDo($this, $buyer, $this->acc, clAd($this->acc))->assertOk()->assertJsonPath('action.state', 'succeeded');
    FakeAdsDriver::failNext('setStatus', 'rejected');
    clDo($this, $buyer, $this->acc, clAd($this->acc))->assertStatus(422)->assertJsonPath('code', 'platform_rejected');
    clDo($this, $buyer, $this->acc, clAd($this->acc, 'ACTIVE'))->assertOk()->assertJsonPath('action.outcome.noop', true);
    expect(clCalls())->toBe(1);

    $third = clPropose($this, $buyer, $this->acc, clAd($this->acc));
    clConfirm($this, $buyer, $third)->assertStatus(422)
        ->assertJsonPath('code', 'cap_exceeded')
        ->assertJsonPath('details.key', 'activations_per_user_day')
        ->assertJsonPath('details.limit', 2)
        ->assertJsonPath('details.used', 2);

    $x = AdWriteAction::where('public_id', $third->json('action.id'))->sole();
    expect($x->state)->toBe('failed')->and($x->error_code)->toBe('cap_exceeded')
        ->and($x->open_business_key)->toBeNull()->and($x->confirmed_at)->toBeNull()
        ->and(clCalls())->toBe(1)
        ->and(AdsAuditLog::where('action', 'write.failed')->where('subject_id', $x->id)->count())->toBe(1);
});

it('records the activation caps it evaluated in limits_checked', function () {
    $buyer = clBuyer($this->acc);
    $res = clPropose($this, $buyer, $this->acc, clAd($this->acc));
    clConfirm($this, $buyer, $res)->assertOk();

    $rows = collect(AdWriteAction::where('public_id', $res->json('action.id'))->sole()->limits_checked)->keyBy('key');
    expect($rows['activations_per_user_day'])->toBe(['key' => 'activations_per_user_day', 'limit' => 20, 'requested' => 1, 'outcome' => 'ok'])
        ->and($rows['activations_per_account_day'])->toBe(['key' => 'activations_per_account_day', 'limit' => 30, 'requested' => 1, 'outcome' => 'ok'])
        ->and($rows->has('max_daily_budget'))->toBeTrue();
});

it('caps Runs per account per day across users', function () {
    $a = clBuyer($this->acc);
    $b = clBuyer($this->acc);
    Artisan::call('ads:write-limits', ['--account' => (string) $this->acc->id, '--set' => ['activations_per_account_day=3']]);

    clDo($this, $a, $this->acc, clAd($this->acc))->assertOk();
    clDo($this, $a, $this->acc, clAd($this->acc))->assertOk();
    clDo($this, $b, $this->acc, clAd($this->acc))->assertOk();

    clDo($this, $b, $this->acc, clAd($this->acc))->assertStatus(422)
        ->assertJsonPath('code', 'cap_exceeded')->assertJsonPath('details.key', 'activations_per_account_day')
        ->assertJsonPath('details.limit', 3)->assertJsonPath('details.used', 3);

    // another account is not affected
    $other = AdAccount::factory()->meta()->create();
    $c = clBuyer($other);
    clDo($this, $c, $other, clAd($other))->assertOk();
});

it('resets the count at Cairo midnight', function () {
    $buyer = clBuyer($this->acc);
    Artisan::call('ads:write-limits', ['--user' => (string) $buyer->id, '--set' => ['activations_per_user_day=1']]);

    $this->travelTo(CarbonImmutable::parse('2026-10-07 23:59:00', 'Africa/Cairo'));
    clDo($this, $buyer, $this->acc, clAd($this->acc))->assertOk();
    clDo($this, $buyer, $this->acc, clAd($this->acc))->assertStatus(422)->assertJsonPath('code', 'cap_exceeded');

    $this->travelTo(CarbonImmutable::parse('2026-10-08 00:01:00', 'Africa/Cairo'));
    clDo($this, $buyer, $this->acc, clAd($this->acc))->assertOk()->assertJsonPath('action.state', 'succeeded');
});

it('never counts or caps a Stop', function () {
    $buyer = clBuyer($this->acc);
    Artisan::call('ads:write-limits', ['--user' => (string) $buyer->id, '--set' => ['activations_per_user_day=1']]);
    Artisan::call('ads:write-limits', ['--account' => (string) $this->acc->id, '--set' => ['activations_per_account_day=0']]);

    foreach (range(1, 3) as $i) {
        clDo($this, $buyer, $this->acc, clAd($this->acc, 'ACTIVE'), 'paused')->assertOk()->assertJsonPath('action.state', 'succeeded');
    }
    clDo($this, $buyer, $this->acc, clAd($this->acc))->assertStatus(422)->assertJsonPath('details.key', 'activations_per_account_day');

    Artisan::call('ads:write-limits', ['--account' => (string) $this->acc->id, '--clear' => ['activations_per_account_day']]);
    clDo($this, $buyer, $this->acc, clAd($this->acc))->assertOk();
});

it('a Stop by an Ads-authority holder locks other users\' Runs on that target for 7 days', function () {
    $this->freezeTime();
    $ad = clAd($this->acc, 'ACTIVE');
    $buyer = clBuyer($this->acc);

    clDo($this, $this->admin, $this->acc, $ad, 'paused')->assertOk();
    $stop = AdWriteAction::where('to_status', 'paused')->sole();
    expect($stop->restart_lock_until?->toIso8601String())->toBe(now()->addDays(7)->toIso8601String());

    clPropose($this, $buyer, $this->acc, $ad)->assertStatus(422)
        ->assertJsonPath('code', 'restart_locked')
        ->assertJsonPath('details.until', now()->addDays(7)->toIso8601String())
        ->assertJsonPath('details.by', 'Owner');
    expect(AdWriteAction::where('to_status', 'active')->count())->toBe(0);

    // the holder's own Run lifts it: it becomes the latest succeeded action on the target
    clDo($this, $this->admin, $this->acc, $ad)->assertOk()->assertJsonPath('action.state', 'succeeded');
    clPropose($this, $buyer, $this->acc, $ad)->assertCreated();
});

it('a buyer Stop does not lock', function () {
    $ad = clAd($this->acc, 'ACTIVE');
    $a = clBuyer($this->acc);
    $b = clBuyer($this->acc);

    clDo($this, $a, $this->acc, $ad, 'paused')->assertOk();

    expect(AdWriteAction::where('to_status', 'paused')->sole()->restart_lock_until)->toBeNull();
    clDo($this, $b, $this->acc, $ad)->assertOk()->assertJsonPath('action.state', 'succeeded');
});

it('the lock expires after 7 days', function () {
    $ad = clAd($this->acc, 'ACTIVE');
    $buyer = clBuyer($this->acc);
    clDo($this, $this->admin, $this->acc, $ad, 'paused')->assertOk();

    $this->travel(7)->days();
    $this->travel(1)->minutes();

    clDo($this, $buyer, $this->acc, $ad)->assertOk()->assertJsonPath('action.state', 'succeeded');
});

it('an Ads-authority holder is never locked, even by another holder', function () {
    $ad = clAd($this->acc, 'ACTIVE');
    $other = User::factory()->adsAuthority()->create(['role' => UserRole::Admin]);
    clDo($this, $this->admin, $this->acc, $ad, 'paused')->assertOk();

    clDo($this, $other, $this->acc, $ad)->assertOk()->assertJsonPath('action.state', 'succeeded');
});

it('notes that a Run may re-enter learning when the CRM paused it more than 7 days ago', function () {
    $ad = clAd($this->acc, 'ACTIVE');
    $buyer = clBuyer($this->acc);
    clDo($this, $buyer, $this->acc, $ad, 'paused')->assertOk();

    $this->travel(3)->days();
    expect(clPropose($this, $buyer, $this->acc, $ad)->assertCreated()->json('notes'))->toBe([]);

    $this->travel(7)->days();
    $res = clPropose($this, $buyer, $this->acc, $ad)->assertCreated();

    expect($res->json('notes'))->toBe([['key' => 'learning_reentry', 'days' => 10]])
        ->and(__('ads.write.notes.learning_reentry', ['days' => 10]))->not->toBe('ads.write.notes.learning_reentry');
});

it('a buyer\'s second Stop does not lift the holder\'s restart lock; only a later Run does', function () {
    $ad = clAd($this->acc, 'ACTIVE');
    $buyer = clBuyer($this->acc);

    clDo($this, $this->admin, $this->acc, $ad, 'paused')->assertOk();
    clDo($this, $buyer, $this->acc, $ad, 'paused')->assertOk()->assertJsonPath('action.state', 'succeeded');

    clPropose($this, $buyer, $this->acc, $ad)->assertStatus(422)->assertJsonPath('code', 'restart_locked')->assertJsonPath('details.by', 'Owner');

    clDo($this, $this->admin, $this->acc, $ad)->assertOk()->assertJsonPath('action.state', 'succeeded');
    clPropose($this, $buyer, $this->acc, $ad)->assertCreated();
});

it('a failing restart lock never skips Stop-beats-Run (rule 4)', function () {
    Log::spy();
    $ad = clAd($this->acc);
    $buyer = clBuyer($this->acc);
    $run = clPropose($this, $buyer, $this->acc, $ad);
    FakeAdsDriver::failNext('setStatus', 'unreachable_after');
    FakeAdsDriver::failNext('readObject', 'unreachable_before');
    clConfirm($this, $buyer, $run)->assertStatus(202);
    $runRow = AdWriteAction::where('public_id', $run->json('action.id'))->sole();
    expect($runRow->state)->toBe('unknown');

    DB::beforeExecuting(function (string $sql) {
        if (str_contains($sql, 'restart_lock_until') && str_starts_with(strtolower(ltrim($sql)), 'update')) {
            throw new RuntimeException('lock write failed');
        }
    });

    clDo($this, $this->admin, $this->acc, $ad, 'paused')->assertOk()->assertJsonPath('action.state', 'succeeded');

    expect($runRow->fresh()->state)->toBe('superseded_by_stop')
        ->and(AdWriteAction::where('to_status', 'paused')->sole()->restart_lock_until)->toBeNull();
    Log::shouldHaveReceived('error')->withArgs(fn ($m, $ctx) => $m === 'ads write: unexpected error' && $ctx['message'] === 'lock write failed');
});

it('checks the latest holder Stop, not the one whose lock ends last', function () {
    $ad = clAd($this->acc, 'ACTIVE');
    $buyer = clBuyer($this->acc);
    $long = User::factory()->adsAuthority()->create(['role' => UserRole::Admin, 'name' => 'Long']);
    Artisan::call('ads:write-limits', ['--user' => $long->id, '--set' => ['restart_lock_days=14']]);
    Artisan::call('ads:write-limits', ['--user' => $this->admin->id, '--set' => ['restart_lock_days=3']]);

    // 14-day lock, lifted by a holder Run; then a 3-day lock by another holder
    clDo($this, $long, $this->acc, $ad, 'paused')->assertOk();
    $this->travel(1)->minutes();
    clDo($this, $long, $this->acc, $ad)->assertOk()->assertJsonPath('action.state', 'succeeded');
    $this->travel(1)->minutes();
    clDo($this, $this->admin, $this->acc, $ad, 'paused')->assertOk();

    clPropose($this, $buyer, $this->acc, $ad)->assertStatus(422)->assertJsonPath('code', 'restart_locked')->assertJsonPath('details.by', 'Owner');
});
