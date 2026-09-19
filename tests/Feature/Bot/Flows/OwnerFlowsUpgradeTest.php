<?php

use App\Bot\Flow\ReplyScheduler;
use App\Bot\Flows\FakeFlowAnswerInterpreter;
use App\Bot\Flows\FlowAnswerInterpreter;
use App\Bot\Flows\FlowDefinition;
use App\Bot\Flows\FlowDefinitions;
use App\Bot\Flows\FlowEngine;
use App\Bot\Flows\FlowScripts;
use App\Bot\Flows\FlowState;
use App\Bot\Flows\FlowStepCatalog;
use App\Bot\Flows\OwnerFlowsUpgrade;
use App\Bot\Flows\ReturnFlowUpgrade;
use App\Channels\Adapters\InstagramAdapter;
use App\Channels\Adapters\MessengerAdapter;
use App\Channels\Adapters\WhatsAppAdapter;
use App\Channels\Cards\OutboundCards;
use App\Channels\Data\InboundMessageData;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Enums\UserRole;
use App\Inbox\InboxIngestor;
use App\Models\BotFlow;
use App\Models\BotFlowVersion;
use App\Models\BotKnowledgeEntry;
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\CustomerIdentity;
use App\Models\Message;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

// Publishing the owner's flows 3–5 (migration 2026_09_19_500020), the new step types for the
// designer, and flow 6: the products menu's store-link button.

function ofuMigrate(): void
{
    (require database_path('migrations/2026_09_19_500020_publish_owner_cancel_complaint_branches_flows.php'))->up();
}

/** Puts the three flows back to their 2026-09-17 seed with one published version each (a pre-upgrade install). */
function ofuLegacyInstall(): void
{
    foreach (OwnerFlowsUpgrade::legacyDefinitions() as $key => $legacy) {
        $flow = BotFlow::where('key', $key)->firstOrFail();
        $flow->versions()->delete();
        $flow->update(['definition' => $legacy]);
        $flow->versions()->create(['version' => 1, 'status' => 'published', 'definition' => $legacy, 'published_at' => now()]);
    }

    BotKnowledgeEntry::whereIn('key', array_map(fn ($k) => 'script.'.$k, OwnerFlowsUpgrade::SCRIPT_KEYS))->delete();
}

it('seeds the owner flows, valid and without warnings', function () {
    foreach (OwnerFlowsUpgrade::definitions() as $key => $def) {
        $live = BotFlow::where('key', $key)->firstOrFail()->definition;

        expect(ReturnFlowUpgrade::same($live, $def))->toBeTrue()
            ->and(FlowDefinitions::all()[$key]['definition'])->toBe($def)
            ->and(FlowDefinition::validate($def))->toBe([])
            ->and(FlowDefinition::validateReferences($def))->toBe([])
            ->and(FlowDefinition::warnings($def))->toBe([]);
    }

    foreach (OwnerFlowsUpgrade::legacyDefinitions() as $def) {
        expect(FlowDefinition::validate($def))->toBe([]);
    }

    // No summary step any more, and every closing text names the order or the case.
    expect(collect(OwnerFlowsUpgrade::definitions())->flatMap(fn ($d) => array_column($d['steps'], 'type'))->contains('summary'))->toBeFalse();
});

