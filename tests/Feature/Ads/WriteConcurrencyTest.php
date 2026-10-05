<?php

/*
 * Roadmap section 2.1 (binding write-concurrency rules), one test per rule.
 *
 * SQLite :memory: shares one connection, so true parallelism is impossible here. Races are simulated with
 * (a) the fake writer's one-shot beforeSetStatus hook, which runs the competing call INSIDE the first call's platform
 * request (the worst interleaving), and (b) sequential replays against the state the first call left. The claim is a
 * single conditional UPDATE, so these cover every interleaving that matters.
 */

use App\Ads\AdsSettings;
use App\Ads\Control\Write\Jobs\RetryStopWrite;
use App\Ads\Control\Write\WriteActionService;
use App\Ads\Control\Write\WriteDenied;
use App\Ads\Platforms\Fake\FakeAdsDriver;
use App\Enums\UserRole;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdAccountAssignment;
use App\Models\AdCampaign;
use App\Models\AdWriteAction;
use App\Models\AdWriteStep;
use App\Models\MediaBuyer;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Http::preventStrayRequests();
    Cache::forget('ads-fake-writer');
    FakeAdsDriver::reset();
    config(['crm.ads.drivers.meta' => 'fake', 'crm.ads.drivers.tiktok' => 'fake', 'crm.ads.drivers.google' => 'fake', 'crm.ads.write_sandbox_accounts' => []]);
    $this->acc = AdAccount::factory()->meta()->create();
    $this->ad = Ad::factory()->for($this->acc, 'account')->create(['status' => 'ACTIVE']);
    $this->admin = User::factory()->adsAuthority()->create(['role' => UserRole::Admin]);
});

function ccBuyer(AdAccount $acc): User
{
    $user = User::factory()->create(['role' => UserRole::MediaBuyer]);
    $buyer = MediaBuyer::factory()->create(['user_id' => $user->id]);
    AdAccountAssignment::factory()->create(['ad_account_id' => $acc->id, 'media_buyer_id' => $buyer->id, 'starts_on' => '2026-01-01', 'ends_on' => null]);

    return $user;
}

function ccPropose(User $u, AdAccount $acc, string $id, string $to, ?string $key = null, string $level = 'ad', string $source = 'ui', string $actor = 'user'): AdWriteAction
{
    return app(WriteActionService::class)->propose($u, $acc, $level, $id, $to, 'test', $key ?? 'k-'.bin2hex(random_bytes(8)), $source, null, $actor)['action'];
}

function ccConfirm(User $u, AdWriteAction $x): AdWriteAction
{
    return app(WriteActionService::class)->confirm($u, $x->fresh(), $x->diff_hash);
}

function ccCalls(): int
{
    return count(Cache::get('ads-fake-writer')['statuses'] ?? []);
}

function ccRefusal(callable $fn): ?WriteDenied
{
    try {
        $fn();

        return null;
    } catch (WriteDenied $e) {
        return $e;
    }
}

it('rule 1+3: an open Run proposal never blocks a Stop; the Stop executes and supersedes it', function () {
    $buyer = ccBuyer($this->acc);
    $run = ccPropose($buyer, $this->acc, $this->ad->external_id, 'active');
    $stop = ccPropose($this->admin, $this->acc, $this->ad->external_id, 'paused');

    $this->actingAs($this->admin)->postJson("/ads/write-actions/{$stop->public_id}/confirm", ['diff_hash' => $stop->diff_hash])
        ->assertOk()->assertJsonPath('action.state', 'succeeded');

    expect(ccCalls())->toBe(1)->and($run->fresh()->state)->toBe('superseded');
    $this->actingAs($buyer)->postJson("/ads/write-actions/{$run->public_id}/confirm", ['diff_hash' => $run->diff_hash])
        ->assertStatus(409)->assertJsonPath('code', 'not_confirmable');
    expect(ccCalls())->toBe(1);
});

