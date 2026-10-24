<?php

use App\Models\BotRun;
use App\Models\Conversation;
use App\Models\ConversationOutcome;
use App\Models\Order;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\User;
use App\Queue\Reports\ServiceCost;
use Illuminate\Support\Carbon;

/**
 * «تكلفة الخدمة» (round 2026-10-24): what the team and the bot cost per handled conversation and
 * per order, for a range of Cairo business days. Every number here is checked by hand in the
 * comments.
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-20 12:00', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true, 'team_monthly_cost_egp' => 31000, 'usd_egp_rate' => 50]);
});

/** A handled (closed) window of `$u` on `$date` for `$c`. */
function scWindow(User $u, Conversation $c, string $date = '2026-10-20', string $reason = 'inquiry', array $attrs = []): QueueEntry
{
    return QueueEntry::factory()->create([
        'conversation_id' => $c->id, 'assigned_user_id' => $u->id, 'status' => 'closed', 'close_reason' => $reason, 'business_date' => $date,
        'delivered_at' => Carbon::parse($date.' 11:00', 'Africa/Cairo'), 'closed_at' => Carbon::parse($date.' 11:10', 'Africa/Cairo'), 'handle_seconds' => 600,
    ] + $attrs);
}

function scRun(Conversation $c, float $usd, string $at = '2026-10-20 10:00'): BotRun
{
    return BotRun::factory()->create(['conversation_id' => $c->id, 'cost_usd' => $usd, 'created_at' => Carbon::parse($at, 'Africa/Cairo')]);
}

it('splits the team cost of the period over the handled conversations and adds each one\'s bot cost', function () {
    $u = User::factory()->create();
    [$a, $b, $c, $botOnly] = Conversation::factory()->count(4)->create();
    scWindow($u, $a);
    scWindow($u, $b, reason: 'problem');
    scWindow($u, $c, reason: 'case');
    scWindow($u, $c, reason: 'no_reply'); // a hand-off is not a handled conversation
    scWindow($u, $a, '2026-10-01'); // another day
    scWindow($u, $b, attrs: ['is_test' => true]); // a test window never counts
    scRun($a, 0.10);
    scRun($a, 0.10);
    scRun($b, 0.20);
    scRun($botOnly, 0.40);
    scRun($c, 1.00, '2026-10-19 10:00'); // the day before: not in the period

    $r = app(ServiceCost::class)->build('2026-10-20', '2026-10-20');

    // October has 31 days: one day of the team costs 31000 / 31 = 1000 EGP, over 3 handled chats.
    expect($r['team_cost_egp'])->toBe(1000.0)
        ->and($r['handled_conversations'])->toBe(3)
        ->and($r['team_cost_per_conversation_egp'])->toBe(333.33)
        // Bot: 0.40 USD on the handled chats (a: 0.20, b: 0.20, c: 0) → 20 EGP / 3 = 6.67; all runs of the day: 0.80 USD = 40 EGP.
        ->and($r['bot_cost_usd'])->toBe(0.8)->and($r['bot_cost_egp'])->toBe(40.0)
        ->and($r['bot_cost_per_conversation_egp'])->toBe(6.67)
        ->and($r['cost_per_conversation_egp'])->toBe(340.0)
        ->and($r['bot_conversations'])->toBe(3)->and($r['bot_cost_per_bot_conversation_egp'])->toBe(13.33)
        ->and($r['total_egp'])->toBe(1040.0)
        ->and($r['usd_egp_rate'])->toBe(50.0)
        ->and($r['orders_from_chats'])->toBe(0)->and($r['cost_per_order_egp'])->toBeNull();
});

it('prices an order by the full cost of the chats that led to it: windows and bot runs', function () {
    $u = User::factory()->create();
    [$a, $b, $c] = Conversation::factory()->count(3)->create();
    scWindow($u, $a);
    scWindow($u, $a, reason: 'problem'); // a second window on the same chat: her share counts twice
    scWindow($u, $b);
    scWindow($u, $c);
    scRun($a, 0.50);
    scRun($b, 0.10);
    // Two orders from chats: one linked on the order row, one through the «ordered» outcome; a third order from the store alone.
    $o1 = Order::factory()->create(['conversation_id' => $a->id, 'created_at' => now()]);
    $o2 = Order::factory()->create(['created_at' => now()]);
    ConversationOutcome::query()->create(['conversation_id' => $b->id, 'episode_key' => 'e1', 'outcome' => 'ordered', 'source' => 'auto', 'set_at' => now(), 'order_id' => $o2->id, 'ended_at' => now()]);
    Order::factory()->create(['created_at' => now()]);
    Order::factory()->create(['conversation_id' => $c->id, 'created_at' => now()->subDays(3)]); // outside the period

    $r = app(ServiceCost::class)->build('2026-10-20', '2026-10-20');

    // 4 handled windows → 250 EGP each. Chat a: 2 × 250 + 25 = 525; chat b: 250 + 5 = 255. Two orders → 390 EGP each.
    expect($r['handled_conversations'])->toBe(4)->and($r['team_cost_per_conversation_egp'])->toBe(250.0)
        ->and($r['orders_from_chats'])->toBe(2)
        ->and($r['orders_chat_cost_egp'])->toBe(780.0)
        ->and($r['cost_per_order_egp'])->toBe(390.0);
});

it('spreads the monthly cost by the days of each month the range touches, and says when the settings are missing', function () {
    $u = User::factory()->create();
    scWindow($u, Conversation::factory()->create(), '2026-09-30');
    scWindow($u, Conversation::factory()->create(), '2026-10-01');

    // 30 Sept (30 days) + 1 Oct (31 days): 31000/30 + 31000/31 = 1033.33 + 1000.
    $r = app(ServiceCost::class)->build('2026-09-30', '2026-10-01');
    expect($r['team_cost_egp'])->toBe(2033.33)->and($r['handled_conversations'])->toBe(2)->and($r['days'])->toBe(2);

    QueueSetting::query()->whereKey(1)->update(['team_monthly_cost_egp' => null, 'usd_egp_rate' => null]);
    scRun(Conversation::factory()->create(), 0.30, '2026-10-01 10:00');
    $r = app(ServiceCost::class)->build('2026-09-30', '2026-10-01');
    expect($r['team_cost_egp'])->toBeNull()->and($r['team_cost_per_conversation_egp'])->toBeNull()
        ->and($r['bot_cost_usd'])->toBe(0.3)->and($r['bot_cost_egp'])->toBeNull()
        ->and($r['cost_per_conversation_egp'])->toBeNull()->and($r['missing'])->toBe(['team_monthly_cost_egp', 'usd_egp_rate']);
});

it('shows the service cost on the queue report page and lets an admin set the two settings', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $this->withoutVite();
    $this->actingAs($admin)->get('/reports/queue?from=2026-10-20&to=2026-10-20')
        ->assertOk()->assertInertia(fn ($page) => $page->component('Reports/Queue')->has('service_cost.cost_per_conversation_egp')->where('service_cost.usd_egp_rate', 50));

    $this->actingAs($admin)->put('/settings/queue', ['team_monthly_cost_egp' => 45000.5, 'usd_egp_rate' => 48.25])->assertRedirect();
    expect((float) QueueSetting::current()->team_monthly_cost_egp)->toBe(45000.5)->and((float) QueueSetting::current()->usd_egp_rate)->toBe(48.25);

    $sup = User::factory()->create(['role' => 'supervisor']);
    $this->actingAs($sup)->put('/settings/queue', ['team_monthly_cost_egp' => 1])->assertForbidden();
});
