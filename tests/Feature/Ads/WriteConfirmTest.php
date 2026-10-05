<?php

use App\Ads\Control\Write\WriteActionService;
use App\Ads\Control\Write\WriteDenied;
use App\Ads\Platforms\Fake\FakeAdsDriver;
use App\Enums\UserRole;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdAccountAssignment;
use App\Models\AdCampaign;
use App\Models\AdsAuditLog;
use App\Models\AdWriteAction;
use App\Models\AdWriteStep;
use App\Models\MediaBuyer;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    Cache::forget('ads-fake-writer');
    FakeAdsDriver::reset();
    config(['crm.ads.drivers.meta' => 'fake', 'crm.ads.drivers.tiktok' => 'fake', 'crm.ads.drivers.google' => 'fake', 'crm.ads.write_sandbox_accounts' => []]);
});

function wcBuyer(AdAccount $acc): User
{
    $user = User::factory()->create(['role' => UserRole::MediaBuyer]);
    $buyer = MediaBuyer::factory()->create(['user_id' => $user->id]);
    AdAccountAssignment::factory()->create(['ad_account_id' => $acc->id, 'media_buyer_id' => $buyer->id, 'starts_on' => '2026-01-01', 'ends_on' => null]);

    return $user;
}

/** Proposes through HTTP and returns the action row. */
function wcPropose($test, User $u, AdAccount $acc, string $level, string $id, string $to, ?string $key = null): AdWriteAction
{
    $res = $test->actingAs($u)->postJson('/ads/write-actions', [
        'type' => 'set_status', 'account_id' => $acc->id, 'target' => ['level' => $level, 'external_id' => $id], 'params' => ['to' => $to], 'reason' => 'test',
    ], ['Idempotency-Key' => $key ?? 'k-'.bin2hex(random_bytes(8))]);
    $res->assertSuccessful();

    return AdWriteAction::where('public_id', $res->json('action.id'))->sole();
}

function wcConfirm($test, User $u, AdWriteAction $x, ?string $hash = null)
{
    return $test->actingAs($u)->postJson("/ads/write-actions/{$x->public_id}/confirm", ['diff_hash' => $hash ?? $x->diff_hash]);
}

function wcStatuses(): array
{
    return Cache::get('ads-fake-writer')['statuses'] ?? [];
}

it('confirms a Stop: one platform call, one succeeded step, local status, audit trail', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create(['status' => 'ACTIVE']);
    $buyer = wcBuyer($acc);
    $x = wcPropose($this, $buyer, $acc, 'ad', $ad->external_id, 'paused');

    wcConfirm($this, $buyer, $x)->assertOk()->assertJsonPath('action.state', 'succeeded')->assertJsonPath('message', __('ads.flash.stopped'));

    expect(wcStatuses())->toBe([['level' => 'ad', 'id' => $ad->external_id, 'status' => 'paused']]);
    $x->refresh();
    expect($x->state)->toBe('succeeded')->and($x->confirmed_by_id)->toBe($buyer->id)->and($x->confirmed_role)->toBe('media_buyer')
        ->and($x->confirmed_at)->not->toBeNull()->and($x->finished_at)->not->toBeNull()->and($x->open_business_key)->toBeNull()
        ->and($x->attempts)->toBe(1);
    $step = AdWriteStep::sole();
    expect($step->state)->toBe('succeeded')->and($step->seq)->toBe(1)->and($step->request_sent_at)->not->toBeNull()
        ->and($step->op)->toBe('set_status')->and($step->after)->toBe(['status' => 'PAUSED']);
    expect($ad->fresh()->status)->toBe('PAUSED');
    expect(AdsAuditLog::where('subject_type', 'AdWriteAction')->where('subject_id', $x->id)->orderBy('id')->pluck('action')->all())
        ->toBe(['write.proposed', 'write.confirmed', 'write.succeeded']);
});

