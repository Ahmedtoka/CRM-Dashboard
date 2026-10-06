<?php

require_once __DIR__.'/../../../Support/AdsControlRoom.php';

use App\Enums\UserRole;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => crSetup($this));

it('opens setup on the accounts tab and serves the rules tab', function () {
    $this->actingAs(crAdmin())->get('/ads/setup')->assertRedirect('/ads/accounts');
    $this->actingAs(crAdmin())->get('/ads/setup/rules')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Ads/SetupRules')->has('settings.winner_thresholds.winner')->has('settings.tax_rate_percent'));
    $this->actingAs(crAdmin())->get('/ads/setup/buyers')->assertInertia(fn (Assert $p) => $p->missing('settings'));
});

it('keeps setup to supervisors and above', function () {
    $this->actingAs(crUser(UserRole::Supervisor))->get('/ads/setup/rules')->assertOk();
    $this->actingAs(crBuyer()['user'])->get('/ads/setup/rules')->assertForbidden();
    $this->actingAs(crUser(UserRole::Moderator))->get('/ads/setup/rules')->assertForbidden();
});