it('rule 3: an unknown Stop never blocks a new Stop; the unknown row stays unknown', function () {
    $first = ccPropose($this->admin, $this->acc, $this->ad->external_id, 'paused');
    FakeAdsDriver::failNext('setStatus', 'unreachable_after');
    FakeAdsDriver::failNext('readObject', 'unreachable_before');
    expect(ccConfirm($this->admin, $first)->state)->toBe('unknown');

    $second = ccPropose($this->admin, $this->acc, $this->ad->external_id, 'paused');

    expect(ccConfirm($this->admin, $second)->state)->toBe('succeeded')
        ->and(ccCalls())->toBe(2)
        ->and($first->fresh()->state)->toBe('unknown');
});

it('rule 3+4: an executing Run never blocks a Stop; the Run ends superseded_by_stop and the Stop is re-applied', function () {
    $buyer = ccBuyer($this->acc);
    $this->ad->update(['status' => 'PAUSED']);
    $run = ccPropose($buyer, $this->acc, $this->ad->external_id, 'active');
    $stop = null;
    FakeAdsDriver::beforeSetStatus(function () use (&$stop) {
        $stop = ccPropose($this->admin, $this->acc, $this->ad->external_id, 'paused');
        expect(ccConfirm($this->admin, $stop)->state)->toBe('succeeded');
    });

    expect(ccConfirm($buyer, $run)->state)->toBe('superseded_by_stop');

    expect(array_column(Cache::get('ads-fake-writer')['statuses'], 'status'))->toBe(['paused', 'active', 'paused'])
        ->and($stop->fresh()->state)->toBe('succeeded')->and($stop->steps()->count())->toBe(2)
        ->and($run->fresh()->open_business_key)->toBeNull()->and($this->ad->fresh()->status)->toBe('PAUSED');
});

it('rule 3: an unknown Run holding its key never blocks a Stop', function () {
    $buyer = ccBuyer($this->acc);
    $run = ccPropose($buyer, $this->acc, $this->ad->external_id, 'active');
    FakeAdsDriver::failNext('setStatus', 'unreachable_after');
    FakeAdsDriver::failNext('readObject', 'unreachable_before');
    expect(ccConfirm($buyer, $run)->state)->toBe('unknown')->and($run->fresh()->open_business_key)->not->toBeNull();

    $stop = ccPropose($buyer, $this->acc, $this->ad->external_id, 'paused');

    expect(ccConfirm($buyer, $stop)->state)->toBe('succeeded')
        ->and($run->fresh()->state)->toBe('superseded_by_stop')->and($run->fresh()->open_business_key)->toBeNull();
});

it('rule 2: a Run cannot start while a Stop on the target is in progress', function () {
    $buyer = ccBuyer($this->acc);
    $stop = ccPropose($buyer, $this->acc, $this->ad->external_id, 'paused');
    FakeAdsDriver::failNext('setStatus', 'unreachable_after');
    FakeAdsDriver::failNext('readObject', 'unreachable_before');
    ccConfirm($buyer, $stop); // unknown
    $run = ccPropose($buyer, $this->acc, $this->ad->external_id, 'active');

    $e = ccRefusal(fn () => ccConfirm($buyer, $run));

    expect($e?->errorCode)->toBe('stop_in_progress')->and($e->status)->toBe(409)->and($run->fresh()->state)->toBe('proposed');
});

it('parallel double confirm (inside the platform call): one platform call, one step', function () {
    $x = ccPropose($this->admin, $this->acc, $this->ad->external_id, 'paused');
    $inner = null;
    FakeAdsDriver::beforeSetStatus(function () use ($x, &$inner) {
        // $x is the copy read before the first claim (still 'proposed' in memory): only the DB claim can refuse it.
        $inner = ccRefusal(fn () => app(WriteActionService::class)->confirm($this->admin, $x, $x->diff_hash));
    });

    expect(ccConfirm($this->admin, $x)->state)->toBe('succeeded');

    expect($inner?->errorCode)->toBe('not_confirmable')->and(ccCalls())->toBe(1)->and(AdWriteStep::count())->toBe(1);
});

