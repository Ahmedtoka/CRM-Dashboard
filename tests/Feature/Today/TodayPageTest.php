<?php

use App\Enums\UserRole;
use App\Models\QueueSetting;
use App\Models\SupportCase;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-06 12:00', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true]);
});

it('renders the urgent strip at once and defers the cards and the team line', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    SupportCase::factory()->create(['status' => 'new', 'sla_due_at' => now()->subMinute()]);

    $this->actingAs($admin)->get('/today')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('Today')
            ->where('mode', 'today')->where('date', '2026-10-06')
            ->where('urgent.0.key', 'cases_overdue')->has('generated_at')
            ->missing('cards')->missing('team')->missing('digest')
            ->loadDeferredProps('digest', fn (AssertableInertia $r) => $r->where('digest.variant', 'owner')->has('digest.yesterday')->has('digest.top'))
            ->loadDeferredProps('cards', fn (AssertableInertia $r) => $r->has('cards.chats')->has('cards.orders')->has('cards.why')->has('cards.ads'))
            ->loadDeferredProps('team', fn (AssertableInertia $r) => $r->has('team')));
});

it('turns into the end-of-day report on yesterday: no urgent strip, complete-day cards', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->get('/today?day=yesterday')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('mode', 'yesterday')->where('date', '2026-10-05')->where('urgent', null)->where('digest', null)
            ->loadDeferredProps('cards', fn (AssertableInertia $r) => $r->where('cards.chats.links.new', '/reports/team?from=2026-10-05&to=2026-10-05')
                ->where('cards.orders.outcome_date', '2026-10-05')));
});

it('keeps each manager\'s page for 60 seconds, apart from every other manager', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $other = User::factory()->create(['role' => UserRole::Supervisor]);
    $urgent = fn (User $u) => $this->actingAs($u)->get('/today')->viewData('page')['props']['urgent'];

    expect($urgent($admin))->toBe([]);
    SupportCase::factory()->create(['status' => 'new', 'sla_due_at' => now()->subMinute()]);

    expect($urgent($admin))->toBe([])                                // cached
        ->and(collect($urgent($other))->pluck('key')->all())->toBe(['cases_overdue']); // her own cache

    $this->travel(61)->seconds();
    expect(collect($urgent($admin))->pluck('key')->all())->toBe(['cases_overdue']);
});

it('reads the urgent cache only when the strip is asked for, and stamps the cards on their own', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $key = "today:urgent:{$admin->id}:today:2026-10-06";

    $this->actingAs($admin)->get('/today')->assertOk()
        ->assertInertia(function (AssertableInertia $p) use ($key) {
            expect(Cache::has($key))->toBeTrue();
            Cache::forget($key);

            $p->loadDeferredProps('cards', fn (AssertableInertia $r) => $r->has('cards.chats')->has('cards_generated_at'));
            expect(Cache::has($key))->toBeFalse(); // the deferred reload never touched the strip
        });
});
