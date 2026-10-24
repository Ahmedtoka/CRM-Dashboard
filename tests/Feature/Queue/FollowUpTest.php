<?php

use App\Enums\MessageDirection;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Enums\UserRole;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\Customer;
use App\Models\Message;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\SupportCase;
use App\Models\User;
use App\Queue\Cases\FollowUpList;
use App\Queue\Jobs\SendCaseResolved;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->withoutVite();
    Carbon::setTestNow(Carbon::parse('2026-10-20 12:00', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true]);
});

function fuSupervisor(): User
{
    return User::factory()->create(['role' => UserRole::Supervisor]);
}

/** A conversation on `$platform`, its last customer message at `$customerAt` (null: she never wrote). */
function fuConversation(Platform $platform = Platform::Facebook, ?Carbon $customerAt = null, array $attrs = []): Conversation
{
    $account = ChannelAccount::factory()->create(['platform' => $platform]);

    return Conversation::factory()->create($attrs + ['channel_account_id' => $account->id, 'last_customer_message_at' => $customerAt]);
}

/** An open flow-collected case (no queue window) on `$conversation`. */
function fuCase(?Conversation $conversation = null, array $attrs = []): SupportCase
{
    $conversation ??= fuConversation();

    return SupportCase::factory()->create($attrs + ['conversation_id' => $conversation->id, 'status' => 'new']);
}

/** A case opened from `$opener`'s queue window, due in `$hours`. */
function fuWindowCase(User $opener, int $hours = 24, array $entry = [], array $attrs = []): SupportCase
{
    $conversation = fuConversation();
    $e = QueueEntry::factory()->create($entry + ['conversation_id' => $conversation->id, 'status' => 'closed', 'close_reason' => 'case', 'assigned_user_id' => $opener->id]);

    return fuCase($conversation, $attrs + ['opened_by_id' => $opener->id, 'queue_entry_id' => $e->id, 'sla_due_at' => now()->addHours($hours)]);
}

function fuInbound(Conversation $c, string $body, Carbon $at): Message
{
    return Message::factory()->create(['conversation_id' => $c->id, 'direction' => MessageDirection::In, 'sender_type' => SenderType::Customer, 'body' => $body, 'created_at' => $at, 'updated_at' => $at]);
}

function fuStaffReply(Conversation $c, User $by, Carbon $at): Message
{
    return Message::factory()->create(['conversation_id' => $c->id, 'direction' => MessageDirection::Out, 'sender_type' => SenderType::User, 'user_id' => $by->id, 'created_at' => $at, 'updated_at' => $at]);
}

/** @return array<int, array<string, mixed>> the page's rows keyed by case id */
function fuRows(User $viewer, string $query = ''): array
{
    $props = test()->actingAs($viewer)->get('/cases/follow-up'.$query)->assertOk()->viewData('page')['props'];

    return collect($props['rows'])->keyBy('id')->all();
}

it('lists only open cases, test chats and test windows left out, with the page props', function () {
    $sup = fuSupervisor();
    $open = fuCase();
    $inProgress = fuCase(null, ['status' => 'in_progress']);
    fuCase(null, ['status' => 'resolved', 'resolved_at' => now()]);
    fuCase(null, ['status' => 'closed', 'closed_at' => now()]);
    fuCase(fuConversation(attrs: ['is_test' => true]));
    fuWindowCase(User::factory()->create(), 24, ['is_test' => true]);

    $this->actingAs($sup)->get('/cases/follow-up')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Cases/FollowUp')
        ->has('rows', 2)
        ->where('counts.open', 2)
        ->where('me', $sup->id)
        ->has('team')
        ->has('filters'));

    expect(array_keys(fuRows($sup)))->toEqualCanonicalizing([$open->id, $inProgress->id]);
});

