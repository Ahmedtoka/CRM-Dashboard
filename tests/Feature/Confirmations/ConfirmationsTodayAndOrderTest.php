<?php

use App\Confirmations\ConfirmationSettings;
use App\Enums\OrderConfirmationStatus as S;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\OrderConfirmation;
use App\Models\User;
use App\Today\TodayCards;
use App\Today\TodayWindow;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;

// WhatsApp order confirmation W4 (spec 2026-10-16): the «النهارده» card and the block on the order page.

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-16 12:00', 'Africa/Cairo'));
    $this->admin = User::factory()->create(['role' => UserRole::Admin]);
});

it('has no confirmations card while the feature is off and the day has none', function () {
    expect(app(TodayCards::class)->confirmations(TodayWindow::for('today'), $this->admin))->toBeNull();
});

it('builds the confirmations card with the call list across days', function () {
    OrderConfirmation::factory()->status(S::Confirmed)->create(['sent_at' => now()->subHours(2)]);
    OrderConfirmation::factory()->status(S::CancelRequested)->create(['sent_at' => now()->subHours(2)]);
    OrderConfirmation::factory()->sent()->create();
    $old = OrderConfirmation::factory()->status(S::NoResponse)->create(['sent_at' => now()->subDays(2)]);
    $old->forceFill(['created_at' => now()->subDays(2)])->saveQuietly();

    $card = app(TodayCards::class)->confirmations(TodayWindow::for('today'), $this->admin);

    expect($card)->toMatchArray([
        'sent' => 3, 'confirmed' => 1, 'confirmed_share' => 0.5, 'cancel' => 1, 'edit' => 0, 'no_response' => 0, 'waiting' => 1, 'needs_call' => 1,
    ])->and($card['links'])->toMatchArray([
        'sent' => '/orders/confirmations?date=2026-10-16',
        'confirmed' => '/orders/confirmations?date=2026-10-16&tab=confirmed',
        'requests' => '/orders/confirmations?date=2026-10-16&tab=requests',
        'needs_call' => '/orders/confirmations?tab=needs_call',
    ]);
});

it('shows an empty card once the feature is on', function () {
    app(ConfirmationSettings::class)->save(['enabled' => true]);

    expect(app(TodayCards::class)->confirmations(TodayWindow::for('today'), $this->admin))->toMatchArray(['sent' => 0, 'needs_call' => 0]);
    expect(app(TodayCards::class)->all(TodayWindow::for('today'), $this->admin))->toHaveKey('confirmations');
});

it('shows the confirmation and its timeline on the order page', function () {
    $c = OrderConfirmation::factory()->status(S::CalledConfirmed)->create([
        'send_at' => now()->subDays(2), 'sent_at' => now()->subDays(2), 'reminded_at' => now()->subDays(2)->addHours(6),
        'called_at' => now()->subHour(), 'call_outcome' => 'confirmed', 'called_by' => $this->admin->id, 'case_id' => null,
    ]);

    $this->actingAs($this->admin)->get("/orders/{$c->order_id}")->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('Orders/Show')
            ->where('confirmation.id', $c->id)
            ->where('confirmation.status', 'called_confirmed')
            ->where('confirmation.caller', $this->admin->name)
            ->where('confirmation.timeline', fn ($steps) => collect($steps)->pluck('key')->all() === ['scheduled', 'sent', 'reminded', 'no_response', 'called']));
});

it('ends a skipped confirmation\'s timeline with its reason, and has none without a confirmation', function () {
    $c = OrderConfirmation::factory()->status(S::Skipped)->create(['skip_reason' => 'too_old']);

    $this->actingAs($this->admin)->get("/orders/{$c->order_id}")
        ->assertInertia(fn (AssertableInertia $p) => $p->where('confirmation.timeline.0.key', 'skipped')->where('confirmation.timeline.0.detail', 'too_old'));

    $other = Order::factory()->create();
    $this->actingAs($this->admin)->get("/orders/{$other->id}")->assertInertia(fn (AssertableInertia $p) => $p->where('confirmation', null));
});
