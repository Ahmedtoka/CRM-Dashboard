<?php

use App\Enums\Platform;
use App\Enums\UserRole;
use App\Events\ConversationUpdated;
use App\Http\Resources\ConversationResource;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\SupportCase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake();
    $this->acc = ChannelAccount::factory()->create(['platform' => Platform::Facebook]);
    $this->admin = User::factory()->create(['role' => UserRole::Admin]);
});

/** A customer's conversation on the Facebook account. */
function chipConv(?Customer $customer = null): Conversation
{
    return Conversation::factory()->for(test()->acc, 'channelAccount')->create(['customer_id' => $customer?->id, 'last_message_at' => now()]);
}

it('carries her open case id on the conversation, before any ticket exists', function () {
    $customer = Customer::factory()->create();
    $conv = chipConv($customer);
    $old = SupportCase::factory()->create(['conversation_id' => $conv->id, 'customer_id' => $customer->id, 'status' => 'new']);
    $new = SupportCase::factory()->create(['conversation_id' => $conv->id, 'customer_id' => $customer->id, 'status' => 'in_progress']);

    expect($conv->queue_entry_id)->toBeNull();

    // The newest open case, on the list and on a single row alike.
    $this->actingAs($this->admin)->getJson('/inbox/conversations')->assertOk()
        ->assertJsonPath('data.0.open_case_id', $new->id)->assertJsonPath('data.0.queue_entry', null);
    expect(ConversationResource::openCaseId($conv->fresh()))->toBe($new->id);

    // Her case follows her customer record into a second conversation.
    $other = chipConv($customer);
    expect(ConversationResource::openCaseId($other))->toBe($new->id)
        ->and($old->id)->toBeLessThan($new->id);
});

it('has no open case once the case is closed or resolved, and for a customer with none', function () {
    $customer = Customer::factory()->create();
    $conv = chipConv($customer);
    SupportCase::factory()->create(['conversation_id' => $conv->id, 'customer_id' => $customer->id, 'status' => 'closed', 'closed_at' => now()]);
    SupportCase::factory()->create(['conversation_id' => $conv->id, 'customer_id' => $customer->id, 'status' => 'in_progress', 'resolved_at' => now()]);
    $stranger = chipConv(Customer::factory()->create());

    $rows = $this->actingAs($this->admin)->getJson('/inbox/conversations')->assertOk()->json('data');

    expect(collect($rows)->pluck('open_case_id')->filter()->all())->toBe([])
        ->and(ConversationResource::openCaseId($conv->fresh()))->toBeNull()
        ->and(ConversationResource::openCaseId($stranger))->toBeNull();
});

it('puts the open case on the live broadcast too, so the chip does not go stale', function () {
    $customer = Customer::factory()->create();
    $conv = chipConv($customer);

    expect((new ConversationUpdated($conv))->broadcastWith()['open_case_id'])->toBeNull();

    $case = SupportCase::factory()->create(['conversation_id' => $conv->id, 'customer_id' => $customer->id, 'status' => 'new']);

    expect((new ConversationUpdated($conv->fresh()))->broadcastWith()['open_case_id'])->toBe($case->id);
});

it('adds no per-row queries for the open case on the inbox list', function () {
    $make = function () {
        $customer = Customer::factory()->create();
        $conv = chipConv($customer);
        SupportCase::factory()->create(['conversation_id' => $conv->id, 'customer_id' => $customer->id, 'status' => 'new']);
    };

    foreach (range(1, 8) as $i) {
        $make();
    }
    $this->actingAs($this->admin)->getJson('/inbox/conversations')->assertOk();

    DB::enableQueryLog();
    $this->actingAs($this->admin)->getJson('/inbox/conversations')->assertOk();
    $withEight = count(DB::getQueryLog());

    foreach (range(1, 8) as $i) {
        $make();
    }
    DB::flushQueryLog();
    $rows = $this->actingAs($this->admin)->getJson('/inbox/conversations')->assertOk()->json('data');

    expect(count(DB::getQueryLog()))->toBe($withEight)
        ->and(collect($rows)->pluck('open_case_id')->filter()->count())->toBe(16);
});