it('names the owner (the window moderator, else the opener) and the drawer assignee separately', function () {
    $sup = fuSupervisor();
    $mod = User::factory()->create(['name' => 'Mona']);
    $assignee = User::factory()->create(['name' => 'Sara']);
    $windowCase = fuWindowCase($mod, 24, [], ['assigned_to_id' => $assignee->id]);
    $windowCase->update(['opened_by_id' => $sup->id]); // the supervisor pressed «خلصت → طلب» for her
    $flowCase = fuCase(null, ['opened_by_id' => $assignee->id]);
    $orphan = fuCase();

    $rows = fuRows($sup);

    expect($rows[$windowCase->id]['owner'])->toBe(['id' => $mod->id, 'name' => 'Mona'])
        ->and($rows[$windowCase->id]['assigned_to'])->toBe(['id' => $assignee->id, 'name' => 'Sara'])
        ->and($rows[$flowCase->id]['owner'])->toBe(['id' => $assignee->id, 'name' => 'Sara'])
        ->and($rows[$flowCase->id]['assigned_to'])->toBeNull()
        ->and($rows[$orphan->id]['owner'])->toBeNull()
        ->and($rows[$orphan->id]['type'])->toBe('complaint')
        ->and($rows[$orphan->id]['type_label'])->not->toBe('')
        ->and($rows[$orphan->id]['opened_at'])->toBe($orphan->created_at->toIso8601String())
        ->and($rows[$orphan->id]['conversation_id'])->toBe($orphan->conversation_id);
});

it('derives the next action: assign, then reply, then overdue, else waiting for the solution', function () {
    $sup = fuSupervisor();
    $mod = User::factory()->create();

    // Nobody owns it and nobody is assigned, even though she is waiting: assign comes first.
    $unowned = fuCase(fuConversation(customerAt: now()->subHour()));
    // Owned, she wrote after the last staff message: reply.
    $cReply = fuConversation(customerAt: now()->subMinutes(10));
    fuStaffReply($cReply, $mod, now()->subHour());
    $reply = fuCase($cReply, ['opened_by_id' => $mod->id, 'sla_due_at' => now()->subHour()]); // overdue too, reply wins
    // A staff note after her message counts as an answer; the SLA passed: overdue.
    $cNoted = fuConversation(customerAt: now()->subHour());
    ConversationNote::factory()->create(['conversation_id' => $cNoted->id, 'user_id' => $mod->id, 'created_at' => now()->subMinutes(30)]);
    $overdue = fuCase($cNoted, ['assigned_to_id' => $mod->id, 'sla_due_at' => now()->subMinutes(5)]);
    // Staff replied after her, SLA still running: waiting for the solution.
    $cWaiting = fuConversation(customerAt: now()->subHour());
    fuStaffReply($cWaiting, $mod, now()->subMinutes(20));
    $waiting = fuCase($cWaiting, ['assigned_to_id' => $mod->id, 'sla_due_at' => now()->addHours(3)]);
    // She never wrote at all and no SLA: waiting.
    $silent = fuCase(null, ['assigned_to_id' => $mod->id]);

    $rows = fuRows($sup);

    expect($rows[$unowned->id]['next_action'])->toBe('assign')
        ->and($rows[$reply->id]['next_action'])->toBe('reply')
        ->and($rows[$overdue->id]['next_action'])->toBe('overdue')
        ->and($rows[$overdue->id]['sla']['state'])->toBe('overdue')
        ->and($rows[$waiting->id]['next_action'])->toBe('resolve_check')
        ->and($rows[$silent->id]['next_action'])->toBe('resolve_check');
});

