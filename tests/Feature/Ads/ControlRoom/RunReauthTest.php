<?php

require_once __DIR__.'/../../../Support/AdsControlRoom.php';

use App\Ads\Platforms\Fake\FakeAdsDriver;
use App\Enums\UserRole;
use App\Models\AdWriteAction;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    crSetup($this);
    Cache::forget('ads-fake-writer');
    FakeAdsDriver::reset();
    config(['crm.ads.write_sandbox_accounts' => [], 'crm.ads.write.run_reauth_seconds' => 900]);
});

function rrPropose($test, $user, $acc, $ad, string $to): AdWriteAction
{
    $res = $test->actingAs($user)->postJson('/ads/write-actions', [
        'type' => 'set_status', 'account_id' => $acc->id, 'target' => ['level' => 'ad', 'external_id' => $ad->external_id], 'params' => ['to' => $to],
    ], ['Idempotency-Key' => 'rr-'.bin2hex(random_bytes(6))])->assertSuccessful();

    return AdWriteAction::where('public_id', $res->json('action.id'))->sole();
}

it('refuses a Run confirm without a recent password with 423', function () {
    $w = crBuyer();
    $ad = crAd($w['account'], [], ['status' => 'PAUSED']);
    $x = rrPropose($this, $w['user'], $w['account'], $ad, 'active');

    $this->actingAs($w['user'])->postJson("/ads/write-actions/{$x->public_id}/confirm", ['diff_hash' => $x->diff_hash])
        ->assertStatus(423)->assertJsonPath('code', 'password_confirmation_required');
    expect($x->fresh()->state)->toBe('proposed');
});

it('lets a Run through within 15 minutes of a password confirmation', function () {
    $w = crBuyer();
    $ad = crAd($w['account'], [], ['status' => 'PAUSED']);
    $x = rrPropose($this, $w['user'], $w['account'], $ad, 'active');

    $res = $this->actingAs($w['user'])->withSession(['auth.password_confirmed_at' => time() - 60])
        ->postJson("/ads/write-actions/{$x->public_id}/confirm", ['diff_hash' => $x->diff_hash]);

    // The fake account may still refuse on its budget cap (RunGuard); what matters is that re-auth passed.
    expect($res->status())->not->toBe(423)->and($res->json('code'))->not->toBe('password_confirmation_required');
});

it('never asks for a password on Stop', function () {
    $w = crBuyer();
    $ad = crAd($w['account'], [], ['status' => 'ACTIVE']);
    $x = rrPropose($this, $w['user'], $w['account'], $ad, 'paused');

    $this->actingAs($w['user'])->postJson("/ads/write-actions/{$x->public_id}/confirm", ['diff_hash' => $x->diff_hash])
        ->assertOk()->assertJsonPath('action.state', 'succeeded');
});

it('confirms the password over JSON and stores the time in the session', function () {
    $w = crBuyer();
    $this->actingAs($w['user'])->postJson('/ads/reauth', ['password' => 'wrong'])->assertStatus(422)->assertJsonValidationErrors('password');
    $this->actingAs($w['user'])->postJson('/ads/reauth', ['password' => 'password'])->assertNoContent()
        ->assertSessionHas('auth.password_confirmed_at');
});

it('keeps reauth to ads report users', function () {
    $this->actingAs(crUser(UserRole::Moderator))->postJson('/ads/reauth', ['password' => 'password'])->assertForbidden();
});
