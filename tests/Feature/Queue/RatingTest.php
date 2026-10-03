<?php

use App\Bot\Flow\Jobs\RunBotTurn;
use App\Channels\Adapters\WhatsAppAdapter;
use App\Channels\Data\InboundMessageData;
use App\Enums\Platform;
use App\Inbox\InboxIngestor;
use App\Inbox\OutboundService;
use App\Models\BotKnowledgeEntry;
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\CustomerIdentity;
use App\Models\Message;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\Shift;
use App\Models\ShiftMember;
use App\Models\User;
use App\Queue\Jobs\RequestRating;
use App\Queue\RatingService;
use App\Queue\WindowLifecycle;
use App\Support\Emoji;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

// Addendum C4: numbers 1–5, no stars, no emoji in anything sent.
const RT_ASK = 'ممكن تقيّمي خدمتنا من 1 لـ 5؟ (5 = ممتازة)';
const RT_THANKS = 'شكراً على تقييمك، رأيك بيفرق معانا.';

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true]); // review_delay_seconds: the column default, 60
});

/** A customer message through the real ingest (Messenger page PAGE-RT, customer PSID-RT). */
function rtIngest(string $id, string $text, ?string $payload = null, bool $bot = false): ?Message
{
    ChannelAccount::query()->firstWhere('external_id', 'PAGE-RT') ?? ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'PAGE-RT']);
    BotSetting::current()->update(['enabled' => $bot]);

    return app(InboxIngestor::class)->ingestMessage(new InboundMessageData(Platform::Facebook, 'PAGE-RT', 'PSID-RT', 'Mona', $id, $text, CarbonImmutable::now(), payload: $payload));
}

/** Her conversation (a real message at 12:00) in an open window of «منى علي», logged in, allowed on Messenger. */
function rtWindow(): array
{
    $c = rtIngest('first', 'عايزة أسأل عن المقاس')->conversation;
    $shift = Shift::query()->first() ?? Shift::factory()->create();
    $u = User::factory()->create(['name' => 'منى علي', 'last_seen_at' => now()]);
    $u->userPlatforms()->create(['platform' => $c->platform->value]);
    $m = ShiftMember::factory()->for($shift)->create(['user_id' => $u->id, 'status' => 'busy']);
    $e = QueueEntry::factory()->create([
        'conversation_id' => $c->id, 'customer_id' => $c->customer_id, 'shift_id' => $shift->id, 'shift_member_id' => $m->id, 'assigned_user_id' => $u->id,
        'status' => 'active', 'window_no' => 1, 'delivered_at' => now(), 'enqueued_at' => now()->subMinutes(2), 'last_customer_message_at' => now(),
    ]);
    $c->forceFill(['assignee_id' => $u->id, 'queue_entry_id' => $e->id, 'handler' => 'human', 'needs_human' => true])->save();

    return [$e, $u, $c->fresh()];
}

/** Her window closed by «خلصت» ($reason) at 12:00; now is the rating's due time, 12:01. */
function rtClosed(string $reason = 'inquiry'): array
{
    [$e, $u, $c] = rtWindow();
    app(WindowLifecycle::class)->close($e, $reason, $u);
    Carbon::setTestNow(now()->addSeconds(60));

    return [$e->fresh(), $u, $c->fresh()];
}

/** The rating question in this conversation (the bot message with buttons), if any. */
function rtAsk(QueueEntry $e): ?Message
{
    return Message::query()->where('conversation_id', $e->conversation_id)->where('sender_type', 'bot')->whereNotNull('buttons')->latest('id')->first();
}

// ───── When it is asked ─────

