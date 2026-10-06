<?php

namespace App\Today;

use App\Ads\Access\AdsScope;
use App\Ads\Reports\AdsFilter;
use App\Ads\Reports\AdsOverview;
use App\Ads\Reports\AdsQuery;
use App\Analytics\ActivityLogger;
use App\Analytics\MetricsService;
use App\Enums\ConversationSource;
use App\Enums\OrderStatus;
use App\Inbox\Outcomes\ChatFunnel;
use App\Inbox\Outcomes\Outcome;
use App\Models\ActivityLog;
use App\Models\Ad;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\QueueSetting;
use App\Models\User;
use App\Queue\RatingStats;
use App\TestLinks\TestScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

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
        private readonly AdsOverview $overview,
        private readonly AdsQuery $adsQuery,
        private readonly AdsScope $adsScope,
        private readonly ChatFunnel $funnel,
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

    public function ads(TodayWindow $w, User $u): ?array
    {
        $from = CarbonImmutable::parse($w->adsFromDate, TodayWindow::TZ);
        $to = CarbonImmutable::parse($w->adsToDate, TodayWindow::TZ);
        $f = new AdsFilter($from, $to, accountIds: $this->adsScope->accountIds($u, $from, $to));
        if ($f->isEmpty()) {
            return null;
        }

        $all = $f->allSpend();
        $totals = $this->overview->totals($all);
        $currency = $this->overview->currency($f);
        $mixed = $currency === AdsOverview::MIXED;
        $spend = $mixed ? null : round((float) $totals['spend'], 2);
        $orders = (int) $totals['real_orders'];

        $ordersByAd = $this->adsQuery->orders($all)->whereNotNull('ad_id')->countBy('ad_id');
        $spendByAd = $this->adsQuery->sums($all, ['ad_id' => 'm.ad_id'])->mapWithKeys(fn (object $r) => [(int) $r->ad_id => (float) $r->spend]);
        $bestId = $ordersByAd->sortDesc()->keys()->first();
        $loserId = $spendByAd->filter(fn (float $s, int $id) => $s > 0 && ! $ordersByAd->has($id))->sortDesc()->keys()->first();
        $names = Ad::query()->whereIn('id', array_filter([$bestId, $loserId]))->pluck('name', 'id');
        $q = "from={$f->fromDate()}&to={$f->toDate()}";

        return [
            'from' => $f->fromDate(),
            'to' => $f->toDate(),
            'currency' => $currency,
            'spend' => $spend,
            'real_orders' => $orders,
            // Order revenue is EGP: over foreign or mixed spend it is not a ROAS (as AdsOverview::build).
            'real_roas' => $mixed || $currency !== 'EGP' ? null : $totals['real_roas'],
            'meta_roas' => $mixed ? null : $totals['roas'],
            'cost_per_order' => $spend !== null && $orders > 0 ? round($spend / $orders, 2) : null,
            'best' => $bestId !== null ? ['id' => (int) $bestId, 'name' => (string) ($names[$bestId] ?? ''), 'orders' => (int) $ordersByAd[$bestId]] : null,
            'loser' => $loserId !== null && ! $mixed ? ['id' => (int) $loserId, 'name' => (string) ($names[$loserId] ?? ''), 'spend' => round($spendByAd[$loserId], 2)] : null,
            'links' => [
                'spend' => "/ads/numbers?{$q}",
                'orders' => "/ads/explorer?{$q}",
                'best' => $bestId !== null ? "/ads/explorer?{$q}&ad={$bestId}" : null,
                'loser' => $loserId !== null ? "/ads/explorer?{$q}&ad={$loserId}" : null,
            ],
        ];
    }

    public function why(TodayWindow $w): array
    {
        $counts = DB::table('conversation_outcomes as o')->join('conversations as c', 'c.id', '=', 'o.conversation_id')
            ->where('c.is_test', false)->whereBetween('o.set_at', [$w->from, $w->to])
            ->groupBy('o.outcome')->selectRaw('o.outcome as outcome, COUNT(*) as n')->pluck('n', 'outcome')
            ->map(fn ($n) => (int) $n);

        $lost = $counts->except([Outcome::Ordered->value, Outcome::Unknown->value])->sortDesc();
        $total = (int) $lost->sum();
        $range = "from={$w->date}&to={$w->date}";
        $top = $this->topSizeOut($w);

        return [
            'total' => $total,
            'ordered' => (int) ($counts[Outcome::Ordered->value] ?? 0),
            'reasons' => $lost->map(fn (int $n, string $key) => ['key' => $key, 'count' => $n, 'share' => round($n / max(1, $total), 2)])->values()->all(),
            'top_size_out' => $top,
            'links' => ['reasons' => "/ads/numbers?{$range}#why", 'top_size_out' => $top ? "/ads/explorer?{$range}&ad={$top['id']}" : null],
        ];
    }

    /** The ad whose chats most often ended «مقاس مش موجود» (S3 ChatFunnel reasons), among the ads chatted about. */
    private function topSizeOut(TodayWindow $w): ?array
    {
        $adIds = DB::table('conversations as c')->join('ads', 'ads.external_id', '=', 'c.ad_id')
            ->where('c.is_test', false)->whereBetween('c.created_at', [$w->from, $w->to])
            ->distinct()->limit(500)->pluck('ads.id')->map(fn ($id) => (int) $id)->all();
        if ($adIds === []) {
            return null;
        }

        $best = collect($this->funnel->forAds($adIds, $w->from, $w->to))
            ->map(fn (array $f) => (int) ($f['reasons'][Outcome::SizeOut->value] ?? 0))
            ->filter(fn (int $n) => $n > 0)->sortDesc();
        if ($best->isEmpty()) {
            return null;
        }

        $id = (int) $best->keys()->first();

        return ['id' => $id, 'name' => (string) Ad::query()->whereKey($id)->value('name'), 'count' => $best->first()];
    }

    private function teamMetrics(TodayWindow $w): array
    {
        return $this->team[$w->mode.$w->date] ??= $this->metrics->teamMetrics($w->from, $w->to);
    }
}
