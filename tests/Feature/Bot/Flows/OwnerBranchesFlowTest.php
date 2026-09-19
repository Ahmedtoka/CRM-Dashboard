<?php

use App\Bot\Flow\ReplyScheduler;
use App\Bot\Flows\BranchFinder;
use App\Bot\Flows\FakeFlowAnswerInterpreter;
use App\Bot\Flows\FlowAnswerInterpreter;
use App\Bot\Flows\FlowDefinition;
use App\Bot\Flows\FlowEngine;
use App\Bot\Flows\FlowResult;
use App\Bot\Flows\FlowState;
use App\Bot\Flows\OwnerFlowsUpgrade;
use App\Bot\Flows\Sandbox\FlowSandbox;
use App\Bot\Flows\Steps\BranchesListStep;
use App\Channels\Adapters\FakeChannelAdapter;
use App\Channels\Adapters\InstagramAdapter;
use App\Channels\Adapters\MessengerAdapter;
use App\Channels\Adapters\WhatsAppAdapter;
use App\Channels\Cards\OutboundCards;
use App\Channels\Data\InboundMessageData;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Enums\UserRole;
use App\Http\Resources\MessageResource;
use App\Inbox\InboxIngestor;
use App\Models\BotFlow;
use App\Models\BotSetting;
use App\Models\Branch;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\CustomerIdentity;
use App\Models\Message;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

// The owner's branches flow of 2026-09-19 (branch cards) and the rich-card support behind it.

beforeEach(function () {
    Event::fake();
    Http::preventStrayRequests();
    config(['crm.drivers.ai' => 'fake']);
    FakeChannelAdapter::reset();
    ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'PAGE1']);
    BotSetting::current()->update(['enabled' => false, 'working_hours' => null]);
    app()->bind(FlowAnswerInterpreter::class, FakeFlowAnswerInterpreter::class);
});

function obrSay(string $text, ?string $payload = null): Conversation
{
    static $n = 0;
    $n++;
    app(InboxIngestor::class)->ingestMessage(new InboundMessageData(
        Platform::Facebook, 'PAGE1', 'PSID-OBR', 'Mona', 'obr'.$n.'-'.uniqid(), $text, CarbonImmutable::now(), payload: $payload,
    ));

    return Conversation::firstOrFail();
}

function obrTurn(string $text, ?string $payload = null): FlowResult
{
    $c = obrSay($text, $payload);

    return app(FlowEngine::class)->handle($c, app(ReplyScheduler::class)->burst($c));
}

function obrStart(): void
{
    $c = obrSay('اهلا');
    app(FlowEngine::class)->runPayload($c, 'flow:branches');
}

function obrBot(): Message
{
    return Message::where('sender_type', SenderType::Bot->value)->latest('id')->firstOrFail();
}

function obrCards(): Message
{
    return Message::where('sender_type', SenderType::Bot->value)->whereNotNull('cards')->latest('id')->firstOrFail();
}

/** @return array{type:string, cards:list<array<string, mixed>>} two sample branch cards */
function obrSampleCards(): array
{
    return OutboundCards::generic([
        ['title' => 'El Marghany', 'subtitle' => "126 El-Marghany St.\n📞 01094538159", 'text' => "📍 El Marghany\n126 El-Marghany St.\n📞 01094538159\n🗺️ https://goo.gl/maps/a", 'buttons' => [
            OutboundCards::webUrl('📍 الخريطة', 'https://goo.gl/maps/a'), OutboundCards::call('📞 اتصل بالفرع', '01094538159'),
        ]],
        ['title' => 'El Hegaz', 'subtitle' => "7 Ali Abd El-Razek St.\n📞 01063498056", 'text' => "📍 El Hegaz\n7 Ali Abd El-Razek St.\n📞 01063498056", 'buttons' => [
            OutboundCards::webUrl('📍 الخريطة', 'https://goo.gl/maps/b'), OutboundCards::call('📞 اتصل بالفرع', '01063498056'),
        ]],
    ]);
}

it('asks for the area with one button per area, then sends the area branches as cards and «تحبي حاجة تانية؟»', function () {
    obrStart();

    expect(obrBot()->body)->toBe(OwnerFlowsUpgrade::BRANCHES_ASK_TEXT)
        ->and(obrBot()->buttons[0]['payload'])->toStartWith('step:branches:list:area:');

    obrTurn('مصر الجديدة', 'step:branches:list:area:heliopolis');

    $cards = obrCards();
    $first = $cards->cards['cards'][0];
    expect($cards->cards['type'])->toBe('generic')
        ->and($cards->cards['cards'])->toHaveCount(2)
        ->and($first['title'])->toBe('El Marghany')
        ->and($first['subtitle'])->toBe("126 El-Marghany St., Next to Shawermer\n📞 01094538159")
        ->and($first['buttons'])->toBe([
            ['type' => 'web_url', 'title' => '📍 الخريطة', 'url' => 'https://goo.gl/maps/EbSV5rzAqCvvyAD37'],
            ['type' => 'phone', 'title' => '📞 اتصل بالفرع', 'phone' => '+201094538159'],
        ])
        ->and($cards->body)->toStartWith("فروعنا في مصر الجديدة 🌸\n\n📍 El Marghany")
        ->and($cards->body)->not->toContain('🕘')
        ->and(obrBot()->body)->toBe('تحبي حاجة تانية؟')
        ->and(array_column(obrBot()->buttons, 'title'))->toBe(['فرع في منطقة تانية', 'القائمة الرئيسية']);

    obrTurn('فرع في منطقة تانية', 'step:branches:more:other_area');
    expect(FlowState::flow(Conversation::first())['step'])->toBe('list')
        ->and(obrBot()->body)->toBe(OwnerFlowsUpgrade::BRANCHES_ASK_TEXT);
});