it('schedules the rating review_delay_seconds after an inquiry or problem close only', function (string $reason, bool $asked) {
    Bus::fake([RequestRating::class]);
    $u = User::factory()->create();
    $e = QueueEntry::factory()->create(['assigned_user_id' => $u->id, 'status' => 'active', 'window_no' => 1, 'delivered_at' => now()]);
    $e->conversation->update(['queue_entry_id' => $e->id, 'handler' => 'human']);

    app(WindowLifecycle::class)->close($e, $reason, $reason === 'auto' ? null : $u, ['case_type' => 'complaint']);

    if ($asked) {
        Bus::assertDispatched(RequestRating::class, fn (RequestRating $job) => $job->entryId === $e->id
            && $job->closedAt === $e->fresh()->closed_at->toIso8601String()
            && $job->delay->equalTo(now()->addSeconds(60)));
    } else {
        Bus::assertNotDispatched(RequestRating::class);
    }
})->with([
    'inquiry' => ['inquiry', true],
    'problem' => ['problem', true],
    'case' => ['case', false],
    'auto' => ['auto', false],
    'escalation' => ['escalation', false],
    'transfer' => ['transfer', false],
    'no_reply' => ['no_reply', false],
    'cancelled' => ['cancelled', false],
]);

it('releases an early job for the rest of the delay, then asks with five number buttons so the last word is ours', function () {
    [$e, $u, $c] = rtWindow();
    app(WindowLifecycle::class)->close($e, 'inquiry', $u);
    $token = $e->fresh()->closed_at->toIso8601String();

    $job = (new RequestRating($e->id, $token))->withFakeQueueInteractions();
    $job->handle(app(RatingService::class));
    $job->assertReleased(delay: 60);
    expect($e->fresh()->review_requested_at)->toBeNull()
        ->and($job->tries)->toBe(0)
        ->and($job->retryUntil()->getTimestamp())->toBe($e->fresh()->closed_at->copy()->addSeconds(60)->addMinutes(65)->getTimestamp());

    Carbon::setTestNow(now()->addSeconds(60));
    $job = (new RequestRating($e->id, $token))->withFakeQueueInteractions();
    $job->handle(app(RatingService::class));
    $job->assertNotReleased();

    $ask = rtAsk($e);
    expect($ask->body)->toBe(RT_ASK)
        ->and(collect($ask->buttons)->pluck('title')->all())->toBe(['1', '2', '3', '4', '5'])
        ->and($ask->buttons[4]['payload'])->toBe("queue_rating:{$e->id}:5")
        ->and(Emoji::contains(json_encode($ask->buttons, JSON_UNESCAPED_UNICODE).$ask->body))->toBeFalse()
        ->and($e->fresh()->review_requested_at->equalTo(now()))->toBeTrue()
        ->and($e->fresh()->review_message_id)->toBe($ask->id)
        ->and(Message::where('conversation_id', $c->id)->where('direction', 'out')->latest('id')->value('id'))->toBe($ask->id);

    $job = (new RequestRating($e->id, $token))->withFakeQueueInteractions();   // a second run asks nothing
    $job->handle(app(RatingService::class));
    expect(Message::where('conversation_id', $c->id)->whereNotNull('buttons')->count())->toBe(1);
});

it('ignores a job of another close of the entry', function () {
    [$e] = rtClosed();

    (new RequestRating($e->id, $e->closed_at->copy()->subMinute()->toIso8601String()))->withFakeQueueInteractions()->handle(app(RatingService::class));

    expect($e->fresh()->review_requested_at)->toBeNull()->and(rtAsk($e))->toBeNull();
});

it('still asks after a thanks since the close', function () {
    [$e, $u] = rtWindow();
    app(WindowLifecycle::class)->close($e, 'inquiry', $u);
    Carbon::setTestNow(now()->addSeconds(20));
    rtIngest('thanks', 'شكراً ❤️');
    Carbon::setTestNow(now()->addSeconds(40));

    expect(app(RatingService::class)->request($e->fresh()))->toBeTrue()->and(rtAsk($e))->not->toBeNull();
});

it('does not ask once she wrote a real message after the close', function () {
    [$e, $u] = rtWindow();
    app(WindowLifecycle::class)->close($e, 'inquiry', $u);
    Carbon::setTestNow(now()->addSeconds(20));
    rtIngest('back', 'طب والمقاس L موجود؟');                // the bot has her after «خلصت» (addendum C2)
    Carbon::setTestNow(now()->addSeconds(40));

    expect(app(RatingService::class)->skipReason($e->fresh()))->toBe('customer_back')
        ->and(app(RatingService::class)->request($e->fresh()))->toBeFalse()
        ->and($e->fresh()->review_requested_at)->toBeNull()
        ->and(rtAsk($e))->toBeNull();
});

