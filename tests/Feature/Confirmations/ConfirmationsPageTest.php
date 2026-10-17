<?php

use App\Confirmations\Events\OrderConfirmationCalled;
use App\Enums\OrderConfirmationStatus as S;
use App\Enums\Platform;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\OrderConfirmation;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia;

// WhatsApp order confirmation W4 (spec 2026-10-16): «تأكيدات الأوردرات» — one Cairo day, tabs, call list.

beforeEach(function () {
    $this->travelTo(now()->parse('2026-10-16 12:00', 'Africa/Cairo'));
    $this->admin = User::factory()->create(['role' => UserRole::Admin]);
});

function confirmationAt(string $cairo, S $status, array $attrs = []): OrderConfirmation
{
    $c = OrderConfirmation::factory()->status($status)->create($attrs);
    $c->forceFill(['created_at' => now()->parse($cairo, 'Africa/Cairo')->utc()])->saveQuietly(); // stored as UTC

    return $c;
}

it('shows the day counters and the rows of the chosen tab', function () {
    confirmationAt('2026-10-16 09:00', S::Confirmed, ['sent_at' => now()->subHours(3), 'answered_at' => now()->subHours(2), 'answer' => 'confirm']);
    confirmationAt('2026-10-16 09:30', S::Confirmed, ['sent_at' => now()->subHours(3)]);
    confirmationAt('2026-10-16 10:00', S::CancelRequested, ['sent_at' => now()->subHours(2)]);
    confirmationAt('2026-10-16 10:30', S::EditRequested, ['sent_at' => now()->subHours(2), 'edit_note' => 'غيري العنوان']);
    confirmationAt('2026-10-16 11:00', S::Sent, ['sent_at' => now()->subHour()]);
    confirmationAt('2026-10-16 11:30', S::Scheduled);
    confirmationAt('2026-10-16 11:40', S::Skipped, ['skip_reason' => 'no_phone']);
    confirmationAt('2026-10-15 23:30', S::Confirmed, ['sent_at' => now()->subDay()]); // yesterday (Cairo): not counted

    $this->actingAs($this->admin)->get('/orders/confirmations')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('Orders/Confirmations')
            ->where('date', '2026-10-16')
            ->where('tab', 'all')
            ->where('counts.sent', 5)
            ->where('counts.confirmed', 2)
            ->where('counts.answered', 4)
            ->where('counts.confirmed_share', 0.5)
            ->where('counts.cancel', 1)
            ->where('counts.edit', 1)
            ->where('counts.waiting', 2)
            ->where('counts.skipped', 1)
            ->where('counts.tabs.all', 7)
            ->where('counts.tabs.requests', 2)
            ->has('rows.data', 7)
            ->where('rows.meta.total', 7)
            ->has('rows.data.0', fn (AssertableInertia $r) => $r->hasAll([
                'id', 'status', 'skip_reason', 'phone', 'order', 'sent_at', 'answered_at', 'edit_note', 'case_id', 'conversation_id', 'needs_call',
            ])->etc()));

    $this->actingAs($this->admin)->get('/orders/confirmations?tab=requests')
        ->assertInertia(fn (AssertableInertia $p) => $p->where('tab', 'requests')->has('rows.data', 2)
            ->where('rows.data.0.status', 'edit_requested')->where('rows.data.0.edit_note', 'غيري العنوان'));

    $this->actingAs($this->admin)->get('/orders/confirmations?date=2026-10-15&tab=confirmed')
        ->assertInertia(fn (AssertableInertia $p) => $p->where('date', '2026-10-15')->has('rows.data', 1));
});

it('lists every confirmation waiting for a call on the call list, whatever its day', function () {
    confirmationAt('2026-10-14 10:00', S::NoResponse, ['sent_at' => now()->subDays(2)]);
    confirmationAt('2026-10-15 10:00', S::NoResponse, ['sent_at' => now()->subDay()]);
    confirmationAt('2026-10-15 11:00', S::Unreachable, ['sent_at' => now()->subDay()]);

    $this->actingAs($this->admin)->get('/orders/confirmations?tab=needs_call')
        ->assertInertia(fn (AssertableInertia $p) => $p->where('allDays', true)->has('rows.data', 2)
            ->where('counts.tabs.needs_call', 2)->where('rows.data.0.needs_call', true));
});

it('falls back to today and the all tab on a bad date or tab', function () {
    $this->actingAs($this->admin)->get('/orders/confirmations?date=2026-02-30&tab=nope')
        ->assertInertia(fn (AssertableInertia $p) => $p->where('date', '2026-10-16')->where('tab', 'all'));
});

