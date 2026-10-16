<?php

use App\Enums\Handler;
use App\Enums\Platform;
use App\Enums\UserRole;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\ConversationOutcome;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\DeviceToken;
use App\Models\Message;
use App\Models\Order;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\QuickReply;
use App\Models\Shift;
use App\Models\ShiftMember;
use App\Models\SupportCase;
use App\Models\Tag;
use App\Models\User;
use App\Push\Drivers\FakePushSender;
use App\Queue\QueueRouter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;

/*
 * End-to-end walk of docs/mobile-app/API-CONTRACT.md (outer repo) with a real Sanctum token from
 * POST auth/login: one moderator (facebook + whatsapp) goes through a whole shift the way the app
 * does, and every step asserts the response keys the app reads, not only the status.
 */

const CT_PASSWORD = 'contract-walk-1';

const CT_USER_KEYS = ['id', 'name', 'email', 'role', 'color', 'locale', 'is_active', 'platforms', 'last_seen_at'];
const CT_AUTH_KEYS = ['token', 'token_type', 'expires_at', 'realtime', 'user'];
const CT_REALTIME_KEYS = ['driver', 'key', 'host', 'port', 'scheme'];
const CT_PAYLOAD_KEYS = ['enabled', 'member', 'entries', 'settings', 'leader_user_id', 'attendance', 'server_time'];
const CT_MEMBER_KEYS = ['id', 'shift_id', 'user', 'status', 'online', 'cap', 'open_count', 'windows', 'break_at', 'break_started_at', 'break_ends_at', 'joined_at', 'today'];
const CT_ENTRY_KEYS = ['id', 'ticket', 'status', 'priority', 'kind', 'platform', 'customer', 'conversation_id', 'assigned_user_id', 'window_no', 'enqueued_at', 'delivered_at', 'bot_summary', 'close_reason', 'silence_left_seconds', 'silence_warned', 'open_case_id'];
const CT_CONVERSATION_KEYS = ['id', 'platform', 'status', 'priority', 'queue', 'handler', 'needs_human', 'unread_count', 'last_message_at', 'last_customer_message_at', 'last_message_preview', 'customer', 'tags', 'assignee', 'queue_entry', 'queue_state', 'open_case_id', 'can'];
const CT_MESSAGE_KEYS = ['id', 'conversation_id', 'direction', 'sender_type', 'user', 'body', 'attachments', 'status', 'error', 'is_template', 'created_at'];
const CT_NOTE_KEYS = ['id', 'conversation_id', 'body', 'mentions', 'user', 'created_at'];
const CT_CASE_KEYS = ['id', 'type', 'type_label', 'status', 'priority', 'order_id', 'order_number', 'summary_header', 'summary_sections', 'data', 'items', 'photos', 'policy_notes', 'assigned_to', 'conversation_id', 'customer', 'created_at', 'closed_at'];

/** A request as the app sends it: bearer token, JSON, and a fresh guard (the test app caches the resolved user otherwise). */
function ctCall(string $method, string $uri, ?string $token, array $body = []): TestResponse
{
    app('auth')->forgetGuards();
    $t = test();
    if ($token !== null) {
        $t->withToken($token);
    } else {
        $t->withoutToken();
    }

    return $t->json($method, '/api/v1/'.ltrim($uri, '/'), $body);
}

function ctKeys(array $actual, array $expected, string $where): void
{
    foreach ($expected as $key) {
        expect(array_key_exists($key, $actual))->toBeTrue("{$where}: missing key '{$key}'");
    }
}