it('publishes the three flows over the old seed as new versions, adds the scripts, and is idempotent', function () {
    ofuLegacyInstall();
    $draftFlow = BotFlow::where('key', 'complaint')->firstOrFail();
    $draft = $draftFlow->versions()->create(['version' => 2, 'status' => 'draft', 'definition' => OwnerFlowsUpgrade::legacyDefinitions()['complaint']]);

    ofuMigrate();

    foreach (OwnerFlowsUpgrade::definitions() as $key => $def) {
        $flow = BotFlow::where('key', $key)->firstOrFail();
        $published = $flow->versions()->where('status', 'published')->sole();

        expect($flow->definition)->toBe($def)
            ->and($published->note)->toBe(OwnerFlowsUpgrade::NOTES[$key])
            ->and($flow->versions()->where('status', 'archived')->where('version', 1)->sole()->definition)->toBe(OwnerFlowsUpgrade::legacyDefinitions()[$key])
            ->and($flow->draft()->exists())->toBeFalse();
    }

    expect($draft->fresh()->status)->toBe('archived');

    foreach (OwnerFlowsUpgrade::SCRIPT_KEYS as $key) {
        expect(BotKnowledgeEntry::where('key', 'script.'.$key)->value('body'))->toBe(FlowScripts::all()[$key]['body']);
    }

    // Re-running changes nothing, and an owner edit of a script is never overwritten.
    BotKnowledgeEntry::where('key', 'script.handover_in_hours')->update(['body' => 'نصي']);
    $versions = BotFlowVersion::count();
    $entries = BotKnowledgeEntry::count();
    ofuMigrate();

    expect(BotFlowVersion::count())->toBe($versions)
        ->and(BotKnowledgeEntry::count())->toBe($entries)
        ->and(BotKnowledgeEntry::where('key', 'script.handover_in_hours')->value('body'))->toBe('نصي');
});

it('adds the cards, handover topic and store link columns once', function () {
    $migration = require database_path('migrations/2026_09_19_500010_add_cards_handover_topic_and_store_url.php');
    $migration->up();
    $migration->up();

    expect(Schema::hasColumns('messages', ['cards']))->toBeTrue()
        ->and(Schema::hasColumns('conversations', ['handover_topic']))->toBeTrue()
        ->and(Schema::hasColumns('bot_settings', ['store_url']))->toBeTrue();
});

it('describes the new step types for the designer and validates their switches', function () {
    $catalog = FlowStepCatalog::all();

    expect($catalog['contact']['label_ar'])->toBe('بيانات التواصل')
        ->and($catalog['item_changes']['has_next'])->toBeTrue()
        ->and($catalog['choice']['fields'])->toContain('allow_text')
        ->and($catalog['text']['fields'])->toContain('photos')
        ->and($catalog['order_items']['fields'])->toContain('return_rules')
        ->and(array_keys($catalog))->toBe(FlowDefinition::TYPES);

    $bad = ['start' => 'a', 'steps' => [
        'a' => ['type' => 'choice', 'field' => 'x', 'text' => 't', 'allow_text' => 'yes', 'options' => [['title' => 'x', 'value' => 'x']], 'next' => 'b'],
        'b' => ['type' => 'item_changes'],
    ]];

    expect(FlowDefinition::validate($bad))->toBe([
        "step 'a' 'allow_text' must be true or false",
        "step 'b' next must be a non-empty string",
    ]);

    $noPicker = ['start' => 'b', 'steps' => ['b' => ['type' => 'item_changes', 'next' => 'end']]];
    expect(FlowDefinition::warnings($noPicker))->toBe(['الخطوة b محتاجة قبلها خطوة اختيار قطع من الأوردر']);
});

// ---- flow 6: the store link under the products menu's answers --------------------------------------

function ofuProducts(): Conversation
{
    Event::fake();
    config(['crm.drivers.ai' => 'fake']);
    app()->bind(FlowAnswerInterpreter::class, FakeFlowAnswerInterpreter::class);
    ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'PAGE1']);
    BotSetting::current()->update(['enabled' => false, 'working_hours' => null]);
    app(InboxIngestor::class)->ingestMessage(new InboundMessageData(Platform::Facebook, 'PAGE1', 'PSID-OFU', 'Mona', 'ofu-1', 'اهلا', CarbonImmutable::now()));
    $c = Conversation::firstOrFail();
    app(FlowEngine::class)->runPayload($c, 'menu:products');

    return $c->fresh();
}

