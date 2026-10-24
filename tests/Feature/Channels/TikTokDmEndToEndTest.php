<?php

// TikTok direct messages (x6, 2026-10-24), end to end: a signed webhook → the inbox → the bot answers through
// the (fake) Business Messaging API → a moderator replies → TikTok's own cap is honoured.

use App\Channels\Adapters\TikTok\FakeTikTokApi;
use App\Channels\Adapters\TikTok\TikTokMessagingApi;
use App\Enums\MessageStatus;
use App\Enums\Platform;
use App\Enums\UserRole;
use App\Inbox\OutboundService;
use App\Models\BotRule;
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\CustomerIdentity;
use App\Models\Message;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

const TT_E2E_SECRET = 'e2e-secret';

beforeEach(function () {
    FakeTikTokApi::reset();
    app()->instance(TikTokMessagingApi::class, new FakeTikTokApi);
    config([
        'crm.drivers.channels' => 'live',
        'crm.drivers.ai' => 'fake',
        'services.tiktok.app_id' => '7591714577012752400',
        'services.tiktok.app_secret' => TT_E2E_SECRET,
    ]);
    $this->account = ChannelAccount::factory()->create([
        'platform' => Platform::TikTok, 'driver' => 'live', 'external_id' => 'open-id-levoile', 'name' => 'Le Voile',
        'credentials' => ['access_token' => 'tt-access', 'refresh_token' => 'tt-refresh', 'access_token_expires_at' => now()->addDay()->toIso8601String()],
        'profile' => ['kind' => 'messaging'],
    ]);
    BotSetting::current()->update(['enabled' => true, 'ai_enabled' => false, 'working_hours' => null, 'max_bot_turns' => 5]);
    BotRule::factory()->create(['name' => 'السعر', 'keywords' => ['بكام'], 'private_reply' => 'الأسعار في الكتالوج', 'scope' => 'both', 'platforms' => [], 'action' => 'reply', 'is_active' => true]);
});

function ttE2ePost(array $content, string $messageId): void
{
    $payload = ['client_key' => '7591714577012752400', 'event' => 'im_receive_msg', 'create_time' => time(), 'user_openid' => 'open-id-levoile', 'content' => json_encode(array_merge([
        'from' => 'mona.adel', 'to' => 'levoilestores', 'unique_identifier' => 'uid-mona',
        'from_user' => ['id' => 'uid-mona', 'role' => 'personal_account'], 'to_user' => ['id' => 'open-id-levoile', 'role' => 'business_account'],
        'conversation_id' => 'conv-abc', 'message_id' => $messageId, 'timestamp' => (int) (microtime(true) * 1000), 'type' => 'text',
        'message_tag' => ['source' => 'APP'],
    ], $content), JSON_UNESCAPED_UNICODE)];
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $t = time();

    test()->call('POST', '/webhooks/tiktok', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_TIKTOK_SIGNATURE' => 't='.$t.',s='.hash_hmac('sha256', $t.'.'.$body, TT_E2E_SECRET)], $body)->assertOk();
}

it('answers a TikTok DM with the bot through the messaging API, then lets a moderator with the TikTok platform reply', function () {
    ttE2ePost(['text' => ['body' => 'بكام الفستان؟']], 'msg-1');

    $identity = CustomerIdentity::where('platform', Platform::TikTok)->where('external_id', 'uid-mona')->sole();
    $conversation = Conversation::where('customer_id', $identity->customer_id)->sole();
    $botReply = $conversation->messages()->where('sender_type', 'bot')->sole();

    expect($botReply->body)->toBe('الأسعار في الكتالوج')
        ->and($botReply->status)->toBe(MessageStatus::Sent)
        ->and($botReply->external_id)->toStartWith('tt_fake_');

    $texts = collect(FakeTikTokApi::sent())->where('message_type', 'TEXT')->values();
    expect($texts)->toHaveCount(1)
        ->and($texts[0]['recipient'])->toBe('conv-abc')
        ->and($texts[0]['text']['body'])->toBe('الأسعار في الكتالوج');

    // A moderator who may work TikTok replies; one without the platform is refused.
    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $mod->userPlatforms()->create(['platform' => Platform::TikTok]);
    $other = User::factory()->create(['role' => UserRole::Moderator]);
    $other->userPlatforms()->create(['platform' => Platform::WhatsApp]);

    $reply = app(OutboundService::class)->sendHuman($conversation->fresh(), $mod, 'اتفضلي الكتالوج');
    expect($reply->fresh()->status)->toBe(MessageStatus::Sent)
        ->and(collect(FakeTikTokApi::sent())->where('message_type', 'TEXT')->last()['text']['body'])->toBe('اتفضلي الكتالوج');

    expect(fn () => app(OutboundService::class)->sendHuman($conversation->fresh(), $other, 'x'))->toThrow(AuthorizationException::class);

    // The same message id delivered again changes nothing.
    ttE2ePost(['text' => ['body' => 'بكام الفستان؟']], 'msg-1');
    expect(Message::where('direction', 'in')->count())->toBe(1);
});

it('offers bot buttons on TikTok as a Q&A card, or as one numbered text beyond three, and the typed number comes back as an ordinary message', function () {
    ttE2ePost(['text' => ['body' => 'أهلاً']], 'msg-hello');
    $conversation = Conversation::where('platform', Platform::TikTok)->sole();
    FakeTikTokApi::reset();

    app(OutboundService::class)->sendBot($conversation, 'تحبي نساعدك في إيه؟', buttons: [['title' => 'المقاسات', 'payload' => 'flow:sizes'], ['title' => 'الشحن', 'payload' => 'flow:shipping']]);
    $sent = FakeTikTokApi::sent();
    expect($sent)->toHaveCount(2)->and($sent[1]['template']['type'])->toBe('QA_BUTTON_CARD')->and($sent[1]['template']['buttons'][0]['id'])->toBe('flow:sizes');

    FakeTikTokApi::reset();
    $menu = array_map(fn ($i) => ['title' => "اختيار {$i}", 'payload' => "p{$i}"], range(1, 5));
    app(OutboundService::class)->sendBot($conversation->fresh(), 'القائمة', buttons: $menu);
    $sent = FakeTikTokApi::sent();
    expect($sent)->toHaveCount(1)->and($sent[0]['text']['body'])->toStartWith('القائمة

1- اختيار 1
2- اختيار 2');
});
