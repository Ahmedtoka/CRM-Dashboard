<?php

use App\Analytics\MetricsService;
use App\Enums\{MessageDirection, OrderType, ParticipantRole, Platform, SenderType};
use App\Models\{ActivityLog, AnalyticsDaily, ChannelAccount, Conversation, ConversationParticipant, Message, Order, User, UserSession};
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

function lbAt(string $s): CarbonImmutable
{
    return CarbonImmutable::parse($s, 'UTC');
}

function lbMessage(Conversation $c, MessageDirection $dir, SenderType $sender, string $at, ?User $u = null): void
{
    Message::factory()->create([
        'conversation_id' => $c->id,
        'platform' => $c->platform,
        'direction' => $dir,
        'sender_type' => $sender,
        'user_id' => $u?->id,
        'created_at' => lbAt($at),
        'updated_at' => lbAt($at),
    ]);
}

function lbTeamActivity(User $u, int $k): void
{
    $platform = [Platform::WhatsApp, Platform::Facebook, Platform::Instagram][$k % 3];
    $account = ChannelAccount::factory()->create(['platform' => $platform]);
    $c = Conversation::factory()->create(['channel_account_id' => $account->id, 'platform' => $platform]);

    lbMessage($c, MessageDirection::In, SenderType::Customer, '2026-09-10 10:00:00');
    lbMessage($c, MessageDirection::Out, SenderType::User, '2026-09-10 10:0'.($k + 1).':00', $u);
    lbMessage($c, MessageDirection::In, SenderType::Customer, '2026-09-10 11:00:00');
    for ($i = 0; $i <= $k; $i++) {
        lbMessage($c, MessageDirection::Out, SenderType::User, '2026-09-10 11:1'.$i.':00', $u);
    }

    ConversationParticipant::factory()->create(['conversation_id' => $c->id, 'user_id' => $u->id, 'role' => ParticipantRole::First, 'first_message_at' => lbAt('2026-09-10 10:01:00')]);
    ActivityLog::factory()->create(['user_id' => $u->id, 'action' => 'conversation.first_response', 'conversation_id' => $c->id, 'platform' => $platform, 'meta' => ['seconds' => 60 * ($k + 1)], 'created_at' => lbAt('2026-09-10 10:01:00')]);
    ActivityLog::factory()->create(['user_id' => $u->id, 'action' => 'comment.private_reply', 'conversation_id' => $c->id, 'platform' => $platform, 'created_at' => lbAt('2026-09-10 12:00:00')]);
    Order::factory()->create(['created_by_id' => $u->id, 'platform' => $platform, 'type' => OrderType::Cod, 'total' => 100 + $k, 'created_at' => lbAt('2026-09-10 12:00:00')]);
    Order::factory()->create(['created_by_id' => $u->id, 'platform' => $platform, 'type' => OrderType::PaymentLink, 'total' => 50, 'paid_at' => lbAt('2026-09-10 13:00:00'), 'created_at' => lbAt('2026-09-10 12:00:00')]);
    UserSession::factory()->create(['user_id' => $u->id, 'started_at' => lbAt('2026-09-10 09:00:00'), 'last_heartbeat_at' => lbAt('2026-09-10 09:40:00'), 'ended_at' => lbAt('2026-09-10 09:5'.$k.':00')]);
}

it('computes the whole leaderboard with a fixed number of queries and the same numbers as the user report', function () {
    $this->travelTo(lbAt('2026-09-10 20:00:00'));
    $users = User::factory()->count(6)->create();
    $users->each(fn (User $u, int $k) => lbTeamActivity($u, $k));
    User::factory()->create(['is_active' => false]);

    $service = app(MetricsService::class);
    $from = lbAt('2026-09-10 00:00:00');
    $to = lbAt('2026-09-10 23:59:59');

    DB::flushQueryLog();
    DB::enableQueryLog();
    $board = $service->leaderboard($from, $to);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($board)->toHaveCount(6)
        ->and($queries)->toBeLessThanOrEqual(12)
        ->and($board[0]['user']['id'])->toBe($users[5]->id);

    foreach ($board as $row) {
        $expected = $service->userMetrics(User::find($row['user']['id']), $from, $to);

        expect(Arr::except($row, 'user'))->toBe($expected);
    }
});

it('uses rolled-up days for past response times and scans only the rest', function () {
    $this->travelTo(lbAt('2026-09-10 12:00:00'));
    $u = User::factory()->create();

    // A rolled-up past day: 4 messages averaging 30 seconds.
    AnalyticsDaily::factory()->create(['user_id' => $u->id, 'platform' => null, 'date' => '2026-09-08', 'messages_sent' => 4, 'avg_response_sec' => 30]);

    // Today (Cairo), live: one reply 90 seconds after the customer.
    $account = ChannelAccount::factory()->create(['platform' => Platform::WhatsApp]);
    $c = Conversation::factory()->create(['channel_account_id' => $account->id, 'platform' => Platform::WhatsApp]);
    lbMessage($c, MessageDirection::In, SenderType::Customer, '2026-09-10 10:00:00');
    lbMessage($c, MessageDirection::Out, SenderType::User, '2026-09-10 10:01:30', $u);

    // Cairo dates 2026-09-08 .. 2026-09-10 as UTC bounds.
    $from = CarbonImmutable::parse('2026-09-08 00:00:00', 'Africa/Cairo')->utc();
    $to = CarbonImmutable::parse('2026-09-10 23:59:59', 'Africa/Cairo')->utc();

    $row = app(MetricsService::class)->leaderboard($from, $to)[0];

    // (4 × 30 + 1 × 90) / 5 = 42
    expect($row['avg_response_sec'])->toBe(42)
        ->and($row['messages_sent'])->toBe(1);
});
