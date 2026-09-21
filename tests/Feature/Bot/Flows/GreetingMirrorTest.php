<?php

use App\Bot\Flow\FakeTurnUnderstanding;
use App\Bot\Flow\TurnUnderstanding;
use App\Bot\Flows\FakeFlowAnswerInterpreter;
use App\Bot\Flows\FlowAnswerInterpreter;
use App\Bot\Flows\GreetingMirror;
use App\Channels\Data\InboundMessageData;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Inbox\InboxIngestor;
use App\Models\BotFlow;
use App\Models\BotKnowledgeEntry;
use App\Models\BotRule;
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

// Fix 2 (2026-09-21): the bot greets back the way she greeted, on its own first line,
// above the {time_greeting} line and the menu. "Now" is a Cairo morning, so the
// time greeting is «صباح الخير».

const GM_SALAM = 'وعليكم السلام ورحمة الله 🌸';

const GM_HI = 'أهلاً بيكي 🌸';

const GM_TIME_GREETING = 'صباح الخير يا فندم يومك حلو ان شاء الله 😍 مع حضرتك ميار من Le Voile';

beforeEach(function () {
    Event::fake();
    Http::preventStrayRequests();
    config(['crm.drivers.ai' => 'fake']);
    ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'PAGE1']);
    BotSetting::current()->update(['enabled' => true, 'ai_enabled' => true, 'working_hours' => null, 'min_confidence' => 0.6]);
    app()->bind(TurnUnderstanding::class, FakeTurnUnderstanding::class);
    app()->bind(FlowAnswerInterpreter::class, FakeFlowAnswerInterpreter::class);
    $this->travelTo(CarbonImmutable::parse('2026-09-19 08:00', 'Africa/Cairo'));
});

function gmSay(string $text): Conversation
{
    static $n = 0;
    $n++;
    app(InboxIngestor::class)->ingestMessage(new InboundMessageData(
        Platform::Facebook, 'PAGE1', 'PSID-GM', 'Mona', 'gm'.$n.'-'.uniqid(), $text, CarbonImmutable::now(),
    ));

    return Conversation::firstOrFail()->fresh();
}

/** @return list<string> */
function gmBodies(): array
{
    return Message::where('sender_type', SenderType::Bot->value)->orderBy('id')->pluck('body')->map(fn ($b) => (string) $b)->all();
}

function gmMirror(string $text): ?string
{
    return app(GreetingMirror::class)->line($text);
}

it('mirrors every greeting the owner listed', function (string $said, string $expected) {
    expect(gmMirror($said))->toBe($expected);
})->with([
    'السلام عليكم' => ['السلام عليكم', GM_SALAM],
    'with the full blessing' => ['السلام عليكم ورحمة الله وبركاته', GM_SALAM],
    'without the alif-lam' => ['سلام عليكم', GM_SALAM],
    'just سلام' => ['سلام', GM_SALAM],
    'صباح الخير' => ['صباح الخير', 'صباح النور'],
    'صباح الفل' => ['صباح الفل', 'صباح الفل والنور'],
    'صباح النور' => ['صباح النور', 'صباح النور'],
    'مساء الخير' => ['مساء الخير', 'مساء النور'],
    'مساء الفل' => ['مساء الفل', 'مساء الفل والنور'],
    'مساء النور' => ['مساء النور', 'مساء النور'],
    'أهلاً' => ['أهلاً', GM_HI],
    'أهلا' => ['أهلا', GM_HI],
    'اهلين' => ['اهلين', GM_HI],
    'هاي' => ['هاي', GM_HI],
    'هالو' => ['هالو', GM_HI],
    'hi' => ['hi', GM_HI],
    'hello' => ['hello', GM_HI],
    'HELLO shouted' => ['HELLO', GM_HI],
]);

it('mirrors misspellings, tashkeel, emoji and punctuation too', function (string $said, string $expected) {
    expect(gmMirror($said))->toBe($expected);
})->with([
    'elongated أهلاااا' => ['اهلاااا', GM_HI],
    'أهلا with an emoji' => ['أهلاً 😍😍', GM_HI],
    'hiii' => ['hiiii', GM_HI],
    'helloooo' => ['helloooo', GM_HI],
    'السلام عليكم with tashkeel' => ['السَّلامُ عَلَيْكُم', GM_SALAM],
    'سلام!!' => ['سلام!!', GM_SALAM],
    'مسا الخير' => ['مسا الخير', 'مساء النور'],
    'صبااااح الخير' => ['صبااااح الخير', 'صباح النور'],
    'greeting then a question' => ['صباح الخير، عايزة أعرف السعر', 'صباح النور'],
    'أهلا وسهلا' => ['أهلا وسهلا', GM_HI],
]);

it('mirrors nothing when she did not open with a greeting', function (string $said) {
    expect(gmMirror($said))->toBeNull();
})->with([
    'a plain question' => ['التوصيل بياخد كام يوم؟'],
    'a price question' => ['الفستان ده بكام؟'],
    'a greeting in the middle' => ['عايزة أعرف السعر يا هاي'],
    'a word that merely starts like سلام' => ['سلامتك من الشحن المتأخر'],
    'an empty message' => ['   '],
    'thanks' => ['شكرا ليكي'],
]);

