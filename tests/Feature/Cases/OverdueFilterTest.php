<?php

use App\Enums\UserRole;
use App\Models\SupportCase;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-10-06 12:00', 'Africa/Cairo')));

it('lists open cases past their SLA, never a closed one or one still in time', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $late = SupportCase::factory()->create(['status' => 'in_progress', 'sla_due_at' => now()->subMinutes(5)]);
    SupportCase::factory()->create(['status' => 'closed', 'sla_due_at' => now()->subHour(), 'closed_at' => now()]);
    SupportCase::factory()->create(['status' => 'new', 'sla_due_at' => now()->addHour()]);
    SupportCase::factory()->create(['status' => 'new', 'sla_due_at' => null]);

    $this->actingAs($sup)->get('/cases?overdue=1')->assertOk()
        ->assertInertia(fn ($p) => $p->where('cases.meta.total', 1)->where('cases.data.0.id', $late->id)->where('filters.overdue', true));
    $this->actingAs($sup)->get('/cases')->assertOk()
        ->assertInertia(fn ($p) => $p->where('cases.meta.total', 4)->where('filters.overdue', null));
});
