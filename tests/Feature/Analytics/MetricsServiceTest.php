<?php

use App\Analytics\MetricsService;
use App\Channels\Data\InboundMessageData;
use App\Enums\ActorType;
use App\Enums\CommentStatus;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\ParticipantRole;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Enums\UserRole;
use App\Inbox\InboxIngestor;
use App\Inbox\OutboundService;
use App\Models\ActivityLog;
use App\Models\AnalyticsDaily;
use App\Models\BotRule;
use App\Models\BotRun;
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\Comment;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\Order;
use App\Models\Post;
use App\Models\User;
use App\Models\UserSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

function t(string $s): CarbonImmutable
{
    return CarbonImmutable::parse($s, 'UTC');
}

function waConversation(array $attrs = []): Conversation
{
    $account = ChannelAccount::factory()->create(['platform' => Platform::WhatsApp]);

    return Conversation::factory()->create(array_merge(['channel_account_id' => $account->id], $attrs));
}

function msg(Conversation $c, MessageDirection $dir, SenderType $sender, string $at, ?User $u = null): Message
{
    return Message::factory()->create([
        'conversation_id' => $c->id,
        'direction' => $dir,
        'sender_type' => $sender,
        'user_id' => $u?->id,
        'created_at' => t($at),
        'updated_at' => t($at),
    ]);
}

it('computes first response time and counts per moderator', function () {
    Event::fake();
    BotSetting::current()->update(['enabled' => false]);
    ChannelAccount::factory()->create(['platform' => Platform::WhatsApp, 'external_id' => 'PN1']);
    $mod = User::factory()->create(['role' => UserRole::Supervisor]);
    $this->travelTo(CarbonImmutable::parse('2026-09-10 10:00:00'));
    $msg = app(InboxIngestor::class)->ingestMessage(new InboundMessageData(Platform::WhatsApp, 'PN1', '201000', 'Ali', 'w1', 'عايز اطلب', CarbonImmutable::now()));
    $this->travel(90)->seconds();
    app(OutboundService::class)->sendHuman($msg->conversation->fresh(), $mod, 'أهلا');
    $m = app(MetricsService::class)->userMetrics($mod, CarbonImmutable::parse('2026-09-10'), CarbonImmutable::parse('2026-09-10 23:59:59'));
    expect($m['messages_sent'])->toBe(1)->and($m['first_responses'])->toBe(1)->and($m['avg_first_response_sec'])->toBe(90)
        ->and($m['by_platform']['whatsapp']['messages_sent'])->toBe(1);
});

