<?php

use App\Enums\Platform;
use App\Enums\UserRole;
use App\Inbox\OutboundService;
use App\Models\BotSetting;
use App\Models\Conversation;
use App\Models\LoadTestRun;
use App\Models\Message;
use App\Models\QueueEntry;
use App\Models\User;
use App\Simulator\LoadTest\Jobs\SendLoadTestFollowUp;
use App\Simulator\LoadTest\LoadTest;
use App\Simulator\LoadTest\Scenarios;
use App\Simulator\Simulator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-07 11:00', 'Africa/Cairo'));
    config(['crm.load_test' => true]);
    BotSetting::current()->update(['enabled' => false]);
    Queue::fake([SendLoadTestFollowUp::class]);
    $this->agent = User::factory()->create(['role' => UserRole::Admin]);
    $this->run = LoadTestRun::activeOrStart();
});

function fuChat(string $scenario = 'late_order'): Conversation
{
    $tag = app(LoadTest::class)->tag(LoadTestRun::active(), $scenario, 'منى', 'fu-'.$scenario);

    return app(Simulator::class)->customerMessage(Platform::WhatsApp, 'fu-'.$scenario, 'منى', Scenarios::render(Scenarios::get($scenario)['opener'], $tag['order_number']), loadTest: $tag)
        ->conversation->fresh();
}

/** Runs the queued follow-up jobs (as the worker would after their delay) and forgets them. */
function fuRunPending(): int
{
    $jobs = Queue::pushed(SendLoadTestFollowUp::class);
    foreach ($jobs as $job) {
        app()->call([$job, 'handle']);
    }
    Queue::fake([SendLoadTestFollowUp::class]); // a fresh log for the next round

    return $jobs->count();
}

/** @return list<string> what the customer wrote, oldest first */
function fuCustomerLines(Conversation $c): array
{
    return Message::query()->where('conversation_id', $c->id)->where('direction', 'in')->orderBy('id')->pluck('body')->all();
}

it('queues the next scenario line 60-120 s after an agent reply, through the inbound pipeline', function () {
    $c = fuChat();

    app(OutboundService::class)->sendHuman($c, $this->agent, 'أهلاً يا فندم، ممكن رقم الأوردر؟');

    Queue::assertPushed(SendLoadTestFollowUp::class, function (SendLoadTestFollowUp $job) use ($c) {
        $delay = (int) round(now()->diffInSeconds($job->delay, false));

        return $job->conversationId === $c->id && $job->step === 1 && $delay >= 60 && $delay <= 120;
    });

    fuRunPending();

    $number = $c->meta['load_test']['order_number'];
    expect($number)->not->toBeNull()
        ->and(fuCustomerLines($c))->toBe([Scenarios::render(Scenarios::get('late_order')['opener'], $number), Scenarios::render(Scenarios::followUp('late_order', 1), $number)])
        ->and($c->fresh()->meta['load_test']['step'])->toBe(1)
        ->and($c->fresh()->meta['load_test']['pending'])->toBeNull()
        ->and($this->run->fresh()->followups_sent)->toBe(1)
        ->and(Message::query()->where('conversation_id', $c->id)->where('direction', 'in')->latest('id')->first()->external_id)->toStartWith('sim_msg_');
});

it('never follows up a bot reply', function () {
    $c = fuChat();

    app(OutboundService::class)->sendBot($c, 'أهلاً بيكي في لو فوال');

    Queue::assertNotPushed(SendLoadTestFollowUp::class);
});

it('queues one follow-up for two quick agent replies', function () {
    $c = fuChat();

    app(OutboundService::class)->sendHuman($c, $this->agent, 'أهلاً');
    app(OutboundService::class)->sendHuman($c->fresh(), $this->agent, 'ممكن رقم الأوردر؟');

    Queue::assertPushed(SendLoadTestFollowUp::class, 1);
    expect(fuRunPending())->toBe(1)->and(fuCustomerLines($c))->toHaveCount(2);
});

it('plays at most three follow-ups, the last one the thanks', function () {
    $c = fuChat('size_color');

    foreach (range(1, 5) as $round) {
        app(OutboundService::class)->sendHuman($c->fresh(), $this->agent, "رد رقم {$round}");
        fuRunPending();
    }

    $lines = fuCustomerLines($c);
    expect($lines)->toHaveCount(4)
        ->and(array_slice($lines, 1))->toBe(Scenarios::followUps('size_color'))
        ->and(end($lines))->toBe(Scenarios::get('size_color')['thanks'])
        ->and($c->fresh()->meta['load_test']['step'])->toBe(3);
});

it('sends nothing once the chat is closed', function () {
    $c = fuChat();
    $entry = QueueEntry::factory()->create(['conversation_id' => $c->id, 'customer_id' => $c->customer_id, 'status' => 'active', 'assigned_user_id' => $this->agent->id]);
    $c->forceFill(['queue_entry_id' => $entry->id])->save();

    app(OutboundService::class)->sendHuman($c->fresh(), $this->agent, 'تمام، اتحل');
    $entry->forceFill(['status' => 'closed', 'closed_at' => now(), 'close_reason' => 'inquiry'])->save(); // «خلصت» before the delay ran out
    fuRunPending();

    expect(fuCustomerLines($c))->toHaveCount(1)->and($c->fresh()->meta['load_test']['pending'])->toBeNull();

    // A resolved chat is closed too.
    $c->forceFill(['queue_entry_id' => null, 'status' => 'resolved', 'resolved_at' => now()])->save();
    app(OutboundService::class)->sendHuman($c->fresh(), $this->agent, 'سلام');
    fuRunPending();
    expect(fuCustomerLines($c))->toHaveCount(1);
});

it('sends nothing after the plan is stopped', function () {
    $c = fuChat();

    app(OutboundService::class)->sendHuman($c, $this->agent, 'أهلاً');
    $this->artisan('crm:load-test', ['action' => 'stop'])->assertSuccessful();
    fuRunPending();

    app(OutboundService::class)->sendHuman($c->fresh(), $this->agent, 'معاكي؟');

    Queue::assertNotPushed(SendLoadTestFollowUp::class);
    expect(fuCustomerLines($c))->toHaveCount(1);
});

it('leaves real chats and a closed gate alone', function () {
    $real = Conversation::factory()->create(['last_customer_message_at' => now()]);
    $c = fuChat();

    app(OutboundService::class)->sendHuman($real, $this->agent, 'أهلاً');
    config(['crm.load_test' => false]);
    app(OutboundService::class)->sendHuman($c, $this->agent, 'أهلاً');

    Queue::assertNotPushed(SendLoadTestFollowUp::class);
});
