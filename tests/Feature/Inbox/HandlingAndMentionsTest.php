<?php

use App\Enums\MessageDirection;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Enums\UserRole;
use App\Events\ConversationUpdated;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake();
    $this->acc = ChannelAccount::factory()->create(['platform' => Platform::Facebook]);
    // `last_customer_message_at` keeps the 24h Messenger reply window open, so the
    // "claim never blocks sending" test can actually exercise a real send — the
    // brief's literal factory call has no customer message at all, which would
    // leave the window CLOSED (422) regardless of the claim/lock behaviour under test.
    $this->conv = Conversation::factory()->for($this->acc, 'channelAccount')->create(['last_message_at' => now(), 'last_customer_message_at' => now()]);
    $this->sara = User::factory()->create(['role' => UserRole::Moderator, 'name' => 'Sara']);
    $this->sara->userPlatforms()->create(['platform' => Platform::Facebook]);
    $this->admin = User::factory()->create(['role' => UserRole::Admin]);
});

it('shows the soft-lock holder, else a human who replied within 30 minutes', function () {
    Message::factory()->create(['conversation_id' => $this->conv->id, 'direction' => MessageDirection::Out, 'sender_type' => SenderType::User,
        'user_id' => $this->sara->id, 'created_at' => now()->subMinutes(10)]);
    $this->conv->update(['last_responder_id' => $this->sara->id]);

    $this->actingAs($this->admin)->getJson('/inbox/conversations')
        ->assertJsonPath('data.0.handling', ['id' => $this->sara->id, 'name' => 'Sara', 'color' => null, 'via' => 'recent_reply']);

    $this->travel(31)->minutes();
    $this->actingAs($this->admin)->getJson('/inbox/conversations')->assertJsonPath('data.0.handling', null);

    $this->actingAs($this->admin)->postJson("/inbox/conversations/{$this->conv->id}/claim")->assertOk()
        ->assertJsonPath('data.handling.id', $this->admin->id)->assertJsonPath('data.handling.via', 'lock');
    expect($this->conv->fresh()->locked_until->diffInMinutes(now(), true))->toBeGreaterThan(29);
});

it('lets a claim take over an existing soft lock without blocking anyone', function () {
    $this->conv->update(['locked_by_id' => $this->sara->id, 'locked_until' => now()->addSeconds(40)]);

    $this->actingAs($this->admin)->postJson("/inbox/conversations/{$this->conv->id}/claim")->assertOk();
    $this->actingAs($this->sara)->postJson("/inbox/conversations/{$this->conv->id}/messages", ['body' => 'أهلاً'])->assertCreated();
});

it('stores mentions and notifies only mentionable teammates', function () {
    $wa = User::factory()->create(['role' => UserRole::Moderator]);
    $wa->userPlatforms()->create(['platform' => Platform::WhatsApp]);

    $this->actingAs($this->admin)->getJson("/inbox/conversations/{$this->conv->id}/mentionable")->assertOk()
        ->assertJsonFragment(['id' => $this->sara->id])->assertJsonMissing(['id' => $wa->id]);

    $this->actingAs($this->admin)->postJson("/inbox/conversations/{$this->conv->id}/notes", ['body' => '@Sara كلميها بخصوص المرتجع', 'mentions' => [$this->sara->id, $wa->id, $this->admin->id]])
        ->assertCreated()->assertJsonPath('data.mentions', [$this->sara->id]);

    expect(UserNotification::where('type', 'note.mention')->pluck('user_id')->all())->toBe([$this->sara->id])
        ->and(UserNotification::first()->data)->toMatchArray(['conversation_id' => $this->conv->id, 'by' => $this->admin->name]);
});

it('keeps a 30-minute claim alive through the claimer typing and sending', function () {
    $this->actingAs($this->admin)->postJson("/inbox/conversations/{$this->conv->id}/claim")->assertOk();

    // Ruling 1: typing() -> SoftLock::acquire() must never shorten the claim
    // down to the plain 45s soft-lock duration.
    $this->actingAs($this->admin)->postJson("/inbox/conversations/{$this->conv->id}/typing")->assertOk();
    expect($this->conv->fresh()->locked_until->diffInMinutes(now(), true))->toBeGreaterThanOrEqual(29);

    // Ruling 1: the claimer's own outbound send -> OutboundService's release()
    // must never clear an active claim either.
    $this->actingAs($this->admin)->postJson("/inbox/conversations/{$this->conv->id}/messages", ['body' => 'تمام هعمله'])->assertCreated();
    $fresh = $this->conv->fresh();
    expect($fresh->locked_by_id)->toBe($this->admin->id)
        ->and($fresh->locked_until->diffInMinutes(now(), true))->toBeGreaterThanOrEqual(29);

    $this->actingAs($this->admin)->getJson('/inbox/conversations')
        ->assertJsonPath('data.0.handling', ['id' => $this->admin->id, 'name' => $this->admin->name, 'color' => null, 'via' => 'lock']);
});

