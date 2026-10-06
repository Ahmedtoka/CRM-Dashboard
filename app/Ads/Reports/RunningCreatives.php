<?php

namespace App\Ads\Reports;

use App\Ads\Control\AdWriteService;
use App\Models\Ad;
use App\Models\MediaBuyer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Ads that ran (have metric rows) in the range, with their aggregates. */
final class RunningCreatives
{
    public const PER_PAGE = [10, 25, 50, 100];

    public const SORTS = ['spend', 'roas', 'ctr', 'impressions', 'clicks', 'purchases', 'conversations', 'date'];

    public const AD_COLUMNS = [
        'ad.id', 'ad.external_id', 'ad.name', 'ad.type', 'ad.status', 'ad.effective_status', 'ad.thumbnail_url', 'ad.image_url',
        'ad.video_url', 'ad.preview_url', 'ad.permalink_url', 'ad.instagram_permalink_url', 'ad.object_story_id', 'ad.headline',
        'ad.body', 'ad.created_time', 'ad.ad_account_id',
    ];

    public function __construct(private readonly AdsQuery $q, private readonly AdInsights $insights) {}

    /**
     * @param  array{status?:string, account?:int|string|null, platform?:string|null, buyer?:int|string|null, q?:string|null, sort?:string, per_page?:int|string, page?:int|string}  $opts
     * @return array{data: list<array>, meta: array{total:int, per_page:int, current_page:int, last_page:int}, counts: array{all:int, active:int, inactive:int}, accounts: list<array{id:int, name:string, count:int}>, totals: array}
     */
    public function build(AdsFilter $f, array $opts): array
    {
        $accountF = $this->narrow($f, $opts);
        $f = $this->narrow($f, $opts, withAccount: false);
        $status = in_array($opts['status'] ?? null, ['active', 'inactive'], true) ? $opts['status'] : 'all';
        $search = trim((string) ($opts['q'] ?? ''));
        $sort = in_array($opts['sort'] ?? null, self::SORTS, true) ? $opts['sort'] : 'spend';
        $perPage = in_array((int) ($opts['per_page'] ?? 0), self::PER_PAGE, true) ? (int) $opts['per_page'] : 25;
        $dir = ($opts['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $extra = array_intersect_key($opts, array_flip(['only_ids', 'no_result_min', 'objective']));

        // counts: every filter but status; accounts: every filter but the account
        $c = $this->base($accountF, null, $search, $extra)
            ->selectRaw("COUNT(*) as total, COALESCE(SUM(CASE WHEN ad.effective_status = 'ACTIVE' THEN 1 ELSE 0 END), 0) as active")->first();
        $counts = ['all' => (int) $c->total, 'active' => (int) $c->active, 'inactive' => (int) $c->total - (int) $c->active];
        $accounts = $this->base($f, $status, $search, $extra)
            ->select(['acc.id', 'acc.name'])->selectRaw('COUNT(*) as n')->groupBy('acc.id', 'acc.name')->orderByDesc('n')->orderBy('acc.id')->get()
            ->map(fn ($r) => ['id' => (int) $r->id, 'name' => (string) $r->name, 'count' => (int) $r->n])->all();

        $total = $counts[$status === 'all' ? 'all' : $status];
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, (int) ($opts['page'] ?? 1)), $lastPage);

        $pageRows = $this->sorted($this->base($accountF, $status, $search, $extra)->select(self::AD_COLUMNS)->addSelect(['g.*', 'acc.name as account_name', 'acc.platform', 'camp.name as campaign_name', 'st.name as adset_name', 'camp.status as campaign_status', 'st.status as adset_status', 'camp.objective as objective']), $sort, $dir)
            ->forPage($page, $perPage)->get();

        $totals = $this->base($accountF, $status, $search, $extra)
            ->selectRaw('COALESCE(SUM(g.spend), 0) as spend, COALESCE(SUM(g.purchase_value), 0) as purchase_value, COALESCE(SUM(g.purchases), 0) as purchases, '
                .'COALESCE(SUM(g.impressions), 0) as impressions, COALESCE(SUM(g.clicks), 0) as clicks, COALESCE(SUM(g.reach), 0) as reach')->first();

        return [
            'data' => $this->rows($pageRows->all(), $accountF),
            'meta' => ['total' => $total, 'per_page' => $perPage, 'current_page' => $page, 'last_page' => $lastPage],
            'counts' => $counts,
            'accounts' => $accounts,
            'totals' => $this->q->derive($totals ?? []),
        ];
    }

