<?php

use App\Bot\Ai\{AiReply, AiResponder, Classification};
use App\Bot\BotEngine;
use App\Enums\{Handler, Platform, SenderType, UserRole};
use App\Analytics\{ActivityLogger, AttributionRecorder};
use App\Inbox\{OutboundService, SoftLock, WindowPolicy};
use App\Media\{MediaPolicy, SampleMedia};
use App\Models\{BotKnowledgeEntry, BotRule, BotRun, BotSetting, ChannelAccount, Conversation, ConversationNote, Customer, CustomerIdentity, Message, MessageAttachment, User};
use Illuminate\Support\Facades\{DB, Event, Storage};

beforeEach(function () {
    Event::fake();
    Storage::fake('media');
    BotSetting::current()->update(['enabled' => true, 'ai_enabled' => true, 'working_hours' => null, 'max_bot_turns' => 5, 'min_confidence' => 0.6]);
    $acc = ChannelAccount::factory()->create(['platform' => Platform::Instagram]);
    $cust = Customer::factory()->create();
    CustomerIdentity::factory()->for($cust)->create(['platform' => Platform::Instagram]);
    $this->conv = Conversation::factory()->for($cust)->for($acc, 'channelAccount')->create(['handler' => Handler::Bot, 'last_customer_message_at' => now()]);
    $this->say = fn (string $text) => app(BotEngine::class)->handleInbound(Message::factory()->for($this->conv)->create(['direction' => 'in', 'sender_type' => SenderType::Customer, 'body' => $text, 'platform' => Platform::Instagram]));
});

it('hands over purchase intent immediately with a summary note', function () {
    $run = ($this->say)('عاوزة أطلب الفستان الستان مقاس M للجيزة');

    $note = ConversationNote::where('conversation_id', $this->conv->id)->latest('id')->first();
    expect($run->decision)->toBe('handover')->and($run->engine)->toBe('signal')
        ->and($this->conv->fresh()->needs_human)->toBeTrue()
        ->and($note->user_id)->toBeNull()
        ->and($note->body)->toContain('السبب: العميلة عايزة تطلب')->toContain('المحافظة: الجيزة')->toContain('المقاسات: M');
});

it('hands over size recommendation and complaints', function () {
    expect(($this->say)('وزني 70 كيلو البسي مقاس ايه')->decision)->toBe('handover');
    // fresh(): the factory doesn't share this model instance with the engine,
    // so updating the stale in-memory copy (already "bot") would be a no-op.
    $this->conv->fresh()->update(['handler' => Handler::Bot, 'needs_human' => false]);
    expect(($this->say)('الاوردر اتاخر ومشكله كبيره')->intent)->toBe('complaint');
});

it('answers shipping fee from the shipping quote and knowledge, never inventing numbers', function () {
    // The fee comes only from the synced Shopify rates (ShippingFeeAnswer).
    $zone = \App\Models\ShippingZone::factory()->create();
    \App\Models\ShippingZoneRegion::factory()->create(['shipping_zone_id' => $zone->id, 'province_code' => 'ALX', 'province_name' => 'Alexandria']);
    \App\Models\ShippingRate::factory()->create(['shipping_zone_id' => $zone->id, 'price' => 60]);
    $run = ($this->say)('الشحن لإسكندرية بكام؟');

    expect($run->decision)->toBe('reply')->and($run->intent)->toBe('shipping')
        ->and($this->conv->messages()->where('sender_type', 'bot')->latest('id')->value('body'))->toContain('60');
});

it('reads knowledge entries at runtime for knowledge rules', function () {
    BotKnowledgeEntry::where('key', 'payment_methods')->update(['body' => 'كاش عند الاستلام بس']);
    ($this->say)('طرق الدفع ايه');
    expect($this->conv->messages()->where('sender_type', 'bot')->latest('id')->value('body'))->toBe('كاش عند الاستلام بس');
});