it('carries her last message (trimmed to 120 chars) and the latest staff note', function () {
    $sup = fuSupervisor();
    $author = User::factory()->create(['name' => 'Hoda']);
    $c = fuConversation(customerAt: now()->subMinutes(5));
    fuInbound($c, 'first', now()->subHour());
    fuStaffReply($c, $author, now()->subMinutes(30));
    fuInbound($c, str_repeat('ب', 200), now()->subMinutes(5));
    ConversationNote::factory()->create(['conversation_id' => $c->id, 'user_id' => $author->id, 'body' => 'old note', 'created_at' => now()->subMinutes(40)]);
    ConversationNote::factory()->create(['conversation_id' => $c->id, 'user_id' => $author->id, 'body' => 'latest note', 'created_at' => now()->subMinutes(20)]);
    $case = fuCase($c, ['assigned_to_id' => $author->id]);
    $bare = fuCase();

    $rows = fuRows($sup);

    expect(mb_strlen($rows[$case->id]['last_customer_message']))->toBe(120)
        ->and($rows[$case->id]['last_customer_message_at'])->toBe(now()->subMinutes(5)->toIso8601String())
        ->and($rows[$case->id]['last_note']['body'])->toBe('latest note')
        ->and($rows[$case->id]['last_note']['author'])->toBe(['id' => $author->id, 'name' => 'Hoda'])
        ->and($rows[$case->id]['last_note']['at'])->toBe(now()->subMinutes(20)->toIso8601String())
        ->and($rows[$bare->id]['last_customer_message'])->toBeNull()
        ->and($rows[$bare->id]['last_note'])->toBeNull();
});

it('sorts overdue first, then the nearest due time, then the oldest, and counts the tiles', function () {
    $sup = fuSupervisor();
    $mod = User::factory()->create();
    $oldNoSla = fuCase(null, ['created_at' => now()->subDays(3)]);
    $newNoSla = fuCase(null, ['created_at' => now()->subDay()]);
    $dueSoon = fuCase(null, ['sla_due_at' => now()->addHour(), 'assigned_to_id' => $sup->id]);
    $dueLater = fuCase(null, ['sla_due_at' => now()->addHours(5)]);
    $veryLate = fuCase(null, ['sla_due_at' => now()->subHours(3), 'opened_by_id' => $mod->id]);
    $justLate = fuCase(null, ['sla_due_at' => now()->subMinutes(2), 'assigned_to_id' => $mod->id]);

    $props = $this->actingAs($sup)->get('/cases/follow-up')->viewData('page')['props'];

    expect(collect($props['rows'])->pluck('id')->all())->toBe([$veryLate->id, $justLate->id, $dueSoon->id, $dueLater->id, $oldNoSla->id, $newNoSla->id])
        ->and($props['counts'])->toBe(['open' => 6, 'overdue' => 2, 'unassigned' => 3, 'mine' => 1]);
});

it('filters by type, owner, overdue and mine while the tiles keep the whole picture', function () {
    $sup = fuSupervisor();
    $mod = User::factory()->create();
    $complaint = fuCase(null, ['assigned_to_id' => $sup->id]);
    $exchange = fuCase(null, ['type' => 'exchange', 'opened_by_id' => $mod->id, 'sla_due_at' => now()->subHour()]);
    $window = fuWindowCase($mod, 24, [], ['assigned_to_id' => $sup->id]);

    expect(array_keys(fuRows($sup, '?type=exchange')))->toBe([$exchange->id])
        ->and(array_keys(fuRows($sup, "?owner={$mod->id}")))->toEqualCanonicalizing([$exchange->id, $window->id])
        ->and(array_keys(fuRows($sup, "?owner={$sup->id}")))->toEqualCanonicalizing([$complaint->id, $window->id])
        ->and(array_keys(fuRows($sup, '?overdue=1')))->toBe([$exchange->id])
        ->and(array_keys(fuRows($sup, '?mine=1')))->toEqualCanonicalizing([$complaint->id, $window->id]);

    $this->actingAs($sup)->get('/cases/follow-up?type=exchange')->assertInertia(fn ($page) => $page
        ->has('rows', 1)->where('counts.open', 3)->where('filters.type', 'exchange'));
    $this->actingAs($sup)->get('/cases/follow-up?type=bogus')->assertSessionHasErrors('type');
});