it('sends one card for a typed branch name', function () {
    obrStart();
    obrTurn('عايزة عنوان فرع المرغني');

    expect(obrCards()->cards['cards'])->toHaveCount(1)
        ->and(obrCards()->cards['cards'][0]['title'])->toBe('El Marghany')
        ->and(obrCards()->body)->toStartWith('📍 El Marghany')
        ->and(obrBot()->body)->toBe('تحبي حاجة تانية؟');
});

it('shows the hours line only once the owner filled it in', function () {
    Branch::where('name', 'El Marghany')->update(['hours' => 'يوميًا من 10 الصبح لـ 11 بالليل']);
    obrStart();
    obrTurn('المرغني');

    $card = obrCards()->cards['cards'][0];
    expect($card['subtitle'])->toContain("🕘 يوميًا من 10 الصبح لـ 11 بالليل\n📞 01094538159")
        ->and($card['text'])->toContain('🕘 يوميًا من 10 الصبح لـ 11 بالليل');
});

it('keeps the phone in an 80-character subtitle by shortening a long address', function () {
    $b = Branch::factory()->create(['area_key' => 'long', 'area_ar' => 'منطقة', 'name' => 'Long', 'address' => str_repeat('شارع طويل جدا ', 12), 'phone' => '01000000000']);
    $card = app(BranchFinder::class)->cards([$b])['cards'][0];

    expect(mb_strlen($card['subtitle']))->toBeLessThanOrEqual(80)->and($card['subtitle'])->toEndWith('📞 01000000000');
});

it('sends the first 10 of a big area, says there are more, and takes a typed name', function () {
    foreach (['Amber', 'Birch', 'Cedar', 'Dahlia', 'Ebony', 'Falcon', 'Garnet', 'Harbor', 'Island', 'Jasmin', 'Kestrel', 'Lantern'] as $i => $name) {
        Branch::factory()->create(['area_key' => 'big_area', 'area_ar' => 'منطقة كبيرة', 'area_en' => 'Big Area', 'name' => $name, 'sort' => 1000 + $i, 'aliases' => []]);
    }

    obrStart();
    obrTurn('منطقة كبيرة', 'step:branches:list:area:big_area');

    expect(obrCards()->cards['cards'])->toHaveCount(10)
        ->and(obrBot()->body)->toBe(BranchesListStep::MORE_BRANCHES_TEXT)
        ->and(FlowState::flow(Conversation::first())['step'])->toBe('list');

    obrTurn('Lantern');
    expect(obrCards()->cards['cards'])->toHaveCount(1)
        ->and(obrCards()->cards['cards'][0]['title'])->toBe('Lantern')
        ->and(obrBot()->body)->toBe('تحبي حاجة تانية؟');
});

it('hands the cards to the channel adapter and shows them in the inbox resource', function () {
    obrStart();
    obrTurn('المرغني');

    $sent = collect(FakeChannelAdapter::sent())->where('method', 'sendText')->first(fn ($s) => isset($s['options']['cards']));
    expect($sent['options']['cards']['cards'][0]['title'])->toBe('El Marghany')
        ->and((new MessageResource(obrCards()))->resolve()['cards']['type'])->toBe('generic');
});

it('renders the cards in the designer sandbox', function () {
    $flow = BotFlow::where('key', 'branches')->firstOrFail();
    $user = User::factory()->create(['role' => UserRole::Admin]);
    $sandbox = app(FlowSandbox::class);

    expect(FlowDefinition::validate($flow->definition))->toBe([]);

    $r = $sandbox->run($flow, 'published', null, [], $user);
    $r = $sandbox->run($flow, 'published', $r['state'], ['payload' => 'step:branches:list:area:alexandria'], $user);

    expect($r['messages'][0]['cards']['type'])->toBe('generic')
        ->and($r['messages'][0]['cards']['cards'])->toHaveCount(4)
        ->and($r['messages'][1]['text'])->toBe('تحبي حاجة تانية؟')
        ->and($r['current'])->toBe(['flow_key' => 'branches', 'step_id' => 'more']);
});

// ---- the channels --------------------------------------------------------------------------------

