<?php

require_once __DIR__.'/../../../Support/AdsControlRoom.php';

use App\Enums\UserRole;
use App\Models\AdAccount;
use App\Models\AdAccountAssignment;
use App\Models\MediaBuyer;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => crSetup($this));

dataset('control_room_pages', ['/ads', '/ads/decisions', '/ads/decisions?tab=log', '/ads/explorer', '/ads/explorer?view=cards', '/ads/explorer?view=tree', '/ads/numbers']);

it('opens every control-room page for admin, supervisor and media buyer', function (string $url) {
    foreach ([crAdmin(), crUser(UserRole::Supervisor), crBuyer()['user']] as $user) {
        $this->actingAs($user)->get($url)->assertOk();
    }
})->with('control_room_pages');

it('refuses agents and sends content users to the library', function (string $url) {
    $this->actingAs(crUser(UserRole::Moderator))->get($url)->assertForbidden();
    $this->actingAs(crUser(UserRole::Content))->get($url)->assertRedirect();
})->with('control_room_pages');

it('scopes the ad drawer per role', function () {
    $w = crBuyer();
    $own = crAd($w['account']);
    $foreign = crAd(AdAccount::factory()->meta()->create());

    $this->actingAs(crAdmin())->getJson("/ads/ad/{$foreign->id}")->assertOk();
    $this->actingAs(crUser(UserRole::Supervisor))->getJson("/ads/ad/{$foreign->id}")->assertOk();
    $this->actingAs($w['user'])->getJson("/ads/ad/{$own->id}")->assertOk();
    $this->actingAs($w['user'])->getJson("/ads/ad/{$foreign->id}")->assertNotFound();
    $this->actingAs(crUser(UserRole::Moderator))->getJson("/ads/ad/{$own->id}")->assertForbidden();
    $this->actingAs(crUser(UserRole::Content))->getJson("/ads/ad/{$own->id}")->assertForbidden();
});

it('shows a buyer only the days they held an account (dated assignments)', function () {
    $acc = AdAccount::factory()->meta()->create(['currency' => 'EGP']);
    $old = crBuyer($acc, '2026-09-01', '2026-09-30');
    $new = crUser(UserRole::MediaBuyer);
    $newBuyer = MediaBuyer::factory()->create(['user_id' => $new->id]);
    AdAccountAssignment::factory()->create(['ad_account_id' => $acc->id, 'media_buyer_id' => $newBuyer->id, 'starts_on' => '2026-10-01', 'ends_on' => null]);
    crAd($acc, ['2026-09-15' => [100, 0, 0, 0], '2026-10-02' => [900, 0, 0, 0]]);
    $q = '/ads/explorer?from=2026-09-01&to=2026-10-05&status=all';

    $this->actingAs($old['user'])->get($q)->assertInertia(fn (Assert $p) => $p->where('result.totals.spend', 100));
    $this->actingAs($new)->get($q)->assertInertia(fn (Assert $p) => $p->where('result.totals.spend', 900));
});