function ofuTap(string $payload, string $title): Message
{
    app(InboxIngestor::class)->ingestMessage(new InboundMessageData(Platform::Facebook, 'PAGE1', 'PSID-OFU', 'Mona', 'ofu-'.uniqid(), $title, CarbonImmutable::now(), payload: $payload));
    $c = Conversation::firstOrFail();
    app(FlowEngine::class)->handle($c, app(ReplyScheduler::class)->burst($c));

    return Message::where('sender_type', SenderType::Bot->value)->latest('id')->firstOrFail();
}

it('adds «🛍️ تسوقي من الموقع» to the products menu answers, with the store link from the settings', function () {
    ofuProducts();
    $size = ofuTap('script:size', 'المقاسات');

    expect($size->cards)->toBe(['type' => 'button', 'buttons' => [['type' => 'web_url', 'title' => '🛍️ تسوقي من الموقع', 'url' => 'https://levoilestores.com/']]])
        ->and($size->buttons)->toBe([['title' => 'القائمة الرئيسية', 'payload' => 'menu:main_menu']]);

    BotSetting::current()->update(['store_url' => 'https://shop.example/']);
    expect(ofuTap('script:payment_info', 'طرق الدفع')->cards['buttons'][0]['url'])->toBe('https://shop.example/');
});

it('sends no store link for a script outside the products menu', function () {
    $c = ofuProducts();
    app(FlowState::class)::clear($c);

    app(FlowEngine::class)->runPayload($c->fresh(), 'script:size');

    expect(Message::where('sender_type', SenderType::Bot->value)->latest('id')->first()->cards)->toBeNull();
});

it('saves the store link on the bot settings page', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->putJson('/settings/bot', ['store_url' => 'https://levoilestores.com/collections/new'])->assertOk();
    expect(BotSetting::current()->storeUrl())->toBe('https://levoilestores.com/collections/new');

    $this->actingAs($admin)->putJson('/settings/bot', ['store_url' => 'not a link'])->assertStatus(422)->assertJsonValidationErrorFor('store_url');

    $this->actingAs($admin)->putJson('/settings/bot', ['store_url' => null])->assertOk();
    expect(BotSetting::current()->storeUrl())->toBe(BotSetting::DEFAULT_STORE_URL);
});

it('sends the store link as a button template on Messenger and Instagram, and as a link line on WhatsApp', function () {
    Http::preventStrayRequests();
    Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'm.1', 'messages' => [['id' => 'wamid.1']]])]);
    config(['crm.drivers.channels' => 'live']);
    $cards = OutboundCards::button([OutboundCards::webUrl('🛍️ تسوقي من الموقع', 'https://levoilestores.com/')]);

    foreach ([[MessengerAdapter::class, Platform::Facebook], [InstagramAdapter::class, Platform::Instagram], [WhatsAppAdapter::class, Platform::WhatsApp]] as [$adapter, $platform]) {
        $account = ChannelAccount::factory()->create(['platform' => $platform, 'driver' => 'live', 'credentials' => ['access_token' => 'tok']]);
        $to = CustomerIdentity::factory()->create(['platform' => $platform, 'external_id' => 'U-'.$platform->value]);
        app($adapter)->sendText($account, $to, 'المقاسات من S لـ XXL', ['cards' => $cards, 'quick_replies' => [['title' => 'القائمة الرئيسية', 'payload' => 'menu:main_menu']]]);
    }

    Http::assertSent(fn (Request $r) => ($r['message']['attachment']['payload'] ?? null) === [
        'template_type' => 'button',
        'text' => 'المقاسات من S لـ XXL',
        'buttons' => [['type' => 'web_url', 'url' => 'https://levoilestores.com/', 'title' => '🛍️ تسوقي من الموقع']],
    ] && ($r['message']['quick_replies'][0]['payload'] ?? null) === 'menu:main_menu');

    Http::assertSent(fn (Request $r) => ($r['messaging_product'] ?? null) === 'whatsapp'
        && (data_get($r->data(), 'interactive.body.text') ?? data_get($r->data(), 'text.body')) === "المقاسات من S لـ XXL\n\n🛍️ تسوقي من الموقع:\nhttps://levoilestores.com/");
});
