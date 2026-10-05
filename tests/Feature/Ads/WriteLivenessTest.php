<?php

/*
 * Batch 2 review fix round 1: rule 4 re-apply on any non-definite Run outcome, notices for every unwatched Stop failure,
 * the named write limiter, Stop retry liveness (lost dispatch, job failure, sweeper) and the claim deadlock mapping.
 */

use App\Ads\Control\Write\Jobs\RetryStopWrite;
use App\Ads\Control\Write\WriteActionService;
use App\Ads\Control\Write\WriteDenied;
use App\Ads\Control\Write\WriteExecutor;
use App\Ads\Platforms\Fake\FakeAdsDriver;
use App\Enums\UserRole;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdAccountAssignment;
use App\Models\AdsAuditLog;
use App\Models\AdWriteAction;
use App\Models\MediaBuyer;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Http::preventStrayRequests();
    Cache::forget('ads-fake-writer');
    FakeAdsDriver::reset();
    config(['crm.ads.drivers.meta' => 'fake', 'crm.ads.drivers.tiktok' => 'fake', 'crm.ads.drivers.google' => 'fake', 'crm.ads.write_sandbox_accounts' => []]);
    $this->acc = AdAccount::factory()->meta()->create(['external_id' => 'act_5550001']);
    $this->ad = Ad::factory()->for($this->acc, 'account')->create(['status' => 'PAUSED']);
    $this->admin = User::factory()->adsAuthority()->create(['role' => UserRole::Admin]);
});

function lvBuyer(AdAccount $acc): User
{
    $user = User::factory()->create(['role' => UserRole::MediaBuyer]);
    $buyer = MediaBuyer::factory()->create(['user_id' => $user->id]);
    AdAccountAssignment::factory()->create(['ad_account_id' => $acc->id, 'media_buyer_id' => $buyer->id, 'starts_on' => '2026-01-01', 'ends_on' => null]);

    return $user;
}

function lvPropose(User $u, AdAccount $acc, string $id, string $to): AdWriteAction
{
    return app(WriteActionService::class)->propose($u, $acc, 'ad', $id, $to, 'test', 'k-'.bin2hex(random_bytes(8)))['action'];
}

function lvConfirm(User $u, AdWriteAction $x): AdWriteAction
{
    return app(WriteActionService::class)->confirm($u, $x->fresh(), $x->diff_hash);
}

function lvStatuses(): array
{
    return array_column(Cache::get('ads-fake-writer')['statuses'] ?? [], 'status');
}

/** A Run confirmed while, inside its platform call, an admin's Stop on the same ad succeeds; $after runs right after the Stop. */
function lvRunRacedByStop($test, callable $after): array
{
    $buyer = lvBuyer($test->acc);
    $run = lvPropose($buyer, $test->acc, $test->ad->external_id, 'active');
    $stop = null;
    FakeAdsDriver::beforeSetStatus(function () use ($test, $after, &$stop) {
        $stop = lvPropose($test->admin, $test->acc, $test->ad->external_id, 'paused');
        expect(lvConfirm($test->admin, $stop)->state)->toBe('succeeded');
        $after();
    });
    $end = lvConfirm($buyer, $run);

    return [$end, $run->fresh(), $stop->fresh()];
}

it('rule 4: re-applies the Stop when the superseded Run ends unknown (the call may have landed, read-back failed)', function () {
    [$end, $run, $stop] = lvRunRacedByStop($this, function () {
        FakeAdsDriver::failNext('setStatus', 'unreachable_after'); // the Run's own call lands, its answer is lost
        FakeAdsDriver::failNext('readObject', 'unreachable_before');
    });

    expect($end->state)->toBe('superseded_by_stop')->and($run->outcome['maybe_landed_after_stop'])->toBe('unknown')
        ->and(lvStatuses())->toBe(['paused', 'active', 'paused'])
        ->and($stop->state)->toBe('succeeded')->and($stop->steps()->count())->toBe(2)
        ->and($stop->outcome['reapply_of'])->toBe($run->public_id)
        ->and($this->ad->fresh()->status)->toBe('PAUSED');
});

it('rule 4: re-applies the Stop even when the Run call did not land and the read-back failed (pausing twice is harmless)', function () {
    [, $run, $stop] = lvRunRacedByStop($this, function () {
        FakeAdsDriver::failNext('setStatus', 'unreachable_before');
        FakeAdsDriver::failNext('readObject', 'unreachable_before');
    });

    expect($run->state)->toBe('superseded_by_stop')->and(lvStatuses())->toBe(['paused', 'paused'])
        ->and($stop->state)->toBe('succeeded')->and($stop->steps()->count())->toBe(2);
});