it('sends the size chart text and image for size chart questions', function () {
    Storage::disk('media')->put('bot/size-chart.png', SampleMedia::bytes('image'));
    BotSetting::current()->update(['size_chart_image_path' => 'bot/size-chart.png', 'size_chart_image_mime' => 'image/png']);

    ($this->say)('ممكن جدول المقاسات');

    $botMessages = $this->conv->messages()->where('sender_type', 'bot')->orderBy('id')->get();
    expect($botMessages[0]->body)->toStartWith('جدول المقاسات (cm):')
        ->and(MessageAttachment::where('message_id', $botMessages[1]->id)->value('type')->value)->toBe('image');
});

it('previews a reply from the settings test box without sending', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $this->actingAs($sup)->postJson('/settings/bot-knowledge/ask', ['text' => 'عاوزة أطلب', 'platform' => 'instagram'])
        ->assertOk()->assertJson(['would_handover' => true, 'reason' => 'purchase']);
    $this->actingAs($sup)->postJson('/settings/bot-knowledge/ask', ['text' => 'الاستبدال ازاي', 'platform' => 'instagram'])
        ->assertOk()->assertJsonPath('would_handover', false)->assertJsonPath('reply', fn ($r) => str_contains($r, '14 يوم'));
    expect(Message::where('sender_type', 'bot')->count())->toBe(0)
        ->and(ConversationNote::count())->toBe(0)
        ->and(BotRun::count())->toBe(0);
});

// ---- additional coverage for the controller rulings ----

it('hands over with an ai_guard note when the ai invents a price', function () {
    app()->instance(AiResponder::class, new class implements AiResponder
    {
        public function classify(string $text): Classification
        {
            throw new RuntimeException('comment classifier is not used for messages');
        }

        public function reply(array $history, array $catalogLines, string $systemPrompt): AiReply
        {
            return new AiReply('reply', 'الشحن لإسكندرية 35 جنيه بس 🌸', 'fake');
        }
    });

    $run = ($this->say)('الشحن لإسكندرية بكام؟');

    expect($run->decision)->toBe('handover')
        ->and($this->conv->messages()->where('sender_type', 'bot')->count())->toBe(0)
        ->and(ConversationNote::where('conversation_id', $this->conv->id)->latest('id')->value('body'))
        ->toContain('الرد كان فيه أرقام مش موجودة في البيانات')->toContain('المحافظة: الإسكندرية');
});

it('uses the default system prompt at runtime when none is saved', function () {
    BotSetting::current()->update(['system_prompt' => null]);
    $seen = new ArrayObject;
    app()->instance(AiResponder::class, new class($seen) implements AiResponder
    {
        public function __construct(private ArrayObject $seen) {}

        public function classify(string $text): Classification
        {
            throw new RuntimeException('unused');
        }

        public function reply(array $history, array $catalogLines, string $systemPrompt): AiReply
        {
            $this->seen['prompt'] = $systemPrompt;

            return new AiReply('handover', '', 'fake');
        }
    });

    ($this->say)('الشحن لإسكندرية بكام؟');

    expect($seen['prompt'])->toBe(BotSetting::DEFAULT_SYSTEM_PROMPT);
});

it('sends nothing for a size chart question when the reply window is closed', function () {
    Storage::disk('media')->put('bot/size-chart.png', SampleMedia::bytes('image'));
    BotSetting::current()->update(['size_chart_image_path' => 'bot/size-chart.png', 'size_chart_image_mime' => 'image/png']);
    $this->conv->fresh()->update(['last_customer_message_at' => now()->subDays(3)]);

    ($this->say)('ممكن جدول المقاسات');

    expect($this->conv->messages()->where('sender_type', 'bot')->count())->toBe(0)
        ->and(MessageAttachment::count())->toBe(0);
});

it('sends the size chart text only when the platform refuses the image', function () {
    Storage::disk('media')->put('bot/size-chart.png', SampleMedia::bytes('image'));
    BotSetting::current()->update(['size_chart_image_path' => 'bot/size-chart.png', 'size_chart_image_mime' => 'image/png']);
    config(['crm.media.platforms.instagram.image' => null]); // MediaPolicy::assertSendable → MediaRejected

    $run = ($this->say)('ممكن جدول المقاسات');

    $bot = $this->conv->messages()->where('sender_type', 'bot')->get();
    expect($run->decision)->toBe('reply')
        ->and($bot)->toHaveCount(1)
        ->and($bot[0]->body)->toStartWith('جدول المقاسات (cm):')
        ->and(MessageAttachment::whereNotNull('message_id')->count())->toBe(0);
});