it('holds the Run key while the Run executes and frees it after', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create(['status' => 'PAUSED']);
    $buyer = wcBuyer($acc);
    $x = wcPropose($this, $buyer, $acc, 'ad', $ad->external_id, 'active');
    $during = null;
    FakeAdsDriver::beforeSetStatus(function () use ($x, &$during) {
        $during = AdWriteAction::whereKey($x->id)->value('open_business_key');
    });

    wcConfirm($this, $buyer, $x)->assertOk()->assertJsonPath('action.state', 'succeeded')->assertJsonPath('message', __('ads.flash.resumed'));

    expect($during)->toBe('run:'.$acc->id.':ad:'.$ad->external_id)
        ->and($x->fresh()->open_business_key)->toBeNull()->and($ad->fresh()->status)->toBe('ACTIVE');
});

it('refuses a second Run on the same ad while the first holds the key; it stays proposed', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create(['status' => 'PAUSED']);
    $buyer = wcBuyer($acc);
    $first = wcPropose($this, $buyer, $acc, 'ad', $ad->external_id, 'active');
    $second = wcPropose($this, $buyer, $acc, 'ad', $ad->external_id, 'active');
    // Confirming the first supersedes other proposed Runs, so the second is re-opened as proposed inside the race window.
    $caught = null;
    FakeAdsDriver::beforeSetStatus(function () use ($second, $buyer, &$caught) {
        AdWriteAction::whereKey($second->id)->update(['state' => 'proposed', 'superseded_by_id' => null, 'finished_at' => null]);
        try {
            app(WriteActionService::class)->confirm($buyer, $second->fresh(), $second->diff_hash);
        } catch (WriteDenied $e) {
            $caught = $e;
        }
    });

    wcConfirm($this, $buyer, $first)->assertOk();

    expect($caught?->errorCode)->toBe('action_in_progress')->and($caught->status)->toBe(409)
        ->and($caught->details['action_id'])->toBe($first->public_id)
        ->and($second->fresh()->state)->toBe('proposed')->and($second->fresh()->open_business_key)->toBeNull()
        ->and(wcStatuses())->toHaveCount(1);
});

it('a confirmed Stop supersedes an open Run proposal but never another Stop proposal', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create(['status' => 'ACTIVE']);
    $buyer = wcBuyer($acc);
    $admin = User::factory()->adsAuthority()->create(['role' => UserRole::Admin]);
    $run = wcPropose($this, $buyer, $acc, 'ad', $ad->external_id, 'active');
    $stopB = wcPropose($this, $buyer, $acc, 'ad', $ad->external_id, 'paused');
    $stopA = wcPropose($this, $admin, $acc, 'ad', $ad->external_id, 'paused');

    wcConfirm($this, $admin, $stopA)->assertOk();

    expect($run->fresh()->state)->toBe('superseded')->and($run->fresh()->superseded_by_id)->toBe($stopA->id)
        ->and($stopB->fresh()->state)->toBe('proposed');
    wcConfirm($this, $buyer, $run)->assertStatus(409)->assertJsonPath('code', 'not_confirmable')->assertJsonPath('details.state', 'superseded');
    wcConfirm($this, $buyer, $stopB)->assertOk()->assertJsonPath('action.state', 'succeeded');
    expect(wcStatuses())->toHaveCount(2);
});

it('a confirmed Run supersedes the other open Run proposals on the target', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create(['status' => 'PAUSED']);
    $buyer = wcBuyer($acc);
    $a = wcPropose($this, $buyer, $acc, 'ad', $ad->external_id, 'active');
    $b = wcPropose($this, $buyer, $acc, 'ad', $ad->external_id, 'active');
    $stop = wcPropose($this, $buyer, $acc, 'ad', $ad->external_id, 'paused');

    wcConfirm($this, $buyer, $a)->assertOk();

    expect($b->fresh()->state)->toBe('superseded')->and($stop->fresh()->state)->toBe('proposed')
        ->and(AdsAuditLog::where('action', 'write.superseded')->where('subject_id', $b->id)->count())->toBe(1);
});

it('refuses a wrong diff_hash with 409 and calls nothing', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create();
    $buyer = wcBuyer($acc);
    $x = wcPropose($this, $buyer, $acc, 'ad', $ad->external_id, 'paused');

    wcConfirm($this, $buyer, $x, str_repeat('0', 64))->assertStatus(409)->assertJsonPath('code', 'diff_changed');
    expect($x->fresh()->state)->toBe('proposed')->and(wcStatuses())->toBe([]);
});

