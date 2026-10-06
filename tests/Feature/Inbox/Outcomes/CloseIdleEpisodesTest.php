<?php

use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Models\Conversation;
use App\Models\ConversationOutcome;
use App\Models\Message;
use App\Models\QueueEntry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-10-10 12:00', 'Africa/Cairo')));

function s3IdleChat(int $hoursAgo, array $attrs = []): Conversation
{
    $at = now()->subHours($hoursAgo);
    $c = Conversation::factory()->create($attrs + ['last_message_at' => $at, 'last_customer_message_at' => $at]);
    Message::factory()->for($c)->create(['direction' => MessageDirection::In, 'sender_type' => SenderType::Customer, 'body' => 'بكام ده؟', 'created_at' => $at]);
    Message::factory()->for($c)->create(['sender_type' => SenderType::Bot, 'created_at' => $at]);

    return $c;
}

it('ends bot chats idle for a day and leaves fresh, test and queued ones alone', function () {
    $idle = s3IdleChat(30);
    $fresh = s3IdleChat(3);
    $test = s3IdleChat(30, ['is_test' => true]);
    $queued = s3IdleChat(30);
    $e = QueueEntry::factory()->create(['conversation_id' => $queued->id, 'status' => 'waiting']);
    $queued->forceFill(['queue_entry_id' => $e->id])->save();

    $this->artisan('outcomes:close-idle')->expectsOutputToContain('Ended 1 idle episodes.')->assertSuccessful();

    expect(ConversationOutcome::pluck('conversation_id')->all())->toBe([$idle->id])
        ->and(ConversationOutcome::sole()->outcome)->toBe('no_answer')->and(ConversationOutcome::sole()->ended_by)->toBe('idle');
});

it('does not end the same episode twice', function () {
    s3IdleChat(30);
    $this->artisan('outcomes:close-idle')->assertSuccessful();
    $this->artisan('outcomes:close-idle')->expectsOutputToContain('Ended 0 idle episodes.')->assertSuccessful();
    expect(ConversationOutcome::count())->toBe(1);
});

it('is scheduled hourly', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'outcomes:close-idle'));
    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('0 * * * *');
});
