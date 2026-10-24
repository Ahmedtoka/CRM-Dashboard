<?php

use App\Enums\MessageDirection;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Enums\UserRole;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\Shift;
use App\Models\ShiftMember;
use App\Models\SupportCase;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Inertia;

/*
 * «سرعة الشاشة» (round 2026-10-24): the moderator's three hot screens stay within a query budget
 * that does not grow with the data — the inbox list page, the thread (detail + messages page) and
 * her desk (`/queue/me`). Each measurement prints queries / bytes / ms to STDERR so a run doubles
 * as the before/after record (docs/round-2026-10-24/screen-speed-x3.md).
 */

beforeEach(function () {
    // Inside the morning shift template (10:00–18:00 Cairo), so the factory shift is the open one.
    Carbon::setTestNow(Carbon::parse('2026-10-24 12:00', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true]);

    $this->supervisor = User::factory()->create(['role' => UserRole::Supervisor, 'last_seen_at' => now()]);
    $this->mod = User::factory()->create(['role' => UserRole::Moderator, 'last_seen_at' => now()]);
    $this->mod2 = User::factory()->create(['role' => UserRole::Moderator, 'last_seen_at' => now()]);
    foreach ([$this->supervisor, $this->mod, $this->mod2] as $u) {
        $u->userPlatforms()->create(['platform' => Platform::Facebook]);
        $u->userPlatforms()->create(['platform' => Platform::Instagram]);
    }
    $this->account = ChannelAccount::factory()->create(['platform' => Platform::Facebook]);
    $this->tags = Tag::factory()->count(5)->create();

    $this->shift = Shift::factory()->create(['leader_user_id' => $this->supervisor->id]);
    ShiftMember::factory()->for($this->shift)->create(['user_id' => $this->supervisor->id]);
    $this->member = ShiftMember::factory()->for($this->shift)->create(['user_id' => $this->mod->id, 'status' => 'busy']);
    ShiftMember::factory()->for($this->shift)->create(['user_id' => $this->mod2->id]);
});

/** Real chats are Arabic: the bodies decide the payload size (JSON escapes a letter as 6 bytes). */
const SPEED_BODIES = [
    'السلام عليكم، عايزة أعرف الطلب بتاعي وصل فين لأني مستنية من أسبوع والمندوب مكلمنيش لحد دلوقتي',
    'أهلاً بيكي، الطلب خرج من المخزن امبارح وهيوصلك خلال يومين إن شاء الله، لو حصل أي تأخير قوليلي',
    'طيب شكراً، وبالنسبة للمقاس لو مطلعش مناسب أقدر أستبدله؟ وهل الاستبدال بفلوس زيادة؟',
];

/**
 * `$n` conversations with a customer, two tags, ten messages each (customer / moderator / bot, the
 * moderator's ones every third with a stored attachment), a responder and a lock on some rows.
 *
 * @return Collection<int, Conversation>
 */
function speedConversations(int $n): Collection
{
    $t = test();
    $mods = [$t->mod, $t->mod2];
    $rows = Conversation::factory()->count($n)->for($t->account, 'channelAccount')->create();

    foreach ($rows->values() as $i => $c) {
        $mod = $mods[$i % 2];
        $c->tags()->attach($t->tags->slice($i % 4, 2)->pluck('id'));
        $messages = Message::factory()->count(10)->for($c)->sequence(
            ['direction' => MessageDirection::In, 'sender_type' => SenderType::Customer],
            ['direction' => MessageDirection::Out, 'sender_type' => SenderType::User, 'user_id' => $mod->id],
            ['direction' => MessageDirection::Out, 'sender_type' => SenderType::Bot],
        )->create(['created_at' => now()->subMinutes(60 - $i % 50), 'body' => SPEED_BODIES[$i % count(SPEED_BODIES)]]);
        foreach ($messages->filter(fn (Message $m, int $k) => $k % 3 === 1 && $k % 2 === 1) as $m) {
            MessageAttachment::factory()->stored()->create(['message_id' => $m->id]);
        }
        $c->forceFill([
            'first_responder_id' => $mod->id,
            'last_responder_id' => $mod->id,
            'locked_by_id' => $i % 5 === 0 ? $mod->id : null,
            'locked_until' => $i % 5 === 0 ? now()->addMinutes(2) : null,
            'last_message_at' => now()->subMinutes($i % 50),
            'last_customer_message_at' => now()->subMinutes($i % 50 + 1),
            'handover_category' => $i % 3 === 0 ? 'order_status' : null,
            'unread_count' => $i % 3,
        ])->save();
    }

    return $rows;
}

