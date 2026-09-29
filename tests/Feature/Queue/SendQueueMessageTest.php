<?php

use App\Inbox\OutboundService;
use App\Models\Message;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\User;
use App\Queue\Jobs\SendQueueMessage;
use App\Queue\QueueScripts;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true]);
});

/** Runs the job against a recording OutboundService; returns the texts it would have sent. */
function sendQueueJob(QueueEntry $e, string $key, array $vars = []): array
{
    $sent = [];
    $outbound = Mockery::mock(OutboundService::class);
    $outbound->shouldReceive('sendBot')->andReturnUsing(function ($c, string $text) use (&$sent) {
        $sent[] = $text;

        return new Message;
    });

    (new SendQueueMessage($e->id, $key, $vars))->handle($outbound, app(QueueScripts::class));

    return $sent;
}

function staleEntry(array $attrs = []): QueueEntry
{
    $e = QueueEntry::factory()->create($attrs + ['status' => 'waiting', 'priority' => 'live']);
    $e->conversation->update(['queue_entry_id' => $e->id]);

    return $e->fresh();
}

it('sends a lounge message while she waits, and drops it once she was called', function (string $key, array $vars) {
    $e = staleEntry();
    expect(sendQueueJob($e, $key, $vars))->toHaveCount(1);

    $e->forceFill(['status' => 'called', 'assigned_user_id' => User::factory()->create()->id])->save();
    expect(sendQueueJob($e, $key, $vars))->toBe([]);

    $e->forceFill(['status' => 'active', 'delivered_at' => now()])->save();
    expect(sendQueueJob($e, $key, $vars))->toBe([]);
})->with([
    'position update' => ['queue_position_update', ['ticket' => 4, 'ahead' => 'قدامك عميلتين', 'eta_sentence' => '']],
    'countdown 5' => ['queue_left_5', []],
    'countdown 3' => ['queue_left_3', []],
    'countdown 1' => ['queue_left_1', []],
    'lounge apology' => ['queue_apology', []],
]);

it('sends the moderator-delay apology only while her window still waits for the reply', function () {
    $u = User::factory()->create();
    $e = staleEntry(['status' => 'active', 'assigned_user_id' => $u->id, 'delivered_at' => now()->subMinutes(4),
        'awaiting_reply_since' => now()->subMinutes(3), 'apology_sent_at' => now()]);

    expect(sendQueueJob($e, 'queue_agent_delay_apology', ['agent' => $u->name]))->toHaveCount(1);

    // The moderator answered before the job ran: the clock and the apology mark are cleared.
    $e->forceFill(['awaiting_reply_since' => null, 'apology_sent_at' => null])->save();
    expect(sendQueueJob($e, 'queue_agent_delay_apology', ['agent' => $u->name]))->toBe([]);

    // She wrote again meanwhile: a new waiting period with no apology of its own yet.
    $e->forceFill(['awaiting_reply_since' => now()])->save();
    expect(sendQueueJob($e, 'queue_agent_delay_apology', ['agent' => $u->name]))->toBe([]);

    // The window closed (handed off, closed by hand).
    $e->forceFill(['status' => 'closed', 'closed_at' => now(), 'awaiting_reply_since' => now()->subMinutes(3), 'apology_sent_at' => now()])->save();
    expect(sendQueueJob($e, 'queue_agent_delay_apology', ['agent' => $u->name]))->toBe([]);
});

it('keeps every other script as before, whatever the entry status', function () {
    $e = staleEntry(['status' => 'active', 'assigned_user_id' => User::factory()->create()->id, 'delivered_at' => now()]);
    expect(sendQueueJob($e, 'queue_called', ['name' => 'منى']))->toHaveCount(1);

    $e->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
    expect(sendQueueJob($e, 'queue_case_opened', ['case_id' => 12]))->toHaveCount(1)
        ->and(sendQueueJob($e, 'queue_auto_closed'))->toHaveCount(1);
});