function obrLive(Platform $platform, string $externalId = 'PAGE-LIVE'): array
{
    config(['crm.drivers.channels' => 'live']);
    $account = ChannelAccount::factory()->create(['platform' => $platform, 'external_id' => $externalId, 'driver' => 'live', 'credentials' => ['access_token' => 'tok']]);
    $to = CustomerIdentity::factory()->create(['platform' => $platform, 'external_id' => 'PSID-LIVE']);

    return [$account, $to];
}

it('sends a Messenger generic-template carousel with map and call buttons and the quick replies', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'm.1'])]);
    [$account, $to] = obrLive(Platform::Facebook);

    $result = app(MessengerAdapter::class)->sendText($account, $to, 'fallback', ['cards' => obrSampleCards(), 'quick_replies' => [['title' => 'القائمة الرئيسية', 'payload' => 'menu:main_menu']]]);

    expect($result->success)->toBeTrue();
    Http::assertSentCount(1);
    Http::assertSent(function (Request $r) {
        $payload = $r['message']['attachment']['payload'] ?? [];

        return $payload['template_type'] === 'generic'
            && count($payload['elements']) === 2
            && $payload['elements'][0]['title'] === 'El Marghany'
            && $payload['elements'][0]['buttons'] === [
                ['type' => 'web_url', 'url' => 'https://goo.gl/maps/a', 'title' => '📍 الخريطة'],
                ['type' => 'phone_number', 'title' => '📞 اتصل بالفرع', 'payload' => '+201094538159'],
            ]
            && $r['message']['quick_replies'][0]['payload'] === 'menu:main_menu'
            && ! isset($r['message']['text']);
    });
});

it('keeps only the web_url buttons on Instagram (the phone stays in the subtitle)', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'm.1'])]);
    [$account, $to] = obrLive(Platform::Instagram);

    app(InstagramAdapter::class)->sendText($account, $to, 'fallback', ['cards' => obrSampleCards()]);

    Http::assertSent(function (Request $r) {
        $element = $r['message']['attachment']['payload']['elements'][0] ?? [];

        return $element['buttons'] === [['type' => 'web_url', 'url' => 'https://goo.gl/maps/a', 'title' => '📍 الخريطة']]
            && str_contains($element['subtitle'], '📞 01094538159');
    });
});

it('caps the carousel at 10 cards', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'm.1'])]);
    [$account, $to] = obrLive(Platform::Facebook);
    $cards = OutboundCards::generic(array_map(fn ($i) => ['title' => "B{$i}", 'subtitle' => 'x', 'buttons' => []], range(1, 12)));

    expect($cards['cards'])->toHaveCount(10);

    app(MessengerAdapter::class)->sendText($account, $to, 'fallback', ['cards' => $cards]);
    Http::assertSent(fn (Request $r) => count($r['message']['attachment']['payload']['elements']) === 10);
});

it('falls back to the plain text when the platform refuses the template', function (string $adapter, Platform $platform) {
    Http::fake(['graph.facebook.com/*' => fn (Request $r) => isset($r['message']['attachment'])
        ? Http::response(['error' => ['message' => '(#100) Invalid template', 'code' => 100]], 400)
        : Http::response(['message_id' => 'm.2'])]);
    [$account, $to] = obrLive($platform);

    $result = app($adapter)->sendText($account, $to, "فروعنا 🌸\n\n📍 El Marghany", ['cards' => obrSampleCards()]);

    // The refused template (the Graph client's own retry included), then the text.
    expect($result->success)->toBeTrue()->and($result->externalId)->toBe('m.2');
    Http::assertSent(fn (Request $r) => ($r['message']['text'] ?? null) === "فروعنا 🌸\n\n📍 El Marghany");
})->with([[MessengerAdapter::class, Platform::Facebook], [InstagramAdapter::class, Platform::Instagram]]);

it('sends one WhatsApp text per branch card', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);
    [$account, $to] = obrLive(Platform::WhatsApp, 'PHONE-ID');

    $result = app(WhatsAppAdapter::class)->sendText($account, $to, 'fallback', ['cards' => obrSampleCards()]);

    expect($result->success)->toBeTrue();
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $r) => ($r['text']['body'] ?? null) === "📍 El Marghany\n126 El-Marghany St.\n📞 01094538159\n🗺️ https://goo.gl/maps/a");
    Http::assertSent(fn (Request $r) => ($r['text']['body'] ?? null) === "📍 El Hegaz\n7 Ali Abd El-Razek St.\n📞 01063498056");
});

it('normalizes a branch phone to +20', function () {
    expect(OutboundCards::phone('01094538159'))->toBe('+201094538159')
        ->and(OutboundCards::phone('+20 109 453 8159'))->toBe('+201094538159')
        ->and(OutboundCards::phone('٠١٠٩٤٥٣٨١٥٩'))->toBe('+201094538159')
        ->and(OutboundCards::phone('201094538159'))->toBe('+201094538159');
});