/**
 * `$n` open windows of the moderator on the given conversations (window 1..n), plus an open
 * support case on every other one.
 *
 * @param  Collection<int, Conversation>  $conversations
 * @return Collection<int, QueueEntry>
 */
function speedWindows(Collection $conversations, int $n): Collection
{
    $t = test();
    $entries = new Collection;

    foreach ($conversations->take($n)->values() as $i => $c) {
        $e = QueueEntry::factory()->create([
            'conversation_id' => $c->id, 'shift_id' => $t->shift->id, 'shift_member_id' => $t->member->id,
            'assigned_user_id' => $t->mod->id, 'status' => 'active', 'window_no' => $i + 1,
            'called_at' => now()->subMinutes(10), 'delivered_at' => now()->subMinutes(10),
            'last_agent_message_at' => now()->subMinutes(2), 'awaiting_reply_since' => $i % 2 === 0 ? now()->subMinute() : null,
            'bot_summary' => ['topic' => 'استفسار عن الطلب', 'lines' => ['عايزة تعرف الطلب وصل فين']],
        ]);
        $c->forceFill(['assignee_id' => $t->mod->id, 'queue_entry_id' => $e->id, 'handler' => 'human', 'last_customer_message_at' => now()->subMinutes(5)])->save();
        if ($i % 2 === 0) {
            SupportCase::factory()->create(['conversation_id' => $c->id, 'customer_id' => $c->customer_id]);
        }
        $entries->push($e);
    }

    return $entries;
}

/** Runs one request with the query log on; prints and returns queries / bytes / ms. @return array{queries:int,bytes:int,ms:float,log:array,content:string} */
function speedMeasure(string $label, callable $request): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $start = hrtime(true);
    /** @var TestResponse $res */
    $res = $request();
    $ms = (hrtime(true) - $start) / 1e6;
    $log = DB::getQueryLog();
    DB::disableQueryLog();
    $res->assertOk();
    $bytes = strlen((string) $res->getContent());

    fwrite(STDERR, sprintf("\n[screen-speed] %-34s queries=%3d  bytes=%7d  ms=%7.1f", $label, count($log), $bytes, $ms));
    // SCREEN_SPEED_DUMP=1 lists every statement: the way to find the one that repeats per row.
    if (getenv('SCREEN_SPEED_DUMP')) {
        foreach ($log as $q) {
            fwrite(STDERR, "\n    ".mb_substr($q['query'], 0, 220));
        }
    }

    return ['queries' => count($log), 'bytes' => $bytes, 'ms' => $ms, 'log' => $log, 'content' => (string) $res->getContent()];
}

/** The presence heartbeat (TrackPresence, once a minute) and the settings row are not part of the screens. */
function speedWarmUp(User $u): void
{
    // The first list of a process also fills two per-process memos (the intent labels, the load-test channel ids).
    test()->actingAs($u)->getJson('/inbox/conversations/counts')->assertOk();
    test()->actingAs($u)->getJson('/inbox/conversations')->assertOk();
}

