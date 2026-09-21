<?php

use App\Channels\Adapters\FakeChannelAdapter;
use App\Enums\Platform;
use App\Http\Controllers\Web\TryController;
use App\Models\BotSetting;
use App\Models\BotTestLink;
use App\Models\BotTestSession;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\TestLinks\TestLinkSessions;
use App\TestLinks\TestScope;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

// The tester's side of a public test link (design 2026-09-21 §1/§2/§6).

beforeEach(function () {
    Event::fake();
    Http::preventStrayRequests();
    ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'PAGE1']);
    // The gating, caps and isolation are what these tests are about, not the bot's answer.
    BotSetting::current()->update(['enabled' => false, 'working_hours' => null]);
});

function tryLink(array $attributes = []): BotTestLink
{
    return BotTestLink::create(array_merge([
        'token' => BotTestLink::newToken(),
        'label' => 'تجربة الفريق',
        'is_active' => true,
        'max_messages_per_session' => 60,
    ], $attributes));
}

it('opens an active link on the name prompt and counts the view', function () {
    $link = tryLink();

    $this->get("/try/{$link->token}")
        ->assertOk()
        ->assertSee('noindex, nofollow, noarchive', false)
        // The name prompt itself is drawn by the page's own bundle from this state.
        ->assertSee('&quot;started&quot;:false', false)
        ->assertSee('data-token="'.$link->token.'"', false);

    expect($link->fresh()->views_count)->toBe(1)
        ->and($link->fresh()->last_opened_at)->not->toBeNull();
});

it('counts a refresh loop as one view until the cooldown passes', function () {
    $link = tryLink();

    $this->get("/try/{$link->token}");
    $this->get("/try/{$link->token}");
    $this->get("/try/{$link->token}");

    expect($link->fresh()->views_count)->toBe(1);

    $this->travel(3)->minutes();
    $this->get("/try/{$link->token}");

    expect($link->fresh()->views_count)->toBe(2);
});

it('shows a polite page for a stopped, an expired and an unknown link', function () {
    $stopped = tryLink(['is_active' => false]);
    $expired = tryLink(['expires_at' => now()->subHour()]);

    $this->get("/try/{$stopped->token}")->assertOk()->assertSee('turned off');
    $this->get("/try/{$expired->token}")->assertOk()->assertSee('expired');
    $this->get('/try/'.str_repeat('z', 32))->assertOk()->assertSee("couldn't find", false);

    expect($stopped->fresh()->views_count)->toBe(0)
        ->and($expired->fresh()->views_count)->toBe(0);
});

it('refuses to start, send or reset on a closed link', function () {
    $link = tryLink(['is_active' => false]);

    $this->postJson("/try/{$link->token}/start", ['name' => 'سارة'])->assertStatus(410);
    $this->postJson("/try/{$link->token}/messages", ['text' => 'hi'])->assertStatus(410);
    $this->postJson("/try/{$link->token}/reset")->assertStatus(410);
});

it('starts a run from a name and builds the customer, identity and conversation on a test account', function () {
    $link = tryLink();

    $response = $this->postJson("/try/{$link->token}/start", ['name' => '  سارة  '])->assertOk();

    expect($response->json('started'))->toBeTrue()
        ->and($response->json('session.name'))->toBe('سارة')
        ->and($response->json('session.run_no'))->toBe(1)
        ->and($response->json('session.cap'))->toBe(60);

    $session = BotTestSession::firstOrFail();
    $account = $link->fresh()->channelAccount;

    expect($account->driver)->toBe(TestScope::DRIVER)
        ->and($account->platform)->toBe(Platform::Facebook)
        ->and($account->name)->toContain('تجربة')
        ->and($session->customer->name)->toBe('سارة')
        ->and($session->conversation->channel_account_id)->toBe($account->id)
        ->and($session->conversation->is_test)->toBeTrue()
        ->and($link->fresh()->sessions_count)->toBe(1);

    $identity = app(TestLinkSessions::class)->identity($session);
    expect($identity?->external_id)->toBe('test:'.$session->session_token);
});

it('rejects a name that is too short', function () {
    $link = tryLink();

    $this->postJson("/try/{$link->token}/start", ['name' => 'a'])->assertStatus(422);
});

it('marks every message of a test run as a test message and never sends it anywhere', function () {
    $link = tryLink();
    $this->postJson("/try/{$link->token}/start", ['name' => 'سارة']);
    $this->postJson("/try/{$link->token}/messages", ['text' => 'السلام عليكم'])->assertOk();

    $conversation = Conversation::firstOrFail();

    expect($conversation->messages()->where('is_test', false)->count())->toBe(0)
        ->and($conversation->messages()->count())->toBeGreaterThan(0)
        ->and(FakeChannelAdapter::sent())->toBe([]);
});