it('maps definite platform refusals to failed actions with codes', function (string $kind, string $code, int $http) {
    $acc = AdAccount::factory()->meta()->create();
    // PAUSED: the Run guard's live read turns a Run of something already ACTIVE into a noop (no platform call).
    $ad = Ad::factory()->for($acc, 'account')->create(['status' => 'PAUSED']);
    $buyer = wcBuyer($acc);
    $x = wcPropose($this, $buyer, $acc, 'ad', $ad->external_id, 'active');
    FakeAdsDriver::failNext('setStatus', $kind);

    wcConfirm($this, $buyer, $x)->assertStatus($http)->assertJsonPath('code', $code)->assertJsonPath('action.state', 'failed');

    $x->refresh();
    expect($x->state)->toBe('failed')->and($x->error_code)->toBe($code)->and($x->open_business_key)->toBeNull()
        ->and(AdWriteStep::sole()->state)->toBe('failed')->and($ad->fresh()->status)->toBe('PAUSED')
        ->and(AdsAuditLog::where('action', 'write.failed')->count())->toBe(1);
})->with([
    'rejected' => ['rejected', 'platform_rejected', 422],
    'permission' => ['permission', 'permission_missing', 422],
    'rate (Run)' => ['rate', 'rate_limited', 429],
]);

it('keeps the scrubbed platform message of a rejection', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create();
    $buyer = wcBuyer($acc);
    $x = wcPropose($this, $buyer, $acc, 'ad', $ad->external_id, 'paused');
    FakeAdsDriver::failNext('setStatus', 'rejected');

    wcConfirm($this, $buyer, $x)->assertStatus(422)->assertJsonPath('details.platform_message', 'Invalid parameter');
    expect($x->fresh()->error_message)->toBe('Invalid parameter');
});

it('sends Retry-After with a throttled Run', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create(['status' => 'PAUSED']);
    $buyer = wcBuyer($acc);
    $x = wcPropose($this, $buyer, $acc, 'ad', $ad->external_id, 'active');
    FakeAdsDriver::failNext('setStatus', 'rate');

    wcConfirm($this, $buyer, $x)->assertStatus(429)->assertHeader('Retry-After', '120');
});

it('marks the connection on a dead token', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create();
    $buyer = wcBuyer($acc);
    $x = wcPropose($this, $buyer, $acc, 'ad', $ad->external_id, 'paused');
    FakeAdsDriver::failNext('setStatus', 'token');

    wcConfirm($this, $buyer, $x)->assertStatus(422)->assertJsonPath('code', 'connection_needs_reconnect');
    expect($acc->connection->fresh()->status)->toBe('needs_reconnect');
});

it('reads back after a lost answer: the change landed, so the action succeeded', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create(['status' => 'ACTIVE']);
    $buyer = wcBuyer($acc);
    $x = wcPropose($this, $buyer, $acc, 'ad', $ad->external_id, 'paused');
    FakeAdsDriver::failNext('setStatus', 'unreachable_after');

    wcConfirm($this, $buyer, $x)->assertOk()->assertJsonPath('action.state', 'succeeded');
    expect($x->fresh()->outcome['read_back'])->toBeTrue()->and($ad->fresh()->status)->toBe('PAUSED');
});

it('reads back a Run that never landed: failed not_applied', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create(['status' => 'PAUSED']);
    $buyer = wcBuyer($acc);
    $x = wcPropose($this, $buyer, $acc, 'ad', $ad->external_id, 'active');
    FakeAdsDriver::failNext('setStatus', 'unreachable_before');

    wcConfirm($this, $buyer, $x)->assertStatus(422)->assertJsonPath('code', 'not_applied');
    expect($x->fresh()->state)->toBe('failed')->and($x->fresh()->open_business_key)->toBeNull();
});

it('leaves the action unknown when the read-back fails too, and the Run keeps its key', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create(['status' => 'PAUSED']);
    $buyer = wcBuyer($acc);
    $x = wcPropose($this, $buyer, $acc, 'ad', $ad->external_id, 'active');
    FakeAdsDriver::failNext('setStatus', 'unreachable_after');
    FakeAdsDriver::failNext('readObject', 'unreachable_before');

    wcConfirm($this, $buyer, $x)->assertStatus(202)->assertJsonPath('action.state', 'unknown');
    $x->refresh();
    expect($x->state)->toBe('unknown')->and($x->open_business_key)->toBe('run:'.$x->target_key)->and($x->finished_at)->toBeNull()
        ->and(AdWriteStep::sole()->state)->toBe('unknown')->and($ad->fresh()->status)->toBe('PAUSED');
});