    /** One ad's row (with preview_html) over the filter; zeros when it did not run in range. */
    public function detail(Ad $ad, AdsFilter $f): array
    {
        $f = $f->allSpend(); // a direct link to one ad shows its numbers whatever its campaign status
        $agg = $this->q->sums($f, ['ad_id' => 'm.ad_id'], fn ($b) => $b->where('m.ad_id', $ad->id))->first();
        $row = DB::table('ads as ad')
            ->join('ad_accounts as acc', 'acc.id', '=', 'ad.ad_account_id')
            ->leftJoin('ad_campaigns as camp', 'camp.id', '=', 'ad.ad_campaign_id')
            ->leftJoin('ad_sets as st', 'st.id', '=', 'ad.ad_set_id')
            ->where('ad.id', $ad->id)
            ->select(self::AD_COLUMNS)->addSelect(['acc.name as account_name', 'acc.platform', 'camp.name as campaign_name', 'st.name as adset_name', 'camp.status as campaign_status', 'st.status as adset_status', 'camp.objective as objective'])
            ->first();
        $row->msg_conversations = (int) DB::table('ad_daily_metrics')->where('ad_id', $ad->id)
            ->whereBetween('date', [$f->fromDate(), $f->toDate()])->sum('msg_conversations');
        foreach (['spend', 'purchase_value', 'purchases', 'impressions', 'clicks', 'reach'] as $k) {
            $row->{$k} = $agg->{$k} ?? 0;
        }

        return $this->rows([$row], $f)[0] + ['preview_html' => $ad->preview_html];
    }

    /**
     * Row keys of the creatives tables from joined ad rows that carry metric sums.
     *
     * @param  list<object>  $rows
     * @return list<array>
     */
    public function rows(array $rows, AdsFilter $f, ?array $insights = null): array
    {
        $ids = array_map(fn ($r) => (int) $r->id, $rows);
        $orders = $ids === [] ? collect() : $this->q->orders($f)->whereIn('ad_id', $ids)->groupBy('ad_id');
        $day = CarbonImmutable::now(AdsFilter::TIMEZONE)->startOfDay();
        $todayF = $f->allSpend()->with(['from' => $day, 'to' => $day]);
        $today = $ids === [] ? collect() : $this->q->sums($todayF, ['ad_id' => 'm.ad_id'], fn ($b) => $b->whereIn('m.ad_id', $ids))
            ->mapWithKeys(fn ($s) => [(int) $s->ad_id => (float) $s->spend]);
        $buyers = $this->buyers($ids, $f);
        $insights ??= $this->insights->forAds($ids, $f->to);

        return array_map(function (object $r) use ($orders, $today, $buyers, $insights) {
            $d = $this->q->derive($r);
            $mine = $orders->get((int) $r->id, collect());
            $revenue = round((float) $mine->sum('net'), 2);
            $chats = (int) ($r->msg_conversations ?? 0);

            return [
                'id' => (int) $r->id,
                'external_id' => (string) $r->external_id,
                'name' => (string) $r->name,
                'platform' => (string) $r->platform,
                'account' => (string) $r->account_name,
                'account_id' => (int) $r->ad_account_id,
                'campaign' => $r->campaign_name,
                'adset' => $r->adset_name,
                'type' => $r->type,
                'status' => $r->status,
                'effective_status' => $r->effective_status,
                'parent_paused' => AdWriteService::statusKind($r->campaign_status ?? null) === 'paused' || AdWriteService::statusKind($r->adset_status ?? null) === 'paused',
                'thumbnail_url' => $r->thumbnail_url,
                'image_url' => $r->image_url,
                'video_url' => $r->video_url,
                'preview_url' => $r->preview_url,
                'permalink_url' => $r->permalink_url,
                'instagram_permalink_url' => $r->instagram_permalink_url,
                'object_story_id' => $r->object_story_id,
                'headline' => $r->headline,
                'body' => $r->body,
                'created_time' => $r->created_time !== null ? CarbonImmutable::parse((string) $r->created_time, 'UTC')->toIso8601String() : null,
                'impressions' => $d['impressions'],
                'clicks' => $d['clicks'],
                'ctr' => $d['ctr'],
                'purchases' => $d['purchases'],
                'spend' => $d['spend'],
                'spend_tax' => $d['spend_tax'],
                'purchase_value' => $d['purchase_value'],
                'roas' => $d['roas'],
                'real_orders' => $mine->count(),
                'real_revenue' => $revenue,
                'real_roas' => AdsQuery::ratio($revenue, $d['spend'], 2),
                'objective' => Objective::family($r->objective ?? null, $chats),
                'conversations' => $chats,
                'spend_today' => round((float) ($today[(int) $r->id] ?? 0), 2),
                'buyer' => $buyers[(int) $r->id] ?? null,
                'trend' => $insights[(int) $r->id]['trend'],
                'fatigue' => $insights[(int) $r->id]['fatigue'],
            ];
        }, $rows);
    }

