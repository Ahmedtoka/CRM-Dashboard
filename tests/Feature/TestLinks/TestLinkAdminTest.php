<?php

use App\Analytics\MetricsService;
use App\Bot\Learning\ConversationReview;
use App\Bot\Learning\ConversationReviewer;
use App\Bot\Learning\LearningScope;
use App\Channels\Adapters\TestChannelAdapter;
use App\Channels\ChannelRegistry;
use App\Enums\MessageDirection;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Enums\UserRole;
use App\Models\BotLearningNote;
use App\Models\BotTestLink;
use App\Models\BotTestSession;
use App\Models\BotTestSessionStep;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\TestLinks\TestLinkReport;
use App\TestLinks\TestScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

// Settings → روابط التجربة, the inbox badge/filter, the reports the test data must stay
// out of, the report it belongs in, and the learning it feeds (design 2026-09-21 §1/§4/§5).

beforeEach(function () {
    Http::preventStrayRequests();
    ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'PAGE1', 'driver' => 'live']);
    $this->travelTo(CarbonImmutable::parse('2026-09-21 12:00', 'Africa/Cairo'));
});

/** A finished test run with a flow trail, without going through the tester's page. */
function seedTestRun(string $name = 'سارة', int $runNo = 1, ?BotTestLink $link = null): BotTestSession
{
    $link ??= BotTestLink::create(['token' => BotTestLink::newToken(), 'label' => 'تجربة الفريق', 'is_active' => true]);
    $account = ChannelAccount::firstWhere('driver', TestScope::DRIVER)
        ?? ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'driver' => TestScope::DRIVER, 'external_id' => 'test-link-'.$link->id]);

    $link->forceFill(['channel_account_id' => $account->id])->save();

    $conversation = Conversation::factory()->create(['channel_account_id' => $account->id, 'platform' => Platform::Facebook]);

    Message::factory()->create(['conversation_id' => $conversation->id, 'direction' => MessageDirection::In, 'sender_type' => SenderType::Customer, 'body' => 'شكوى']);
    Message::factory()->create(['conversation_id' => $conversation->id, 'direction' => MessageDirection::Out, 'sender_type' => SenderType::Bot, 'body' => 'الشكوى بخصوص إيه؟']);

    $session = BotTestSession::create([
        'bot_test_link_id' => $link->id,
        'session_token' => BotTestSession::newToken(),
        'tester_name' => $name,
        'run_no' => $runNo,
        'conversation_id' => $conversation->id,
        'device_family' => 'iPhone',
        'started_at' => now()->subMinutes(4),
        'last_seen_at' => now(),
    ]);

    foreach ([['complaint', 'type'], ['complaint', 'contact'], ['complaint', 'description']] as [$flow, $step]) {
        BotTestSessionStep::create(['bot_test_session_id' => $session->id, 'flow_key' => $flow, 'step_id' => $step, 'entered_at' => now()]);
    }

    return $session;
}

it('lets a supervisor create, stop and delete a link, and keeps a moderator out', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $mod = User::factory()->create(['role' => UserRole::Moderator]);

    $this->actingAs($mod)->get('/settings/bot-test-links')->assertForbidden();
    $this->actingAs($mod)->postJson('/settings/bot-test-links', ['label' => 'تجربة'])->assertForbidden();

    $this->actingAs($sup)->get('/settings/bot-test-links')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('settings/BotTestLinks')->has('links', 0));

    $created = $this->actingAs($sup)
        ->postJson('/settings/bot-test-links', ['label' => 'تجربة الفريق', 'max_messages_per_session' => 30])
        ->assertOk()
        ->json('data.link');

    $link = BotTestLink::firstOrFail();

    expect($created['url'])->toBe(url('/try/'.$link->token))
        ->and(strlen($link->token))->toBe(BotTestLink::TOKEN_LENGTH)
        ->and($link->max_messages_per_session)->toBe(30)
        ->and($link->created_by)->toBe($sup->id)
        // The link's own channel account is ready before anyone opens it.
        ->and($link->channelAccount->driver)->toBe(TestScope::DRIVER);

    // Stopping it ends the runs that were open.
    $session = seedTestRun(link: $link);

    $this->actingAs($sup)->patchJson("/settings/bot-test-links/{$link->id}", ['is_active' => false])->assertOk();

    expect($link->fresh()->is_active)->toBeFalse()
        ->and($session->fresh()->ended_reason)->toBe(BotTestSession::ENDED_LINK_STOPPED);

    $this->actingAs($sup)->getJson("/settings/bot-test-links/{$link->id}/sessions")
        ->assertOk()
        ->assertJsonPath('data.sessions.0.name', 'سارة');

    $this->actingAs($sup)->deleteJson("/settings/bot-test-links/{$link->id}")->assertOk();

    expect(BotTestLink::count())->toBe(0)
        // The conversation itself stays in the inbox for the record.
        ->and(Conversation::count())->toBe(1);
});