it('computes response time, roles, comments, orders and online minutes for a user', function () {
    $this->travelTo(t('2026-09-11 12:00:00'));
    $mod = User::factory()->create();
    $other = User::factory()->create();
    $c = waConversation();

    msg($c, MessageDirection::Out, SenderType::Bot, '2026-09-10 09:59:00');
    msg($c, MessageDirection::In, SenderType::Customer, '2026-09-10 10:00:00');
    msg($c, MessageDirection::Out, SenderType::User, '2026-09-10 10:01:00', $mod);   // 60s
    msg($c, MessageDirection::In, SenderType::Customer, '2026-09-10 10:05:00');
    msg($c, MessageDirection::In, SenderType::Customer, '2026-09-10 10:06:00');
    msg($c, MessageDirection::Out, SenderType::User, '2026-09-10 10:07:00', $mod);   // 120s from 10:05
    msg($c, MessageDirection::Out, SenderType::User, '2026-09-10 10:08:00', $mod);   // no pending customer message
    msg($c, MessageDirection::Out, SenderType::User, '2026-09-10 10:09:00', $other);

    ConversationParticipant::factory()->create(['conversation_id' => $c->id, 'user_id' => $mod->id, 'role' => ParticipantRole::Continued, 'first_message_at' => t('2026-09-10 10:01:00')]);
    ConversationParticipant::factory()->create(['conversation_id' => $c->id, 'user_id' => $mod->id, 'role' => ParticipantRole::FollowUp, 'first_message_at' => t('2026-09-10 10:07:00')]);
    ConversationParticipant::factory()->create(['conversation_id' => $c->id, 'user_id' => $mod->id, 'role' => ParticipantRole::FollowUp, 'first_message_at' => t('2026-09-08 10:07:00')]);

    foreach (['conversation.resolved', 'comment.replied', 'comment.hidden', 'comment.private_reply'] as $action) {
        ActivityLog::factory()->create(['user_id' => $mod->id, 'action' => $action, 'conversation_id' => $c->id, 'platform' => Platform::WhatsApp, 'created_at' => t('2026-09-10 11:00:00')]);
    }

    Order::factory()->create(['created_by_id' => $mod->id, 'platform' => Platform::WhatsApp, 'type' => OrderType::Cod, 'total' => 100, 'created_at' => t('2026-09-10 11:00:00')]);
    Order::factory()->create(['created_by_id' => $mod->id, 'platform' => Platform::WhatsApp, 'type' => OrderType::PaymentLink, 'total' => 250.5, 'paid_at' => t('2026-09-10 12:00:00'), 'created_at' => t('2026-09-10 11:00:00')]);
    Order::factory()->create(['created_by_id' => $mod->id, 'platform' => Platform::Facebook, 'type' => OrderType::PaymentLink, 'total' => 50, 'created_at' => t('2026-09-10 11:00:00')]);
    Order::factory()->create(['created_by_id' => $mod->id, 'platform' => Platform::WhatsApp, 'type' => OrderType::Cod, 'total' => 999, 'status' => OrderStatus::Cancelled, 'created_at' => t('2026-09-10 11:00:00')]);

    UserSession::factory()->create(['user_id' => $mod->id, 'started_at' => t('2026-09-09 23:30:00'), 'last_heartbeat_at' => t('2026-09-10 00:45:00')]);
    UserSession::factory()->create(['user_id' => $mod->id, 'started_at' => t('2026-09-10 08:00:00'), 'last_heartbeat_at' => t('2026-09-10 08:20:00'), 'ended_at' => t('2026-09-10 08:30:00')]);

    $m = app(MetricsService::class)->userMetrics($mod, t('2026-09-10 00:00:00'), t('2026-09-10 23:59:59'));

    expect(array_keys($m))->toBe(['messages_sent', 'conversations_handled', 'first_responses', 'continued', 'follow_ups', 'resolved', 'avg_first_response_sec', 'avg_response_sec', 'comments_handled', 'private_replies', 'orders_count', 'orders_total', 'cod_count', 'payment_link_count', 'payment_link_paid', 'online_minutes', 'by_platform', 'orders_created_count', 'orders_created_total', 'orders_delivered', 'revenue_realized', 'orders_returned', 'delivery_rate', 'return_rate', 'by_source'])
        ->and($m['messages_sent'])->toBe(3)
        ->and($m['conversations_handled'])->toBe(1)
        ->and($m['first_responses'])->toBe(0)
        ->and($m['continued'])->toBe(1)
        ->and($m['follow_ups'])->toBe(1)
        ->and($m['resolved'])->toBe(1)
        ->and($m['avg_first_response_sec'])->toBe(0)
        ->and($m['avg_response_sec'])->toBe(90)
        ->and($m['comments_handled'])->toBe(3)
        ->and($m['private_replies'])->toBe(1)
        ->and($m['orders_count'])->toBe(3)
        ->and($m['orders_total'])->toBe(400.5)
        ->and($m['cod_count'])->toBe(1)
        ->and($m['payment_link_count'])->toBe(2)
        ->and($m['payment_link_paid'])->toBe(1)
        ->and($m['online_minutes'])->toBe(75)
        ->and($m['by_platform']['whatsapp'])->toBe(['messages_sent' => 3, 'orders_count' => 2])
        ->and($m['by_platform']['facebook'])->toBe(['messages_sent' => 0, 'orders_count' => 1]);
});

it('does not start a response-time sample from a low-value customer message', function () {
    $this->travelTo(t('2026-09-12 12:00:00'));
    $mod = User::factory()->create();
    $c = waConversation();

    Message::factory()->create([
        'conversation_id' => $c->id,
        'direction' => MessageDirection::In,
        'sender_type' => SenderType::Customer,
        'is_low_value' => true,
        'created_at' => t('2026-09-12 10:00:00'),
        'updated_at' => t('2026-09-12 10:00:00'),
    ]);
    msg($c, MessageDirection::Out, SenderType::User, '2026-09-12 10:01:00', $mod);

    $m = app(MetricsService::class)->userMetrics($mod, t('2026-09-12 00:00:00'), t('2026-09-12 23:59:59'));

    expect($m['messages_sent'])->toBe(1)->and($m['avg_response_sec'])->toBe(0);
});

