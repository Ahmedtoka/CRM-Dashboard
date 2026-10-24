<?php

use App\Ads\Control\WriteActionLog;
use App\Ads\Platforms\Fake\FakeAdsDriver;
use App\Enums\UserRole;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdsAuditLog;
use App\Models\AdWriteAction;
use App\Models\AdWriteStep;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Round 3: every platform execution sits behind CRM_ADS_EXECUTE (crm.ads.execute). Off = dry run: logged, never sent. */
beforeEach(function () {
    Http::preventStrayRequests();
    Cache::forget('ads-fake-writer');
    FakeAdsDriver::reset();
    config(['crm.ads.drivers.meta' => 'fake', 'crm.ads.drivers.tiktok' => 'fake', 'crm.ads.drivers.google' => 'fake', 'crm.ads.write_sandbox_accounts' => []]);
});

function r3Admin(): User
{
    return User::factory()->adsAuthority()->create(['role' => UserRole::Admin]);
}

function r3Propose($test, User $u, AdAccount $acc, string $level, string $id, string $to): AdWriteAction
{
    $res = $test->actingAs($u)->postJson('/ads/write-actions', [
        'type' => 'set_status', 'account_id' => $acc->id, 'target' => ['level' => $level, 'external_id' => $id], 'params' => ['to' => $to], 'reason' => 'round3',
    ], ['Idempotency-Key' => 'r3-'.bin2hex(random_bytes(8))]);
    $res->assertSuccessful();

    return AdWriteAction::where('public_id', $res->json('action.id'))->sole();
}

function r3FakeStatuses(): array
{
    return Cache::get('ads-fake-writer')['statuses'] ?? [];
}

it('the proposal says whether confirming will really execute (execute flag)', function () {
    config(['crm.ads.execute' => false]);
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create(['status' => 'ACTIVE']);

    $this->actingAs(r3Admin())->postJson('/ads/write-actions', [
        'type' => 'set_status', 'account_id' => $acc->id, 'target' => ['level' => 'ad', 'external_id' => $ad->external_id], 'params' => ['to' => 'paused'],
    ], ['Idempotency-Key' => 'r3-flag-1'])->assertCreated()->assertJsonPath('execute', false);

    config(['crm.ads.execute' => true]);
    $this->actingAs(r3Admin())->postJson('/ads/write-actions', [
        'type' => 'set_status', 'account_id' => $acc->id, 'target' => ['level' => 'ad', 'external_id' => $ad->external_id], 'params' => ['to' => 'paused'],
    ], ['Idempotency-Key' => 'r3-flag-2'])->assertCreated()->assertJsonPath('execute', true);
});

it('a confirmed Stop with the execute flag off is a dry run: nothing reaches the platform, the row and the audit say so', function () {
    config(['crm.ads.execute' => false]);
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create(['status' => 'ACTIVE']);
    $admin = r3Admin();
    $x = r3Propose($this, $admin, $acc, 'ad', $ad->external_id, 'paused');

    $this->actingAs($admin)->postJson("/ads/write-actions/{$x->public_id}/confirm", ['diff_hash' => $x->diff_hash])
        ->assertOk()->assertJsonPath('action.state', 'dry_run')->assertJsonPath('action.outcome.dry_run', true)
        ->assertJsonPath('message', __('ads.flash.dry_run'));

    expect(r3FakeStatuses())->toBe([]);
    expect($ad->fresh()->status)->toBe('ACTIVE'); // the local mirror is untouched too
    $x->refresh();
    expect($x->state)->toBe('dry_run')->and($x->confirmed_by_id)->toBe($admin->id)->and($x->finished_at)->not->toBeNull()
        ->and($x->outcome['would'])->toBe(['op' => 'set_status', 'level' => 'ad', 'external_id' => $ad->external_id, 'after' => ['status' => 'PAUSED']]);
    $step = AdWriteStep::sole();
    expect($step->state)->toBe('dry_run')->and($step->request_sent_at)->toBeNull()->and($step->after)->toBe(['status' => 'PAUSED']);
    expect(AdsAuditLog::where('subject_type', 'AdWriteAction')->where('subject_id', $x->id)->orderBy('id')->pluck('action')->all())
        ->toBe(['write.proposed', 'write.confirmed', 'write.dry_run']);
});

it('a dry run is reported as such in the decisions log and cannot be undone', function () {
    config(['crm.ads.execute' => false]);
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create(['status' => 'ACTIVE']);
    $admin = r3Admin();
    $x = r3Propose($this, $admin, $acc, 'ad', $ad->external_id, 'paused');
    $this->actingAs($admin)->postJson("/ads/write-actions/{$x->public_id}/confirm", ['diff_hash' => $x->diff_hash])->assertOk();

    $rows = app(WriteActionLog::class)->rows($admin);
    expect($rows)->toHaveCount(1)->and($rows[0]['result'])->toBe('dry_run')->and($rows[0]['undoable'])->toBeFalse()->and($rows[0]['public_id'])->toBe($x->public_id);

    $this->actingAs($admin)->postJson("/ads/write-actions/{$x->public_id}/rollback", [], ['Idempotency-Key' => 'r3-undo-dry'])
        ->assertStatus(422)->assertJsonPath('code', 'not_reversible');
});

it('with the execute flag on the Stop really reaches the platform (the old behaviour)', function () {
    config(['crm.ads.execute' => true]);
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create(['status' => 'ACTIVE']);
    $admin = r3Admin();
    $x = r3Propose($this, $admin, $acc, 'ad', $ad->external_id, 'paused');

    $this->actingAs($admin)->postJson("/ads/write-actions/{$x->public_id}/confirm", ['diff_hash' => $x->diff_hash])->assertOk()->assertJsonPath('action.state', 'succeeded');
    expect(r3FakeStatuses())->toHaveCount(1)->and($ad->fresh()->status)->toBe('PAUSED');
    $rows = app(WriteActionLog::class)->rows($admin);
    expect($rows[0]['result'])->toBe('ok')->and($rows[0]['undoable'])->toBeTrue();
});

it('the execute flag is read from CRM_ADS_EXECUTE and is off unless the env says so', function () {
    // phpunit.xml turns it on for the older write tests; the config line itself must default to false.
    $source = file_get_contents(config_path('crm.php'));
    expect($source)->toContain("'execute' => (bool) env('CRM_ADS_EXECUTE', false)");
});
