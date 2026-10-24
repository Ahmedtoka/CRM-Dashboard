<?php

// TikTok direct messages (x6, 2026-10-24): what the adapter sends through the Business Messaging API, recorded
// by FakeTikTokApi. TikTok addresses a conversation_id (never a user) and cannot mix text with an image.

use App\Channels\Adapters\TikTok\FakeTikTokApi;
use App\Channels\Adapters\TikTok\TikTokApiException;
use App\Channels\Adapters\TikTok\TikTokMessagingApi;
use App\Channels\Adapters\TikTokAdapter;
use App\Channels\Cards\OutboundCards;
use App\Enums\AttachmentStatus;
use App\Enums\AttachmentType;
use App\Enums\Platform;
use App\Models\ChannelAccount;
use App\Models\CustomerIdentity;
use App\Models\MessageAttachment;
use App\Models\TikTokConversation;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    FakeTikTokApi::reset();
    app()->instance(TikTokMessagingApi::class, new FakeTikTokApi);
    config(['services.tiktok.menu_style' => 'card', 'services.tiktok.menu_prompt' => 'اختاري من هنا']);
    $this->account = ChannelAccount::factory()->create([
        'platform' => Platform::TikTok, 'driver' => 'live', 'external_id' => 'open-id-levoile',
        'credentials' => ['access_token' => 'tt-access', 'refresh_token' => 'tt-refresh'], 'profile' => ['kind' => 'messaging'],
    ]);
    $this->to = CustomerIdentity::factory()->create(['platform' => Platform::TikTok, 'external_id' => 'uid-mona']);
    TikTokConversation::remember('open-id-levoile', 'uid-mona', 'conv-abc', 'mona.adel', now());
    $this->adapter = app(TikTokAdapter::class);
});

it('sends a plain text to the conversation id, cut to 6000 characters', function () {
    $result = $this->adapter->sendText($this->account, $this->to, str_repeat('ا', 6500));

    expect($result->success)->toBeTrue()->and($result->externalId)->toStartWith('tt_fake_');
    $sent = FakeTikTokApi::sent();
    expect($sent)->toHaveCount(1)
        ->and($sent[0]['recipient_type'])->toBe('CONVERSATION')
        ->and($sent[0]['recipient'])->toBe('conv-abc')
        ->and($sent[0]['message_type'])->toBe('TEXT')
        ->and(mb_strlen($sent[0]['text']['body']))->toBe(6000);
});

it('refuses to write first: no conversation id means no send, nothing reaches TikTok', function () {
    $stranger = CustomerIdentity::factory()->create(['platform' => Platform::TikTok, 'external_id' => 'uid-new']);

    $result = $this->adapter->sendText($this->account, $stranger, 'أهلاً');

    expect($result->success)->toBeFalse()->and($result->error)->toBe(TikTokAdapter::NO_CONVERSATION)->and(FakeTikTokApi::calls())->toBe([]);
});

it('sends up to three short bot buttons as a Q&A button card after the text, carrying our payloads as button ids', function () {
    $result = $this->adapter->sendText($this->account, $this->to, 'تحبي نساعدك في إيه؟', ['quick_replies' => [
        ['title' => 'المقاسات', 'payload' => 'flow:sizes'],
        ['title' => 'الشحن', 'payload' => 'flow:shipping'],
    ]]);

    expect($result->success)->toBeTrue()->and($result->externalId)->toStartWith('tt_fake_');
    $sent = FakeTikTokApi::sent();
    expect($sent)->toHaveCount(2)
        ->and($sent[0]['text']['body'])->toBe('تحبي نساعدك في إيه؟')
        ->and($sent[1]['message_type'])->toBe('TEMPLATE')
        ->and($sent[1]['template']['type'])->toBe('QA_BUTTON_CARD')
        ->and($sent[1]['template']['title'])->toBe('اختاري من هنا')
        ->and($sent[1]['template']['buttons'])->toBe([
            ['type' => 'REPLY', 'title' => 'المقاسات', 'id' => 'flow:sizes'],
            ['type' => 'REPLY', 'title' => 'الشحن', 'id' => 'flow:shipping'],
        ]);
});