it('rule 4: re-applies the Stop when the read-back says the Run did not apply', function () {
    [, $run, $stop] = lvRunRacedByStop($this, function () {
        FakeAdsDriver::failNext('setStatus', 'unreachable_before'); // read-back then finds PAUSED: not_applied
    });

    expect($run->state)->toBe('superseded_by_stop')->and(lvStatuses())->toBe(['paused', 'paused'])->and($stop->steps()->count())->toBe(2);
});

it('rule 4: does not re-apply after a definite refusal of the Run call', function () {
    [, $run, $stop] = lvRunRacedByStop($this, function () {
        FakeAdsDriver::failNext('setStatus', 'rejected');
    });

    expect($run->state)->toBe('superseded_by_stop')->and(lvStatuses())->toBe(['paused'])->and($stop->steps()->count())->toBe(1);
});

it('tells the Ads-authority holders when a re-apply is refused at execute (account switched off)', function () {
    [, $run, $stop] = lvRunRacedByStop($this, function () {
        $this->acc->update(['write_enabled' => false]); // the Run's call still lands; the re-apply is then refused
    });

    expect($stop->state)->toBe('failed')->and($stop->error_code)->toBe('account_not_writable')
        ->and($stop->outcome['deep_link'])->toBe('https://adsmanager.facebook.com/adsmanager/manage/ads?act=5550001&selected_ad_ids='.$this->ad->external_id)
        ->and(lvStatuses())->toBe(['paused', 'active']);
    $n = UserNotification::where('type', 'ads.stop_failed')->where('user_id', $this->admin->id)->sole();
    expect($n->data['action_id'])->toBe($stop->public_id)->and($n->data['error_code'])->toBe('account_not_writable')->and($n->data['deep_link'])->toBe($stop->outcome['deep_link']);
});

it('tells the holders when a retried Stop ends in a definite platform refusal', function () {
    Queue::fake();
    $this->ad->update(['status' => 'ACTIVE']);
    $stop = lvPropose($this->admin, $this->acc, $this->ad->external_id, 'paused');
    FakeAdsDriver::failNext('setStatus', 'rate');
    expect(lvConfirm($this->admin, $stop)->state)->toBe('executing');
    FakeAdsDriver::failNext('setStatus', 'rejected');

    app()->call([Queue::pushed(RetryStopWrite::class)->first(), 'handle']);

    expect($stop->fresh()->state)->toBe('failed')->and($stop->fresh()->error_code)->toBe('platform_rejected')
        ->and(UserNotification::where('type', 'ads.stop_failed')->count())->toBe(1);
});

it('does not notify for a first-attempt Stop refusal the user sees on screen', function () {
    $this->ad->update(['status' => 'ACTIVE']);
    $stop = lvPropose($this->admin, $this->acc, $this->ad->external_id, 'paused');
    FakeAdsDriver::failNext('setStatus', 'rejected');

    expect(lvConfirm($this->admin, $stop)->state)->toBe('failed')->and(UserNotification::count())->toBe(0);
});

it('ads:write-resolve accepts an executing Stop whose retry is more than 10 minutes overdue, not one only 5 minutes late', function () {
    $lost = AdWriteAction::factory()->stop()->create(['state' => 'executing', 'executing_at' => now()->subMinutes(30), 'retry_at' => now()->subMinutes(11), 'attempts' => 1]);
    $late = AdWriteAction::factory()->stop()->create(['state' => 'executing', 'executing_at' => now()->subMinutes(30), 'retry_at' => now()->subMinutes(5), 'attempts' => 1]);

    $this->artisan('ads:write-resolve', ['public_id' => $lost->public_id, '--succeeded' => true, '--note' => 'paused in Ads Manager'])->assertSuccessful();
    $this->artisan('ads:write-resolve', ['public_id' => $late->public_id, '--succeeded' => true, '--note' => 'x'])->assertFailed();

    expect($lost->fresh()->state)->toBe('succeeded')->and($lost->fresh()->retry_at)->toBeNull()->and($late->fresh()->state)->toBe('executing');
});

