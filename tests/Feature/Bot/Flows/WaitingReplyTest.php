<?php

use App\Bot\Flows\WaitingReply;
use App\Enums\Handler;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Models\BotKnowledgeEntry;
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake();
    config(['crm.drivers.ai' => 'fake']);
    $this->account = ChannelAccount::factory()->create(['platform' => Platform::Facebook]);
});

function waitingConversation(array $attributes = []): Conversation
{
    return Conversation::factory()->for(test()->account, 'channelAccount')->create(array_merge([
        'platform' => Platform::Facebook,
        'handler' => Handler::Human,
        'needs_human' => true,
        'handover_at' => now()->subMinutes(5),
        'last_customer_message_at' => now(),
    ], $attributes));
}

function lastBotBody(Conversation $c): ?string
{
    return Message::where('conversation_id', $c->id)->where('sender_type', SenderType::Bot->value)->latest('id')->value('body');
}

it('reassures her once while she waits for a person', function () {
    $c = waitingConversation();

    expect(app(WaitingReply::class)->maybeSend($c))->toBeTrue()
        ->and(lastBotBody($c))->toContain('وصلت للفريق');
});

it('does not repeat itself inside the cooldown, and speaks again after it', function () {
    config(['crm.bot.waiting_ack_minutes' => 15]);
    $c = waitingConversation();
    $now = CarbonImmutable::now();

    expect(app(WaitingReply::class)->maybeSend($c, $now))->toBeTrue()
        ->and(app(WaitingReply::class)->maybeSend($c->fresh(), $now->addMinutes(5)))->toBeFalse()
        ->and(app(WaitingReply::class)->maybeSend($c->fresh(), $now->addMinutes(16)))->toBeTrue();
});

it('stays quiet once an agent has answered', function () {
    $c = waitingConversation();
    Message::factory()->create([
        'conversation_id' => $c->id,
        'sender_type' => SenderType::User->value,
        'user_id' => User::factory()->create()->id,
        'direction' => 'out',
        'created_at' => now()->subMinute(),
    ]);

    expect(app(WaitingReply::class)->maybeSend($c))->toBeFalse();
});

it('stays quiet when the conversation is not waiting for anyone', function () {
    $c = waitingConversation(['needs_human' => false, 'handover_at' => null]);

    expect(app(WaitingReply::class)->maybeSend($c))->toBeFalse();
});

it('names the next opening when the team is closed', function () {
    BotSetting::current()->update([
        'working_hours' => ['days' => [0, 1, 2, 3, 4, 5, 6], 'from' => '10:00', 'to' => '22:00'],
    ]);
    $c = waitingConversation();

    // 03:00 Cairo: closed, so the reassurance points at the opening time instead of "soon".
    app(WaitingReply::class)->maybeSend($c, CarbonImmutable::parse('2026-09-21 00:00', 'UTC'));

    expect(lastBotBody($c))->toContain('أول ما نفتح');
});

it('seeds the three waiting scripts once, and leaves an edited one alone', function () {
    $keys = ['script.waiting_ack_in_hours', 'script.waiting_ack_after_hours', 'script.waiting_ack_no_hours'];
    BotKnowledgeEntry::where('key', $keys[0])->update(['body' => 'نص حضرتك']);

    Artisan::call('migrate', ['--force' => true]);

    expect(BotKnowledgeEntry::whereIn('key', $keys)->count())->toBe(3)
        ->and(BotKnowledgeEntry::where('key', $keys[0])->value('body'))->toBe('نص حضرتك');
});