/** The moderator, her open shift, a waiting WhatsApp customer and the rest of the world the walk needs. */
function ctWorld(): array
{
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    config([
        'crm.push.driver' => 'fake',
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'pub-key',
        'broadcasting.connections.reverb.secret' => 'server-only-secret',
        'broadcasting.connections.reverb.app_id' => '42',
        'broadcasting.connections.reverb.options.host' => '127.0.0.1',
        'broadcasting.connections.reverb.options.port' => 8080,
        'broadcasting.connections.reverb.options.scheme' => 'http',
        'crm.mobile.reverb' => ['host' => '10.0.2.2', 'port' => 8090, 'scheme' => 'http'],
    ]);
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true]);
    $shift = Shift::factory()->create();

    $mod = User::factory()->create(['role' => UserRole::Moderator, 'name' => 'Salma', 'password' => bcrypt(CT_PASSWORD), 'locale' => 'ar', 'last_seen_at' => now()]);
    foreach (['facebook', 'whatsapp'] as $p) {
        $mod->userPlatforms()->create(['platform' => $p]);
    }
    // A teammate she mentions in a note; she has a phone registered.
    $mate = User::factory()->create(['role' => UserRole::Moderator, 'name' => 'Mona', 'locale' => 'ar']);
    $mate->userPlatforms()->create(['platform' => 'whatsapp']);
    DeviceToken::factory()->create(['user_id' => $mate->id]);

    $acc = ChannelAccount::factory()->create(['platform' => Platform::WhatsApp]);
    $cust = Customer::factory()->create(['name' => 'Nour Ali']);
    CustomerIdentity::factory()->for($cust)->create(['platform' => Platform::WhatsApp]);
    $conv = Conversation::factory()->for($cust)->for($acc, 'channelAccount')->create([
        'platform' => Platform::WhatsApp, 'handler' => Handler::Human, 'needs_human' => true,
        'last_customer_message_at' => now()->subMinute(), 'last_message_at' => now()->subMinute(),
    ]);
    Message::factory()->for($conv)->create(['direction' => 'in', 'sender_type' => 'customer', 'body' => 'عايزة أسأل على مقاس']);
    $entry = QueueEntry::factory()->create(['conversation_id' => $conv->id, 'status' => 'waiting', 'priority' => 'live', 'enqueued_at' => now()->subMinutes(2)]);

    $case = SupportCase::factory()->create(['conversation_id' => $conv->id]);
    $reply = QuickReply::factory()->create(['body' => 'أهلاً {customer_name}، معاكي {agent_name}']);
    $tag = Tag::query()->create(['name' => 'VIP', 'color' => '#1877F2']);

    return compact('shift', 'mod', 'mate', 'conv', 'entry', 'case', 'reply', 'tag');
}