it('never copies the size chart image when the platform would refuse it (final fix wave I4)', function () {
    Storage::disk('media')->put('bot/size-chart.png', SampleMedia::bytes('image'));
    BotSetting::current()->update(['size_chart_image_path' => 'bot/size-chart.png', 'size_chart_image_mime' => 'image/png']);
    config(['crm.media.platforms.instagram.image.max_bytes' => 10]); // stored image is bigger than this

    ($this->say)('ممكن جدول المقاسات');

    expect($this->conv->messages()->where('sender_type', 'bot')->count())->toBe(1)
        ->and(MessageAttachment::count())->toBe(0)
        ->and(Storage::disk('media')->allFiles('outbound'))->toBe([]);
});

it('deletes the size chart copy (row and file) when the attachment send throws (final fix wave I4)', function () {
    Storage::disk('media')->put('bot/size-chart.png', SampleMedia::bytes('image'));
    BotSetting::current()->update(['size_chart_image_path' => 'bot/size-chart.png', 'size_chart_image_mime' => 'image/png']);
    app()->instance(OutboundService::class, new class(app(WindowPolicy::class), app(AttributionRecorder::class), app(ActivityLogger::class), app(SoftLock::class), app(MediaPolicy::class)) extends OutboundService
    {
        public function sendBotAttachment(Conversation $c, MessageAttachment $attachment, ?string $caption = null): Message
        {
            throw new RuntimeException('queue down');
        }
    });

    ($this->say)('ممكن جدول المقاسات');

    expect($this->conv->messages()->where('sender_type', 'bot')->count())->toBe(1)
        ->and(MessageAttachment::count())->toBe(0)
        ->and(Storage::disk('media')->allFiles('outbound'))->toBe([]);
    Storage::disk('media')->assertExists('bot/size-chart.png');
});

it('hands over a purchase message outside working hours with a summary note', function () {
    BotSetting::current()->update(['working_hours' => ['days' => [], 'from' => '00:00', 'to' => '23:59'], 'outside_hours_message' => 'احنا مقفولين دلوقتي 🌙']);

    $run = ($this->say)('عاوزة أطلب الفستان ده');

    expect($run->decision)->toBe('handover')->and($run->engine)->toBe('signal')
        ->and($this->conv->fresh()->handler)->toBe(Handler::Human)
        ->and(ConversationNote::where('conversation_id', $this->conv->id)->value('body'))->toContain('العميلة عايزة تطلب');
});

it('seeds the default knowledge rules once without overwriting owner edits', function () {
    $rule = BotRule::where('knowledge_key', 'payment_methods')->firstOrFail();
    $rule->update(['keywords' => ['فلوس']]);

    (include database_path('migrations/2026_09_14_250000_seed_default_knowledge_rules.php'))->up();

    expect(BotRule::where('knowledge_key', 'payment_methods')->count())->toBe(1)
        ->and(BotRule::where('sends_size_chart', true)->count())->toBe(1)
        ->and($rule->fresh()->keywords)->toBe(['فلوس'])
        ->and($rule->fresh()->private_reply)->toBeNull();
});

