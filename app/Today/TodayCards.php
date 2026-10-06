<?php

namespace App\Today;

use App\Analytics\ActivityLogger;
use App\Analytics\MetricsService;
use App\Enums\ConversationSource;
use App\Enums\OrderStatus;
use App\Models\ActivityLog;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\QueueSetting;
use App\Models\User;
use App\Queue\RatingStats;
use App\TestLinks\TestScope;

/**
 * The four cards of «النهارده» (wireframe 04 §4): chats, orders, ads, why she did not buy. Every figure reuses an
 * existing definition (MetricsService, the Ads reports, S3 outcomes) and carries the link of the screen that lists it.
 */
final class TodayCards
{
    /** @var array<string, array<string, mixed>> teamMetrics per window, one request */
    private array $team = [];

    public function __construct(
        private readonly MetricsService $metrics,
        private readonly QueueDay $queueDay,
        private readonly RatingStats $ratings,
    ) {}

    /** @return array{chats: array, orders: array, ads: ?array, why: array} */
    public function all(TodayWindow $w, User $u): array
    {
        return ['chats' => $this->chats($w), 'orders' => $this->orders($w), 'ads' => $this->ads($w, $u), 'why' => $this->why($w)];
    }

    public function chats(TodayWindow $w): array
    {
        $team = $this->teamMetrics($w);
        $new = (int) $team['conversations_new'];
        $fromAds = Conversation::query()->where('is_test', false)->where('source', ConversationSource::Ad->value)
            ->whereBetween('created_at', [$w->from, $w->to])->count();
        // MetricsService::botMetrics' auto_resolved and handovers, without its flow replay.
        $botAlone = Conversation::query()->where('is_test', false)->whereBetween('resolved_at', [$w->from, $w->to])
            ->whereDoesntHave('participants', fn ($q) => $q->whereNotNull('user_id'))->count();
        $toAgent = TestScope::excludeConversations(ActivityLog::query())->where('action', ActivityLogger::CONVERSATION_HANDOVER)
            ->whereBetween('created_at', [$w->from, $w->to])->count();
        $range = "from={$w->date}&to={$w->date}";

        return [
            'new' => $new,
            'from_ads' => $fromAds,
            'ads_share' => $new > 0 ? round($fromAds / $new, 2) : null,
            'bot_alone' => $botAlone,
            'to_agent' => $toAgent,
            'first_reply_avg_sec' => (int) $team['avg_first_response_sec'],
            'queue' => QueueSetting::current()->enabled ? $this->queueDay->for($w->date) : null,
            'rating' => $this->ratings->summary($w->from, $w->to),
            'links' => [
                'new' => "/reports/team?{$range}",
                'ads' => '/inbox?flags=ad',
                'bot' => "/reports/bot?{$range}",
                'first_reply' => "/reports/team?{$range}",
                'rating' => "/reports/team?{$range}#ratings",
                'queue' => '/board',
            ],
        ];
    }

    public function orders(TodayWindow $w): array
    {
        $team = $this->teamMetrics($w);
        $out = $this->teamMetrics($w->outcomeDay());
        $dayOrders = fn (OrderStatus $s) => Order::query()->where('status', $s->value)->whereBetween('created_at', [$w->from, $w->to])->count();
        $d = $w->date;

        return [
            'count' => (int) $team['orders_count'],
            'total' => (float) $team['orders_total'],
            'from_chat' => (int) $team['by_source']['chat']['created_count'],
            'from_store' => (int) $team['by_source']['store']['created_count'],
            'cancelled' => $dayOrders(OrderStatus::Cancelled),
            'failed' => $dayOrders(OrderStatus::Failed),
            'outcome_date' => $w->outcomeDay()->date,
            'delivered' => (int) $out['orders_delivered'],
            'returned' => (int) $out['orders_returned'],
            'links' => [
                'count' => "/orders?from={$d}&to={$d}",
                'from_chat' => "/orders?source=chat&from={$d}&to={$d}",
                'from_store' => "/orders?source=store&from={$d}&to={$d}",
                'cancelled' => "/orders?status=cancelled&from={$d}&to={$d}",
                'failed' => "/orders?status=failed&from={$d}&to={$d}",
                'delivered' => '/orders?shipment_step=delivered',
                'returned' => '/orders?shipment_step=returned',
            ],
        ];
    }

    /** Filled in Task 10. */
    public function ads(TodayWindow $w, User $u): ?array
    {
        return null;
    }

    /** Filled in Task 10. */
    public function why(TodayWindow $w): array
    {
        return ['total' => 0, 'ordered' => 0, 'reasons' => [], 'top_size_out' => null, 'links' => []];
    }

    private function teamMetrics(TodayWindow $w): array
    {
        return $this->team[$w->mode.$w->date] ??= $this->metrics->teamMetrics($w->from, $w->to);
    }
}