it('sequential double confirm: the second is 409, still one call', function () {
    $x = ccPropose($this->admin, $this->acc, $this->ad->external_id, 'paused');
    ccConfirm($this->admin, $x);

    $this->actingAs($this->admin)->postJson("/ads/write-actions/{$x->public_id}/confirm", ['diff_hash' => $x->diff_hash])
        ->assertStatus(409)->assertJsonPath('code', 'not_confirmable')->assertJsonPath('details.state', 'succeeded');
    expect(ccCalls())->toBe(1)->and(AdWriteStep::count())->toBe(1);
});

it('two Runs on the same target: exactly one executes at a time, the other is 409 action_in_progress', function () {
    $buyer = ccBuyer($this->acc);
    $this->ad->update(['status' => 'PAUSED']);
    $first = ccPropose($buyer, $this->acc, $this->ad->external_id, 'active', 'run-first-01');
    $second = null;
    $inner = null;
    $executing = null;
    FakeAdsDriver::beforeSetStatus(function () use ($buyer, &$second, &$inner, &$executing) {
        $second = ccPropose($buyer, $this->acc, $this->ad->external_id, 'active', 'run-second-1'); // proposing never locks
        $inner = ccRefusal(fn () => ccConfirm($buyer, $second));
        $executing = AdWriteAction::where('target_key', $second->target_key)->where('state', 'executing')->count();
    });

    expect(ccConfirm($buyer, $first)->state)->toBe('succeeded');

    expect($inner?->errorCode)->toBe('action_in_progress')->and($inner->details['action_id'])->toBe($first->public_id)
        ->and($executing)->toBe(1)->and($second->fresh()->state)->toBe('proposed')
        ->and(ccCalls())->toBe(1);
    expect(ccConfirm($buyer, $second)->state)->toBe('succeeded'); // free again once the first finished
});

it('same Idempotency-Key with a different body is 409; the same body replays with no extra row or call', function () {
    $buyer = ccBuyer($this->acc);
    $body = fn (string $to) => ['type' => 'set_status', 'account_id' => $this->acc->id, 'target' => ['level' => 'ad', 'external_id' => $this->ad->external_id], 'params' => ['to' => $to]];

    $this->actingAs($buyer)->postJson('/ads/write-actions', $body('paused'), ['Idempotency-Key' => 'same-key-01'])->assertCreated();
    $this->actingAs($buyer)->postJson('/ads/write-actions', $body('active'), ['Idempotency-Key' => 'same-key-01'])
        ->assertStatus(409)->assertJsonPath('code', 'idempotency_key_reused');
    $this->actingAs($buyer)->postJson('/ads/write-actions', $body('paused'), ['Idempotency-Key' => 'same-key-01'])->assertOk();

    expect(AdWriteAction::count())->toBe(1)->and(ccCalls())->toBe(0);
});

it('a confirm by another user is 403 not_proposer, no call, and the proposer can still confirm', function () {
    $buyer = ccBuyer($this->acc);
    $x = ccPropose($buyer, $this->acc, $this->ad->external_id, 'paused');

    $e = ccRefusal(fn () => ccConfirm($this->admin, $x));

    expect($e?->errorCode)->toBe('not_proposer')->and($e->status)->toBe(403)->and(ccCalls())->toBe(0)->and($x->fresh()->state)->toBe('proposed')
        ->and(ccConfirm($buyer, $x)->state)->toBe('succeeded');
});