it('serves the inbox list, the thread and my desk within fixed query budgets on 50 conversations', function () {
    $conversations = speedConversations(50);
    $entries = speedWindows($conversations, 5);
    $one = $conversations->get(1);
    speedWarmUp($this->mod);

    $list = speedMeasure('GET /inbox/conversations (50)', fn () => $this->actingAs($this->mod)->getJson('/inbox/conversations'));
    // The asset version Inertia expects (the Vite manifest hash when a build exists, else empty): a stale one answers 409.
    $page = speedMeasure('GET /inbox (Inertia page)', fn () => $this->actingAs($this->mod)->get('/inbox', ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) Inertia::getVersion()]));
    $show = speedMeasure('GET /inbox/conversations/{id}', fn () => $this->actingAs($this->mod)->getJson("/inbox/conversations/{$one->id}"));
    $messages = speedMeasure('GET .../{id}/messages', fn () => $this->actingAs($this->mod)->getJson("/inbox/conversations/{$one->id}/messages"));
    $me = speedMeasure('GET /queue/me (5 windows)', fn () => $this->actingAs($this->mod)->getJson('/queue/me'));
    fwrite(STDERR, "\n");

    // Before the round: list 6 / page 18 / show 10 / messages 4 / me 20 (me grew by one settings read per window).
    expect($list['queries'])->toBeLessThanOrEqual(7)
        ->and($page['queries'])->toBeLessThanOrEqual(17)
        ->and($show['queries'])->toBeLessThanOrEqual(12)
        ->and($messages['queries'])->toBeLessThanOrEqual(5)
        ->and($me['queries'])->toBeLessThanOrEqual(14);

    // Web JSON carries Arabic as UTF-8 (2 bytes a letter, not the 6 of an escape): 42.5 KB → 33.7 KB for this list page.
    $escapedArabic = '\u06';
    expect($list['content'])->not->toContain($escapedArabic)
        ->and($show['content'])->not->toContain($escapedArabic)
        ->and($messages['content'])->not->toContain($escapedArabic)
        ->and($me['content'])->not->toContain($escapedArabic)
        ->and($list['content'])->toContain('السلام عليكم')
        ->and(count($this->actingAs($this->mod)->getJson('/inbox/conversations')->json('data')))->toBe(30);
})->group('speed');

it('does not add queries per row: the list, the thread and the desk cost the same on 10, 50 and 100 rows', function () {
    $small = speedConversations(10);
    speedWindows($small, 2);
    speedWarmUp($this->mod);
    $one = $small->first();

    $list10 = speedMeasure('list (10 rows)', fn () => $this->actingAs($this->mod)->getJson('/inbox/conversations'));
    $me2 = speedMeasure('queue/me (2 windows)', fn () => $this->actingAs($this->mod)->getJson('/queue/me'));

    $more = speedConversations(40);
    speedWindows($more, 8);
    $list50 = speedMeasure('list (50 rows, page of 30)', fn () => $this->actingAs($this->mod)->getJson('/inbox/conversations'));
    $me10 = speedMeasure('queue/me (10 windows)', fn () => $this->actingAs($this->mod)->getJson('/queue/me'));

    speedConversations(50);
    $list100 = speedMeasure('list (100 rows, page of 30)', fn () => $this->actingAs($this->mod)->getJson('/inbox/conversations'));

    // A thread with 10 messages and one with 50: the same number of queries.
    $show10 = speedMeasure('show (10 messages)', fn () => $this->actingAs($this->mod)->getJson("/inbox/conversations/{$one->id}"));
    $messages10 = speedMeasure('messages (10)', fn () => $this->actingAs($this->mod)->getJson("/inbox/conversations/{$one->id}/messages"));
    Message::factory()->count(40)->for($one)->sequence(
        ['direction' => MessageDirection::In, 'sender_type' => SenderType::Customer],
        ['direction' => MessageDirection::Out, 'sender_type' => SenderType::User, 'user_id' => $this->mod2->id],
    )->create();
    $show50 = speedMeasure('show (50 messages)', fn () => $this->actingAs($this->mod)->getJson("/inbox/conversations/{$one->id}"));
    $messages50 = speedMeasure('messages (50)', fn () => $this->actingAs($this->mod)->getJson("/inbox/conversations/{$one->id}/messages"));
    fwrite(STDERR, "\n");

    expect($list50['queries'])->toBe($list10['queries'])
        ->and($list100['queries'])->toBe($list50['queries'])
        ->and($me10['queries'])->toBe($me2['queries'])
        ->and($show50['queries'])->toBe($show10['queries'])
        ->and($messages50['queries'])->toBe($messages10['queries'])
        ->and(count($this->actingAs($this->mod)->getJson('/queue/me')->json('data.entries')))->toBe(10);
})->group('speed');
