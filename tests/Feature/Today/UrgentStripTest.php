<?php

use App\Ads\Launch\LaunchCounters;
use App\Ads\Launch\LaunchState;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\AdAccount;
use App\Models\AdsSyncRun;
use App\Models\Order;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\SupportCase;
use App\Models\User;
use App\Today\UrgentStrip;
use Illuminate\Support\Carbon;
use Tests\Support\LaunchWorld;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-06 12:00', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true]);
});

function usAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin]);
}

function usInLounge(array $attrs = []): QueueEntry
{
    $e = QueueEntry::factory()->create($attrs);
    $e->conversation->update(['queue_entry_id' => $e->id, 'handler' => 'human', 'needs_human' => true]);

    return $e;
}

it('shows nothing when nothing is urgent', function () {
    expect(app(UrgentStrip::class)->for(usAdmin()))->toBe([]);
});

it('counts each item exactly as the screen it links to lists it', function () {
    $admin = usAdmin();
    $agent = User::factory()->create();
    usInLounge(['status' => 'active', 'assigned_user_id' => $agent->id, 'awaiting_reply_since' => now()->subMinutes(9), 'apology_sent_at' => now()->subMinutes(2)]);
    usInLounge(['enqueued_at' => now()->subMinutes(14)]);
    usInLounge(['enqueued_at' => now()->subMinutes(3)]);
    QueueEntry::factory()->create(['assigned_user_id' => $agent->id, 'status' => 'closed', 'close_reason' => 'inquiry', 'review_stars' => 1, 'reviewed_at' => now()->subHour()]);
    QueueEntry::factory()->create(['assigned_user_id' => $agent->id, 'status' => 'closed', 'close_reason' => 'inquiry', 'review_stars' => 2, 'reviewed_at' => now()->subDay()]); // yesterday
    Order::factory()->create(['status' => OrderStatus::AwaitingPayment, 'created_at' => now()->subHours(3)]);
    Order::factory()->create(['status' => OrderStatus::AwaitingPayment, 'created_at' => now()->subHour()]);    // not 2 h yet
    Order::factory()->create(['status' => OrderStatus::AwaitingPayment, 'created_at' => now()->subDays(9)]);   // stale, outside the week
    SupportCase::factory()->create(['status' => 'new', 'sla_due_at' => now()->subMinute()]);
    SupportCase::factory()->create(['status' => 'closed', 'sla_due_at' => now()->subHour(), 'closed_at' => now()]);

    $items = collect(app(UrgentStrip::class)->for($admin))->keyBy('key');

    expect($items->keys()->all())->toBe(['overdue_windows', 'lounge', 'low_ratings', 'unpaid_orders', 'cases_overdue'])
        ->and($items['overdue_windows'])->toMatchArray(['count' => 1, 'tone' => 'danger', 'href' => '/inbox?queue=overdue'])
        ->and($items['lounge'])->toMatchArray(['count' => 2, 'tone' => 'warn', 'href' => '/inbox?queue=waiting', 'longest_wait_seconds' => 840])
        ->and($items['low_ratings'])->toMatchArray(['count' => 1, 'href' => '/reports/team?from=2026-10-06&to=2026-10-06&stars=low#ratings'])
        ->and($items['unpaid_orders'])->toMatchArray(['count' => 1, 'href' => '/orders?status=awaiting_payment&older_than=120&from=2026-09-30'])
        ->and($items['cases_overdue'])->toMatchArray(['count' => 1, 'href' => '/cases?overdue=1']);

    // Every link opens a list of exactly that many rows.
    $this->actingAs($admin);
    $this->getJson(str_replace('/inbox?', '/inbox/conversations?', $items['overdue_windows']['href']))->assertOk()->assertJsonCount(1, 'data');
    $this->getJson(str_replace('/inbox?', '/inbox/conversations?', $items['lounge']['href']))->assertOk()->assertJsonCount(2, 'data');
    $this->get(strtok($items['low_ratings']['href'], '#'))->assertInertia(fn ($p) => $p->has('ratings.list', 1));
    $this->getJson($items['unpaid_orders']['href'])->assertOk()->assertJsonPath('meta.total', 1);
    $this->get($items['cases_overdue']['href'])->assertInertia(fn ($p) => $p->where('cases.meta.total', 1));
});

it('leaves the queue items out while the queue is off', function () {
    QueueSetting::current()->update(['enabled' => false]);
    usInLounge();

    expect(collect(app(UrgentStrip::class)->for(usAdmin()))->pluck('key')->all())->not->toContain('lounge');
});

it('flags an ads account whose last good sync is older than the stale threshold', function () {
    config(['crm.ads.health.stale_after_hours' => 3]);
    $acc = AdAccount::factory()->meta()->create(['last_synced_at' => now()->subHours(4)]);
    AdsSyncRun::factory()->create(['ad_account_id' => $acc->id, 'status' => 'ok', 'finished_at' => now()->subHours(4)]);
    $fresh = AdAccount::factory()->meta()->create(['last_synced_at' => now()->subMinutes(10)]);
    AdsSyncRun::factory()->create(['ad_account_id' => $fresh->id, 'status' => 'ok', 'finished_at' => now()->subMinutes(10)]);

    $item = collect(app(UrgentStrip::class)->for(usAdmin()))->firstWhere('key', 'ads_sync');

    expect($item)->toMatchArray(['count' => 1, 'age_minutes' => 240, 'tone' => 'warn', 'href' => route('ads.sync', absolute: false)]);
});

it('shows the launches awaiting the viewer\'s approval from LaunchCounters', function () {
    LaunchWorld::boot();
    $admin = User::factory()->adsAuthority()->create(['role' => UserRole::Admin]);
    LaunchWorld::launch(LaunchWorld::make(), LaunchState::AwaitingApproval); // S1 has no AdLaunch factory

    $expected = LaunchCounters::for($admin)['awaiting_approval'];
    $item = collect(app(UrgentStrip::class)->for($admin))->firstWhere('key', 'launches');

    expect($expected)->toBeGreaterThan(0)
        ->and($item)->toMatchArray(['count' => $expected, 'href' => route('ads.approvals.index', absolute: false)]);
});