it('a supervisor without Ads authority is refused at campaign level (Run and Stop, pipeline and legacy endpoint) until granted', function () {
    $camp = AdCampaign::factory()->for($this->acc, 'account')->create();
    $sup = User::factory()->create(['role' => UserRole::Supervisor, 'email' => 'sup@example.test']);

    foreach (['active', 'paused'] as $to) {
        $this->actingAs($sup)->postJson('/ads/write-actions', ['type' => 'set_status', 'account_id' => $this->acc->id,
            'target' => ['level' => 'campaign', 'external_id' => $camp->external_id], 'params' => ['to' => $to]], ['Idempotency-Key' => 'camp-'.$to.'-01'])
            ->assertForbidden()->assertJsonPath('code', 'ads_authority_required');
        // The legacy endpoint keeps its slice-1 answer (422) until the batch-4 shim; it is refused all the same.
        $this->actingAs($sup)->postJson('/ads/actions/status', ['account_id' => $this->acc->id, 'level' => 'campaign', 'external_id' => $camp->external_id, 'status' => $to])
            ->assertStatus(422);
    }
    expect(ccCalls())->toBe(0);

    // Ad level needs no flag.
    expect(ccConfirm($sup, ccPropose($sup, $this->acc, $this->ad->external_id, 'paused'))->state)->toBe('succeeded');

    $this->artisan('ads:authority', ['--grant' => 'sup@example.test'])->assertSuccessful();
    $sup->refresh();
    expect(ccConfirm($sup, ccPropose($sup, $this->acc, $camp->external_id, 'paused', null, 'campaign'))->state)->toBe('succeeded');
});

it('an assistant-sourced proposal never blocks a human Stop', function () {
    $buyer = ccBuyer($this->acc);
    $assistant = ccPropose($buyer, $this->acc, $this->ad->external_id, 'active', null, 'ad', 'assistant', 'assistant');
    expect($assistant->source)->toBe('assistant')->and($assistant->actor_type)->toBe('assistant');

    $stop = ccPropose($buyer, $this->acc, $this->ad->external_id, 'paused');

    expect(ccConfirm($buyer, $stop)->state)->toBe('succeeded')->and($assistant->fresh()->state)->toBe('superseded')->and(ccCalls())->toBe(1);
});

it('kill switch off: a Run is 503 at propose and at confirm, a Stop succeeds, a scheduled Stop retry still runs', function () {
    Queue::fake();
    $buyer = ccBuyer($this->acc);
    $ad2 = Ad::factory()->for($this->acc, 'account')->create(['status' => 'PAUSED']);
    $runBefore = ccPropose($buyer, $this->acc, $ad2->external_id, 'active');
    $retrying = ccPropose($buyer, $this->acc, $this->ad->external_id, 'paused');
    FakeAdsDriver::failNext('setStatus', 'rate');
    expect(ccConfirm($buyer, $retrying)->state)->toBe('executing');

    app(AdsSettings::class)->set('writes_enabled', false);

    expect(ccRefusal(fn () => ccPropose($buyer, $this->acc, $ad2->external_id, 'active'))?->status)->toBe(503)
        ->and(ccRefusal(fn () => ccConfirm($buyer, $runBefore))?->errorCode)->toBe('writes_disabled');
    $other = Ad::factory()->for($this->acc, 'account')->create(['status' => 'ACTIVE']);
    expect(ccConfirm($buyer, ccPropose($buyer, $this->acc, $other->external_id, 'paused'))->state)->toBe('succeeded');

    app()->call([Queue::pushed(RetryStopWrite::class)->first(), 'handle']);
    expect($retrying->fresh()->state)->toBe('succeeded')->and(ccCalls())->toBe(2);
});

it('losing the cache causes no second write: replay after Cache::flush answers from the database', function () {
    $buyer = ccBuyer($this->acc);
    $key = 'flush-key-01';
    $x = ccPropose($buyer, $this->acc, $this->ad->external_id, 'paused', $key);
    ccConfirm($buyer, $x);
    expect(ccCalls())->toBe(1);

    Cache::flush(); // also empties the fake writer's call log: any new call would show up as 1

    $this->actingAs($buyer)->postJson('/ads/write-actions', ['type' => 'set_status', 'account_id' => $this->acc->id,
        'target' => ['level' => 'ad', 'external_id' => $this->ad->external_id], 'params' => ['to' => 'paused'], 'reason' => 'test'], ['Idempotency-Key' => $key])
        ->assertOk()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('action.state', 'succeeded');
    expect(ccRefusal(fn () => ccConfirm($buyer, $x))?->errorCode)->toBe('not_confirmable');

    expect(ccCalls())->toBe(0)->and(AdWriteAction::count())->toBe(1)->and(AdWriteStep::count())->toBe(1);
});