it('walks the whole mobile contract with a real token', function () {
    $w = ctWorld();
    $mod = $w['mod'];
    $conv = $w['conv'];

    // ── login ──
    $login = ctCall('POST', 'auth/login', null, ['email' => $mod->email, 'password' => CT_PASSWORD, 'device_name' => 'Pixel 7a'])->assertOk()->json();
    ctKeys($login, CT_AUTH_KEYS, 'login');
    ctKeys($login['user'], CT_USER_KEYS, 'login.user');
    ctKeys($login['realtime'], CT_REALTIME_KEYS, 'login.realtime');
    expect($login['token_type'])->toBe('Bearer')
        ->and($login['user']['id'])->toBe($mod->id)
        ->and($login['realtime'])->toBe(['driver' => 'reverb', 'key' => 'pub-key', 'host' => '10.0.2.2', 'port' => 8090, 'scheme' => 'http'])
        ->and(Carbon::parse($login['expires_at'])->equalTo(now()->addDays(30)))->toBeTrue()
        ->and(json_encode($login))->not->toContain('server-only-secret');
    $token = $login['token'];
    expect(PersonalAccessToken::findToken($token)->abilities)->toBe(['mobile']);

    // ── me ──
    $me = ctCall('GET', 'me', $token)->assertOk()->json();
    ctKeys($me, ['data', 'realtime'], 'me');
    ctKeys($me['data'], [...CT_USER_KEYS, 'preferences', 'token_expires_at'], 'me.data');
    expect($me['data']['token_expires_at'])->toBe($login['expires_at'])
        ->and($me['realtime'])->toBe($login['realtime'])
        ->and(collect($me['data']['platforms'])->sort()->values()->all())->toBe(['facebook', 'whatsapp']);

    // ── devices ──
    $fcm = 'ct-device:'.Str::random(140);
    $dev = ctCall('POST', 'devices', $token, ['token' => $fcm, 'platform' => 'android', 'app_version' => '1.0.0', 'locale' => 'ar'])->assertCreated()->json();
    ctKeys($dev['data'], ['id', 'platform', 'app_version', 'last_registered_at'], 'devices');
    ctCall('POST', 'devices', $token, ['token' => $fcm, 'platform' => 'android'])->assertOk()->assertJsonPath('data.id', $dev['data']['id']);
    expect(DeviceToken::query()->where('user_id', $mod->id)->sole()->personal_access_token_id)->toBe(PersonalAccessToken::findToken($token)->id);

    // ── queue/me before check-in ──
    $desk = ctCall('GET', 'queue/me', $token)->assertOk()->json('data');
    ctKeys($desk, CT_PAYLOAD_KEYS, 'queue/me');
    ctKeys($desk['attendance'], ['eligible', 'shift_open', 'shift_name', 'next_starts_at'], 'queue/me.attendance');
    ctKeys($desk['settings'], ['silence_warn_seconds', 'silence_close_seconds', 'windows_per_moderator', 'break_minutes'], 'queue/me.settings');
    expect($desk['enabled'])->toBeTrue()->and($desk['member'])->toBeNull()->and($desk['attendance']['shift_open'])->toBeTrue();

    // ── check-in ──
    $in = ctCall('POST', 'queue/me/check-in', $token)->assertOk()->json('data');
    ctKeys($in, CT_PAYLOAD_KEYS, 'check-in');
    ctKeys($in['member'], CT_MEMBER_KEYS, 'check-in.member');
    expect($in['member']['user']['id'])->toBe($mod->id);

    // ── the router gives her the waiting customer ──
    app(QueueRouter::class)->run('contract');
    $entry = $w['entry']->fresh();
    expect($entry->assigned_user_id)->toBe($mod->id)->and($entry->status)->toBe('active');
    $desk = ctCall('GET', 'queue/me', $token)->assertOk()->json('data');
    expect($desk['entries'])->toHaveCount(1);
    ctKeys($desk['entries'][0], CT_ENTRY_KEYS, 'queue/me.entries.0');
    expect($desk['entries'][0]['id'])->toBe($entry->id)->and($desk['entries'][0]['conversation_id'])->toBe($conv->id);
    // Her new window reached her phone.
    app(FakePushSender::class)->assertSentTo($mod, 'queue.assigned');

    // ── inbox list ──
    $list = ctCall('GET', 'conversations', $token)->assertOk()->json();
    ctKeys($list, ['data'], 'conversations');
    $row = collect($list['data'])->firstWhere('id', $conv->id);
    expect($row)->not->toBeNull();
    ctKeys($row, CT_CONVERSATION_KEYS, 'conversations.row');
    ctKeys($row['queue_entry'], ['id', 'assigned_user_id', 'priority', 'ticket', 'window_no', 'status'], 'conversations.row.queue_entry');

    // ── thread ──
    $thread = ctCall('GET', "conversations/{$conv->id}", $token)->assertOk()->json();
    ctKeys($thread, ['conversation', 'messages', 'notes', 'customer', 'participants', 'cases', 'window', 'lock'], 'thread');
    ctKeys($thread['messages'][0], CT_MESSAGE_KEYS, 'thread.messages.0');
    ctKeys($thread['window'], ['mode', 'expires_at'], 'thread.window');
    expect(collect($thread['cases'])->pluck('id')->all())->toContain($w['case']->id);

    // ── context ──
    $ctx = ctCall('GET', "conversations/{$conv->id}/context", $token)->assertOk()->json('data');
    ctKeys($ctx, ['outcome', 'handover', 'ad'], 'context');
    ctKeys($ctx['outcome'], ['current', 'source', 'auto'], 'context.outcome');

    // ── saved replies + render ──
    $replies = ctCall('GET', 'quick-replies?platform=whatsapp', $token)->assertOk()->json('data');
    $item = collect($replies)->firstWhere('id', $w['reply']->id);
    expect($item)->not->toBeNull();
    ctKeys($item, ['id', 'shortcut', 'title', 'body', 'platforms', 'scope', 'category', 'attachments'], 'quick-replies.item');
    $rendered = ctCall('POST', "conversations/{$conv->id}/quick-replies/{$w['reply']->id}/render", $token)->assertOk()->json();
    ctKeys($rendered, ['body', 'attachments', 'missing'], 'render');
    expect($rendered['body'])->toContain('Nour')->toContain('Salma');

    // ── send with client_id, then the replay ──
    $clientId = (string) Str::uuid();
    $sent = ctCall('POST', "conversations/{$conv->id}/messages", $token, ['body' => $rendered['body'], 'quick_reply_id' => $w['reply']->id, 'client_id' => $clientId])->assertCreated()->json('data');
    ctKeys($sent, CT_MESSAGE_KEYS, 'messages.store');
    $replay = ctCall('POST', "conversations/{$conv->id}/messages", $token, ['body' => $rendered['body'], 'quick_reply_id' => $w['reply']->id, 'client_id' => $clientId])->assertOk()->json('data');
    expect($replay['id'])->toBe($sent['id'])
        ->and(Message::query()->where('conversation_id', $conv->id)->where('direction', 'out')->where('user_id', $mod->id)->count())->toBe(1);

    // ── note with a mention ──
    $noteClient = (string) Str::uuid();
    $note = ctCall('POST', "conversations/{$conv->id}/notes", $token, ['body' => 'منى، شوفي المقاس ده', 'mentions' => [$w['mate']->id], 'client_id' => $noteClient])->assertCreated()->json('data');
    ctKeys($note, CT_NOTE_KEYS, 'notes.store');
    expect($note['mentions'])->toBe([$w['mate']->id]);
    ctCall('POST', "conversations/{$conv->id}/notes", $token, ['body' => 'منى، شوفي المقاس ده', 'mentions' => [$w['mate']->id], 'client_id' => $noteClient])
        ->assertOk()->assertJsonPath('data.id', $note['id']);
    app(FakePushSender::class)->assertSentTo($w['mate'], 'note.mention');

    // ── close the window ──
    $closed = ctCall('POST', "queue/entries/{$entry->id}/close", $token, ['reason' => 'inquiry', 'outcome' => 'browsing'])->assertOk()->json('data');
    ctKeys($closed, [...CT_ENTRY_KEYS, 'close_reason', 'support_case_id'], 'close');
    expect($closed['status'])->toBe('closed')->and($closed['close_reason'])->toBe('inquiry')->and($closed['support_case_id'])->toBeNull()
        ->and(ConversationOutcome::query()->where('conversation_id', $conv->id)->value('outcome'))->toBe('browsing');
    expect(ctCall('GET', 'queue/me', $token)->assertOk()->json('data.entries'))->toBe([]);

    // ── cases ──
    $cases = ctCall('GET', 'cases', $token)->assertOk()->json();
    ctKeys($cases, ['data', 'meta', 'counts'], 'cases');
    ctKeys($cases['meta'], ['current_page', 'last_page', 'per_page', 'total'], 'cases.meta');
    ctKeys($cases['counts'], ['all', 'new', 'in_progress', 'closed'], 'cases.counts');
    ctKeys($cases['data'][0], CT_CASE_KEYS, 'cases.data.0');
    ctCall('GET', "cases/{$w['case']->id}", $token)->assertOk()->assertJsonPath('data.id', $w['case']->id);

    // ── notifications ──
    $bell = ctCall('GET', 'notifications', $token)->assertOk()->json();
    ctKeys($bell, ['data', 'unread_notifications', 'unread_conversations'], 'notifications');
    expect($bell['data'])->not->toBeEmpty()->and($bell['unread_notifications'])->toBeGreaterThan(0);
    ctKeys($bell['data'][0], ['id', 'type', 'data', 'read_at', 'created_at'], 'notifications.data.0');
    ctCall('POST', 'notifications/read', $token)->assertOk()->assertExactJson(['unread_notifications' => 0]);

    // ── counts + tags ──
    $counts = ctCall('GET', 'conversations/counts', $token)->assertOk()->json();
    ctKeys($counts, ['status', 'queue', 'capped_at'], 'counts');
    $tags = ctCall('GET', 'tags', $token)->assertOk()->json('data');
    expect($tags)->toBe([['id' => $w['tag']->id, 'name' => 'VIP', 'color' => '#1877F2']]);
    $tagged = ctCall('POST', "conversations/{$conv->id}/tags", $token, ['tag_ids' => [$w['tag']->id]])->assertOk()->json('data');
    ctKeys($tagged, CT_CONVERSATION_KEYS, 'conversations.tags');
    expect(collect($tagged['tags'])->pluck('id')->all())->toBe([$w['tag']->id]);

    // ── refresh ──
    $this->travel(25)->days();
    $fresh = ctCall('POST', 'auth/refresh', $token)->assertOk()->json();
    ctKeys($fresh, CT_AUTH_KEYS, 'refresh');
    expect($fresh['token'])->not->toBe($token)->and($fresh['realtime'])->toBe($login['realtime']);
    ctCall('GET', 'me', $token)->assertUnauthorized();
    ctCall('GET', 'me', $fresh['token'])->assertOk()->assertJsonPath('data.id', $mod->id);
    expect(DeviceToken::query()->where('user_id', $mod->id)->sole()->personal_access_token_id)->toBe(PersonalAccessToken::findToken($fresh['token'])->id);
    $token = $fresh['token'];

    // ── logout with the device token ──
    $member = ShiftMember::query()->where('user_id', $mod->id)->sole();
    ctCall('POST', 'auth/logout', $token, ['device_token' => $fcm])->assertOk()->assertExactJson(['data' => ['ok' => true]]);
    expect(DeviceToken::query()->where('user_id', $mod->id)->count())->toBe(0)
        ->and($member->fresh()->status)->toBe('left')
        ->and($mod->tokens()->count())->toBe(0);
    ctCall('GET', 'me', $token)->assertUnauthorized();
});

