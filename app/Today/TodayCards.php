<?php

namespace App\Today;

use App\Ads\Access\AdsScope;
use App\Ads\Reports\AdsFilter;
use App\Ads\Reports\AdsOverview;
use App\Ads\Reports\AdsQuery;
use App\Analytics\ActivityLogger;
use App\Analytics\MetricsService;
use App\Confirmations\ConfirmationSettings;
use App\Confirmations\Ui\ConfirmationBoard;
use App\Enums\OrderStatus;
use App\Inbox\ConversationQuery;
use App\Inbox\Outcomes\ChatFunnel;
use App\Inbox\Outcomes\Outcome;
use App\Models\ActivityLog;
use App\Models\Ad;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\QueueSetting;
use App\Models\User;
use App\Orders\OrdersAnalytics;
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
    /** Where «ليه ماشترتش» opens: one place to change (the integration may point it elsewhere). */
    public const WHY_PATH = '/ads/numbers';

    public const WHY_FRAGMENT = 'funnel';

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
        private readonly ConversationQuery $conversations,
        private readonly ConfirmationBoard $confirmationBoard,
        private readonly ConfirmationSettings $confirmationSettings,
    ) {}

    /** @return array{chats: array, orders: array, ads: ?array, why: array, confirmations: ?array} */
    public function all(TodayWindow $w, User $u): array
    {
        return [
            'chats' => $this->chats($w, $u), 'orders' => $this->orders($w), 'ads' => $this->ads($w, $u), 'why' => $this->why($w),
            'confirmations' => $this->confirmations($w, $u),
        ];
    }

    /**
     * WhatsApp order confirmation (spec 2026-10-16, W4): the day's counters and the call list, each opening its tab
     * of «تأكيدات الأوردرات». Null while the feature is off and the day has none (no empty card).
     */
    public function confirmations(TodayWindow $w, User $u): ?array
    {
        $counts = $this->confirmationBoard->counts($this->confirmationBoard->between($this->confirmationBoard->visibleTo($u), $w->from, $w->to));
        if ($counts['total'] === 0 && ! $this->confirmationSettings->enabled()) {
            return null;
        }

        // The call list is every confirmation still waiting for a call, whatever day it was made.
        $needsCall = $this->confirmationBoard->visibleTo($u)->needsCall()->count();
        $page = fn (string $tab) => self::link('/orders/confirmations', ['date' => $w->date, 'tab' => $tab === 'all' ? null : $tab]);

        return [
            'sent' => $counts['sent'],
            'confirmed' => $counts['confirmed'],
            'confirmed_share' => $counts['confirmed_share'],
            'cancel' => $counts['cancel'],
            'edit' => $counts['edit'],
            'no_response' => $counts['no_response'],
            'waiting' => $counts['waiting'],
            'needs_call' => $needsCall,
            'links' => [
                'sent' => $page('all'),
                'confirmed' => $page('confirmed'),
                'requests' => $page('requests'),
                // The call list has no day (ConfirmationBoard::ALL_DAYS_TABS).
                'needs_call' => self::link('/orders/confirmations', ['tab' => 'needs_call']),
            ],
        ];
    }

    /** `$u` is the viewer: «من إعلانات» is counted by the inbox's own query, so its link lists exactly those chats. */
    public function chats(TodayWindow $w, User $u): array
    {
        $team = $this->teamMetrics($w);
        $new = (int) $team['conversations_new'];
        $day = ['from' => $w->date, 'to' => $w->date];
        $fromAds = $this->conversations->filtered($u, ['flags' => ['ad']] + $day)->count();
        // MetricsService::botMetrics' auto_resolved and handovers, without its flow replay.
        $botAlone = Conversation::query()->where('is_test', false)->whereBetween('resolved_at', [$w->from, $w->to])
            ->whereDoesntHave('participants', fn ($q) => $q->whereNotNull('user_id'))->count();
        $toAgent = TestScope::excludeConversations(ActivityLog::query())->where('action', ActivityLogger::CONVERSATION_HANDOVER)
            ->whereBetween('created_at', [$w->from, $w->to])->count();

        return [
            'new' => $new,
            'from_ads' => $fromAds,
            'ads_share' => $new > 0 ? round($fromAds / $new, 2) : null,
            'bot_alone' => $botAlone,
            'to_agent' => $toAgent,
            'first_reply_avg_sec' => (int) $team['avg_first_response_sec'],
            'by_hour' => $this->newChatsByHour($w),
            'queue' => QueueSetting::current()->enabled ? $this->queueDay->for($w->date) : null,
            'rating' => $this->ratings->summary($w->from, $w->to),
            'links' => [
                'new' => self::link('/reports/team', $day),
                'ads' => self::link('/inbox', ['flags' => 'ad'] + $day),
                'bot' => self::link('/bot/performance', $day),
                'first_reply' => self::link('/reports/team', $day),
                'rating' => self::link('/reports/team', $day, 'ratings'),
                'queue' => '/board',
            ],
        ];
    }

    public function orders(TodayWindow $w): array
    {
        // One grouped query on the same order date the /orders list filters on (coalesce(placed_at, created_at),
        // fresh-orders review): every number opens exactly its rows. Counted orders leave out cancelled and
        // failed (MetricsService::EXCLUDED_ORDER_STATUSES): `real=1` lists the same set.
        $rows = Order::query()->toBase()
            ->whereRaw(OrdersAnalytics::ORDER_DATE.' between ? and ?', [$w->from->toDateTimeString(), $w->to->toDateTimeString()])
            ->groupBy('source', 'status')
            ->selectRaw('source, status, count(*) as n, sum(total) as total')
            ->get();
        $real = $rows->whereNotIn('status', MetricsService::EXCLUDED_ORDER_STATUSES);
        $byStatus = fn (OrderStatus $s) => (int) $rows->where('status', $s->value)->sum('n');
        $day = ['from' => $w->date, 'to' => $w->date];
        $realLink = ['real' => 1];

        return [
            'count' => (int) $real->sum('n'),
            'total' => round((float) $real->sum('total'), 2),
            'from_chat' => (int) $real->where('source', 'chat')->sum('n'),
            'from_store' => (int) $real->where('source', 'store')->sum('n'),
            'cancelled' => $byStatus(OrderStatus::Cancelled),
            'failed' => $byStatus(OrderStatus::Failed),
            'links' => [
                'count' => self::link('/orders', $realLink + $day),
                'from_chat' => self::link('/orders', $realLink + ['source' => 'chat'] + $day),
                'from_store' => self::link('/orders', $realLink + ['source' => 'store'] + $day),
                'cancelled' => self::link('/orders', ['status' => 'cancelled'] + $day),
                'failed' => self::link('/orders', ['status' => 'failed'] + $day),
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
        $q = ['from' => $f->fromDate(), 'to' => $f->toDate()];

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
                'spend' => self::link('/ads/numbers', $q),
                'orders' => self::link('/ads/explorer', $q),
                'best' => $bestId !== null ? self::link('/ads/explorer', $q + ['ad' => $bestId]) : null,
                'loser' => $loserId !== null ? self::link('/ads/explorer', $q + ['ad' => $loserId]) : null,
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
        $range = ['from' => $w->date, 'to' => $w->date];
        $top = $this->topSizeOut($w);

        return [
            'total' => $total,
            'ordered' => (int) ($counts[Outcome::Ordered->value] ?? 0),
            'reasons' => $lost->map(fn (int $n, string $key) => ['key' => $key, 'count' => $n, 'share' => round($n / max(1, $total), 2)])->values()->all(),
            'top_size_out' => $top,
            'links' => [
                'reasons' => self::link(self::WHY_PATH, $range, self::WHY_FRAGMENT),
                'top_size_out' => $top ? self::link('/ads/explorer', $range + ['ad' => $top['id']]) : null,
            ],
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

    /**
     * One link: path, query (null and '' dropped, in the order given) and an optional #fragment.
     *
     * @param  array<string, scalar|null>  $query
     */
    public static function link(string $path, array $query = [], ?string $fragment = null): string
    {
        $qs = http_build_query(array_filter($query, fn ($v) => $v !== null && $v !== ''), '', '&', PHP_QUERY_RFC3986);

        return $path.($qs !== '' ? '?'.$qs : '').($fragment !== null && $fragment !== '' ? '#'.$fragment : '');
    }

    /**
     * The hourly chart on «النهارده» (redesign p1): the window's new chats per Cairo hour, 0–23, on the same
     * definition as `new` (no test chats). Bucketed in PHP: a day is a few hundred rows, and no SQL date function
     * differs between MySQL and SQLite this way.
     *
     * @return list<int>
     */
    private function newChatsByHour(TodayWindow $w): array
    {
        $hours = array_fill(0, 24, 0);
        Conversation::query()->where('is_test', false)->whereBetween('created_at', [$w->from, $w->to])
            ->pluck('created_at')
            ->each(function ($at) use (&$hours) {
                $hours[(int) CarbonImmutable::parse($at)->setTimezone(TodayWindow::TZ)->format('G')]++;
            });

        return $hours;
    }

    private function teamMetrics(TodayWindow $w): array
    {
        return $this->team[$w->mode.$w->date] ??= $this->metrics->teamMetrics($w->from, $w->to);
    }
}
