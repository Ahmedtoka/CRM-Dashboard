<?php

use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\QueueEntry;
use App\Models\Shift;
use App\Models\ShiftMember;
use App\Models\SupportCase;
use Illuminate\Support\Facades\DB;

function resetInboxWorld(): array
{
    $a = ChannelAccount::factory()->create(['platform' => 'facebook']);
    $b = ChannelAccount::factory()->create(['platform' => 'whatsapp']);
    $customer = Customer::factory()->create();

    $ca = Conversation::factory()->create(['channel_account_id' => $a->id, 'customer_id' => $customer->id]);
    $cb = Conversation::factory()->create(['channel_account_id' => $b->id, 'customer_id' => $customer->id]);
    Message::factory()->count(3)->create(['conversation_id' => $ca->id]);
    Message::factory()->count(2)->create(['conversation_id' => $cb->id]);

    $entry = QueueEntry::factory()->create(['conversation_id' => $ca->id]);
    $ca->forceFill(['queue_entry_id' => $entry->id])->save();
    SupportCase::factory()->create(['conversation_id' => $ca->id]);
    ShiftMember::factory()->for(Shift::factory()->create())->create();

    return [$a, $b, $customer];
}

it('only counts without --force', function () {
    resetInboxWorld();

    $this->artisan('crm:reset-inbox')->assertSuccessful();

    expect(Conversation::count())->toBe(2)->and(Message::count())->toBe(5)
        ->and(QueueEntry::count())->toBe(1)->and(Shift::count())->toBe(1);
});

it('deletes every conversation, its messages, tickets and cases, and the queue day, keeping customers and channels', function () {
    [$a, $b, $customer] = resetInboxWorld();

    $this->artisan('crm:reset-inbox', ['--force' => true])->assertSuccessful();

    expect(Conversation::count())->toBe(0)->and(Message::count())->toBe(0)
        ->and(QueueEntry::count())->toBe(0)->and(SupportCase::count())->toBe(0)
        ->and(Shift::count())->toBe(0)->and(ShiftMember::count())->toBe(0)
        ->and(DB::table('queue_days')->count())->toBe(0)
        ->and($customer->fresh())->not->toBeNull()
        ->and(ChannelAccount::whereKey([$a->id, $b->id])->count())->toBe(2);
});

it('limits itself to the given channel accounts and leaves the queue day alone', function () {
    [$a, $b] = resetInboxWorld();

    $this->artisan('crm:reset-inbox', ['--force' => true, '--account' => [$b->id]])->assertSuccessful();

    expect(Conversation::pluck('channel_account_id')->all())->toBe([$a->id])
        ->and(Message::count())->toBe(3)
        ->and(QueueEntry::count())->toBe(1)->and(Shift::count())->toBe(1);
});
