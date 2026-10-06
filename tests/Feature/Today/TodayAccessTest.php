<?php

use App\Enums\UserRole;
use App\Models\MediaBuyer;
use App\Models\User;
use App\Today\TodayWindow;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-10-06 12:00', 'Africa/Cairo')));

it('opens for admins and supervisors', function (UserRole $role) {
    $u = User::factory()->create(['role' => $role]);

    $this->actingAs($u)->get('/today')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('Today')->where('mode', 'today')->where('date', '2026-10-06'));
})->with([UserRole::Admin, UserRole::Supervisor]);

it('sends an agent to the inbox and refuses her json', function () {
    $mod = User::factory()->create(['role' => UserRole::Moderator]);

    $this->actingAs($mod)->get('/today')->assertRedirect(route('inbox'));
    $this->actingAs($mod)->getJson('/today')->assertForbidden();
});

it('keeps the ads roles in the ads hub', function () {
    $buyer = User::factory()->create(['role' => UserRole::MediaBuyer]);
    MediaBuyer::factory()->create(['user_id' => $buyer->id]);
    $content = User::factory()->create(['role' => UserRole::Content]);
    $adsAdmin = User::factory()->adsAuthority()->create(['role' => UserRole::Admin]);

    $this->actingAs($buyer)->get('/today')->assertRedirect();
    expect($this->actingAs($buyer)->get('/today')->headers->get('Location'))->toContain('/ads');
    $this->actingAs($content)->get('/today')->assertRedirect(route('ads.materials.index', absolute: false));
    $this->actingAs($content)->getJson('/today')->assertForbidden();
    $this->actingAs($adsAdmin)->get('/today')->assertOk();
});

it('asks a guest to sign in', function () {
    $this->get('/today')->assertRedirect('/login');
});

it('reads the day in Cairo: today runs to now, yesterday is the complete day', function () {
    $t = TodayWindow::for('today');
    $y = TodayWindow::for('yesterday');

    expect($t->date)->toBe('2026-10-06')
        ->and($t->from->toIso8601String())->toBe('2026-10-05T21:00:00+00:00') // Cairo is UTC+3 on 6 Oct 2026
        ->and($t->to->toIso8601String())->toBe(now()->utc()->toIso8601String())
        ->and([$t->adsFromDate, $t->adsToDate])->toBe(['2026-10-05', '2026-10-06'])
        ->and($y->date)->toBe('2026-10-05')
        ->and($y->to->toIso8601String())->toBe('2026-10-05T20:59:59+00:00')
        ->and([$y->adsFromDate, $y->adsToDate])->toBe(['2026-10-05', '2026-10-05'])
        ->and($t->outcomeDay()->date)->toBe('2026-10-05')
        ->and($y->outcomeDay()->date)->toBe('2026-10-05');
});

it('treats an unknown day as today', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->get('/today?day=tomorrow')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('mode', 'today'));
    $this->actingAs($admin)->get('/today?day=yesterday')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('mode', 'yesterday')->where('date', '2026-10-05'));
});