it('shows a moderator only her platforms, and her own filter in that scope', function () {
    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $mod->userPlatforms()->create(['platform' => Platform::Facebook]);
    $fb = fuCase(fuConversation(Platform::Facebook), ['assigned_to_id' => $mod->id]);
    fuCase(fuConversation(Platform::WhatsApp), ['assigned_to_id' => $mod->id]);

    $this->actingAs($mod)->get('/cases/follow-up')->assertOk()->assertInertia(fn ($page) => $page
        ->has('rows', 1)->where('rows.0.id', $fb->id)->where('counts.open', 1)->where('counts.mine', 1));
});

it('lets a moderator take a case and mark it resolved through the existing PATCH, which tells the customer', function () {
    Bus::fake([SendCaseResolved::class]);
    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $mod->userPlatforms()->create(['platform' => Platform::Facebook]);
    $case = fuCase(fuConversation(Platform::Facebook));

    $this->actingAs($mod)->patchJson("/cases/{$case->id}", ['assigned_to_id' => $mod->id])->assertOk()->assertJsonPath('data.assigned_to.id', $mod->id);
    expect(fuRows($mod)[$case->id]['next_action'])->toBe('resolve_check');

    $this->actingAs($mod)->patchJson("/cases/{$case->id}", ['status' => 'resolved'])->assertOk()->assertJsonPath('data.status', 'resolved');

    expect($case->fresh()->resolved_at)->not->toBeNull()->and(fuRows($mod))->toBe([]);
    Bus::assertDispatched(SendCaseResolved::class, fn ($job) => $job->caseId === $case->id);
});

it('stores a follow-up note on the case conversation and shows it as the last note', function () {
    $sup = fuSupervisor();
    $case = fuCase();

    $this->actingAs($sup)->postJson("/inbox/conversations/{$case->conversation_id}/notes", ['body' => 'كلمتها، هتستنى الاستبدال'])->assertCreated();

    expect(ConversationNote::query()->where('conversation_id', $case->conversation_id)->count())->toBe(1)
        ->and(fuRows($sup)[$case->id]['last_note']['body'])->toBe('كلمتها، هتستنى الاستبدال')
        ->and(fuRows($sup)[$case->id]['last_note']['author']['id'])->toBe($sup->id);
});

it('runs the same number of queries for 5 and for 30 open cases', function () {
    $sup = fuSupervisor();
    $mod = User::factory()->create();
    $make = function () use ($mod) {
        $c = fuConversation(customerAt: now()->subMinutes(5));
        fuInbound($c, 'هو فين الأوردر؟', now()->subMinutes(5));
        fuStaffReply($c, $mod, now()->subHour());
        ConversationNote::factory()->create(['conversation_id' => $c->id, 'user_id' => $mod->id]);
        fuCase($c, ['opened_by_id' => $mod->id, 'sla_due_at' => now()->addHour()]);
    };

    $count = function () use ($sup): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(FollowUpList::class)->build($sup, []);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    for ($i = 0; $i < 5; $i++) {
        $make();
    }
    $five = $count();
    for ($i = 0; $i < 25; $i++) {
        $make();
    }

    expect($count())->toBe($five)->and(count(app(FollowUpList::class)->build($sup, [])['rows']))->toBe(30);
});

it('skips the customer-less case cleanly and keeps the customer card on the rest', function () {
    $sup = fuSupervisor();
    $customer = Customer::factory()->create(['name' => 'Nour', 'phone' => '+201000000001']);
    $with = fuCase(fuConversation(attrs: ['customer_id' => $customer->id]));
    $without = fuCase(null, ['customer_id' => null]);

    $rows = fuRows($sup);

    expect($rows[$with->id]['customer'])->toBe(['id' => $customer->id, 'name' => 'Nour', 'phone' => '+201000000001'])
        ->and($rows[$without->id]['customer'])->toBeNull();
});
