<?php

use App\Channels\Adapters\FakeChannelAdapter;
use App\Comments\CommentActions;
use App\Enums\MessageStatus;
use App\Enums\Platform;
use App\Enums\UserRole;
use App\Inbox\OutboundService;
use App\Models\BotRule;
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\Shift;
use App\Models\ShiftMember;
use App\Models\User;
use App\Queue\RatingService;
use App\Queue\WindowLifecycle;
use App\Simulator\LoadTest\LoadTestChannels;
use App\Simulator\Simulator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/*
 * Production load test (2026-10-07): on a server where the live driver serves the real page, account
 * and number, nothing sent in a test-channel conversation may reach Meta. Every send must be recorded
 * by the fake adapter against the load-test account, and no HTTP request may leave at all.
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-07 12:00', 'Africa/Cairo'));
    config(['crm.drivers.channels' => 'live']);
    Http::fake();
    FakeChannelAdapter::reset();

    // The real channels, live and connected, with the lower ids.
    foreach ([Platform::Facebook, Platform::Instagram, Platform::WhatsApp] as $p) {
        ChannelAccount::factory()->create([
            'platform' => $p, 'external_id' => 'REAL-'.$p->value, 'driver' => 'live', 'status' => 'connected',
            'credentials' => ['page_access_token' => 'real', 'access_token' => 'real', 'phone_number_id' => '1'],
        ]);
    }
    BotSetting::current()->update(['enabled' => false, 'working_hours' => null]);
});

afterEach(function () {
    Http::assertNothingSent();
    $test = ChannelAccount::query()->where('is_load_test', true)->pluck('id')->all();

    foreach (FakeChannelAdapter::sent() as $send) {
        expect($send['account_id'] ?? null)->toBeIn($test);
    }
});

function osConversation(Platform $p = Platform::Facebook): Conversation
{
    return app(Simulator::class)->customerMessage($p, 'os-'.$p->value, 'منى', 'السلام عليكم')->conversation->fresh();
}

/** @return list<string> what the fake adapter "sent" with this method */
function osSent(string $method): array
{
    return collect(FakeChannelAdapter::sent())->where('method', $method)->where('result', 'ok')->pluck('text')->filter()->values()->all();
}

it('keeps an agent reply on every test channel off Meta', function (Platform $p) {
    $c = osConversation($p);
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $m = app(OutboundService::class)->sendHuman($c, $admin, 'أهلاً يا فندم');

    expect($m->fresh()->status)->toBe(MessageStatus::Sent)
        ->and($m->fresh()->external_id)->toStartWith('fake_')
        ->and(osSent('sendText'))->toContain('أهلاً يا فندم')
        ->and($c->channelAccount->is_load_test)->toBeTrue();
})->with([Platform::Facebook, Platform::Instagram, Platform::WhatsApp]);

it('keeps a bot reply off Meta', function () {
    BotSetting::current()->update(['enabled' => true, 'ai_enabled' => false]);
    BotRule::factory()->create(['name' => 'السعر', 'keywords' => ['بكام'], 'private_reply' => 'الأسعار في الكتالوج', 'scope' => 'both', 'platforms' => [], 'action' => 'reply', 'is_active' => true]);

    $c = app(Simulator::class)->customerMessage(Platform::Facebook, 'os-bot', 'هبة', 'بكام الفستان؟')->conversation;

    $bot = Message::query()->where('conversation_id', $c->id)->where('sender_type', 'bot')->sole();
    expect($bot->status)->toBe(MessageStatus::Sent)->and(osSent('sendText'))->toContain('الأسعار في الكتالوج');
});

it('keeps a carousel off Meta', function () {
    $c = osConversation();
    $cards = ['type' => 'generic', 'cards' => [['title' => 'فستان', 'subtitle' => '1250', 'image_url' => 'https://example.com/a.jpg', 'url' => 'https://example.com/p', 'buttons' => []]]];

    $m = app(OutboundService::class)->sendBot($c, 'فستان 1250', cards: $cards);

    $send = collect(FakeChannelAdapter::sent())->firstWhere('method', 'sendText');
    expect($m->fresh()->status)->toBe(MessageStatus::Sent)
        ->and($send['options']['cards']['type'] ?? null)->toBe('generic');
});

it('keeps the closing message and the rating question off Meta', function () {
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true]);
    $c = osConversation();
    $u = User::factory()->create(['name' => 'منى علي', 'last_seen_at' => now()]);
    $u->userPlatforms()->create(['platform' => 'facebook']);
    $shift = Shift::factory()->create();
    $member = ShiftMember::factory()->for($shift)->create(['user_id' => $u->id, 'status' => 'busy']);
    $e = QueueEntry::factory()->create([
        'conversation_id' => $c->id, 'customer_id' => $c->customer_id, 'shift_id' => $shift->id, 'shift_member_id' => $member->id,
        'assigned_user_id' => $u->id, 'status' => 'active', 'window_no' => 1, 'delivered_at' => now(),
        'enqueued_at' => now()->subMinutes(2), 'last_customer_message_at' => now(),
    ]);
    $c->forceFill(['assignee_id' => $u->id, 'queue_entry_id' => $e->id, 'handler' => 'human', 'needs_human' => true])->save();

    app(WindowLifecycle::class)->close($e, 'inquiry', $u);
    Carbon::setTestNow(now()->addSeconds(60));
    app(RatingService::class)->request($e->fresh());

    $bot = Message::query()->where('conversation_id', $c->id)->where('sender_type', 'bot')->orderBy('id')->get();
    expect($bot)->not->toBeEmpty()
        ->and($bot->every(fn (Message $m) => $m->status === MessageStatus::Sent))->toBeTrue()
        ->and($bot->whereNotNull('buttons'))->toHaveCount(1) // the rating question was asked
        ->and(count(osSent('sendText')))->toBe($bot->count());
});

it('keeps a comment reply and hide on a test channel off Meta', function () {
    $comment = app(Simulator::class)->comment(Platform::Facebook, 'post-1', 'os-cm', 'سلمى', 'بكام؟');
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    app(CommentActions::class)->reply($comment, 'ردينا عليكي في الخاص', $admin);
    app(CommentActions::class)->hide($comment->fresh(), $admin);

    expect(collect(FakeChannelAdapter::sent())->pluck('method')->all())->toContain('replyToComment', 'hideComment')
        ->and($comment->post->channelAccount->is_load_test)->toBeTrue();
});

it('never asks the Graph API about an ad of a test conversation', function () {
    app(Simulator::class)->customerMessage(Platform::Facebook, 'os-ad', 'نور', 'عايزة الفستان اللي في الإعلان', extra: [
        'referral' => ['source' => 'ADS', 'type' => 'OPEN_THREAD', 'ad_id' => '120200000001', 'ads_context_data' => ['ad_title' => 'فستان سواريه']],
    ]);

    $c = Conversation::query()->where('channel_account_id', LoadTestChannels::account(Platform::Facebook)->id)->sole();
    expect($c->ad_id)->toBe('120200000001')->and($c->ad_title)->toBe('فستان سواريه');
});
