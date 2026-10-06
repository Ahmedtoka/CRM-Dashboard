<?php

use App\Analytics\MetricsService;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\User;
use App\Models\UserSession;
use App\Today\TeamLine;
use App\Today\TodayWindow;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-06 12:00', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true]);
});

it('lists who worked the day with windows closed, orders and rating; leaves idle people out', function () {
    $mona = User::factory()->create(['role' => UserRole::Moderator, 'name' => 'Mona']);
    $sara = User::factory()->create(['role' => UserRole::Moderator, 'name' => 'Sara']);
    User::factory()->create(['role' => UserRole::Moderator, 'name' => 'Idle']);
    UserSession::factory()->create(['user_id' => $sara->id, 'ended_at' => null, 'last_heartbeat_at' => now()]);
    QueueEntry::factory()->count(2)->create(['assigned_user_id' => $mona->id, 'status' => 'closed', 'close_reason' => 'inquiry', 'closed_at' => now()]);
    QueueEntry::factory()->create(['assigned_user_id' => $mona->id, 'status' => 'closed', 'close_reason' => 'transfer', 'closed_at' => now()]); // not handled
    QueueEntry::factory()->create(['assigned_user_id' => $mona->id, 'status' => 'closed', 'close_reason' => 'inquiry', 'review_stars' => 4, 'reviewed_at' => now()]);
    Order::factory()->create(['created_by_id' => $mona->id, 'status' => OrderStatus::Confirmed, 'created_at' => now()->subHour()]);
    Order::factory()->create(['created_by_id' => $mona->id, 'status' => OrderStatus::Cancelled, 'created_at' => now()->subHour()]);

    $w = TodayWindow::for('today');
    $rows = app(TeamLine::class)->for($w);
    $board = collect(app(MetricsService::class)->leaderboard($w->from, $w->to))->keyBy('user.id');

    expect(collect($rows)->pluck('user.name')->all())->toBe(['Sara', 'Mona']) // online first
        ->and($rows[1])->toMatchArray(['online' => false, 'windows_closed' => 3, 'orders' => $board[$mona->id]['orders_count'], 'href' => "/reports/users/{$mona->id}?from=2026-10-06&to=2026-10-06"])
        ->and($rows[1]['orders'])->toBe(1)
        ->and($rows[1]['rating'])->toBe(['count' => 1, 'avg' => 4.0, 'low' => 0])
        ->and($rows[0])->toMatchArray(['online' => true, 'windows_closed' => 0, 'orders' => 0]);
});

it('shows nobody online on yesterday\'s report', function () {
    $sara = User::factory()->create(['role' => UserRole::Moderator]);
    UserSession::factory()->create(['user_id' => $sara->id, 'ended_at' => null, 'last_heartbeat_at' => now()]);

    expect(app(TeamLine::class)->for(TodayWindow::for('yesterday')))->toBe([]);
});