it('badges and filters the test conversations in the inbox', function () {
    $session = seedTestRun();
    $real = Conversation::factory()->create(['channel_account_id' => ChannelAccount::firstWhere('driver', 'live')->id, 'platform' => Platform::Facebook]);
    $agent = User::factory()->create(['role' => UserRole::Admin]);

    $all = $this->actingAs($agent)->getJson('/inbox/conversations')->assertOk()->json('data');

    expect(collect($all)->firstWhere('id', $session->conversation_id)['is_test'])->toBeTrue()
        ->and(collect($all)->firstWhere('id', $real->id)['is_test'])->toBeFalse();

    $onlyTests = $this->actingAs($agent)->getJson('/inbox/conversations?filter=test')->assertOk()->json('data');

    expect(collect($onlyTests)->pluck('id')->all())->toBe([$session->conversation_id]);
});

it('never shows a test account on the channels screen and refuses to manage it there', function () {
    seedTestRun();
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $testAccount = ChannelAccount::firstWhere('driver', TestScope::DRIVER);

    $this->actingAs($admin)->get('/settings/channels')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('settings/Channels')
            ->where('accounts', fn ($accounts) => collect($accounts)->pluck('id')->doesntContain($testAccount->id))
            ->etc());

    $this->actingAs($admin)->putJson("/settings/channels/{$testAccount->id}", ['name' => 'x'])->assertNotFound();
    $this->actingAs($admin)->deleteJson("/settings/channels/{$testAccount->id}")->assertNotFound();
});

it('keeps the team test out of the dashboard reports and the rollup', function () {
    $session = seedTestRun();
    $live = ChannelAccount::firstWhere('driver', 'live');
    $real = Conversation::factory()->create(['channel_account_id' => $live->id, 'platform' => Platform::Facebook]);
    Message::factory()->create(['conversation_id' => $real->id, 'direction' => MessageDirection::In, 'sender_type' => SenderType::Customer, 'body' => 'مرحبا']);

    $from = CarbonImmutable::now()->subDay();
    $to = CarbonImmutable::now()->addDay();

    $team = app(MetricsService::class)->teamMetrics($from, $to);
    $bot = app(MetricsService::class)->botMetrics($from, $to);

    expect($team['inbound_messages'])->toBe(1)
        ->and($team['conversations_new'])->toBe(1)
        ->and($bot['messages_sent'])->toBe(0);

    // And the conversation is still there, only flagged.
    expect((bool) Conversation::whereKey($session->conversation_id)->value('is_test'))->toBeTrue();

    $this->artisan('crm:rollup', ['date' => CarbonImmutable::now(MetricsService::TZ)->toDateString()])->assertSuccessful();
});