it('puts the mirror above the time greeting and the menu on the flow path', function () {
    gmSay('السلام عليكم');

    $bodies = gmBodies();
    expect($bodies[0])->toBe(GM_SALAM."\n".GM_TIME_GREETING)
        ->and(substr_count($bodies[0], GM_SALAM))->toBe(1)
        ->and($bodies[1])->not->toContain(GM_SALAM)
        ->and(Message::where('sender_type', SenderType::Bot->value)->orderBy('id')->get()[1]->buttons)->toHaveCount(7);
});

it('puts the mirror above the time greeting on the agent fast path when the flows are off', function () {
    BotFlow::query()->update(['is_active' => false]);

    gmSay('صباح الفل');

    expect(gmBodies()[0])->toStartWith('صباح الفل والنور'."\n")
        ->and(gmBodies()[0])->toContain(GM_TIME_GREETING)
        ->and(mb_strpos(gmBodies()[0], 'صباح الفل والنور'))->toBeLessThan(mb_strpos(gmBodies()[0], GM_TIME_GREETING));
});

it('mirrors a greeting that comes with a question, once, above the answer', function () {
    BotFlow::query()->update(['is_active' => false]);
    BotRule::query()->update(['is_active' => false]);

    gmSay('السلام عليكم، التوصيل بياخد كام يوم؟');

    $reply = implode("\n", gmBodies());
    expect($reply)->toStartWith(GM_SALAM."\n")
        ->and(substr_count($reply, GM_SALAM))->toBe(1)
        ->and($reply)->toContain('3-5 ايام عمل');
});

it('mirrors a greeting answered by an owner rule too', function () {
    BotFlow::query()->update(['is_active' => false]);

    gmSay('السلام عليكم'); // the seeded «ترحيب (معلومات)» rule answers with store_intro

    $reply = implode("\n", gmBodies());
    expect($reply)->toStartWith(GM_SALAM."\n")
        ->and(substr_count($reply, GM_SALAM))->toBe(1)
        ->and($reply)->toContain('براند ملابس محتشمة');
});

it('never mirrors twice in the same turn, and not at all on a later non-greeting message', function () {
    gmSay('هاي');
    expect(substr_count(implode("\n", gmBodies()), GM_HI))->toBe(1);

    $before = count(gmBodies());
    gmSay('التوصيل بياخد كام يوم؟');

    expect(implode("\n", array_slice(gmBodies(), $before)))->not->toContain(GM_HI);
});

it('adds no extra line at all when nothing matches', function () {
    BotFlow::query()->update(['is_active' => false]);

    gmSay('التوصيل بياخد كام يوم؟');

    expect(gmBodies()[0])->toStartWith(GM_TIME_GREETING);
});

it('lets the owner reword a mirror from the dashboard', function () {
    BotKnowledgeEntry::where('key', 'script.greeting_mirror_salam')->update(['body' => 'وعليكم السلام يا قمر 💜']);

    expect(gmMirror('السلام عليكم'))->toBe('وعليكم السلام يا قمر 💜');

    gmSay('السلام عليكم');
    expect(gmBodies()[0])->toStartWith('وعليكم السلام يا قمر 💜'."\n");
});

it('stops mirroring a greeting whose script the owner turned off', function () {
    BotKnowledgeEntry::where('key', 'script.greeting_mirror_hi')->update(['is_active' => false]);

    expect(gmMirror('هاي'))->toBeNull();

    gmSay('هاي');
    expect(gmBodies()[0])->toBe(GM_TIME_GREETING);
});

it('seeds one editable script per mirror', function () {
    expect(GreetingMirror::scripts())->toHaveCount(8);

    foreach (array_keys(GreetingMirror::OPENERS) as $key) {
        expect(BotKnowledgeEntry::where('key', 'script.'.GreetingMirror::SCRIPT_PREFIX.$key)->where('is_active', true)->exists())
            ->toBeTrue("script.greeting_mirror_{$key} is missing");
    }
});

it('updates the untouched handover scripts and inserts the mirrors only once', function () {
    $migration = require database_path('migrations/2026_09_21_100010_handover_transfer_sentence_and_greeting_mirrors.php');

    // An already-seeded database from before the fix, plus one script the owner reworded herself.
    BotKnowledgeEntry::where('key', 'script.handover_in_hours')->update(['body' => 'تمام ✅ حولتك لحد من الفريق، هيرد عليكي خلال دقايق 🌸']);
    BotKnowledgeEntry::where('key', 'script.handover_no_hours')->update(['body' => 'نص المالك']);
    BotKnowledgeEntry::where('key', 'script.greeting_mirror_salam')->delete();
    BotKnowledgeEntry::where('key', 'script.greeting_mirror_hi')->update(['body' => 'أهلاً يا قمر']);

    $migration->up();
    $migration->up();

    expect(BotKnowledgeEntry::where('key', 'script.handover_in_hours')->value('body'))->toBe('تمام ✅ هيتم تحويلك لموظف خدمة العملاء خلال دقايق 🌸')
        ->and(BotKnowledgeEntry::where('key', 'script.handover_no_hours')->value('body'))->toBe('نص المالك')
        ->and(BotKnowledgeEntry::where('key', 'script.greeting_mirror_salam')->count())->toBe(1)
        ->and(BotKnowledgeEntry::where('key', 'script.greeting_mirror_salam')->value('body'))->toBe(GM_SALAM)
        ->and(BotKnowledgeEntry::where('key', 'script.greeting_mirror_hi')->count())->toBe(1)
        ->and(BotKnowledgeEntry::where('key', 'script.greeting_mirror_hi')->value('body'))->toBe('أهلاً يا قمر');
});
