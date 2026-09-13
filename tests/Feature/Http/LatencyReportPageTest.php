<?php

use App\Analytics\LatencyRecorder;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Carbon;

it('shows percentiles with pass/fail against targets for admins only', function () {
    // Latency tracking is off by default (fix round 1 removed the global test env
    // override) — enable it explicitly for the one test here that records samples.
    config(['crm.latency.enabled' => true]);

    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    foreach (range(1, 20) as $i) {
        app(LatencyRecorder::class)->list('q', 0.0, 0.1);
    } // 100 ms

    $this->actingAs($sup)->get('/reports/latency')->assertForbidden();
    $this->actingAs($admin)->get('/reports/latency')->assertOk()
        ->assertInertia(fn ($p) => $p->component('Reports/Latency')
            ->where('kinds.list.p95', 100)
            ->where('kinds.list.pass', true)
            ->where('kinds.list.target', 300)
            ->where('kinds.inbound.target', 2000)
            ->where('kinds.outbound.target', 1500));
});

it('shows no-data state (neither pass nor fail) when a kind has zero samples in the window', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->get('/reports/latency')->assertOk()
        ->assertInertia(fn ($p) => $p->component('Reports/Latency')
            ->where('kinds.inbound.count', 0)
            ->where('kinds.inbound.pass', null)
            ->where('kinds.outbound.count', 0)
            ->where('kinds.outbound.pass', null)
            ->where('kinds.list.count', 0)
            ->where('kinds.list.pass', null));
});

it('accepts a custom Cairo datetime window and converts it to UTC bounds', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)
        ->get('/reports/latency?window=custom&from=2026-09-10T10:00&to=2026-09-10T12:00')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Reports/Latency')
            ->where('window', 'custom')
            // 2026-09-10 10:00 Cairo (UTC+3) = 07:00 UTC
            ->where('range.from', '2026-09-10T07:00:00+00:00'));
});

it('falls back to the 1h window when window=custom is missing from or to', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00', 'UTC'));
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    // Neither from nor to given.
    $this->actingAs($admin)->get('/reports/latency?window=custom')->assertOk()
        ->assertInertia(fn ($p) => $p->component('Reports/Latency')
            ->where('window', '1h')
            ->where('range.from', '2026-09-10T11:00:00+00:00')
            ->where('range.to', '2026-09-10T12:00:00+00:00'));

    // Only "from" given, "to" missing.
    $this->actingAs($admin)->get('/reports/latency?window=custom&from=2026-09-10T10:00')->assertOk()
        ->assertInertia(fn ($p) => $p->component('Reports/Latency')
            ->where('window', '1h')
            ->where('range.from', '2026-09-10T11:00:00+00:00')
            ->where('range.to', '2026-09-10T12:00:00+00:00'));

    Carbon::setTestNow();
});
