<?php

use App\Bot\Flows\FlowState;
use App\Channels\Data\InboundMessageData;
use App\Enums\Handler;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Inbox\InboxIngestor;
use App\Models\BotRule;
use App\Models\BotRun;
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Event::fake();
    Http::preventStrayRequests();
    config(['crm.drivers.ai' => 'claude', 'crm.anthropic.key' => 'test-key', 'crm.bot.agent.enabled' => true]);
    ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'PAGE1']);
    BotSetting::current()->update(['enabled' => true, 'ai_enabled' => true, 'working_hours' => null]);
    // An owner's keyword rule still answers before the agent; these tests are about the agent.
    BotRule::query()->update(['is_active' => false]);
});

function agentSay(string $text): Conversation
{
    app(InboxIngestor::class)->ingestMessage(new InboundMessageData(
        Platform::Facebook, 'PAGE1', 'PSID-1', 'Mona', 'ag-'.uniqid(), $text, CarbonImmutable::now(),
    ));

    return Conversation::firstOrFail();
}

function agentToolUse(string $name, array $input): array
{
    return ['stop_reason' => 'tool_use', 'usage' => ['input_tokens' => 100, 'output_tokens' => 20], 'content' => [
        ['type' => 'tool_use', 'id' => 'tu_'.uniqid(), 'name' => $name, 'input' => $input],
    ]];
}

function agentText(string $text): array
{
    return ['stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 100, 'output_tokens' => 20], 'content' => [['type' => 'text', 'text' => $text]]];
}

function agentBotMessages()
{
    return Message::where('sender_type', SenderType::Bot->value)->orderBy('id')->get();
}

it('searches the catalog with a tool and shows the products as picture cards under its reply', function () {
    $p = Product::factory()->create(['title' => 'فستان ستان', 'handle' => 'satin-dress', 'status' => 'active', 'image_url' => 'https://cdn.shopify.com/s/files/satin.webp?v=1']);
    $p->variants()->create(['shopify_id' => 'v1', 'title' => 'أسود / M', 'price' => 950, 'inventory_quantity' => 3]);

    Http::fakeSequence('api.anthropic.com/*')
        ->push(agentToolUse('search_products', ['query' => 'فستان ستان']))
        ->push(agentText('أيوه متوفر يا فندم 🌸 هتلاقي الصور تحت'));

    agentSay('عندكم فستان ستان؟');

    $bot = agentBotMessages();
    expect($bot)->toHaveCount(2)
        ->and($bot[0]->body)->toBe('أيوه متوفر يا فندم 🌸 هتلاقي الصور تحت')
        ->and($bot[1]->cards['cards'][0]['title'])->toBe('فستان ستان')
        ->and($bot[1]->cards['cards'][0]['image_url'])->toContain('format=jpg')
        ->and(BotRun::latest('id')->first()->engine)->toBe('agent')
        ->and(BotRun::latest('id')->first()->intent)->toBe('search_products');

    // The second call carries the tool result: the honest «nothing found» wording lives there too.
    Http::assertSent(fn ($r) => str_contains($r->url(), 'anthropic') && collect($r['messages'])->contains(
        fn ($m) => is_array($m['content']) && str_contains((string) ($m['content'][0]['content'] ?? ''), '950 جنيه')
    ));
});

it('tells the model the catalog has no such product instead of letting it guess', function () {
    Http::fakeSequence('api.anthropic.com/*')
        ->push(agentToolUse('search_products', ['query' => 'عباية']))
        ->push(agentText('للأسف مش لاقية عبايات عندنا يا فندم 🌸'));

    agentSay('عندكم عبايات سودا؟');

    expect(agentBotMessages()->pluck('body')->all())->toBe(['للأسف مش لاقية عبايات عندنا يا فندم 🌸']);
    Http::assertSent(fn ($r) => collect($r['messages'] ?? [])->contains(
        fn ($m) => is_array($m['content']) && str_contains((string) ($m['content'][0]['content'] ?? ''), 'NO PRODUCTS FOUND')
    ));
});

it('never sends a price the tools and the knowledge did not give: the older turn runner answers instead', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence()->push(agentText('الفستان بـ 777 جنيه يا فندم'))->whenEmpty(Http::response([], 500)),
    ]);

    agentSay('الفستان بكام؟');

    expect(agentBotMessages()->pluck('body')->implode(' '))->not->toContain('777')
        ->and(BotRun::where('engine', 'agent')->count())->toBe(0);
});

it('hands over to a person when the agent asks for it, with its summary on the note', function () {
    Http::fakeSequence('api.anthropic.com/*')
        ->push(agentToolUse('handover_to_human', ['reason' => 'payment_issue', 'summary' => 'العميلة اتخصم منها مرتين']))
        ->push(agentText('حقك عليا يا فندم 🙏 هوصل حضرتك بزميلتي حالًا'));

    $c = agentSay('اتخصم مني الفلوس مرتين!!');

    expect($c->refresh()->handler)->not->toBe(Handler::Bot)
        ->and(agentBotMessages()->first()->body)->toContain('حقك عليا')
        ->and(BotRun::latest('id')->first()->decision)->toBe('reply_and_handover');
});

it('starts the guided flow the agent picked', function () {
    useOrderAwareReturnFlow();
    Http::fakeSequence('api.anthropic.com/*')
        ->push(agentToolUse('start_flow', ['flow' => 'return_exchange']))
        ->push(agentText('تمام يا فندم 🌸'));

    $c = agentSay('المقاس طلع صغير وعايزة أبدله');

    expect(FlowState::flow($c->refresh())['key'] ?? null)->toBe('return_exchange')
        ->and(BotRun::latest('id')->first()->decision)->toBe('flow_started');
});
