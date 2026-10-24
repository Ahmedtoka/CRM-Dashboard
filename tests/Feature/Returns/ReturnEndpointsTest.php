<?php

use App\Cases\Returns\SendReturnUpdate;
use App\Enums\Platform;
use App\Enums\UserRole;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\SupportCase;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

// The moderator's return actions over HTTP (round 2026-10-24): POST /cases/{case}/return/{stage}, the case
// resource's `return` block, and the order page's return cases.

beforeEach(function () {
    Queue::fake([SendReturnUpdate::class]);
    $this->travelTo('2026-10-24 10:00:00');
    $this->user = User::factory()->create(['role' => UserRole::Supervisor]);
    $account = ChannelAccount::factory()->create(['platform' => Platform::WhatsApp, 'driver' => 'fake', 'status' => 'connected']);
    $customer = Customer::factory()->create();
    $this->conversation = Conversation::factory()->create(['platform' => Platform::WhatsApp, 'channel_account_id' => $account->id, 'customer_id' => $customer->id]);
    $this->order = Order::factory()->create(['customer_id' => $customer->id, 'conversation_id' => $this->conversation->id]);
    $this->case = SupportCase::factory()->create([
        'conversation_id' => $this->conversation->id, 'customer_id' => $customer->id, 'platform' => 'whatsapp',
        'type' => 'return', 'order_id' => $this->order->id, 'data' => ['reason' => 'size', 'reason_title' => 'المقاس'],
    ]);
});

it('moves a return one step and answers with the case and its lifecycle', function () {
    $this->actingAs($this->user)
        ->postJson("/cases/{$this->case->id}/return/pickup_scheduled", ['pickup_date' => '2026-10-26'])
        ->assertOk()
        ->assertJsonPath('data.return.stage', 'pickup_scheduled')
        ->assertJsonPath('data.return.pickup_date', '2026-10-26')
        ->assertJsonPath('data.status', 'in_progress')
        ->assertJsonPath('data.return.next', ['pickup_scheduled', 'picked_up', 'cancelled']);

    expect($this->case->fresh()->return_stage)->toBe('pickup_scheduled');
});

it('refuses an out-of-order or incomplete step with a translatable code', function () {
    $this->actingAs($this->user)
        ->postJson("/cases/{$this->case->id}/return/picked_up", ['refund_amount' => 100])
        ->assertStatus(422)
        ->assertJsonPath('code', 'out_of_order');

    $this->actingAs($this->user)
        ->postJson("/cases/{$this->case->id}/return/pickup_scheduled")
        ->assertStatus(422)
        ->assertJsonPath('code', 'pickup_date_required');

    $this->actingAs($this->user)
        ->postJson("/cases/{$this->case->id}/return/nonsense")
        ->assertStatus(422)
        ->assertJsonPath('code', 'unknown_stage');
});

it('follows the case policy: a moderator without the platform may not act', function () {
    $other = User::factory()->create(['role' => UserRole::Moderator]);
    $other->userPlatforms()->create(['platform' => Platform::Facebook]);

    $this->actingAs($other)
        ->postJson("/cases/{$this->case->id}/return/pickup_scheduled", ['pickup_date' => '2026-10-26'])
        ->assertForbidden();
});

it('shows the lifecycle on the case endpoint and the order page', function () {
    $this->actingAs($this->user)->postJson("/cases/{$this->case->id}/return/pickup_scheduled", ['pickup_date' => '2026-10-26'])->assertOk();

    $this->actingAs($this->user)->getJson("/cases/{$this->case->id}")
        ->assertOk()
        ->assertJsonPath('data.return.stage', 'pickup_scheduled')
        ->assertJsonCount(2, 'data.return.timeline');

    $this->actingAs($this->user)->get("/orders/{$this->order->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('Orders/Show')
            ->where('returnCases.0.id', $this->case->id)
            ->where('returnCases.0.return.stage', 'pickup_scheduled')
            ->where('order.return_cases.0.stage', 'pickup_scheduled'));
});
