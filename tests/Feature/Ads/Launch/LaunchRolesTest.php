<?php

use App\Ads\Launch\LaunchChecks;
use App\Ads\Launch\LaunchState;
use App\Enums\UserRole;
use App\Models\AdPublication;
use App\Models\AdSet;
use App\Models\MediaBuyer;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\Support\LaunchWorld;

beforeEach(function () {
    LaunchWorld::boot();
    $this->withoutVite();
    Queue::fake();
});

/**
 * One request per role on a fresh world; expected HTTP status per role. Roles: content, buyerUser (holds the account
 * today), stranger (a media buyer holding no account of the world), manager (supervisor with Ads authority), admin
 * (Ads authority), supervisor (no authority), agent (moderator).
 */
function lrMatrix($test, callable $request, array $expected): void
{
    foreach ($expected as $role => $status) {
        $w = LaunchWorld::make();
        $w['stranger'] = User::factory()->create(['role' => UserRole::MediaBuyer]);
        MediaBuyer::factory()->create(['user_id' => $w['stranger']->id, 'is_active' => true]);
        $res = $request($test, $w, $w[$role]);
        expect($res->getStatusCode())->toBe($status, "role {$role}");
    }
}

/** content prepares; buyers review; authority approves (D1-D5). */
const LR_ALL = ['content', 'buyerUser', 'stranger', 'manager', 'admin', 'supervisor', 'agent'];

function lrExpect(array $statuses): array
{
    return array_combine(LR_ALL, $statuses);
}

it('GET /ads/launches', function () {
    lrMatrix($this, fn ($t, $w, $u) => $t->actingAs($u)->get('/ads/launches'), lrExpect([200, 200, 200, 200, 200, 200, 403]));
});

it('GET /ads/launches/options', function () {
    lrMatrix($this, fn ($t, $w, $u) => $t->actingAs($u)->getJson("/ads/launches/options?material={$w['material']->id}"), lrExpect([200, 200, 200, 200, 200, 200, 403]));
});

it('GET /ads/launches/{launch} and /checks', function () {
    $draft = fn ($w) => LaunchWorld::launch($w);
    lrMatrix($this, fn ($t, $w, $u) => $t->actingAs($u)->getJson('/ads/launches/'.$draft($w)->public_id), lrExpect([200, 200, 404, 200, 200, 200, 403]));
    lrMatrix($this, fn ($t, $w, $u) => $t->actingAs($u)->getJson('/ads/launches/'.$draft($w)->public_id.'/checks'), lrExpect([200, 200, 404, 200, 200, 200, 403]));
});

it('GET /ads/approvals', function () {
    lrMatrix($this, fn ($t, $w, $u) => $t->actingAs($u)->get('/ads/approvals'), lrExpect([302, 403, 403, 200, 200, 200, 403]));
});

it('POST /ads/materials/{material}/launches', function () {
    lrMatrix($this, fn ($t, $w, $u) => $t->actingAs($u)->postJson("/ads/materials/{$w['material']->id}/launches", [
        'adset_id' => $w['adset']->id, 'file_ids' => [$w['files'][0]->id], 'captions' => [LaunchWorld::caption()],
    ]), lrExpect([201, 201, 201, 201, 201, 201, 403]));
});

it('PUT /ads/launches/{launch} (draft)', function () {
    lrMatrix($this, fn ($t, $w, $u) => $t->actingAs($u)->putJson('/ads/launches/'.LaunchWorld::launch($w)->public_id, ['revision' => 1, 'captions' => [LaunchWorld::caption(2)]]),
        lrExpect([200, 403, 404, 200, 200, 200, 403]));
});

it('POST /ads/launches/{launch}/submit', function () {
    lrMatrix($this, fn ($t, $w, $u) => $t->actingAs($u)->postJson('/ads/launches/'.LaunchWorld::launch($w)->public_id.'/submit', ['revision' => 1]),
        lrExpect([200, 403, 404, 200, 200, 200, 403]));
});

it('POST /ads/launches/{launch}/send-back', function () {
    lrMatrix($this, fn ($t, $w, $u) => $t->actingAs($u)->postJson('/ads/launches/'.LaunchWorld::launch($w, LaunchState::BuyerReview)->public_id.'/send-back', ['code' => 'off_brand']),
        lrExpect([403, 200, 404, 200, 200, 403, 403]));
});

it('POST /ads/launches/{launch}/withdraw (draft)', function () {
    lrMatrix($this, fn ($t, $w, $u) => $t->actingAs($u)->postJson('/ads/launches/'.LaunchWorld::launch($w)->public_id.'/withdraw'),
        lrExpect([200, 403, 404, 200, 200, 200, 403]));
});

