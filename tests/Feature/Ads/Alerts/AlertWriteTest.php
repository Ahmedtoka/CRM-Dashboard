<?php

use App\Ads\Platforms\Fake\FakeAdsDriver;
use App\Models\AdsAlert;
use App\Models\AdWriteAction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\AlertWorld as W;

beforeEach(function () {
    W::freeze();
    Http::preventStrayRequests();
    Cache::forget('ads-fake-writer');
    FakeAdsDriver::reset();
    config(['crm.ads.drivers.meta' => 'fake', 'crm.ads.drivers.tiktok' => 'fake', 'crm.ads.drivers.google' => 'fake', 'crm.ads.write_sandbox_accounts' => []]);
});

function awPropose($test, $user, $acc, $ad, ?string $source, ?string $ref)
{
    $res = $test->actingAs($user)->postJson('/ads/write-actions', array_filter([
        'type' => 'set_status', 'account_id' => $acc->id, 'target' => ['level' => 'ad', 'external_id' => $ad->external_id],
        'params' => ['to' => 'paused'], 'reason' => 'from the decisions feed', 'source' => $source, 'source_ref' => $ref,
    ], fn ($v) => $v !== null), ['Idempotency-Key' => 'k-'.bin2hex(random_bytes(8))])->assertSuccessful();

    return AdWriteAction::where('public_id', $res->json('action.id'))->sole();
}

it('records the alert as the source and closes the ad alerts when the Stop succeeds', function () {
    $acc = W::account();
    $buyer = W::buyer($acc);
    $ad = W::ad($acc);
    $alert = AdsAlert::factory()->create(['ad_id' => $ad->id]);
    $other = AdsAlert::factory()->create(['ad_id' => W::ad($acc)->id]);

    $x = awPropose($this, $buyer, $acc, $ad, 'alert', (string) $alert->id);
    expect($x->source)->toBe('alert')->and($x->source_ref)->toBe((string) $alert->id);

    $this->actingAs($buyer)->postJson("/ads/write-actions/{$x->public_id}/confirm", ['diff_hash' => $x->diff_hash])
        ->assertOk()->assertJsonPath('action.state', 'succeeded');

    expect($alert->fresh()->state)->toBe('acted')->and($alert->fresh()->write_action_id)->toBe($x->id)
        ->and($other->fresh()->state)->toBe('open');
});

it('proposes as ui when the alert is about another ad or outside the user scope', function () {
    $acc = W::account();
    $buyer = W::buyer($acc);
    $ad = W::ad($acc);
    $wrongAd = AdsAlert::factory()->create(['ad_id' => W::ad($acc)->id]);
    $outside = AdsAlert::factory()->create(['ad_id' => W::ad(W::account())->id]);

    expect(awPropose($this, $buyer, $acc, $ad, 'alert', (string) $wrongAd->id)->source)->toBe('ui')
        ->and(awPropose($this, $buyer, $acc, $ad, 'alert', (string) $outside->id)->source)->toBe('ui');
});
