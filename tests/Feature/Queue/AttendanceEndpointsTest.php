<?php

use App\Models\QueueAttendanceEvent;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\Shift;
use App\Models\ShiftMember;
use App\Models\User;
use App\Queue\ShiftService;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true]);
});

/** A moderator allowed on every platform, logged in now. */
function aeModerator(array $attrs = []): User
{
    $u = User::factory()->create($attrs + ['role' => 'moderator', 'last_seen_at' => now()]);

    foreach (['facebook', 'instagram', 'whatsapp', 'tiktok'] as $platform) {
        $u->userPlatforms()->create(['platform' => $platform]);
    }

    return $u;
}

/** One open window of hers on this desk. */
function aeWindow(ShiftMember $m): QueueEntry
{
    $e = QueueEntry::factory()->create([
        'shift_id' => $m->shift_id, 'shift_member_id' => $m->id, 'assigned_user_id' => $m->user_id, 'status' => 'active',
        'window_no' => 1, 'called_at' => now(), 'delivered_at' => now(),
    ]);
    $e->conversation->update(['assignee_id' => $m->user_id, 'assigned_at' => now(), 'queue_entry_id' => $e->id, 'handler' => 'human']);
    $m->update(['status' => 'busy']);

    return $e;
}

it('tells the inbox whether she may check in, and when the shift starts, without opening anything', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 09:30', 'Africa/Cairo'));
    $u = aeModerator();

    $before = $this->actingAs($u)->getJson('/queue/me')->assertOk()
        ->assertJsonPath('data.member', null)
        ->assertJsonPath('data.attendance.eligible', true)
        ->assertJsonPath('data.attendance.shift_open', false)
        ->assertJsonPath('data.attendance.shift_name', null);
    expect(Carbon::parse($before->json('data.attendance.next_starts_at'))->equalTo(Carbon::parse('2026-10-05 10:00', 'Africa/Cairo')))->toBeTrue();

    Carbon::setTestNow(Carbon::parse('2026-10-05 10:00:05', 'Africa/Cairo')); // its time came; the tick has not opened it yet
    $this->actingAs($u)->getJson('/queue/me')->assertOk()
        ->assertJsonPath('data.attendance.shift_open', true)
        ->assertJsonPath('data.attendance.shift_name', 'صباحي')
        ->assertJsonPath('data.attendance.next_starts_at', null);

    $this->actingAs(User::factory()->create(['role' => 'admin']))->getJson('/queue/me')->assertOk()
        ->assertJsonPath('data.attendance.eligible', false);

    expect(Shift::count())->toBe(0);
});

it('checks her in from the inbox, and the router gives her the lounge', function () {
    Shift::factory()->create();
    $waiting = QueueEntry::factory()->create();
    $u = aeModerator();

    $this->actingAs($u)->postJson('/queue/me/check-in')->assertOk()
        ->assertJsonPath('data.member.user.id', $u->id)
        ->assertJsonPath('data.member.status', 'busy')
        ->assertJsonPath('data.entries.0.id', $waiting->id)
        ->assertJsonPath('data.attendance.shift_open', true);
});

it('refuses a check-in outside the hours with the time the shift starts, and without a platform', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 08:00', 'Africa/Cairo'));

    $this->actingAs(aeModerator(['locale' => 'ar']))->postJson('/queue/me/check-in')->assertStatus(409)
        ->assertJsonPath('message', 'مفيش شيفت شغال دلوقتي. الشيفت بيبدأ 10:00.');

    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    $bare = User::factory()->create(['role' => 'moderator', 'locale' => 'ar', 'last_seen_at' => now()]);
    $this->actingAs($bare)->postJson('/queue/me/check-in')->assertForbidden()
        ->assertJsonPath('message', 'حسابك مش مسموح له بأي منصة، كلّمي المشرفة.');

    expect(ShiftMember::count())->toBe(0);
});

it('checks out from the inbox: at once without windows, else closing until she sends them back to the lounge', function () {
    Shift::factory()->create();
    $free = aeModerator();
    app(ShiftService::class)->checkIn($free);
    $this->actingAs($free)->postJson('/queue/me/check-out')->assertOk()->assertJsonPath('data.member', null);

    $u = aeModerator();
    $m = app(ShiftService::class)->checkIn($u);
    $e = aeWindow($m);

    $this->actingAs($u)->postJson('/queue/me/check-out')->assertOk()
        ->assertJsonPath('data.member.status', 'checking_out')->assertJsonPath('data.entries.0.id', $e->id);

    $this->actingAs($u)->postJson('/queue/me/hand-back')->assertOk()
        ->assertJsonPath('data.member', null)->assertJsonPath('data.entries', []);

    expect($m->fresh()->status)->toBe('left')
        ->and(QueueEntry::query()->where('status', 'waiting')->where('priority', 'returning')->count())->toBe(1);
});

it('refuses a hand-back when she is not checking out, and a check-out off the shift', function () {
    Shift::factory()->create();
    $u = aeModerator();
    app(ShiftService::class)->checkIn($u);

    $this->actingAs($u)->postJson('/queue/me/hand-back')->assertStatus(409)->assertJsonPath('message', __('errors.queue.not_checking_out'));
    $this->actingAs(aeModerator())->postJson('/queue/me/check-out')->assertNotFound()->assertJsonPath('message', __('errors.queue.not_on_shift'));
});

it('lets the leader check a moderator out and send her windows back on her behalf', function () {
    $shift = Shift::factory()->create();
    $leader = User::query()->findOrFail($shift->leader_user_id);
    $u = aeModerator();
    $m = app(ShiftService::class)->checkIn($u);
    aeWindow($m);

    $this->actingAs($leader)->postJson("/board/members/{$m->id}/check-out")->assertOk()->assertJsonPath('data.members.0.status', 'checking_out');
    $this->actingAs($leader)->postJson("/board/members/{$m->id}/hand-back")->assertOk()
        ->assertJsonCount(0, 'data.members')->assertJsonCount(1, 'data.waiting');

    expect($m->fresh()->status)->toBe('left')
        ->and(QueueAttendanceEvent::query()->where('user_id', $u->id)->where('event', 'out')->value('by_user_id'))->toBe($leader->id);

    $this->actingAs($leader)->postJson("/board/members/{$m->id}/check-out")->assertStatus(409)->assertJsonPath('message', __('errors.queue.member_gone'));
    $this->actingAs($leader)->postJson("/board/members/{$m->id}/hand-back")->assertStatus(409)->assertJsonPath('message', __('errors.queue.not_checking_out'));
    $this->actingAs($leader)->deleteJson("/board/members/{$m->id}")->assertNotFound();
});

it('refuses the attendance actions while the queue is off', function () {
    Shift::factory()->create();
    $u = aeModerator();
    $m = app(ShiftService::class)->checkIn($u);
    QueueSetting::current()->update(['enabled' => false]);

    foreach (['/queue/me/check-in', '/queue/me/check-out', '/queue/me/hand-back'] as $url) {
        $this->actingAs($u)->postJson($url)->assertStatus(409)->assertJsonPath('message', __('errors.queue.disabled'));
    }

    expect($m->fresh()->status)->toBe('available');
});