it('POST /ads/launches/{launch}/forward', function () {
    lrMatrix($this, fn ($t, $w, $u) => $t->actingAs($u)->postJson('/ads/launches/'.LaunchWorld::launch($w, LaunchState::BuyerReview)->public_id.'/forward', ['revision' => 1]),
        lrExpect([403, 200, 404, 200, 200, 403, 403]));
});

it('POST /ads/launches/{launch}/retry', function () {
    lrMatrix($this, function ($t, $w, $u) {
        $l = LaunchWorld::launch($w, LaunchState::CreateFailed);
        AdPublication::create([
            'ad_material_id' => $w['material']->id, 'ad_material_file_id' => $w['files'][0]->id, 'ad_account_id' => $w['account']->id, 'platform' => 'meta',
            'campaign_external_id' => $l->campaign_external_id, 'adset_external_id' => $l->adset_external_id, 'headline' => 'H', 'primary_text' => 'T', 'cta' => 'SHOP_NOW',
            'ad_name' => 'x', 'link' => (string) $l->link, 'url_tags' => 'u', 'status' => AdPublication::ERROR, 'error' => 'boom', 'ad_launch_id' => $l->id,
        ]);

        return $t->actingAs($u)->postJson("/ads/launches/{$l->public_id}/retry");
    }, lrExpect([403, 200, 404, 200, 200, 403, 403]));
});

it('POST /ads/launches/{launch}/stop', function () {
    lrMatrix($this, fn ($t, $w, $u) => $t->actingAs($u)->postJson('/ads/launches/'.LaunchWorld::launch($w, LaunchState::Live)->public_id.'/stop', [], ['Idempotency-Key' => 'stop-'.bin2hex(random_bytes(6))]),
        lrExpect([403, 200, 404, 200, 200, 200, 403]));
});

it('POST /ads/launches/{launch}/retire', function () {
    lrMatrix($this, fn ($t, $w, $u) => $t->actingAs($u)->postJson('/ads/launches/'.LaunchWorld::launch($w, LaunchState::Live)->public_id.'/retire', [], ['Idempotency-Key' => 'ret-'.bin2hex(random_bytes(6))]),
        lrExpect([403, 200, 404, 200, 200, 403, 403]));
});

it('POST /ads/approvals/{launch}/approve', function () {
    lrMatrix($this, function ($t, $w, $u) {
        $l = LaunchWorld::launch($w, LaunchState::AwaitingApproval);
        $hash = LaunchChecks::hash($l, app(LaunchChecks::class)->run($l, 'approve', $u));

        return $t->actingAs($u)->withSession(LaunchWorld::confirmed())
            ->postJson("/ads/approvals/{$l->public_id}/approve", ['revision' => $l->revision, 'checks_hash' => $hash, 'ack_warnings' => []]);
    }, lrExpect([403, 403, 403, 200, 200, 403, 403]));
});

it('POST /ads/approvals/{launch}/return and /reject', function () {
    foreach (['return', 'reject'] as $kind) {
        lrMatrix($this, fn ($t, $w, $u) => $t->actingAs($u)->postJson('/ads/approvals/'.LaunchWorld::launch($w, LaunchState::AwaitingApproval)->public_id.'/'.$kind, ['code' => 'off_brand']),
            lrExpect([403, 403, 403, 200, 200, 403, 403]));
    }
});

it('POST /ads/approvals/bulk', function () {
    lrMatrix($this, fn ($t, $w, $u) => $t->actingAs($u)->withSession(LaunchWorld::confirmed())->postJson('/ads/approvals/bulk'), lrExpect([403, 403, 403, 200, 200, 403, 403]));
});

it('POST /ads/reauth', function () {
    lrMatrix($this, fn ($t, $w, $u) => $t->actingAs($u)->postJson('/ads/reauth', ['password' => 'password']), lrExpect([403, 200, 200, 200, 200, 200, 403]));
});

it('GET /ads/slots and POST /ads/slots/{adSet}', function () {
    lrMatrix($this, fn ($t, $w, $u) => $t->actingAs($u)->getJson('/ads/slots'), lrExpect([403, 200, 200, 200, 200, 403, 403]));
    lrMatrix($this, fn ($t, $w, $u) => $t->actingAs($u)->postJson('/ads/slots/'.AdSet::factory()->create(['ad_campaign_id' => $w['campaign']->id])->id, ['open' => true]),
        lrExpect([403, 200, 403, 200, 200, 403, 403]));
});