it('keeps an existing lower-priority owner rule winning over the seeded defaults', function () {
    BotRule::query()->delete();
    BotRule::factory()->create(['name' => 'دفع المالك', 'priority' => 1, 'scope' => 'both', 'platforms' => [], 'match_type' => 'any_keyword', 'keywords' => ['الدفع'], 'private_reply' => 'رد المالك عن الدفع', 'action' => 'reply', 'is_active' => true]);
    BotRule::factory()->create(['name' => 'سعر المالك', 'priority' => 3, 'scope' => 'message', 'platforms' => [], 'match_type' => 'any_keyword', 'keywords' => ['بكام'], 'private_reply' => 'رد المالك عن السعر', 'action' => 'reply', 'is_active' => true]);

    (include database_path('migrations/2026_09_14_250000_seed_default_knowledge_rules.php'))->up();

    expect(BotRule::where('knowledge_key', 'payment_methods')->exists())->toBeFalse()
        ->and(BotRule::whereNotNull('knowledge_key')->orWhere('sends_size_chart', true)->max('priority'))->toBe(0);

    ($this->say)('طرق الدفع ايه');
    expect($this->conv->messages()->where('sender_type', 'bot')->latest('id')->value('body'))->toBe('رد المالك عن الدفع');
});

it('re-ranks or deactivates already-seeded default rules without touching edited ones', function () {
    BotRule::query()->delete();
    $at = now()->subDay()->startOfSecond();
    $seed = fn (string $name, ?string $key, bool $chart, array $keywords, int $priority) => DB::table('bot_rules')->insertGetId([
        'name' => $name, 'is_active' => true, 'priority' => $priority, 'scope' => 'message', 'platforms' => '[]', 'match_type' => 'any_keyword',
        'keywords' => json_encode($keywords, JSON_UNESCAPED_UNICODE), 'public_replies' => '[]', 'private_reply' => null, 'action' => 'reply', 'hits' => 0,
        'knowledge_key' => $key, 'sends_size_chart' => $chart, 'created_at' => $at, 'updated_at' => $at,
    ]);
    $greeting = $seed('ترحيب (معلومات)', 'store_intro', false, ['السلام عليكم', 'هاي'], 60);
    $hours = $seed('مواعيد العمل', 'working_hours_text', false, ['مواعيدكم'], 61);
    $payment = $seed('طرق الدفع (معلومات)', 'payment_methods', false, ['الدفع', 'كاش'], 62);
    DB::table('bot_rules')->where('id', $payment)->update(['updated_at' => $at->copy()->addHour()]); // owner edited it
    BotRule::factory()->create(['name' => 'الترحيب', 'priority' => 5, 'scope' => 'both', 'platforms' => [], 'keywords' => ['السلام عليكم'], 'private_reply' => 'أهلاً', 'action' => 'reply', 'is_active' => true]);
    BotRule::factory()->create(['name' => 'طرق الدفع', 'priority' => 30, 'scope' => 'both', 'platforms' => [], 'keywords' => ['كاش'], 'private_reply' => 'كاش', 'action' => 'reply', 'is_active' => true]);

    (include database_path('migrations/2026_09_14_250010_rerank_default_knowledge_rules.php'))->up();

    expect(BotRule::find($greeting)->is_active)->toBeFalse()
        ->and(BotRule::find($hours)->is_active)->toBeTrue()
        ->and(BotRule::find($hours)->priority)->toBeLessThan(5)
        ->and(BotRule::find($payment)->priority)->toBe(62)
        ->and(BotRule::find($payment)->is_active)->toBeTrue();
});

it('rolls back only the rows the seed created', function () {
    $edited = BotRule::where('knowledge_key', 'payment_methods')->firstOrFail();
    DB::table('bot_rules')->where('id', $edited->id)->update(['updated_at' => now()->addHour()]);
    $owner = BotRule::factory()->create(['name' => 'مواعيد العمل', 'priority' => 10, 'scope' => 'both', 'keywords' => ['مواعيد'], 'knowledge_key' => null, 'is_active' => true]);

    (include database_path('migrations/2026_09_14_250000_seed_default_knowledge_rules.php'))->down();

    expect(BotRule::find($edited->id))->not->toBeNull()
        ->and(BotRule::find($owner->id))->not->toBeNull()
        ->and(BotRule::where('knowledge_key', 'store_intro')->exists())->toBeFalse()
        ->and(BotRule::where('sends_size_chart', true)->exists())->toBeFalse();
});

it('forbids moderators from the ask-the-bot preview', function () {
    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $this->actingAs($mod)->postJson('/settings/bot-knowledge/ask', ['text' => 'بكام', 'platform' => 'instagram'])->assertForbidden();
});