it('RetryStopWrite::failed ends a Stop still executing for its attempt, with the link and a notice', function () {
    $stop = AdWriteAction::factory()->stop()->create(['ad_account_id' => $this->acc->id, 'target_external_id' => $this->ad->external_id,
        'state' => 'executing', 'executing_at' => now(), 'attempts' => 1, 'retry_at' => null]);
    Log::spy();

    (new RetryStopWrite($stop->id, 1))->failed(new RuntimeException('worker died access_token=SECRET123'));

    $stop->refresh();
    expect($stop->state)->toBe('failed')->and($stop->error_code)->toBe('stop_failed')->and($stop->outcome['deep_link'])->toContain('act=5550001')
        ->and($stop->error_message)->not->toContain('SECRET123')
        ->and(UserNotification::where('type', 'ads.stop_failed')->where('user_id', $this->admin->id)->count())->toBe(1);
    Log::shouldHaveReceived('error')->withArgs(fn ($msg, $ctx) => ! str_contains(json_encode($ctx), 'SECRET123'));
});

it('RetryStopWrite::failed leaves a Stop that moved on alone', function () {
    $done = AdWriteAction::factory()->stop()->succeeded()->create(['attempts' => 2]);
    $later = AdWriteAction::factory()->stop()->create(['state' => 'executing', 'executing_at' => now(), 'attempts' => 3]);

    (new RetryStopWrite($done->id, 1))->failed(new RuntimeException('x'));
    (new RetryStopWrite($later->id, 1))->failed(new RuntimeException('x'));

    expect($done->fresh()->state)->toBe('succeeded')->and($later->fresh()->state)->toBe('executing')->and(UserNotification::count())->toBe(0);
});

it('the sweeper re-dispatches an overdue Stop retry, ends one past the bound failed, and leaves the rest', function () {
    Queue::fake();
    $overdue = AdWriteAction::factory()->stop()->create(['ad_account_id' => $this->acc->id, 'target_external_id' => $this->ad->external_id,
        'target_key' => $this->acc->id.':ad:'.$this->ad->external_id, 'confirmed_by_id' => $this->admin->id, 'state' => 'executing', 'executing_at' => now()->subMinutes(20), 'retry_at' => now()->subMinutes(6), 'attempts' => 1]);
    $bound = AdWriteAction::factory()->stop()->create(['ad_account_id' => $this->acc->id, 'state' => 'executing', 'executing_at' => now()->subMinutes(20), 'retry_at' => now()->subMinutes(6), 'attempts' => 3, 'error_code' => 'rate_limited']);
    $onTime = AdWriteAction::factory()->stop()->create(['state' => 'executing', 'executing_at' => now()->subMinutes(5), 'retry_at' => now()->subMinutes(2), 'attempts' => 1]);
    $run = AdWriteAction::factory()->run()->create(['state' => 'executing', 'executing_at' => now()->subMinutes(20), 'retry_at' => now()->subMinutes(6), 'attempts' => 1]);

    $this->artisan('ads:write-sweep')->assertSuccessful();

    Queue::assertPushedOn('commerce', RetryStopWrite::class, fn (RetryStopWrite $j) => $j->actionId === $overdue->id && $j->attempts === 1);
    Queue::assertPushed(RetryStopWrite::class, 1);
    expect($overdue->fresh()->state)->toBe('executing')->and($overdue->fresh()->retry_at->diffInSeconds(now(), true))->toBeLessThan(5)
        ->and($bound->fresh()->state)->toBe('failed')->and($bound->fresh()->error_code)->toBe('rate_limited')
        ->and($bound->fresh()->outcome['deep_link'])->toContain('act=5550001')
        ->and(UserNotification::where('type', 'ads.stop_failed')->count())->toBe(1)
        ->and($onTime->fresh()->state)->toBe('executing')->and($run->fresh()->state)->toBe('executing')
        ->and(lvStatuses())->toBe([]) // never a platform call
        ->and(AdsAuditLog::where('action', 'write.retry_redispatched')->count())->toBe(1);

    // The re-dispatched job claims and sends the retry; a second sweep finds nothing overdue.
    app()->call([Queue::pushed(RetryStopWrite::class)->first(), 'handle']);
    expect($overdue->fresh()->attempts)->toBe(2);
    $this->artisan('ads:write-sweep')->assertSuccessful();
    Queue::assertPushed(RetryStopWrite::class, 1);
});

it('schedules the sweeper every 5 minutes on one server without overlapping, under its own name', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'ads:write-sweep'));

    expect($events)->toHaveCount(1);
    $e = $events->first();
    expect($e->expression)->toBe('*/5 * * * *')->and($e->onOneServer)->toBeTrue()->and($e->withoutOverlapping)->toBeTrue()
        ->and($e->description)->toBe('ads:write-sweep-stop-retries');
});