it('computes team metrics', function () {
    $this->travelTo(t('2026-09-10 20:00:00'));
    $mod = User::factory()->create();

    $waiting = waConversation(['last_customer_message_at' => t('2026-09-10 10:00:00'), 'needs_human' => true, 'created_at' => t('2026-09-10 09:00:00')]);
    msg($waiting, MessageDirection::In, SenderType::Customer, '2026-09-10 10:00:00');

    $answered = waConversation(['last_customer_message_at' => t('2026-09-10 10:00:00'), 'created_at' => t('2026-09-01 09:00:00')]);
    msg($answered, MessageDirection::In, SenderType::Customer, '2026-09-10 10:00:00');
    msg($answered, MessageDirection::Out, SenderType::User, '2026-09-10 10:02:00', $mod);
    msg($answered, MessageDirection::Out, SenderType::Bot, '2026-09-10 10:03:00');

    waConversation(['status' => ConversationStatus::Resolved, 'resolved_at' => t('2026-09-10 11:00:00'), 'needs_human' => true, 'last_customer_message_at' => t('2026-09-10 10:00:00'), 'created_at' => t('2026-09-01 09:00:00')]);

    ActivityLog::factory()->create(['user_id' => $mod->id, 'action' => 'conversation.first_response', 'platform' => Platform::WhatsApp, 'meta' => ['seconds' => 100], 'created_at' => t('2026-09-10 10:02:00')]);
    ActivityLog::factory()->create(['user_id' => $mod->id, 'action' => 'conversation.first_response', 'platform' => Platform::WhatsApp, 'meta' => ['seconds' => 201], 'created_at' => t('2026-09-10 10:02:00')]);

    $post = Post::factory()->create(['channel_account_id' => ChannelAccount::factory()->create(['platform' => Platform::Instagram])->id]);
    Comment::factory()->create(['post_id' => $post->id, 'status' => CommentStatus::Replied, 'created_at' => t('2026-09-10 10:00:00')]);
    Comment::factory()->create(['post_id' => $post->id, 'status' => CommentStatus::Hidden, 'created_at' => t('2026-09-10 10:00:00')]);

    Order::factory()->create(['platform' => Platform::WhatsApp, 'total' => 120, 'created_at' => t('2026-09-10 11:00:00')]);

    $m = app(MetricsService::class)->teamMetrics(t('2026-09-10 00:00:00'), t('2026-09-10 23:59:59'));

    $cairoHour = t('2026-09-10 10:00:00')->setTimezone('Africa/Cairo')->hour;

    expect(array_keys($m))->toBe(['inbound_messages', 'outbound_messages', 'bot_messages', 'conversations_new', 'conversations_resolved', 'waiting_now', 'needs_human_now', 'avg_first_response_sec', 'comments_total', 'comments_by_status', 'orders_count', 'orders_total', 'by_platform', 'by_hour', 'orders_created_count', 'orders_created_total', 'orders_delivered', 'revenue_realized', 'orders_returned', 'delivery_rate', 'return_rate', 'by_source'])
        ->and($m['inbound_messages'])->toBe(2)
        ->and($m['outbound_messages'])->toBe(1)
        ->and($m['bot_messages'])->toBe(1)
        ->and($m['conversations_new'])->toBe(1)
        ->and($m['conversations_resolved'])->toBe(1)
        ->and($m['waiting_now'])->toBe(1)
        ->and($m['needs_human_now'])->toBe(1)
        ->and($m['avg_first_response_sec'])->toBe(151)
        ->and($m['comments_total'])->toBe(2)
        ->and($m['comments_by_status'])->toBe(['new' => 0, 'replied' => 1, 'hidden' => 1, 'ignored' => 0])
        ->and($m['orders_count'])->toBe(1)
        ->and($m['orders_total'])->toBe(120.0)
        ->and($m['by_platform']['whatsapp'])->toBe(['inbound' => 2, 'outbound' => 1, 'comments' => 0, 'orders_count' => 1, 'orders_total' => 120.0])
        ->and($m['by_platform']['instagram']['comments'])->toBe(2)
        ->and($m['by_hour'])->toHaveCount(24)
        ->and($m['by_hour'][$cairoHour])->toBe(2);

    $ig = app(MetricsService::class)->teamMetrics(t('2026-09-10 00:00:00'), t('2026-09-10 23:59:59'), Platform::Instagram);
    expect($ig['inbound_messages'])->toBe(0)->and($ig['comments_total'])->toBe(2)->and($ig['waiting_now'])->toBe(0);
});