it('re-checks the policy at confirm: a buyer unassigned since the proposal is out of scope', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create();
    $buyer = wcBuyer($acc);
    $x = wcPropose($this, $buyer, $acc, 'ad', $ad->external_id, 'paused');
    AdAccountAssignment::query()->update(['ends_on' => now('Africa/Cairo')->subDays(2)->toDateString()]);

    wcConfirm($this, $buyer, $x)->assertForbidden()->assertJsonPath('code', 'out_of_scope');
    expect(wcStatuses())->toBe([])->and($x->fresh()->state)->toBe('proposed');
});

it('only the proposer confirms or cancels', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create();
    $buyer = wcBuyer($acc);
    $admin = User::factory()->adsAuthority()->create(['role' => UserRole::Admin]);
    $x = wcPropose($this, $buyer, $acc, 'ad', $ad->external_id, 'paused');

    wcConfirm($this, $admin, $x)->assertForbidden()->assertJsonPath('code', 'not_proposer');
    $this->actingAs($admin)->postJson("/ads/write-actions/{$x->public_id}/cancel")->assertForbidden()->assertJsonPath('code', 'not_proposer');
    expect($x->fresh()->state)->toBe('proposed')->and(wcStatuses())->toBe([]);

    $this->actingAs($buyer)->postJson("/ads/write-actions/{$x->public_id}/cancel")->assertOk()->assertJsonPath('action.state', 'cancelled');
    expect($x->fresh()->state)->toBe('cancelled')->and(AdsAuditLog::where('action', 'write.cancelled')->count())->toBe(1);
    wcConfirm($this, $buyer, $x)->assertStatus(409)->assertJsonPath('code', 'not_confirmable');
    $this->actingAs($buyer)->postJson("/ads/write-actions/{$x->public_id}/cancel")->assertStatus(409);
});

it('refuses an expired proposal with 410', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create();
    $buyer = wcBuyer($acc);
    $x = wcPropose($this, $buyer, $acc, 'ad', $ad->external_id, 'paused');
    $this->travel(11)->minutes();

    wcConfirm($this, $buyer, $x)->assertStatus(410)->assertJsonPath('code', 'proposal_expired');
    expect($x->fresh()->state)->toBe('expired')->and(wcStatuses())->toBe([]);
});

it('refuses a confirm by a user who cannot see the action with 404', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create();
    $buyer = wcBuyer($acc);
    $x = wcPropose($this, $buyer, $acc, 'ad', $ad->external_id, 'paused');
    $other = wcBuyer(AdAccount::factory()->meta()->create());

    wcConfirm($this, $other, $x)->assertNotFound();
});

it('fails at execute when the guard refuses (sandbox only), without a step', function () {
    config(['crm.ads.drivers.meta' => 'live']);
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create();
    $buyer = wcBuyer($acc);
    $x = wcPropose($this, $buyer, $acc, 'ad', $ad->external_id, 'paused');

    wcConfirm($this, $buyer, $x)->assertStatus(422)->assertJsonPath('code', 'sandbox_only');
    expect($x->fresh()->state)->toBe('failed')->and(AdWriteStep::count())->toBe(0);
});

it('validates the confirm body', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create();
    $buyer = wcBuyer($acc);
    $x = wcPropose($this, $buyer, $acc, 'ad', $ad->external_id, 'paused');

    $this->actingAs($buyer)->postJson("/ads/write-actions/{$x->public_id}/confirm", [])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
});

it('lets an Ads authority holder Stop a campaign through the pipeline', function () {
    $acc = AdAccount::factory()->meta()->create();
    $camp = AdCampaign::factory()->for($acc, 'account')->create(['status' => 'ACTIVE']);
    $admin = User::factory()->adsAuthority()->create(['role' => UserRole::Admin]);
    $x = wcPropose($this, $admin, $acc, 'campaign', $camp->external_id, 'paused');

    wcConfirm($this, $admin, $x)->assertOk();
    expect($camp->fresh()->status)->toBe('PAUSED')->and(wcStatuses()[0]['level'])->toBe('campaign');
});