it('stops at the message cap with a polite stop and ends the run', function () {
    $link = tryLink(['max_messages_per_session' => 2]);
    $this->postJson("/try/{$link->token}/start", ['name' => 'سارة']);

    $this->postJson("/try/{$link->token}/messages", ['text' => 'one'])->assertOk();
    $this->postJson("/try/{$link->token}/messages", ['text' => 'two'])->assertOk();

    $blocked = $this->postJson("/try/{$link->token}/messages", ['text' => 'three'])->assertStatus(429);

    expect($blocked->json('session.cap_reached'))->toBeTrue()
        ->and(BotTestSession::firstOrFail()->ended_reason)->toBe(BotTestSession::ENDED_CAP);
});

it('throttles a session that polls too fast', function () {
    $link = tryLink();
    $this->postJson("/try/{$link->token}/start", ['name' => 'سارة']);

    // The limit is per run, so the polls all share one bucket once the run exists.
    for ($i = 0; $i < TryController::POLLS_PER_MINUTE; $i++) {
        $this->getJson("/try/{$link->token}/state")->assertOk();
    }

    $this->getJson("/try/{$link->token}/state")->assertStatus(429);
});

it('lets a tester keep talking while the 3 s poll runs: polls never spend the write budget', function () {
    // The page polls 20 times a minute on its own; counting that against the write
    // limit throttled a tester who had only been sitting there watching.
    $link = tryLink();
    $this->postJson("/try/{$link->token}/start", ['name' => 'سارة']);

    for ($i = 0; $i < TryController::PER_MINUTE; $i++) {
        $this->getJson("/try/{$link->token}/state")->assertOk();
    }

    $this->postJson("/try/{$link->token}/messages", ['text' => 'عايزة أرجّع'])->assertOk();
});

it('refuses to serve a link that is full', function () {
    $link = tryLink(['max_sessions' => 1]);
    $this->postJson("/try/{$link->token}/start", ['name' => 'سارة'])->assertOk();

    // A second browser, with no run of its own, cannot open one.
    $this->flushSession();
    $this->postJson("/try/{$link->token}/start", ['name' => 'منى'])->assertStatus(429);
});

it('keeps the finished run for the report and opens a numbered new one on start over', function () {
    $link = tryLink();
    $this->postJson("/try/{$link->token}/start", ['name' => 'سارة']);
    $this->postJson("/try/{$link->token}/messages", ['text' => 'أول جلسة']);

    $first = BotTestSession::firstOrFail();

    $response = $this->postJson("/try/{$link->token}/reset")->assertOk();

    expect($response->json('session.run_no'))->toBe(2)
        ->and($response->json('session.name'))->toBe('سارة')
        ->and($response->json('messages'))->toBe([]);

    $first->refresh();

    expect($first->ended_reason)->toBe(BotTestSession::ENDED_RESET)
        ->and($first->ended_at)->not->toBeNull()
        ->and(BotTestSession::count())->toBe(2)
        ->and(BotTestSession::latest('id')->first()->conversation_id)->not->toBe($first->conversation_id)
        ->and($link->fresh()->sessions_count)->toBe(2);
});

it('ends every running session the moment its link is stopped', function () {
    $link = tryLink();
    $this->postJson("/try/{$link->token}/start", ['name' => 'سارة']);

    app(TestLinkSessions::class)->endAllFor($link);

    expect(BotTestSession::firstOrFail()->ended_reason)->toBe(BotTestSession::ENDED_LINK_STOPPED);

    $link->update(['is_active' => false]);

    $this->getJson("/try/{$link->token}/state")->assertOk()->assertJsonPath('open', false);
});

it('never lets a tester read another tester\'s run', function () {
    $link = tryLink();
    $this->postJson("/try/{$link->token}/start", ['name' => 'سارة']);
    $this->postJson("/try/{$link->token}/messages", ['text' => 'سر سارة']);
    $sara = BotTestSession::firstOrFail();

    // A different browser: its own run, and no way to reach Sara's.
    $this->flushSession();
    $this->postJson("/try/{$link->token}/start", ['name' => 'منى'])->assertOk();

    $mine = $this->getJson("/try/{$link->token}/state")->assertOk();

    expect(collect($mine->json('messages'))->pluck('body'))->not->toContain('سر سارة');

    $this->postJson("/try/{$link->token}/messages", ['text' => 'hi', 'session' => $sara->session_token])
        ->assertStatus(403);
});

it('refuses every endpoint to a browser that never started', function () {
    $link = tryLink();

    $this->postJson("/try/{$link->token}/messages", ['text' => 'hi'])->assertStatus(403);
    $this->postJson("/try/{$link->token}/reset")->assertStatus(403);
    $this->getJson("/try/{$link->token}/state")->assertOk()->assertJsonPath('started', false);
});
