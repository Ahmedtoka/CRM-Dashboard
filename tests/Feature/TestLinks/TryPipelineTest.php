<?php

use App\Bot\Flows\FakeFlowAnswerInterpreter;
use App\Bot\Flows\FlowAnswerInterpreter;
use App\Channels\Adapters\FakeChannelAdapter;
use App\Enums\MessageStatus;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Enums\UserRole;
use App\Models\BotSetting;
use App\Models\BotTestLink;
use App\Models\BotTestSession;
use App\Models\BotTestSessionStep;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\SupportCase;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

// A tester on a public link runs the REAL published flows, exactly as a customer on
// Messenger would (design 2026-09-21 §3), and nothing they say ever leaves the system.

beforeEach(function () {
    Http::preventStrayRequests();
    config(['crm.drivers.ai' => 'fake']);
    ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'PAGE1']);
    BotSetting::current()->update(['enabled' => true, 'ai_enabled' => false, 'working_hours' => null]);
    app()->bind(FlowAnswerInterpreter::class, FakeFlowAnswerInterpreter::class);
    $this->travelTo(CarbonImmutable::parse('2026-09-21 12:00', 'Africa/Cairo'));
});

function pipelineLink(): BotTestLink
{
    return BotTestLink::create([
        'token' => BotTestLink::newToken(),
        'label' => 'تجربة الفريق',
        'is_active' => true,
        'max_messages_per_session' => 60,
    ]);
}

function trySend(BotTestLink $link, ?string $text, ?string $payload = null): array
{
    return test()->postJson("/try/{$link->token}/messages", array_filter([
        'text' => $text,
        'payload' => $payload,
    ], fn ($v) => $v !== null))->assertOk()->json();
}

it('runs a real published flow to a recorded case, and records the funnel trail', function () {
    $link = pipelineLink();
    $this->postJson("/try/{$link->token}/start", ['name' => 'سارة'])->assertOk();

    // Straight into the owner's complaint flow, the same payload a Messenger button carries.
    $opened = trySend($link, 'شكوى', 'flow:complaint');
    expect(collect($opened['messages'])->pluck('body')->implode(' '))->toContain('الشكوى');

    trySend($link, 'حاجة تانية', 'step:complaint:type:other');
    trySend($link, 'سارة 01012345678');
    $done = trySend($link, 'المنتج وصل متأخر جدًا');

    $conversation = Conversation::firstOrFail();
    $case = SupportCase::where('conversation_id', $conversation->id)->firstOrFail();

    expect($case->type)->toBe('complaint')
        ->and($conversation->is_test)->toBeTrue()
        ->and(collect($done['messages'])->where('direction', 'out'))->not->toBeEmpty();

    // Nothing reached a platform: the test driver swallowed every send.
    expect(FakeChannelAdapter::sent())->toBe([]);

    $trail = BotTestSessionStep::query()->orderBy('id')->get();

    expect($trail->pluck('flow_key')->unique()->all())->toBe(['complaint'])
        ->and($trail->pluck('step_id')->all())->toContain('type')
        ->and($trail->pluck('step_id')->all())->toContain('description')
        // The closing row (no step) is what marks the flow as finished in the report.
        ->and($trail->last()->step_id)->toBeNull();
});

it('marks the bot replies delivered and read once the tester has polled them', function () {
    $link = pipelineLink();
    $this->postJson("/try/{$link->token}/start", ['name' => 'سارة']);
    trySend($link, 'شكوى', 'flow:complaint');

    $this->getJson("/try/{$link->token}/state")->assertOk();

    $bot = Message::where('sender_type', SenderType::Bot->value)->firstOrFail();

    expect($bot->status)->toBe(MessageStatus::Read)
        ->and($bot->delivered_at)->not->toBeNull()
        ->and($bot->external_id)->toStartWith('test_');
});

it('shows an agent reply from the inbox on the tester page', function () {
    $link = pipelineLink();
    $this->postJson("/try/{$link->token}/start", ['name' => 'سارة']);
    trySend($link, 'محتاجة حد يكلمني');

    $conversation = Conversation::firstOrFail();
    $agent = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($agent)
        ->postJson("/inbox/conversations/{$conversation->id}/messages", ['body' => 'أهلاً، أنا معاكي 🌸'])
        ->assertSuccessful();

    // Back on the tester's page (their own browser session is still the one that started).
    app('auth')->logout();

    $state = $this->getJson("/try/{$link->token}/state")->assertOk()->json();

    expect(collect($state['messages'])->pluck('body'))->toContain('أهلاً، أنا معاكي 🌸')
        ->and(FakeChannelAdapter::sent())->toBe([]);
});

it('takes a photo from the tester and stores it on the inbound message', function () {
    Storage::fake(config('crm.media.disk', 'media'));

    $link = pipelineLink();
    $this->postJson("/try/{$link->token}/start", ['name' => 'سارة']);

    $this->post("/try/{$link->token}/photo", ['photo' => UploadedFile::fake()->image('shot.jpg', 40, 40)])
        ->assertOk();

    $message = Message::where('sender_type', SenderType::Customer->value)->latest('id')->firstOrFail();
    $attachment = $message->mediaAttachments()->firstOrFail();

    expect($attachment->status->value)->toBe('stored')
        ->and($attachment->path)->not->toBeNull()
        ->and(Storage::disk(config('crm.media.disk', 'media'))->exists($attachment->path))->toBeTrue();

    // And only this tester may read it back.
    $this->get("/try/{$link->token}/media/{$attachment->id}")->assertOk();

    $this->flushSession();
    $this->get("/try/{$link->token}/media/{$attachment->id}")->assertForbidden();
});

it('numbers a second run of the same tester and keeps the two conversations apart', function () {
    $link = pipelineLink();
    $this->postJson("/try/{$link->token}/start", ['name' => 'سارة']);
    trySend($link, 'أول جلسة');

    $this->postJson("/try/{$link->token}/reset")->assertOk();
    trySend($link, 'تاني جلسة');

    $runs = BotTestSession::orderBy('id')->get();

    expect($runs)->toHaveCount(2)
        ->and($runs[1]->run_no)->toBe(2)
        ->and($runs[0]->conversation_id)->not->toBe($runs[1]->conversation_id);

    $second = Conversation::find($runs[1]->conversation_id);

    expect($second->messages()->where('body', 'أول جلسة')->exists())->toBeFalse()
        ->and($second->messages()->where('body', 'تاني جلسة')->exists())->toBeTrue();
});
