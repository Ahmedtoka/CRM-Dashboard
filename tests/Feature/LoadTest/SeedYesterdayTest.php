<?php

use App\Enums\Handler;
use App\Enums\Platform;
use App\Models\BotRun;
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\LoadTestRun;
use App\Models\Message;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\Shift;
use App\Queue\QueueRouter;
use App\Simulator\LoadTest\BacklogRouter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-07 09:00', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true]);
    BotSetting::current()->update(['enabled' => true, 'working_hours' => null]);
});

it('refuses every action unless CRM_LOAD_TEST is on', function (string $action) {
    config(['crm.load_test' => false]);

    $this->artisan('crm:load-test', ['action' => $action])
        ->expectsOutputToContain('CRM_LOAD_TEST')
        ->assertFailed();

    expect(Conversation::count())->toBe(0)->and(LoadTestRun::count())->toBe(0)->and(ChannelAccount::count())->toBe(0);
})->with(['seed-yesterday', 'start', 'status', 'stop']);

it('keeps the scheduler tick a silent no-op while the gate is off', function () {
    config(['crm.load_test' => false]);

    $this->artisan('crm:load-test', ['action' => 'tick'])->assertSuccessful();

    expect(LoadTestRun::count())->toBe(0);
});

it('rejects an unknown action', function () {
    config(['crm.load_test' => true]);

    $this->artisan('crm:load-test', ['action' => 'explode'])->assertFailed();
});

it('seeds N chats from yesterday evening, handed over and waiting in the queue, on the test channels only', function () {
    config(['crm.load_test' => true, 'crm.drivers.channels' => 'live']);
    Http::fake();
    $real = ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'REAL-fb', 'driver' => 'live']);

    $this->artisan('crm:load-test', ['action' => 'seed-yesterday', '--count' => 6])->assertSuccessful();

    $chats = Conversation::query()->with(['channelAccount', 'queueEntry'])->get();
    $yesterday = CarbonImmutable::parse('2026-10-06 18:00', 'Africa/Cairo');

    expect($chats)->toHaveCount(6)
        ->and($chats->every(fn (Conversation $c) => $c->channelAccount->is_load_test))->toBeTrue()
        ->and(Conversation::where('channel_account_id', $real->id)->exists())->toBeFalse()
        ->and(LoadTestRun::active()->seeded)->toBe(6)
        ->and(now()->equalTo(Carbon::parse('2026-10-07 09:00', 'Africa/Cairo')))->toBeTrue(); // the clock is given back

    foreach ($chats as $c) {
        $entry = QueueEntry::query()->where('conversation_id', $c->id)->sole();
        $opener = Message::query()->where('conversation_id', $c->id)->where('direction', 'in')->sole();

        expect($c->isLoadTest())->toBeTrue()
            ->and($c->meta['load_test']['step'])->toBe(0)
            ->and($c->handler)->toBe(Handler::Human)
            ->and($c->needs_human)->toBeTrue()
            ->and($entry->status)->toBe('waiting')
            ->and($entry->priority)->toBe('overnight') // nobody was on shift last night
            ->and($entry->business_date->toDateString())->toBe('2026-10-06')
            ->and($opener->created_at->betweenIncluded($yesterday, $yesterday->setTime(23, 59)))->toBeTrue()
            ->and($c->created_at->toDateString())->toBe($opener->created_at->toDateString())
            ->and($c->last_customer_message_at->equalTo($opener->created_at))->toBeTrue()
            ->and($entry->enqueued_at->greaterThanOrEqualTo($opener->created_at))->toBeTrue()
            ->and($entry->enqueued_at->lessThan(now()))->toBeTrue()
            // The bot never answered a backlog chat: it is the agents' work this morning.
            ->and(BotRun::query()->where('conversation_id', $c->id)->exists())->toBeFalse();
    }

    Http::assertNothingSent();
});

it('refuses to seed while a shift is open', function () {
    config(['crm.load_test' => true]);
    Shift::factory()->create(['status' => 'open']);

    $this->artisan('crm:load-test', ['action' => 'seed-yesterday', '--count' => 2])
        ->expectsOutputToContain('a shift is open')
        ->assertFailed();

    expect(Conversation::count())->toBe(0);
});

it('never runs the queue router under the fake clock, and gives it back afterwards', function () {
    config(['crm.load_test' => true]);
    $this->mock(QueueRouter::class, fn ($m) => $m->shouldReceive('run')->never()->shouldReceive('runAfterCommit')->never());

    $this->artisan('crm:load-test', ['action' => 'seed-yesterday', '--count' => 3])
        ->expectsOutputToContain('left out of every report')
        ->assertSuccessful();

    expect(app(QueueRouter::class))->not->toBeInstanceOf(BacklogRouter::class)
        ->and(QueueEntry::query()->where('status', 'waiting')->count())->toBe(3);
});