it('excludes spam and low-priority conversations from waiting_now and needs_human_now', function () {
    $this->travelTo(t('2026-09-13 20:00:00'));

    $normal = waConversation(['last_customer_message_at' => t('2026-09-13 10:00:00'), 'needs_human' => true]);
    msg($normal, MessageDirection::In, SenderType::Customer, '2026-09-13 10:00:00');

    $spam = waConversation(['priority' => 'spam', 'last_customer_message_at' => t('2026-09-13 10:00:00'), 'needs_human' => true]);
    msg($spam, MessageDirection::In, SenderType::Customer, '2026-09-13 10:00:00');

    $low = waConversation(['priority' => 'low', 'last_customer_message_at' => t('2026-09-13 10:00:00'), 'needs_human' => true]);
    msg($low, MessageDirection::In, SenderType::Customer, '2026-09-13 10:00:00');

    $m = app(MetricsService::class)->teamMetrics(t('2026-09-13 00:00:00'), t('2026-09-13 23:59:59'));

    expect($m['waiting_now'])->toBe(1)->and($m['needs_human_now'])->toBe(1);
});

it('computes bot metrics', function () {
    $this->travelTo(t('2026-09-10 20:00:00'));
    $mod = User::factory()->create();
    $rule = BotRule::factory()->create(['name' => 'price']);

    $auto = waConversation(['status' => ConversationStatus::Resolved, 'resolved_at' => t('2026-09-10 12:00:00')]);
    msg($auto, MessageDirection::Out, SenderType::Bot, '2026-09-10 10:00:00');
    ConversationParticipant::factory()->create(['conversation_id' => $auto->id, 'user_id' => null, 'role' => ParticipantRole::First]);

    $human = waConversation(['status' => ConversationStatus::Resolved, 'resolved_at' => t('2026-09-10 12:00:00')]);
    msg($human, MessageDirection::Out, SenderType::Bot, '2026-09-10 10:00:00');
    msg($human, MessageDirection::Out, SenderType::Bot, '2026-09-10 10:01:00');
    ConversationParticipant::factory()->create(['conversation_id' => $human->id, 'user_id' => $mod->id, 'role' => ParticipantRole::First]);

    $third = waConversation();
    $fourth = waConversation();
    BotRun::factory()->create(['conversation_id' => $third->id, 'engine' => 'rule', 'rule_id' => $rule->id, 'created_at' => t('2026-09-10 10:00:00')]);
    BotRun::factory()->create(['conversation_id' => $fourth->id, 'engine' => 'rule', 'rule_id' => $rule->id, 'created_at' => t('2026-09-10 10:00:00')]);
    BotRun::factory()->create(['conversation_id' => $third->id, 'engine' => 'ai', 'decision' => 'handover', 'cost_usd' => 0.0125, 'created_at' => t('2026-09-10 10:00:00')]);
    BotRun::factory()->create(['conversation_id' => $third->id, 'engine' => 'ai', 'cost_usd' => 0.5, 'created_at' => t('2026-09-01 10:00:00')]);

    foreach (['price', 'price', 'keyword'] as $reason) {
        ActivityLog::factory()->create(['actor_type' => ActorType::System, 'user_id' => null, 'action' => 'conversation.handover', 'conversation_id' => $third->id, 'meta' => ['reason' => $reason], 'created_at' => t('2026-09-10 10:00:00')]);
    }
    foreach (['comment.replied', 'comment.hidden', 'comment.private_reply', 'comment.private_reply'] as $action) {
        ActivityLog::factory()->create(['actor_type' => ActorType::Bot, 'user_id' => null, 'action' => $action, 'created_at' => t('2026-09-10 10:00:00')]);
    }
    ActivityLog::factory()->create(['actor_type' => ActorType::User, 'user_id' => $mod->id, 'action' => 'comment.replied', 'created_at' => t('2026-09-10 10:00:00')]);

    $m = app(MetricsService::class)->botMetrics(t('2026-09-10 00:00:00'), t('2026-09-10 23:59:59'));

    expect(array_keys($m))->toBe(['messages_sent', 'conversations_touched', 'auto_resolved', 'handovers', 'handover_rate', 'handover_reasons', 'rule_hits', 'ai_runs', 'ai_cost_usd', 'comments_replied', 'comments_hidden', 'private_replies', 'flows'])
        ->and($m['messages_sent'])->toBe(3)
        ->and($m['conversations_touched'])->toBe(4)
        ->and($m['auto_resolved'])->toBe(1)
        ->and($m['handovers'])->toBe(3)
        ->and($m['handover_rate'])->toBe(0.75)
        ->and($m['handover_reasons'])->toBe(['price' => 2, 'keyword' => 1])
        ->and($m['rule_hits'])->toBe([['rule_id' => $rule->id, 'name' => 'price', 'hits' => 2]])
        ->and($m['ai_runs'])->toBe(1)
        ->and($m['ai_cost_usd'])->toBe(0.0125)
        ->and($m['comments_replied'])->toBe(1)
        ->and($m['comments_hidden'])->toBe(1)
        ->and($m['private_replies'])->toBe(2);
});