it('lets another user take over an active claim and dispatches ConversationUpdated', function () {
    $this->actingAs($this->admin)->postJson("/inbox/conversations/{$this->conv->id}/claim")->assertOk();

    $this->actingAs($this->sara)->postJson("/inbox/conversations/{$this->conv->id}/claim")->assertOk()
        ->assertJsonPath('data.handling.id', $this->sara->id)->assertJsonPath('data.handling.via', 'lock');

    $fresh = $this->conv->fresh();
    expect($fresh->locked_by_id)->toBe($this->sara->id)
        ->and($fresh->claimed_until->isFuture())->toBeTrue();

    Event::assertDispatched(ConversationUpdated::class, fn (ConversationUpdated $e) => $e->conversation->id === $this->conv->id);
});

it('forbids claim and mentionable for a user without access to the platform', function () {
    $wa = User::factory()->create(['role' => UserRole::Moderator]);
    $wa->userPlatforms()->create(['platform' => Platform::WhatsApp]);

    $this->actingAs($wa)->postJson("/inbox/conversations/{$this->conv->id}/claim")->assertForbidden();
    $this->actingAs($wa)->getJson("/inbox/conversations/{$this->conv->id}/mentionable")->assertForbidden();
});

it('drops duplicate ids and inactive users from mentions', function () {
    $inactive = User::factory()->create(['role' => UserRole::Moderator, 'is_active' => false]);
    $inactive->userPlatforms()->create(['platform' => Platform::Facebook]);

    $this->actingAs($this->admin)->postJson("/inbox/conversations/{$this->conv->id}/notes", [
        'body' => '@Sara لو سمحتي',
        'mentions' => [$this->sara->id, $this->sara->id, $inactive->id],
    ])->assertCreated()->assertJsonPath('data.mentions', [$this->sara->id]);

    expect(UserNotification::where('type', 'note.mention')->count())->toBe(1);
});

it('masks a phone number in the mention excerpt and caps it at 80 characters', function () {
    $body = 'اتصلي بيها على 01001234567 علشان موضوع الشحن اللي اتأخر من كذا يوم وهي مستنية رد بقالها فترة طويلة أوي';

    $this->actingAs($this->admin)->postJson("/inbox/conversations/{$this->conv->id}/notes", [
        'body' => $body,
        'mentions' => [$this->sara->id],
    ])->assertCreated();

    $excerpt = UserNotification::where('type', 'note.mention')->first()->data['excerpt'];
    expect(mb_strlen($excerpt))->toBeLessThanOrEqual(80)->and($excerpt)->not->toContain('01001234567');
});

it('exposes claim and mentionable on the api v1 routes too', function () {
    $token = $this->admin->createToken('test')->plainTextToken;

    $this->withToken($token)->postJson("/api/v1/conversations/{$this->conv->id}/claim")->assertOk()
        ->assertJsonPath('data.handling.via', 'lock');
    $this->withToken($token)->getJson("/api/v1/conversations/{$this->conv->id}/mentionable")->assertOk()
        ->assertJsonFragment(['id' => $this->sara->id]);
});

it('adds no per-row queries to the inbox list', function () {
    // Every conversation in both batches gets a REAL human reply message, not just
    // a `last_responder_id` column value — otherwise `last_human_reply_at` (the
    // sub-select) is null and ConversationResource::handling() short-circuits
    // before ever touching the `lastResponder` relation, silently hiding a
    // regression if that eager load were ever removed.
    $makeHandled = function () {
        $c = Conversation::factory()->for($this->acc, 'channelAccount')->create(['last_message_at' => now(), 'last_responder_id' => $this->sara->id]);
        Message::factory()->create(['conversation_id' => $c->id, 'direction' => MessageDirection::Out, 'sender_type' => SenderType::User, 'user_id' => $this->sara->id]);

        return $c;
    };

    foreach (range(1, 10) as $i) {
        $makeHandled();
    }
    // Warm up the session/presence-tracking middleware first (its one-time "create a
    // session row" queries on the very first authenticated request in a test are not
    // part of what this test measures — only the list query's own row-count scaling is).
    $this->actingAs($this->admin)->getJson('/inbox/conversations')->assertOk();

    DB::enableQueryLog();
    $this->actingAs($this->admin)->getJson('/inbox/conversations')->assertOk();
    $withTen = count(DB::getQueryLog());

    foreach (range(1, 10) as $i) {
        $makeHandled();
    }
    DB::flushQueryLog();
    $this->actingAs($this->admin)->getJson('/inbox/conversations')->assertOk();

    expect(count(DB::getQueryLog()))->toBe($withTen);
});