it('falls back to a numbered list in one message for more than three buttons, a long title, or the text menu style', function () {
    $seven = array_map(fn ($i) => ['title' => "اختيار {$i}", 'payload' => "p{$i}"], range(1, 7));
    $this->adapter->sendText($this->account, $this->to, 'اختاري', ['quick_replies' => $seven]);
    $sent = FakeTikTokApi::sent();
    expect($sent)->toHaveCount(1)->and($sent[0]['text']['body'])->toBe("اختاري\n\n1- اختيار 1\n2- اختيار 2\n3- اختيار 3\n4- اختيار 4\n5- اختيار 5\n6- اختيار 6\n7- اختيار 7");

    FakeTikTokApi::reset();
    $this->adapter->sendText($this->account, $this->to, 'اختاري', ['quick_replies' => [['title' => 'عنوان طويل جداً أكتر من عشرين حرف بكتير', 'payload' => 'p1']]]);
    expect(FakeTikTokApi::sent()[0]['text']['body'])->toContain('1- عنوان طويل');

    FakeTikTokApi::reset();
    config(['services.tiktok.menu_style' => 'text']);
    $this->adapter->sendText($this->account, $this->to, 'اختاري', ['quick_replies' => [['title' => 'المقاسات', 'payload' => 'flow:sizes']]]);
    expect(FakeTikTokApi::sent())->toHaveCount(1)->and(FakeTikTokApi::sent()[0]['text']['body'])->toBe("اختاري\n\n1- المقاسات");
});

it('sends the numbered options as text when TikTok refuses the card, and still reports the text as sent', function () {
    $this->adapter->sendText($this->account, $this->to, 'x', []); // warm: nothing
    FakeTikTokApi::reset();

    // The first call (the text) succeeds; the second (the card) fails with a parameter error.
    $api = new class extends FakeTikTokApi
    {
        public int $n = 0;

        public function send(ChannelAccount $account, array $body): array
        {
            if (++$this->n === 2) {
                throw new TikTokApiException(40002, 'template not supported');
            }

            return parent::send($account, $body);
        }
    };
    app()->instance(TikTokMessagingApi::class, $api);
    $adapter = app(TikTokAdapter::class);

    $result = $adapter->sendText($this->account, $this->to, 'تحبي إيه؟', ['quick_replies' => [['title' => 'المقاسات', 'payload' => 'flow:sizes'], ['title' => 'الشحن', 'payload' => 'flow:shipping']]]);

    expect($result->success)->toBeTrue();
    $sent = FakeTikTokApi::sent();
    expect($sent)->toHaveCount(2)->and($sent[1]['message_type'])->toBe('TEXT')->and($sent[1]['text']['body'])->toBe("1- المقاسات\n2- الشحن");
});

it('sends rich cards as text with their links (no carousel, no pictures: each would cost a capped message)', function () {
    $cards = OutboundCards::generic([
        ['title' => 'فستان ستان', 'subtitle' => '1250 ج', 'image_url' => 'https://cdn.example/1.jpg', 'url' => 'https://levoilestores.com/p/1', 'text' => "فستان ستان – 1250 ج\nhttps://levoilestores.com/p/1"],
        ['title' => 'فستان شيفون', 'subtitle' => '990 ج', 'image_url' => 'https://cdn.example/2.jpg', 'buttons' => [OutboundCards::webUrl('اشتري', 'https://levoilestores.com/p/2')]],
    ]);

    $this->adapter->sendText($this->account, $this->to, 'الموديلات دي ممكن تعجبك', ['cards' => $cards]);

    $sent = FakeTikTokApi::sent();
    expect($sent)->toHaveCount(1)->and($sent[0]['message_type'])->toBe('TEXT')
        ->and($sent[0]['text']['body'])->toBe("الموديلات دي ممكن تعجبك\n\nفستان ستان – 1250 ج\nhttps://levoilestores.com/p/1\n\nفستان شيفون\n990 ج\nhttps://levoilestores.com/p/2");
    expect(FakeTikTokApi::calls('uploadImage'))->toBe([]);

    FakeTikTokApi::reset();
    $this->adapter->sendText($this->account, $this->to, 'تسوقي من الموقع', ['cards' => OutboundCards::button([OutboundCards::webUrl('الموقع', 'https://levoilestores.com')])]);
    expect(FakeTikTokApi::sent()[0]['text']['body'])->toBe("تسوقي من الموقع\n\nالموقع:\nhttps://levoilestores.com");
});

