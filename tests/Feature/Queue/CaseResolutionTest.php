<?php

use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\ScoreEvent;
use App\Models\SupportCase;
use App\Models\User;
use App\Models\UserNotification;
use App\Queue\Cases\CaseSla;
use App\Queue\Jobs\SendCaseResolved;
use App\Queue\Jobs\SendQueueMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true]);
});

/** A case opened from a queue window by `$opener`, due in `$hours`. */
function crCase(User $opener, int $hours = 24, array $entry = []): SupportCase
{
    $e = QueueEntry::factory()->create(['status' => 'closed', 'close_reason' => 'case', 'assigned_user_id' => $opener->id] + $entry);

    return SupportCase::factory()->create([
        'conversation_id' => $e->conversation_id, 'opened_by_id' => $opener->id, 'assigned_to_id' => $opener->id,
        'queue_entry_id' => $e->id, 'sla_due_at' => now()->addHours($hours), 'status' => 'new',
    ]);
}

function crSupervisor(): User
{
    return User::factory()->create(['role' => 'supervisor']);
}

/** @return list<array{0:string,1:int}> */
function crLines(int $userId): array
{
    return ScoreEvent::query()->where('user_id', $userId)->orderBy('id')->get()->map(fn (ScoreEvent $l) => [$l->kind, $l->points])->all();
}

it('resolves a case in time: stamps it, pays the opener and tells the customer', function () {
    Bus::fake([SendCaseResolved::class]);
    $opener = User::factory()->create();
    $case = crCase($opener);

    $this->actingAs(crSupervisor())->patchJson("/cases/{$case->id}", ['status' => 'resolved'])->assertOk()
        ->assertJsonPath('data.status', 'resolved')->assertJsonPath('data.sla.state', 'in_time');

    $case->refresh();
    expect($case->resolved_at)->not->toBeNull()->and($case->resolved_by_id)->not->toBeNull()
        ->and(crLines($opener->id))->toBe([['case', 14]])
        ->and(ScoreEvent::query()->value('support_case_id'))->toBe($case->id);
    Bus::assertDispatched(SendCaseResolved::class, fn ($job) => $job->caseId === $case->id); // close cycle §6.3
});

it('pays the late amount after the due time and takes it back when the case is reopened', function () {
    $opener = User::factory()->create();
    $case = crCase($opener, 1);
    Carbon::setTestNow(now()->addHours(2));
    $sup = crSupervisor();

    $this->actingAs($sup)->patchJson("/cases/{$case->id}", ['status' => 'resolved'])->assertJsonPath('data.sla.state', 'late');
    $this->actingAs($sup)->patchJson("/cases/{$case->id}", ['status' => 'in_progress'])->assertOk()->assertJsonPath('data.sla.state', 'overdue');
    expect($case->fresh()->resolved_at)->toBeNull()->and(crLines($opener->id))->toBe([['case', 4], ['case', -4]]);

    $this->actingAs($sup)->patchJson("/cases/{$case->id}", ['status' => 'resolved']);
    expect(crLines($opener->id))->toBe([['case', 4], ['case', -4], ['case', 4]]);
});

it('pays nobody for a flow-collected case or a test chat, and closing is never paid', function () {
    $sup = crSupervisor();
    $flow = SupportCase::factory()->create(['status' => 'new']);
    $this->actingAs($sup)->patchJson("/cases/{$flow->id}", ['status' => 'resolved'])->assertOk();

    $opener = User::factory()->create();
    $test = crCase($opener, 24, ['is_test' => true]);
    $this->actingAs($sup)->patchJson("/cases/{$test->id}", ['status' => 'resolved'])->assertOk();
    $closed = crCase($opener);
    $this->actingAs($sup)->patchJson("/cases/{$closed->id}", ['status' => 'closed'])->assertOk();

    expect(ScoreEvent::count())->toBe(0)->and($flow->fresh()->resolved_at)->not->toBeNull();
});

it('shows the SLA of an open case and leaves resolved cases out of the overdue filter', function () {
    $opener = User::factory()->create();
    $open = crCase($opener, 3);
    $late = crCase($opener, -1);
    $done = crCase($opener, -2);
    $done->update(['status' => 'resolved', 'resolved_at' => now()]);

    $this->actingAs(crSupervisor())->getJson("/cases/{$open->id}")->assertJsonPath('data.sla.state', 'open')
        ->assertJsonPath('data.sla.due_at', $open->sla_due_at->toIso8601String());

    $ids = collect($this->actingAs(crSupervisor())->get('/cases?overdue=1')->viewData('page')['props']['cases']['data'])->pluck('id')->all();
    expect($ids)->toBe([$late->id]);
});

it('tells the opener and the assignee once when an open queue case goes overdue', function () {
    $opener = User::factory()->create();
    $assignee = User::factory()->create();
    $case = crCase($opener, 1);
    $case->update(['assigned_to_id' => $assignee->id]);
    $fresh = crCase($opener, 5);

    Carbon::setTestNow(now()->addMinutes(61));
    app(CaseSla::class)->sweep();
    app(CaseSla::class)->sweep();

    expect($case->fresh()->sla_breached_at)->not->toBeNull()
        ->and($fresh->fresh()->sla_breached_at)->toBeNull()
        ->and(UserNotification::query()->where('type', 'cases.sla_overdue')->orderBy('user_id')->pluck('user_id')->all())->toBe([$opener->id, $assignee->id]);
});

it('pays nothing when the moderator resolves her own case, and tells the customer only the first time', function () {
    Bus::fake([SendCaseResolved::class]);
    $opener = User::factory()->create();
    $case = crCase($opener);
    $opener->userPlatforms()->create(['platform' => $case->conversation->platform->value]); // she may work this platform

    $this->actingAs($opener)->patchJson("/cases/{$case->id}", ['status' => 'resolved'])->assertOk();
    $this->actingAs($opener)->patchJson("/cases/{$case->id}", ['status' => 'in_progress'])->assertOk();
    $this->actingAs(crSupervisor())->patchJson("/cases/{$case->id}", ['status' => 'resolved'])->assertOk();

    expect(crLines($opener->id))->toBe([['case', 14]]);
    Bus::assertDispatchedTimes(SendCaseResolved::class, 1);
});

it('pays the window moderator, not the supervisor who closed the window as a case for her', function () {
    $mod = User::factory()->create();
    $sup = crSupervisor();
    $case = crCase($mod);
    $case->update(['opened_by_id' => $sup->id]); // the supervisor pressed «خلصت → طلب» on her window

    $this->actingAs(User::factory()->create(['role' => 'admin']))->patchJson("/cases/{$case->id}", ['status' => 'resolved'])->assertOk();

    expect(crLines($mod->id))->toBe([['case', 14]])->and(crLines($sup->id))->toBe([]);
});