it('asks one customer at most once a day', function () {
    [$e, $u, $c] = rtClosed();
    expect(app(RatingService::class)->request($e))->toBeTrue();

    Carbon::setTestNow(now()->addHours(3));
    $second = QueueEntry::factory()->create(['conversation_id' => $c->id, 'customer_id' => $c->customer_id, 'assigned_user_id' => $u->id,
        'status' => 'closed', 'close_reason' => 'problem', 'closed_at' => now()->subSeconds(60)]);
    expect(app(RatingService::class)->skipReason($second->fresh()))->toBe('asked_today')
        ->and(app(RatingService::class)->request($second))->toBeFalse();

    Carbon::setTestNow(now()->addHours(22));            // 25 hours after the first question
    $c->forceFill(['last_customer_message_at' => now()])->save();   // she wrote today: the reply window is open
    $third = QueueEntry::factory()->create(['conversation_id' => $c->id, 'customer_id' => $c->customer_id, 'assigned_user_id' => $u->id,
        'status' => 'closed', 'close_reason' => 'inquiry', 'closed_at' => now()->subSeconds(60)]);
    expect(app(RatingService::class)->request($third))->toBeTrue();
});

it('does not ask on a test conversation, outside the reply window, with the queue off or the script off', function () {
    [$e, $u, $c] = rtClosed();
    $svc = app(RatingService::class);

    $c->forceFill(['is_test' => true])->save();
    expect($svc->skipReason($e->fresh()))->toBe('test')->and($svc->request($e))->toBeFalse();

    $c->forceFill(['is_test' => false, 'last_customer_message_at' => now()->subDays(2)])->save();
    expect($svc->skipReason($e->fresh()))->toBe('window_closed')->and($svc->request($e))->toBeFalse();

    $c->forceFill(['last_customer_message_at' => now()])->save();
    QueueSetting::current()->update(['enabled' => false]);
    expect($svc->skipReason($e->fresh()))->toBe('queue_off')->and($svc->request($e))->toBeFalse();

    QueueSetting::current()->update(['enabled' => true]);
    BotKnowledgeEntry::where('key', 'script.queue_review_ask')->update(['is_active' => false]);
    expect($svc->request($e))->toBeFalse()->and($e->fresh()->review_requested_at)->toBeNull();

    BotKnowledgeEntry::where('key', 'script.queue_review_ask')->update(['is_active' => true]);
    expect($svc->request($e))->toBeTrue();
});

it('asks nothing when the job runs more than an hour late', function () {
    [$e] = rtClosed();
    Carbon::setTestNow(now()->addMinutes(61));

    expect(app(RatingService::class)->skipReason($e->fresh()))->toBe('late')
        ->and(app(RatingService::class)->request($e))->toBeFalse();
});

it('asks no rating for a close with no moderator', function () {
    [$e, $u, $c] = rtClosed();
    $e->forceFill(['assigned_user_id' => null])->save();

    expect(app(RatingService::class)->skipReason($e->fresh()))->toBe('no_agent');
});

// ───── WhatsApp (addendum C3) ─────