it('returns zeroed bot metrics when there is no data', function () {
    $m = app(MetricsService::class)->botMetrics(t('2026-09-10 00:00:00'), t('2026-09-10 23:59:59'));

    expect($m['handover_rate'])->toBe(0.0)->and($m['rule_hits'])->toBe([])->and($m['handover_reasons'])->toBe([]);
});

it('builds a leaderboard sorted by messages sent and a Cairo heatmap', function () {
    $this->travelTo(t('2026-09-10 20:00:00'));
    $a = User::factory()->create(['name' => 'A', 'color' => '#111111']);
    $b = User::factory()->create(['name' => 'B', 'color' => '#222222']);
    $c = waConversation();
    msg($c, MessageDirection::Out, SenderType::User, '2026-09-10 10:00:00', $a);
    msg($c, MessageDirection::Out, SenderType::User, '2026-09-10 10:01:00', $b);
    msg($c, MessageDirection::Out, SenderType::User, '2026-09-10 22:30:00', $b); // Cairo next day 01:30

    $service = app(MetricsService::class);
    $board = $service->leaderboard(t('2026-09-10 00:00:00'), t('2026-09-10 23:59:59'));

    expect($board[0]['user'])->toBe(['id' => $b->id, 'name' => 'B', 'color' => '#222222'])
        ->and($board[0]['messages_sent'])->toBe(2)
        ->and($board[1]['user']['id'])->toBe($a->id);

    $heat = $service->hourlyHeatmap($b, t('2026-09-10 00:00:00'), t('2026-09-10 23:59:59'));
    $late = t('2026-09-10 22:30:00')->setTimezone('Africa/Cairo');
    $early = t('2026-09-10 10:01:00')->setTimezone('Africa/Cairo');

    expect($heat)->toHaveCount(7)->and($heat[0])->toHaveCount(24)
        ->and($heat[$late->dayOfWeek][$late->hour])->toBe(1)
        ->and($heat[$early->dayOfWeek][$early->hour])->toBe(1)
        ->and(array_sum(array_map('array_sum', $service->hourlyHeatmap(null, t('2026-09-10 00:00:00'), t('2026-09-10 23:59:59')))))->toBe(3);
});