it('shows a moderator only the confirmations of orders she can see', function () {
    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $mod->userPlatforms()->create(['platform' => Platform::Facebook]);
    $mine = confirmationAt('2026-10-16 09:00', S::Sent, ['order_id' => Order::factory()->create(['platform' => Platform::Facebook])->id]);
    confirmationAt('2026-10-16 09:00', S::Sent, ['order_id' => Order::factory()->create(['platform' => Platform::Instagram])->id]);
    confirmationAt('2026-10-16 09:00', S::Sent); // a store order: no platform

    $this->actingAs($mod)->get('/orders/confirmations')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->has('rows.data', 1)->where('rows.data.0.id', $mine->id)->where('counts.tabs.all', 1)
            ->where('canEditSettings', false));
});

it('is for signed-in staff only', function () {
    $this->get('/orders/confirmations')->assertRedirect('/login');
});

it('records a call outcome through CallOutcome', function (string $outcome, S $status) {
    Event::fake([OrderConfirmationCalled::class]);
    $c = confirmationAt('2026-10-15 10:00', S::NoResponse, ['sent_at' => now()->subDay()]);

    $this->actingAs($this->admin)->postJson("/orders/confirmations/{$c->id}/call", ['outcome' => $outcome])
        ->assertOk()
        ->assertJsonPath('data.status', $status->value)
        ->assertJsonPath('data.needs_call', false);

    expect($c->fresh())->status->toBe($status)->called_by->toBe($this->admin->id)->call_outcome->toBe($outcome);
    Event::assertDispatched(OrderConfirmationCalled::class);
})->with([
    'confirmed' => ['confirmed', S::CalledConfirmed],
    'cancelled' => ['cancelled', S::CalledCancelled],
    'unreachable' => ['unreachable', S::Unreachable],
]);

it('records an answer the customer gave in her own words, from sent, edit_requested or a past unreachable', function (S $from) {
    Event::fake([OrderConfirmationCalled::class]);
    $c = confirmationAt('2026-10-16 09:00', $from, ['sent_at' => now()->subHour()]);

    $this->actingAs($this->admin)->get('/orders/confirmations')
        ->assertInertia(fn (AssertableInertia $p) => $p->where('rows.data.0.can_record', true));

    $this->actingAs($this->admin)->postJson("/orders/confirmations/{$c->id}/call", ['outcome' => 'confirmed'])
        ->assertOk()
        ->assertJsonPath('data.status', S::CalledConfirmed->value)
        ->assertJsonPath('data.can_record', false);

    expect($c->fresh())->status->toBe(S::CalledConfirmed)->called_by->toBe($this->admin->id);
    Event::assertDispatched(OrderConfirmationCalled::class, fn ($e) => $e->confirmation->id === $c->id && $e->outcome === 'confirmed');
})->with([
    'sent' => [S::Sent],
    'edit requested' => [S::EditRequested],
    'unreachable (a later call)' => [S::Unreachable],
]);

it('validates the outcome and refuses a confirmation whose answer is settled', function () {
    $c = confirmationAt('2026-10-15 10:00', S::NoResponse);
    $this->actingAs($this->admin)->postJson("/orders/confirmations/{$c->id}/call", ['outcome' => 'maybe'])->assertJsonValidationErrors('outcome');
    $this->actingAs($this->admin)->postJson("/orders/confirmations/{$c->id}/call", [])->assertJsonValidationErrors('outcome');

    foreach ([S::Scheduled, S::Confirmed, S::CancelRequested, S::Skipped, S::Failed, S::CalledConfirmed, S::CalledCancelled] as $settled) {
        $done = confirmationAt('2026-10-15 10:00', $settled);
        $this->actingAs($this->admin)->postJson("/orders/confirmations/{$done->id}/call", ['outcome' => 'confirmed'])->assertStatus(422);
        expect($done->fresh()->status)->toBe($settled);
    }
});

it('lets only staff who can see the order record its call', function () {
    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $mod->userPlatforms()->create(['platform' => Platform::Facebook]);
    $other = confirmationAt('2026-10-15 10:00', S::NoResponse, ['order_id' => Order::factory()->create(['platform' => Platform::Instagram])->id]);
    $own = confirmationAt('2026-10-15 10:00', S::NoResponse, ['order_id' => Order::factory()->create(['platform' => Platform::Facebook])->id]);

    $this->actingAs($mod)->postJson("/orders/confirmations/{$other->id}/call", ['outcome' => 'confirmed'])->assertForbidden();
    $this->actingAs($mod)->postJson("/orders/confirmations/{$own->id}/call", ['outcome' => 'confirmed'])->assertOk();

    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $this->actingAs($sup)->postJson("/orders/confirmations/{$other->id}/call", ['outcome' => 'unreachable'])->assertOk();
});