describe('attachments', function () {
    beforeEach(function () {
        Storage::fake('media');
        config(['crm.media.disk' => 'media']);
        Storage::disk('media')->put('out/chart.png', 'PNG-BYTES');
    });

    function ttAttachment(array $overrides = []): MessageAttachment
    {
        return MessageAttachment::query()->create(array_merge([
            'type' => AttachmentType::Image, 'disk' => 'media', 'path' => 'out/chart.png', 'mime' => 'image/png', 'size_bytes' => 9,
            'original_name' => 'chart.png', 'status' => AttachmentStatus::Stored,
        ], $overrides));
    }

    it('uploads a JPG/PNG, sends it by media_id, and sends the caption as its own text', function () {
        $result = $this->adapter->sendAttachment($this->account, $this->to, ttAttachment(), 'جدول المقاسات');

        expect($result->success)->toBeTrue();
        $upload = FakeTikTokApi::calls('uploadImage')[0];
        expect($upload['filename'])->toBe('chart.png');
        $sent = FakeTikTokApi::sent();
        expect($sent)->toHaveCount(2)
            ->and($sent[0]['message_type'])->toBe('IMAGE')->and($sent[0]['image']['media_id'])->toStartWith('media_fake_')
            ->and($sent[1]['message_type'])->toBe('TEXT')->and($sent[1]['text']['body'])->toBe('جدول المقاسات');
    });

    it('refuses anything but a JPG/PNG up to 3 MB with a clear reason, calling nothing', function () {
        foreach ([
            ['type' => AttachmentType::Video, 'mime' => 'video/mp4'],
            ['type' => AttachmentType::Audio, 'mime' => 'audio/ogg'],
            ['type' => AttachmentType::File, 'mime' => 'application/pdf'],
            ['type' => AttachmentType::Image, 'mime' => 'image/webp'],
            ['type' => AttachmentType::Image, 'mime' => 'image/png', 'size_bytes' => 4 * 1024 * 1024],
        ] as $case) {
            $result = $this->adapter->sendAttachment($this->account, $this->to, ttAttachment($case));
            expect($result->success)->toBeFalse()->and($result->error)->toBe(TikTokAdapter::IMAGE_ONLY);
        }

        expect(FakeTikTokApi::calls())->toBe([]);
    });
});

describe('failures', function () {
    it('maps an invalid token to an auth error (the channel alert), a throttle to a retry, and the DM rules to a plain failure', function () {
        FakeTikTokApi::failNext(TikTokApiException::INVALID_TOKEN, 'Invalid or incorrect access token.');
        $auth = $this->adapter->sendText($this->account, $this->to, 'x');
        expect($auth->success)->toBeFalse()->and($auth->authError)->toBeTrue()->and($auth->retryable)->toBeFalse();

        FakeTikTokApi::failNext(TikTokApiException::THROTTLED, 'Requests made too frequently.');
        $slow = $this->adapter->sendText($this->account, $this->to, 'x');
        expect($slow->success)->toBeFalse()->and($slow->retryable)->toBeTrue()->and($slow->authError)->toBeFalse();

        FakeTikTokApi::failNext(TikTokApiException::BLOCKED_BY_RULES, 'The message is blocked due to limitation of direct message rules.');
        $blocked = $this->adapter->sendText($this->account, $this->to, 'x');
        expect($blocked->success)->toBeFalse()->and($blocked->retryable)->toBeFalse()->and($blocked->error)->toBe(TikTokAdapter::BLOCKED);

        FakeTikTokApi::failNext(TikTokApiException::SYSTEM_ERROR, 'System error.');
        expect($this->adapter->sendText($this->account, $this->to, 'x')->retryable)->toBeTrue();
    });

    it('refuses a channel without a messaging token (the ad-comments channel) as an auth problem, and a disconnected one outright', function () {
        $comments = ChannelAccount::factory()->create(['platform' => Platform::TikTok, 'driver' => 'live', 'external_id' => '7000', 'profile' => ['source' => 'ad_comments']]);
        $r = $this->adapter->sendText($comments, $this->to, 'x');
        expect($r->success)->toBeFalse()->and($r->authError)->toBeTrue()->and($r->error)->toBe(TikTokAdapter::DMS_NOT_CONNECTED);

        $this->account->update(['status' => 'disconnected']);
        expect($this->adapter->sendText($this->account, $this->to, 'x')->success)->toBeFalse();
        expect(FakeTikTokApi::calls())->toBe([]);
    });
});

it('shows typing as a SENDER_ACTION and never throws', function () {
    $this->adapter->typing($this->account, $this->to, true);
    $this->adapter->typing($this->account, $this->to, false);

    $sent = FakeTikTokApi::sent();
    expect($sent)->toHaveCount(1)->and($sent[0]['message_type'])->toBe('SENDER_ACTION')->and($sent[0]['sender_action'])->toBe('TYPING');

    FakeTikTokApi::failNext(TikTokApiException::THROTTLED);
    $this->adapter->typing($this->account, $this->to, true);
});

it('still refuses a private reply from a comment and keeps the ad-comments capabilities', function () {
    expect($this->adapter->sendPrivateReply($this->account, 'c1', 'x')->error)->toBe(TikTokAdapter::PRIVATE_REPLY_REFUSED)
        ->and($this->adapter->capabilities()->windowHours)->toBe(48)
        ->and($this->adapter->capabilities()->templatesOutsideWindow)->toBeFalse()
        ->and($this->adapter->capabilities()->hideComment)->toBeTrue();
});