it('builds the team test report, its funnel and its CSV', function () {
    $link = BotTestLink::create(['token' => BotTestLink::newToken(), 'label' => 'تجربة الفريق', 'is_active' => true]);
    $first = seedTestRun('سارة', 1, $link);
    $second = seedTestRun('سارة', 2, $link);

    // The second run left the flow: that is what "finished" means in the funnel.
    BotTestSessionStep::create(['bot_test_session_id' => $second->id, 'flow_key' => 'complaint', 'step_id' => null, 'entered_at' => now()]);

    $report = app(TestLinkReport::class)->forLink($link->fresh());

    expect($report['totals']['sessions'])->toBe(2)
        ->and($report['totals']['testers'])->toBe(1)
        ->and($report['totals']['messages'])->toBe(4)
        ->and($report['totals']['finished'])->toBe(1);

    $funnel = collect($report['funnels'])->firstWhere('key', 'complaint');

    expect($funnel['entered'])->toBe(2)
        ->and($funnel['finished'])->toBe(1)
        ->and(collect($funnel['steps'])->firstWhere('id', 'type')['reached'])->toBe(2)
        ->and(collect($funnel['steps'])->firstWhere('id', 'description')['dropped'])->toBe(1)
        ->and($funnel['top_drop_offs'][0]['id'])->toBe('description');

    $rows = collect($report['sessions']);

    expect($rows->firstWhere('id', $first->id)['label'])->toBe('سارة')
        ->and($rows->firstWhere('id', $second->id)['label'])->toBe('سارة — الجلسة ٢')
        ->and($rows->firstWhere('id', $first->id)['finished'])->toBeFalse()
        ->and($rows->firstWhere('id', $first->id)['dropped_at'])->toBe('description')
        ->and($rows->firstWhere('id', $first->id)['device_family'])->toBe('iPhone');

    $sup = User::factory()->create(['role' => UserRole::Supervisor]);

    $this->actingAs($sup)->get("/reports/team-test?link={$link->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Reports/TeamTest')
            ->has('sessions', 2)
            ->has('funnels', 1)
            ->where('totals.sessions', 2)
            ->etc());

    $this->actingAs($sup)->getJson("/reports/team-test/sessions/{$first->id}")
        ->assertOk()
        ->assertJsonPath('data.label', 'سارة')
        ->assertJsonPath('data.transcript.0.body', 'شكوى');

    $csv = $this->actingAs($sup)->get("/reports/team-test/export?link={$link->id}");
    $csv->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

    $body = $csv->streamedContent();

    // The header row and the finished flag follow the exporting user's locale.
    expect($body)->toContain(__('labels.csv.test_links.tester'))
        ->and($body)->toContain(__('labels.csv.yes'))
        ->and($body)->toContain('سارة')
        ->and($body)->toContain('iPhone');

    $this->actingAs(User::factory()->create(['role' => UserRole::Moderator]))->get('/reports/team-test')->assertForbidden();
});

it('lets the bot learn from a test run and tags the note as test', function () {
    $session = seedTestRun();
    $conversation = Conversation::findOrFail($session->conversation_id);

    Message::factory()->create(['conversation_id' => $conversation->id, 'direction' => MessageDirection::In, 'sender_type' => SenderType::Customer, 'body' => 'وصلت متأخرة']);

    expect(LearningScope::drivers())->toBe(['live', 'test'])
        ->and(LearningScope::includes($conversation))->toBeTrue();

    app(ConversationReview::class)->review($conversation, app(ConversationReviewer::class));

    expect(BotLearningNote::firstOrFail()->source)->toBe(BotLearningNote::SOURCE_TEST);

    $live = Conversation::factory()->create(['channel_account_id' => ChannelAccount::firstWhere('driver', 'live')->id]);
    foreach (['فين الأوردر', 'حد يرد'] as $body) {
        Message::factory()->create(['conversation_id' => $live->id, 'direction' => MessageDirection::In, 'sender_type' => SenderType::Customer, 'body' => $body]);
    }

    app(ConversationReview::class)->review($live, app(ConversationReviewer::class));

    expect(BotLearningNote::latest('id')->first()->source)->toBe(BotLearningNote::SOURCE_LIVE);

    $sup = User::factory()->create(['role' => UserRole::Supervisor]);

    $this->actingAs($sup)->get('/settings/bot-learning?source=test')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('settings/BotLearning')
            ->where('source', 'test')
            ->where('today.live', 1)
            ->where('today.test', 1)
            ->etc());
});

it('gives a test account an adapter that swallows sends and accepts no webhook', function () {
    seedTestRun();

    $testAccount = ChannelAccount::firstWhere('driver', TestScope::DRIVER);
    $liveAccount = ChannelAccount::firstWhere('driver', 'live');
    $registry = app(ChannelRegistry::class);

    expect($registry->adapterFor($testAccount))->toBeInstanceOf(TestChannelAdapter::class)
        ->and($registry->adapterFor($liveAccount))->not->toBeInstanceOf(TestChannelAdapter::class)
        // `account()` is what the webhook path falls back to: never a test account.
        ->and($registry->account(Platform::Facebook)->id)->toBe($liveAccount->id);

    $adapter = new TestChannelAdapter;

    expect($adapter->verifySignature(request()))->toBeFalse()
        ->and($adapter->normalize(['events' => [['type' => 'message']]]))->toBe([]);
});