it('still serves the old app: legacy login, resolve without outcome, orders, cities and provinces', function () {
    $w = ctWorld();
    $mod = $w['mod'];
    $fb = Conversation::factory()->create(['platform' => Platform::Facebook, 'handler' => Handler::Human, 'last_customer_message_at' => now()]);
    Order::factory()->create(['conversation_id' => $fb->id, 'customer_id' => $fb->customer_id, 'platform' => 'facebook', 'created_by_id' => $mod->id]);

    // The old app sends exactly these three fields and reads `token` + `user`.
    $login = ctCall('POST', 'auth/login', null, ['email' => $mod->email, 'password' => CT_PASSWORD, 'device_name' => 'old-app'])->assertOk()->json();
    expect($login)->toHaveKeys(['token', 'user']);
    $token = $login['token'];

    // A token issued before the mobile release (no expiry, abilities *) keeps working.
    $legacy = $mod->createToken('old-phone')->plainTextToken;
    ctCall('GET', 'me', $legacy)->assertOk()->assertJsonPath('data.token_expires_at', null);

    ctCall('POST', "conversations/{$fb->id}/resolve", $token)->assertOk()
        ->assertJsonPath('data.id', $fb->id)->assertJsonPath('data.status', 'resolved');
    expect(ConversationOutcome::query()->where('conversation_id', $fb->id)->value('outcome'))->toBe('unknown');

    $orders = ctCall('GET', 'orders', $token)->assertOk()->json('data');
    expect($orders)->not->toBeEmpty()->and(array_key_exists('shipment', $orders[0]))->toBeTrue()->and($orders[0]['shipment'])->toBeNull();

    ctCall('GET', 'cities', $token)->assertOk();
    ctCall('GET', 'shipping/provinces', $token)->assertOk();
    ctCall('GET', 'shipping/quote?subtotal=100', $token)->assertOk();
});