    /** Applies the page's own platform/buyer/account options on top of the (already scoped) filter. */
    public function narrow(AdsFilter $f, array $opts, bool $withAccount = true): AdsFilter
    {
        $changes = [];
        if (filled($opts['platform'] ?? null) && $f->platform === null) {
            $changes['platform'] = (string) $opts['platform'];
        }
        if (is_numeric($opts['buyer'] ?? null) && $f->restrictBuyerId === null) {
            $changes['buyerId'] = (int) $opts['buyer'];
        }
        if ($withAccount && is_numeric($opts['account'] ?? null)) {
            $a = (int) $opts['account'];
            $changes['accountIds'] = $f->accountIds === null ? [$a] : array_values(array_intersect($f->accountIds, [$a]));
        }

        return $changes === [] ? $f : $f->with($changes);
    }

    /**
     * ads joined to their range aggregates (g), accounts, campaigns and ad sets.
     *
     * @param  array{only_ids?: ?list<int>, no_result_min?: float|int|string|null, objective?: ?string}  $extra
     */
    private function base(AdsFilter $f, ?string $status, string $search, array $extra = []): Builder
    {
        $agg = $this->q->metrics($f)->select(['m.ad_id'])
            ->selectRaw(AdsQuery::SUMS.', COALESCE(SUM(m.msg_conversations), 0) as msg_conversations')->groupBy('m.ad_id');

        $q = DB::table('ads as ad')
            ->joinSub($agg, 'g', 'g.ad_id', '=', 'ad.id')
            ->join('ad_accounts as acc', 'acc.id', '=', 'ad.ad_account_id')
            ->leftJoin('ad_campaigns as camp', 'camp.id', '=', 'ad.ad_campaign_id')
            ->leftJoin('ad_sets as st', 'st.id', '=', 'ad.ad_set_id');

        if ($status === 'active') {
            $q->where('ad.effective_status', 'ACTIVE');
        } elseif ($status === 'inactive') {
            $q->where(fn ($w) => $w->whereNull('ad.effective_status')->orWhere('ad.effective_status', '!=', 'ACTIVE'));
        }
        if ($search !== '') {
            $q->where('ad.name', 'like', '%'.$search.'%');
        }
        if (array_key_exists('only_ids', $extra) && $extra['only_ids'] !== null) {
            $extra['only_ids'] === [] ? $q->whereRaw('1 = 0') : $q->whereIn('ad.id', $extra['only_ids']);
        }
        if (($extra['no_result_min'] ?? null) !== null) {
            // Inlined as a formatted float: SQLite binds floats as text, and a text bound never compares as a number
            // against an aggregate with no column affinity.
            $q->whereRaw('g.spend >= '.sprintf('%.2F', (float) $extra['no_result_min']))
                ->where('g.purchases', '<=', 0)->where('g.msg_conversations', '<=', 0);
        }
        $family = $extra['objective'] ?? null;
        if ($family === Objective::MESSAGES) {
            $q->where(fn ($w) => $w->whereIn('camp.objective', Objective::values(Objective::MESSAGES))->orWhere('g.msg_conversations', '>', 0));
        } elseif (in_array($family, [Objective::SALES, Objective::TRAFFIC], true)) {
            $q->whereIn('camp.objective', Objective::values($family))->where('g.msg_conversations', '<=', 0);
        }

        return $q;
    }

    private function sorted(Builder $q, string $sort, string $dir = 'desc'): Builder
    {
        $d = $dir === 'asc' ? 'ASC' : 'DESC';
        match ($sort) {
            'roas' => $q->orderByRaw("g.purchase_value * 1.0 / NULLIF(g.spend, 0) {$d}"),
            'ctr' => $q->orderByRaw("g.clicks * 1.0 / NULLIF(g.impressions, 0) {$d}"),
            'date' => $q->orderBy('ad.created_time', $dir),
            'conversations' => $q->orderBy('g.msg_conversations', $dir),
            default => $q->orderBy("g.{$sort}", $dir),
        };

        return $q->orderByDesc('g.spend')->orderByDesc('ad.id');
    }

    /**
     * Buyer of each ad over the range = the buyer with the most spend on it (null = unassigned).
     *
     * @param  list<int>  $ids
     * @return array<int, string|null>
     */
    private function buyers(array $ids, AdsFilter $f): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->q->sums($f, ['ad_id' => 'm.ad_id', 'buyer_id' => 'a.media_buyer_id'], fn ($b) => $b->whereIn('m.ad_id', $ids));
        $names = MediaBuyer::query()->whereIn('id', $rows->pluck('buyer_id')->filter()->unique())->pluck('name', 'id');

        $out = [];
        foreach ($rows->sortByDesc(fn ($r) => (float) $r->spend) as $r) {
            if (! array_key_exists((int) $r->ad_id, $out)) {
                $out[(int) $r->ad_id] = $r->buyer_id !== null ? ($names[(int) $r->buyer_id] ?? null) : null;
            }
        }

        return $out;
    }
}
