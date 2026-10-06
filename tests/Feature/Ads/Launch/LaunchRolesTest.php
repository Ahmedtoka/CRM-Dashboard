<?php

use App\Ads\Launch\LaunchChecks;
use App\Ads\Launch\LaunchState;
use App\Models\AdSet;
use Tests\Support\LaunchWorld;

beforeEach(function () {
    LaunchWorld::boot();
    $this->withoutVite();
});

/** One request per role on a fresh world; expected HTTP status per role. */
function lrMatrix($test, callable $request, array $expected): void
{
    foreach ($expected as $role => $status) {
        $w = LaunchWorld::make();
        $res = $request($test, $w, $w[$role]);
        expect($res->getStatusCode())->toBe($status, "role {$role}");
    }
}

it('GET /ads/launches', function () {
    lrMatrix($this, fn ($t, $w, $u) => $t->actingAs($u)->get('/ads/launches'),
        ['content' => 200, 'buyerUser' => 200, 'manager' => 200, 'admin' => 200, 'supervisor' => 200, 'agent' => 403]);
});

it('GET /ads/approvals', function () {
    lrMatrix($this, fn ($t, $w, $u) => $t->actingAs($u)->get('/ads/approvals'),
        ['content' => 302, 'buyerUser' => 403, 'manager' => 200, 'admin' => 200, 'supervisor' => 200, 'agent' => 403]);
});

it('POST /ads/materials/{material}/launches', function () {
    lrMatrix($this, fn ($t, $w, $u) => $t->actingAs($u)->postJson("/ads/materials/{$w['material']->id}/launches", [
        'adset_id' => $w['adset']->id, 'file_ids' => [$w['files'][0]->id], 'captions' => [LaunchWorld::caption()],
    ]), ['content' => 201, 'buyerUser' => 201, 'manager' => 201, 'admin' => 201, 'supervisor' => 201, 'agent' => 403]);
});

it('GET /ads/slots and POST /ads/slots/{adSet}', function () {
    lrMatrix($this, fn ($t, $w, $u) => $t->actingAs($u)->getJson('/ads/slots'),
        ['content' => 403, 'buyerUser' => 200, 'manager' => 200, 'admin' => 200, 'supervisor' => 403, 'agent' => 403]);
    lrMatrix($this, fn ($t, $w, $u) => $t->actingAs($u)->postJson('/ads/slots/'.AdSet::factory()->create(['ad_campaign_id' => $w['campaign']->id])->id, ['open' => true]),
        ['content' => 403, 'buyerUser' => 200, 'manager' => 200, 'admin' => 200, 'supervisor' => 403, 'agent' => 403]);
});

it('POST /ads/launches/{launch}/forward', function () {
    lrMatrix($this, function ($t, $w, $u) {
        \Illuminate\Support\Facades\Queue::fake();
        $l = LaunchWorld::launch($w, LaunchState::BuyerReview);

        return $t->actingAs($u)->postJson("/ads/launches/{$l->public_id}/forward", ['revision' => 1]);
    }, ['content' => 403, 'buyerUser' => 200, 'manager' => 200, 'admin' => 200, 'supervisor' => 403, 'agent' => 403]);
});

it('POST /ads/approvals/{launch}/approve', function () {
    lrMatrix($this, function ($t, $w, $u) {
        $l = LaunchWorld::launch($w, LaunchState::AwaitingApproval);
        $hash = LaunchChecks::hash($l, app(LaunchChecks::class)->run($l, 'approve', $u));

        return $t->actingAs($u)->withSession(LaunchWorld::confirmed())
            ->postJson("/ads/approvals/{$l->public_id}/approve", ['revision' => $l->revision, 'checks_hash' => $hash, 'ack_warnings' => []]);
    }, ['content' => 403, 'buyerUser' => 403, 'manager' => 200, 'admin' => 200, 'supervisor' => 403, 'agent' => 403]);
});

it('POST /ads/approvals/bulk', function () {
    lrMatrix($this, fn ($t, $w, $u) => $t->actingAs($u)->withSession(LaunchWorld::confirmed())->postJson('/ads/approvals/bulk'),
        ['content' => 403, 'buyerUser' => 403, 'manager' => 200, 'admin' => 200, 'supervisor' => 403, 'agent' => 403]);
});

it('POST /ads/launches/{launch}/stop', function () {
    lrMatrix($this, function ($t, $w, $u) {
        $l = LaunchWorld::launch($w, LaunchState::Live);

        return $t->actingAs($u)->postJson("/ads/launches/{$l->public_id}/stop", [], ['Idempotency-Key' => 'stop-'.bin2hex(random_bytes(6))]);
    }, ['content' => 403, 'buyerUser' => 200, 'manager' => 200, 'admin' => 200, 'supervisor' => 200, 'agent' => 403]);
});
