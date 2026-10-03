<?php

use App\Enums\ConversationPriority;
use App\Enums\ConversationSource;
use App\Enums\ConversationStatus;
use App\Enums\Handler;
use App\Enums\Platform;
use App\Enums\UserRole;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\User;

/*
 * Task 4a (UI overhaul spec §1.2): status / queue / assignee / flags filters on GET /inbox/conversations.
 * Every row is built with known ids; each test asserts the exact id set.
 */

function v2Conv(string $key, array $attrs = [], Platform $platform = Platform::Facebook): Conversation
{
    $c = Conversation::factory()
        ->for(ChannelAccount::factory()->state(['platform' => $platform]), 'channelAccount')
        ->create(array_merge(['status' => ConversationStatus::Open, 'handler' => Handler::Bot], $attrs));
    $GLOBALS['v2rows'][$key] = $c->id;

    return $c;
}

function v2Queue(string $key, array $entry, array $conv = []): Conversation
{
    $c = v2Conv($key, array_merge(['handler' => Handler::Human], $conv));
    $e = QueueEntry::factory()->create(array_merge(['conversation_id' => $c->id], $entry));
    $c->forceFill(['queue_entry_id' => $e->id])->save();

    return $c;
}

/** @param list<string> $keys */
function v2Ids(array $keys): array
{
    $ids = array_map(fn ($k) => $GLOBALS['v2rows'][$k], $keys);
    sort($ids);

    return $ids;
}

function v2Get(User $u, string $query): array
{
    $ids = test()->actingAs($u)->getJson('/inbox/conversations?'.$query)->assertOk()->json('data.*.id');
    sort($ids);

    return $ids;
}

beforeEach(function () {
    $GLOBALS['v2rows'] = [];
    $this->admin = User::factory()->create(['role' => UserRole::Admin]);
    $this->a = User::factory()->create(['role' => UserRole::Moderator, 'name' => 'A']);
    $this->b = User::factory()->create(['role' => UserRole::Moderator, 'name' => 'B']);
    foreach ([$this->a, $this->b] as $m) {
        foreach (Platform::cases() as $p) {
            $m->userPlatforms()->create(['platform' => $p]);
        }
    }
    $this->fbOnly = User::factory()->create(['role' => UserRole::Moderator]);
    $this->fbOnly->userPlatforms()->create(['platform' => Platform::Facebook]);

    v2Conv('open_plain', ['handler' => Handler::Human]);
    v2Conv('bot_pending', ['status' => ConversationStatus::Pending]);
    v2Conv('closed', ['status' => ConversationStatus::Resolved]);
    v2Conv('waiting', ['handler' => Handler::Human, 'last_customer_message_at' => now()->subMinutes(10)]);
    // R3: X is assigned to B but A replied last -> B's; Y is unassigned and A replied last -> A's.
    v2Conv('x', ['handler' => Handler::Human, 'assignee_id' => $this->b->id, 'last_responder_id' => $this->a->id]);
    v2Conv('y', ['handler' => Handler::Human, 'last_responder_id' => $this->a->id]);
    v2Conv('ig', ['handler' => Handler::Human, 'last_responder_id' => $this->a->id], Platform::Instagram);
    v2Conv('ad', ['source' => ConversationSource::Ad]);
    v2Conv('ad_spam', ['source' => ConversationSource::Ad, 'priority' => ConversationPriority::Spam]);
    v2Queue('q_wait', ['status' => 'waiting', 'priority' => 'live']);
    v2Queue('q_overdue', ['status' => 'active', 'awaiting_reply_since' => now()->subMinutes(9), 'apology_sent_at' => now()->subMinutes(2)]);
    v2Queue('q_called', ['status' => 'called']);
    v2Queue('q_returning', ['status' => 'waiting', 'priority' => 'returning']);
    v2Queue('q_closed', ['status' => 'closed', 'priority' => 'returning']);
});