it('sends the WhatsApp rating as one interactive list with rows 1 to 5, whatever the menu style', function () {
    config(['crm.whatsapp_menu_style' => 'buttons']);
    Http::fake(['graph.facebook.com/*' => Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.sent']]])]);
    $account = ChannelAccount::factory()->create(['platform' => 'whatsapp', 'driver' => 'live', 'external_id' => '1098765432', 'credentials' => ['access_token' => 'WA-TOKEN']]);
    $to = CustomerIdentity::factory()->create(['platform' => 'whatsapp', 'external_id' => '201112223334']);
    $e = QueueEntry::factory()->create();

    $result = app(WhatsAppAdapter::class)->sendText($account, $to, RT_ASK, ['quick_replies' => RatingService::buttons($e)]);

    expect($result->success)->toBeTrue();
    Http::assertSentCount(1);
    Http::assertSent(fn (ClientRequest $r) => $r['type'] === 'interactive'
        && $r['interactive']['type'] === 'list'
        && $r['interactive']['body']['text'] === RT_ASK
        && $r['interactive']['action']['sections'][0]['rows'] === array_map(
            fn (int $n) => ['id' => "queue_rating:{$e->id}:{$n}", 'title' => (string) $n],
            range(1, 5),
        ));

    // A bot menu of five keeps the owner's reply-button style.
    Http::fake(['graph.facebook.com/*' => Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.sent']]])]);
    $menu = array_map(fn ($i) => ['title' => "اختيار {$i}", 'payload' => "p{$i}"], range(1, 5));
    app(WhatsAppAdapter::class)->sendText($account, $to, 'اختاري', ['quick_replies' => $menu]);
    Http::assertSent(fn (ClientRequest $r) => ($r['interactive']['type'] ?? null) === 'button');
});

// ───── Her answer ─────

it('stores a button tap as the rating of that close, thanks her, and never reopens, counts unread or wakes the bot', function () {
    Bus::fake([RunBotTurn::class]);
    [$e, $u, $c] = rtClosed();                           // «خلصت» handed her to the bot (addendum C2)
    expect($c->handler->value)->toBe('bot');
    app(RatingService::class)->request($e);
    $unread = $c->fresh()->unread_count;

    rtIngest('tap', '5', "queue_rating:{$e->id}:5", bot: true);

    $e->refresh();
    expect($e->review_stars)->toBe(5)
        ->and($e->reviewed_at->equalTo(now()))->toBeTrue()
        ->and($e->reversed_at)->toBeNull()
        ->and(QueueEntry::where('conversation_id', $c->id)->count())->toBe(1)
        ->and($c->fresh()->unread_count)->toBe($unread)
        ->and(Message::where('conversation_id', $c->id)->where('sender_type', 'bot')->latest('id')->value('body'))->toBe(RT_THANKS);
    Bus::assertNotDispatched(RunBotTurn::class);

    rtIngest('tap-again', '1', "queue_rating:{$e->id}:1", bot: true);   // a second tap changes nothing

    expect($e->fresh()->review_stars)->toBe(5)
        ->and(Message::where('conversation_id', $c->id)->where('body', RT_THANKS)->count())->toBe(1)
        ->and($c->fresh()->unread_count)->toBe($unread);
    Bus::assertNotDispatched(RunBotTurn::class);
});

it('takes a typed digit, an Arabic digit, or stars while the question is the last thing we said', function (string $typed, int $stars) {
    Bus::fake([RunBotTurn::class]);
    [$e, $u, $c] = rtClosed();
    app(RatingService::class)->request($e);

    rtIngest('typed-'.md5($typed), $typed, bot: true);

    expect($e->fresh()->review_stars)->toBe($stars)
        ->and(QueueEntry::where('conversation_id', $c->id)->count())->toBe(1)
        ->and(Message::where('conversation_id', $c->id)->where('body', RT_THANKS)->count())->toBe(1);
    Bus::assertNotDispatched(RunBotTurn::class);
})->with([
    'a digit' => ['4', 4],
    'an Arabic digit' => ['٤', 4],
    'a digit with punctuation and spaces' => [' 5 ! ', 5],
    'an Arabic digit and a full stop' => ['٣.', 3],
    'a digit and «نجوم»' => ['5 نجوم', 5],
    'three stars' => ['⭐⭐⭐', 3],
    'a digit and a star with its variation selector' => ["2⭐\u{FE0F}", 2],
    'two black stars' => ['★★', 2],
]);

it('reads a number out of range or a sentence as a real message', function (string $typed) {
    expect(app(RatingService::class)->typedStars($typed))->toBeNull();
})->with(['0', '6', '٧', '45', 'تمام 5', 'المقاس 5', '⭐⭐⭐⭐⭐⭐', '']);

it('reads a typed digit as a real message once a moderator wrote after the question', function () {
    [$e, $u, $c] = rtClosed();
    app(RatingService::class)->request($e);
    app(OutboundService::class)->sendHuman($c->fresh(), $u, 'لو في أي حاجة أنا موجودة');

    rtIngest('five', '5');

    expect($e->fresh()->review_stars)->toBeNull();
});

it('swallows a tap on a rating button older than a day', function () {
    Bus::fake([RunBotTurn::class]);
    [$e, $u, $c] = rtClosed();
    app(RatingService::class)->request($e);
    Carbon::setTestNow(now()->addHours(25));
    $unread = $c->fresh()->unread_count;

    rtIngest('late-tap', '4', "queue_rating:{$e->id}:4", bot: true);

    expect($e->fresh()->review_stars)->toBeNull()
        ->and($c->fresh()->unread_count)->toBe($unread)
        ->and(QueueEntry::where('conversation_id', $c->id)->count())->toBe(1)
        ->and(Message::where('conversation_id', $c->id)->where('body', RT_THANKS)->count())->toBe(0);
    Bus::assertNotDispatched(RunBotTurn::class);
});

it('leaves a message alone with the queue off and no question asked', function () {
    [$e, $u, $c] = rtClosed();
    QueueSetting::current()->update(['enabled' => false]);
    $unread = $c->fresh()->unread_count;

    rtIngest('five-off', '5');

    expect($e->fresh()->review_stars)->toBeNull()->and($c->fresh()->unread_count)->toBe($unread + 1);
});

// ───── The reworded defaults (addendum C4, migration 200040) ─────

it('rewords the rating scripts still on the old default and leaves an owner-edited one alone', function () {
    $migration = require database_path('migrations/2026_10_01_200040_reword_queue_review_scripts.php');
    BotKnowledgeEntry::where('key', 'script.queue_review_ask')->update(['body' => 'قيّمي خدمة {name} من 1 لـ5 '."\u{1F338}"]);
    BotKnowledgeEntry::where('key', 'script.queue_review_thanks')->update(['body' => 'شكراً جداً يا قمر']);

    $migration->up();
    $migration->up();   // idempotent

    expect(BotKnowledgeEntry::where('key', 'script.queue_review_ask')->value('body'))->toBe(RT_ASK)
        ->and(BotKnowledgeEntry::where('key', 'script.queue_review_thanks')->value('body'))->toBe('شكراً جداً يا قمر');

    BotKnowledgeEntry::where('key', 'script.queue_review_thanks')->update(['body' => 'شكراً لتقييمك']);
    $migration->up();
    expect(BotKnowledgeEntry::where('key', 'script.queue_review_thanks')->value('body'))->toBe(RT_THANKS);

    $migration->down();
    expect(BotKnowledgeEntry::where('key', 'script.queue_review_ask')->value('body'))->toBe('قيّمي خدمة {name} من 1 لـ5')
        ->and(BotKnowledgeEntry::where('key', 'script.queue_review_thanks')->value('body'))->toBe('شكراً لتقييمك');
});

// ───── A failed send leaves no mark, also when the platform fails it later (async) ─────

it('asks again within the day when the earlier question ended failed on the platform', function () {
    [$e, $u, $c] = rtClosed();
    expect(app(RatingService::class)->request($e))->toBeTrue();
    rtAsk($e)->update(['status' => 'failed']); // the platform refused it after the send

    Carbon::setTestNow(now()->addHours(3));
    $c->forceFill(['last_customer_message_at' => now()])->save();
    $second = QueueEntry::factory()->create(['conversation_id' => $c->id, 'customer_id' => $c->customer_id, 'assigned_user_id' => $u->id,
        'status' => 'closed', 'close_reason' => 'problem', 'closed_at' => now()->subSeconds(60)]);

    expect(app(RatingService::class)->skipReason($second->fresh()))->toBeNull()
        ->and(app(RatingService::class)->request($second))->toBeTrue();
});

it('reads a typed digit as a real message when the question ended failed on the platform', function () {
    [$e, $u, $c] = rtClosed();
    app(RatingService::class)->request($e);
    rtAsk($e)->update(['status' => 'failed']);

    rtIngest('five-failed', '5');

    expect($e->fresh()->review_stars)->toBeNull();
});
