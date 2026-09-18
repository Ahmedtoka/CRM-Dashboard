<?php

use App\Models\ActivityLog;
use App\Models\BotRun;
use App\Models\Comment;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Order;
use App\Models\SupportCase;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['crm.legal.retention_months' => 24]);
    Storage::fake('media');
});

/** A conversation whose one message (with a photo) was sent at $at. */
function retentionConversation(Customer $customer, DateTimeInterface $at, string $file): Conversation
{
    $conversation = Conversation::factory()->create(['customer_id' => $customer->id]);
    $message = Message::factory()->create(['conversation_id' => $conversation->id]);
    Storage::disk('media')->put($file, 'bytes');
    MessageAttachment::factory()->create(['message_id' => $message->id, 'disk' => 'media', 'path' => $file]);
    SupportCase::factory()->create(['conversation_id' => $conversation->id, 'customer_id' => $customer->id]);
    BotRun::factory()->create(['conversation_id' => $conversation->id]);
    ActivityLog::factory()->create(['conversation_id' => $conversation->id]);

    Message::whereKey($message->id)->update(['created_at' => $at, 'updated_at' => $at]);
    Conversation::whereKey($conversation->id)->update(['last_message_at' => $at, 'created_at' => $at]);

    return $conversation->fresh();
}

it('deletes conversations past the retention window with their data, and customers left empty', function () {
    $old = Customer::factory()->create();
    $oldConversation = retentionConversation($old, now()->subMonths(25), 'inbound/old.jpg');

    $recent = Customer::factory()->create();
    $recentConversation = retentionConversation($recent, now()->subMonths(3), 'inbound/recent.jpg');

    // Old conversation, but the customer has an order: the conversation goes, the customer stays.
    $buyer = Customer::factory()->create();
    $buyerConversation = retentionConversation($buyer, now()->subMonths(30), 'inbound/buyer.jpg');
    Order::factory()->create(['customer_id' => $buyer->id]);

    // Old and recent conversations for the same customer: only the old one goes.
    $mixed = Customer::factory()->create();
    $mixedOld = retentionConversation($mixed, now()->subMonths(26), 'inbound/mixed-old.jpg');
    $mixedRecent = retentionConversation($mixed, now()->subDays(10), 'inbound/mixed-new.jpg');

    // A customer that never chatted (e.g. from Shopify) is never touched.
    $untouched = Customer::factory()->create();

    $this->artisan('crm:prune-retention')->assertSuccessful();

    expect(Conversation::find($oldConversation->id))->toBeNull()
        ->and(Message::where('conversation_id', $oldConversation->id)->exists())->toBeFalse()
        ->and(SupportCase::where('conversation_id', $oldConversation->id)->exists())->toBeFalse()
        ->and(BotRun::where('conversation_id', $oldConversation->id)->exists())->toBeFalse()
        ->and(ActivityLog::where('conversation_id', $oldConversation->id)->exists())->toBeFalse()
        ->and(Customer::find($old->id))->toBeNull()
        ->and(Storage::disk('media')->exists('inbound/old.jpg'))->toBeFalse()
        // Recent data stays.
        ->and(Conversation::find($recentConversation->id))->not->toBeNull()
        ->and(Customer::find($recent->id))->not->toBeNull()
        ->and(Storage::disk('media')->exists('inbound/recent.jpg'))->toBeTrue()
        ->and(SupportCase::where('conversation_id', $recentConversation->id)->exists())->toBeTrue()
        // Customer with an order keeps the customer row.
        ->and(Conversation::find($buyerConversation->id))->toBeNull()
        ->and(Customer::find($buyer->id))->not->toBeNull()
        ->and(Order::where('customer_id', $buyer->id)->exists())->toBeTrue()
        // Mixed.
        ->and(Conversation::find($mixedOld->id))->toBeNull()
        ->and(Conversation::find($mixedRecent->id))->not->toBeNull()
        ->and(Customer::find($mixed->id))->not->toBeNull()
        ->and(Storage::disk('media')->exists('inbound/mixed-new.jpg'))->toBeTrue()
        ->and(Customer::find($untouched->id))->not->toBeNull();
});

it('keeps a conversation whose last_message_at is stale but that has a recent message', function () {
    $customer = Customer::factory()->create();
    $conversation = retentionConversation($customer, now()->subMonths(25), 'inbound/a.jpg');
    Message::factory()->create(['conversation_id' => $conversation->id]); // created now
    Conversation::whereKey($conversation->id)->update(['last_message_at' => now()->subMonths(25)]);

    $this->artisan('crm:prune-retention')->assertSuccessful();

    expect(Conversation::find($conversation->id))->not->toBeNull();
});

it('deletes old standalone comments and their customers, keeps recent ones', function () {
    $oldComment = Comment::factory()->create();
    Comment::whereKey($oldComment->id)->update(['created_at' => now()->subMonths(25)]);
    $reply = Comment::factory()->create(['customer_id' => null, 'parent_external_id' => $oldComment->external_id]);
    $recentComment = Comment::factory()->create();

    $this->artisan('crm:prune-retention')->assertSuccessful();

    expect(Comment::find($oldComment->id))->toBeNull()
        ->and(Comment::find($reply->id))->toBeNull()
        ->and(Customer::find($oldComment->customer_id))->toBeNull()
        ->and(Comment::find($recentComment->id))->not->toBeNull()
        ->and(Customer::find($recentComment->customer_id))->not->toBeNull();
});

it('honours the configured window', function () {
    config(['crm.legal.retention_months' => 6]);
    $customer = Customer::factory()->create();
    $conversation = retentionConversation($customer, now()->subMonths(7), 'inbound/b.jpg');

    $this->artisan('crm:prune-retention')->assertSuccessful();

    expect(Conversation::find($conversation->id))->toBeNull();
});

it('only counts on --dry-run', function () {
    $customer = Customer::factory()->create();
    $conversation = retentionConversation($customer, now()->subMonths(25), 'inbound/c.jpg');

    $this->artisan('crm:prune-retention', ['--dry-run' => true])
        ->expectsOutputToContain('conversations=1')
        ->assertSuccessful();

    expect(Conversation::find($conversation->id))->not->toBeNull()
        ->and(Customer::find($customer->id))->not->toBeNull()
        ->and(Storage::disk('media')->exists('inbound/c.jpg'))->toBeTrue();
});

it('processes in chunks', function () {
    $ids = collect(range(1, 5))->map(fn ($i) => retentionConversation(Customer::factory()->create(), now()->subMonths(25), "inbound/chunk{$i}.jpg")->id);

    $this->artisan('crm:prune-retention', ['--chunk' => 2])->assertSuccessful();

    expect(Conversation::whereIn('id', $ids)->count())->toBe(0);
});

it('schedules the retention prune daily in Cairo on one server without overlapping', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'crm:prune-retention'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 4 * * *')
        ->and($event->timezone)->toBe('Africa/Cairo')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});