it('reads analytics_daily for full past days in ranges longer than 31 days', function () {
    $this->travelTo(t('2026-09-10 12:00:00'));
    $mod = User::factory()->create();
    AnalyticsDaily::create(['date' => '2026-08-01', 'user_id' => $mod->id, 'platform' => null, 'messages_sent' => 7, 'first_responses' => 2, 'avg_first_response_sec' => 60, 'online_minutes' => 30]);
    AnalyticsDaily::create(['date' => '2026-08-01', 'user_id' => $mod->id, 'platform' => Platform::Facebook, 'messages_sent' => 7, 'orders_count' => 1]);

    $c = waConversation();
    msg($c, MessageDirection::Out, SenderType::User, '2026-09-10 10:00:00', $mod);

    // Columns without a rollup come from narrow counts over the full range.
    ConversationParticipant::factory()->create(['conversation_id' => $c->id, 'user_id' => $mod->id, 'role' => ParticipantRole::Continued, 'first_message_at' => t('2026-08-01 10:00:00')]);
    ActivityLog::factory()->create(['user_id' => $mod->id, 'action' => 'comment.private_reply', 'platform' => Platform::WhatsApp, 'created_at' => t('2026-08-01 10:00:00')]);
    Order::factory()->create(['created_by_id' => $mod->id, 'platform' => Platform::Facebook, 'type' => OrderType::PaymentLink, 'paid_at' => t('2026-08-01 11:00:00'), 'created_at' => t('2026-08-01 10:00:00')]);
    Order::factory()->create(['created_by_id' => $mod->id, 'platform' => Platform::Facebook, 'type' => OrderType::Cod, 'created_at' => t('2026-08-01 10:00:00')]);

    $queries = [];
    DB::listen(function ($q) use (&$queries) {
        $queries[] = [$q->sql, array_map(fn ($b) => $b instanceof DateTimeInterface ? $b->format('Y-m-d H:i:s') : (string) $b, $q->bindings)];
    });

    $m = app(MetricsService::class)->userMetrics($mod, t('2026-07-01 00:00:00'), t('2026-09-10 23:59:59'));

    $fullRangeMessageScans = collect($queries)->filter(fn ($q) => str_contains($q[0], '"messages"')
        && in_array('2026-07-01 00:00:00', $q[1], true)
        && in_array('2026-09-10 23:59:59', $q[1], true));

    expect($fullRangeMessageScans)->toBeEmpty()
        ->and($m['messages_sent'])->toBe(8)
        ->and($m['first_responses'])->toBe(2)
        ->and($m['avg_first_response_sec'])->toBe(60)
        ->and($m['online_minutes'])->toBe(30)
        ->and($m['continued'])->toBe(1)
        ->and($m['private_replies'])->toBe(1)
        ->and($m['cod_count'])->toBe(1)
        ->and($m['payment_link_count'])->toBe(1)
        ->and($m['payment_link_paid'])->toBe(1)
        ->and($m['by_platform']['facebook'])->toBe(['messages_sent' => 7, 'orders_count' => 1])
        ->and($m['by_platform']['whatsapp']['messages_sent'])->toBe(1);
});

it('uses per-platform analytics_daily rows for a platform-filtered range longer than 31 days', function () {
    $this->travelTo(t('2026-09-10 12:00:00'));
    $mod = User::factory()->create();
    AnalyticsDaily::create(['date' => '2026-08-15', 'user_id' => $mod->id, 'platform' => null, 'messages_sent' => 50, 'online_minutes' => 30]);
    AnalyticsDaily::create(['date' => '2026-08-15', 'user_id' => $mod->id, 'platform' => Platform::Facebook, 'messages_sent' => 7, 'first_responses' => 2, 'avg_first_response_sec' => 60, 'orders_count' => 1, 'orders_total' => 99.5]);
    AnalyticsDaily::create(['date' => '2026-08-15', 'user_id' => $mod->id, 'platform' => Platform::WhatsApp, 'messages_sent' => 11]);

    $fb = ChannelAccount::factory()->create(['platform' => Platform::Facebook]);
    msg(Conversation::factory()->create(['channel_account_id' => $fb->id]), MessageDirection::Out, SenderType::User, '2026-09-10 10:00:00', $mod);
    msg(waConversation(), MessageDirection::Out, SenderType::User, '2026-09-10 10:00:00', $mod);

    $m = app(MetricsService::class)->userMetrics($mod, t('2026-08-01 00:00:00'), t('2026-09-10 23:59:59'), Platform::Facebook);

    expect($m['messages_sent'])->toBe(8)
        ->and($m['first_responses'])->toBe(2)
        ->and($m['avg_first_response_sec'])->toBe(60)
        ->and($m['orders_count'])->toBe(1)
        ->and($m['orders_total'])->toBe(99.5)
        ->and($m['online_minutes'])->toBe(0)
        ->and($m['by_platform']['facebook'])->toBe(['messages_sent' => 8, 'orders_count' => 1])
        ->and($m['by_platform']['whatsapp'])->toBe(['messages_sent' => 0, 'orders_count' => 0]);
});