it('exhausting another throttle:60 route never throttles a Stop propose or confirm', function () {
    $this->ad->update(['status' => 'ACTIVE']);
    for ($i = 0; $i < 61; $i++) {
        $res = $this->actingAs($this->admin)->get('/search?q=x');
    }
    $res->assertStatus(429);

    $propose = $this->actingAs($this->admin)->postJson('/ads/write-actions', ['type' => 'set_status', 'account_id' => $this->acc->id,
        'target' => ['level' => 'ad', 'external_id' => $this->ad->external_id], 'params' => ['to' => 'paused']], ['Idempotency-Key' => 'stop-after-search'])->assertCreated();
    $this->actingAs($this->admin)->postJson('/ads/write-actions/'.$propose->json('action.id').'/confirm', ['diff_hash' => $propose->json('diff_hash')])->assertOk();
});

it('a user out of Run budget can still Stop', function () {
    config(['crm.ads.write.run_per_minute' => 2]);
    $body = fn (string $to, string $id) => ['type' => 'set_status', 'account_id' => $this->acc->id, 'target' => ['level' => 'ad', 'external_id' => $id], 'params' => ['to' => $to]];
    $ads = Ad::factory()->for($this->acc, 'account')->count(3)->create(['status' => 'PAUSED']);

    $this->actingAs($this->admin)->postJson('/ads/write-actions', $body('active', $ads[0]->external_id), ['Idempotency-Key' => 'run-0001'])->assertCreated();
    $this->actingAs($this->admin)->postJson('/ads/write-actions', $body('active', $ads[1]->external_id), ['Idempotency-Key' => 'run-0002'])->assertCreated();
    $this->actingAs($this->admin)->postJson('/ads/write-actions', $body('active', $ads[2]->external_id), ['Idempotency-Key' => 'run-0003'])->assertStatus(429);

    $stop = $this->actingAs($this->admin)->postJson('/ads/write-actions', $body('paused', $ads[0]->external_id), ['Idempotency-Key' => 'stop-0001'])->assertCreated();
    $this->actingAs($this->admin)->postJson('/ads/write-actions/'.$stop->json('action.id').'/confirm', ['diff_hash' => $stop->json('diff_hash')])->assertOk();
});

it('maps a MariaDB deadlock inside the claim to 409 action_in_progress; the action stays proposed', function () {
    $buyer = lvBuyer($this->acc);
    $run = lvPropose($buyer, $this->acc, $this->ad->external_id, 'active');
    $thrown = false;
    DB::beforeExecuting(function (string $sql) use (&$thrown) {
        if (! $thrown && str_starts_with(strtolower($sql), 'update') && str_contains($sql, 'ad_write_actions') && str_contains($sql, 'confirmed_by_id')) {
            $thrown = true;
            $pdo = new PDOException('Deadlock found when trying to get lock; try restarting transaction');
            $pdo->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];

            throw new QueryException('mysql', $sql, [], $pdo);
        }
    });

    $e = null;
    try {
        lvConfirm($buyer, $run);
    } catch (WriteDenied $caught) {
        $e = $caught;
    }

    expect($thrown)->toBeTrue()->and($e?->errorCode)->toBe('action_in_progress')->and($e->status)->toBe(409)
        ->and($run->fresh()->state)->toBe('proposed')->and($run->fresh()->open_business_key)->toBeNull()->and(lvStatuses())->toBe([]);
});

it('recognises deadlocks by SQLSTATE 40001 or error 1213 only', function (array $info, bool $expected) {
    $pdo = new PDOException('x');
    $pdo->errorInfo = $info;

    expect(WriteActionService::isDeadlock(new QueryException('mysql', 'update', [], $pdo)))->toBe($expected);
})->with([
    'sqlstate 40001' => [['40001', 1213, 'Deadlock'], true],
    'errno 1213' => [['HY000', 1213, 'Deadlock'], true],
    'unique violation' => [['23000', 1062, 'Duplicate'], false],
    'lock wait timeout' => [['HY000', 1205, 'Lock wait'], false],
]);

it('logs an unexpected executor error with a scrubbed message only', function () {
    Log::spy();
    WriteExecutor::logUnexpected(new RuntimeException('boom access_token=SECRET999&x=1'));

    Log::shouldHaveReceived('error')->withArgs(fn ($msg, $ctx) => $ctx['message'] === 'boom access_token=***&x=1');
});