it('filters by status open / resolved / closed / pending', function () {
    expect(v2Get($this->admin, 'status=open'))->toBe(v2Ids(['open_plain', 'waiting', 'x', 'y', 'ig', 'ad', 'q_wait', 'q_overdue', 'q_called', 'q_returning', 'q_closed']))
        ->and(v2Get($this->admin, 'status=resolved'))->toBe(v2Ids(['closed']))
        ->and(v2Get($this->admin, 'status=closed'))->toBe(v2Ids(['closed']))
        ->and(v2Get($this->admin, 'status=pending'))->toBe(v2Ids(['bot_pending']));
});

it('filters by status waiting, and legacy filter=waiting is the same', function () {
    expect(v2Get($this->admin, 'status=waiting'))->toBe(v2Ids(['waiting']))
        ->and(v2Get($this->admin, 'filter=waiting'))->toBe(v2Get($this->admin, 'status=waiting'));
});

it('filters by status bot and with_moderator', function () {
    expect(v2Get($this->admin, 'status=bot'))->toBe(v2Ids(['bot_pending', 'ad']))
        ->and(v2Get($this->admin, 'status=with_moderator'))->toBe(v2Ids(['x', 'y', 'ig']));
});

it('filters by queue state off the queue entry', function () {
    expect(v2Get($this->admin, 'queue=waiting'))->toBe(v2Ids(['q_wait', 'q_returning']))
        ->and(v2Get($this->admin, 'queue=window'))->toBe(v2Ids(['q_overdue', 'q_called']))
        ->and(v2Get($this->admin, 'queue=overdue'))->toBe(v2Ids(['q_overdue']))
        ->and(v2Get($this->admin, 'queue=returning'))->toBe(v2Ids(['q_returning']));
});

it('keeps the queue filter honest when the queue is off', function () {
    QueueSetting::current()->update(['enabled' => false]);

    expect(v2Get($this->admin, 'queue=waiting'))->toBe(v2Ids(['q_wait', 'q_returning']));
});

it('filters by assignee with the R3 rule, me and none', function () {
    expect(v2Get($this->admin, 'assignee='.$this->b->id))->toBe(v2Ids(['x']))
        ->and(v2Get($this->admin, 'assignee='.$this->a->id))->toBe(v2Ids(['y', 'ig']))
        ->and(v2Get($this->a, 'assignee=me'))->toBe(v2Ids(['y', 'ig']))
        ->and(v2Get($this->b, 'assignee=me'))->toBe(v2Ids(['x']))
        ->and(v2Get($this->admin, 'assignee=none'))->toBe(v2Ids([
            'open_plain', 'bot_pending', 'closed', 'waiting', 'ad', 'q_wait', 'q_overdue', 'q_called', 'q_returning', 'q_closed',
        ]));
});

it('ANDs the flags, spam only shown when asked for', function () {
    expect(v2Get($this->admin, 'flags=ad,spam'))->toBe(v2Ids(['ad_spam']))
        ->and(v2Get($this->admin, 'flags=ad'))->toBe(v2Ids(['ad']))
        ->and(v2Get($this->admin, 'filter=ad'))->toBe(v2Ids(['ad']));
});

it('ANDs params together', function () {
    expect(v2Get($this->admin, 'status=with_moderator&assignee='.$this->a->id.'&platform=facebook'))->toBe(v2Ids(['y']));
});

it('never shows a facebook-only moderator an instagram row under any filter', function (string $query) {
    $ids = v2Get($this->fbOnly, str_replace('{a}', (string) $this->a->id, $query));

    expect($ids)->not->toContain($GLOBALS['v2rows']['ig']);
})->with([
    '', 'status=open', 'status=with_moderator', 'assignee={a}', 'assignee=none', 'queue=waiting',
    'flags=ad,spam', 'status=bot', 'status=waiting', 'platform=instagram',
]);

it('rejects unknown values with 422', function (string $query) {
    $this->actingAs($this->admin)->getJson('/inbox/conversations?'.$query)->assertStatus(422);
})->with(['status=nope', 'queue=x', 'assignee=abc', 'flags=ad,nope', 'filter=nope']);
