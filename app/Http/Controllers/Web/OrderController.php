<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Concerns\OrderEndpoints;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Http\Support\ModeratorScope;
use App\Http\Support\SortParam;
use App\Models\Ad;
use App\Models\Order;
use App\Models\User;
use App\Orders\AdsManagerLink;
use App\Orders\OrdersAnalytics;
use App\Orders\OrdersByAd;
use App\Shopify\Client\ShopifyClient;
use App\Shopify\Jobs\RefreshShopifyOrders;
use App\Shopify\Sync\OrderRefresher;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class OrderController extends Controller
{
    use OrderEndpoints;

    /** Sortable columns of the web list (DataTable key => column). The API list keeps id desc. */
    private const SORTS = ['created_at' => 'created_at', 'total' => 'total', 'date' => 'date'];

    /** The /orders tabs (fresh-orders F5). */
    private const TABS = ['list', 'analytics', 'ads'];

    /**
     * The Orders page, or (for a plain `Accept: application/json` request, e.g. the activity/report widgets, the
     * «النهارده» links or a test) the same list as the API returns. The page has three tabs on one filter set
     * (fresh-orders F5): `list`, `analytics` (OrdersAnalytics) and `ads` (OrdersByAd); only the open tab is
     * computed. Without from/to the page shows this Cairo month; the JSON list keeps no default range.
     */
    public function index(Request $request, OrdersAnalytics $analytics, OrdersByAd $byAd): Response|AnonymousResourceCollection
    {
        $range = $request->wantsJson() ? null : $this->defaultRange($request);

        $filters = $this->orderFilters($request);

        if ($request->wantsJson()) {
            $query = $this->orderQuery($request);
            $this->applySort(SortParam::parse($request->query('sort'), self::SORTS), $query);

            return OrderResource::collection($query->paginate(30)->withQueryString());
        }

        $tab = in_array($request->query('tab'), self::TABS, true) ? (string) $request->query('tab') : 'list';
        $sort = SortParam::parse($request->query('sort'), self::SORTS);
        $orders = null;

        if ($tab === 'list') {
            $query = $this->orderQuery($request);
            $this->applySort($sort, $query);
            $query->with(ModeratorScope::ORDER_AD_RELATIONS); // the «المصدر» column (web page only)
            $orders = OrderResource::collection($query->paginate(30)->withQueryString());
        }

        return Inertia::render('Orders/Index', [
            'tab' => $tab,
            'orders' => $orders,
            'analytics' => $tab === 'analytics' ? $analytics->build($this->orderBaseQuery($request), $range['from'] ?? null, $range['to'] ?? null) : null,
            'adsBreakdown' => $tab === 'ads' ? $byAd->rows($this->orderBaseQuery($request)) : null,
            'range' => $range,
            'canOpenAds' => $request->user()->isSupervisorOrAbove(),
            'governorates' => $this->governorateOptions(),
            'filters' => array_merge([
                'status' => null, 'type' => null, 'platform' => null, 'q' => null, 'created_by' => null, 'from' => null, 'to' => null,
                'source' => null, 'financial_status' => null, 'fulfillment_status' => null, 'mismatch' => null, 'stuck' => null,
                'older_than' => null, 'real' => null, 'governorate' => null, 'ad_platform' => null,
            ], $filters, ['sort' => $sort?->value()]),
            // Options for the "created by" filter.
            'team' => User::query()->where('is_active', true)->inboxStaff()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * `/orders/ads/{ad}` (fresh-orders F5): one ad's orders under the page filters — summary, units per product
     * and the orders list. Role scope as on /orders (a moderator sees her platforms' orders of that ad).
     */
    public function ad(Request $request, Ad $ad, OrdersAnalytics $analytics): Response
    {
        $range = $this->defaultRange($request);
        $base = $this->orderBaseQuery($request)->where('orders.ad_id', $ad->id);
        $ad->loadMissing(['campaign:id,name', 'adSet:id,name', 'account:id,platform,external_id']);
        $platform = $ad->account?->platform;

        $query = $this->orderQuery($request)->where('orders.ad_id', $ad->id)->with(ModeratorScope::ORDER_AD_RELATIONS);

        return Inertia::render('Orders/AdOrders', [
            'ad' => [
                'id' => $ad->id,
                'name' => $ad->name,
                'thumbnail_url' => $ad->thumbnail_url,
                'platform' => $platform,
                'external_id' => $ad->external_id !== null ? (string) $ad->external_id : null,
                'campaign' => $ad->campaign?->name,
                'ad_set' => $ad->adSet?->name,
                'manager_url' => AdsManagerLink::for($platform, $ad->external_id !== null ? (string) $ad->external_id : null, $ad->account?->external_id),
            ],
            'summary' => $analytics->totals($base, $range['from']),
            'products' => $analytics->products($base, 20),
            'orders' => OrderResource::collection($query->paginate(30)->withQueryString()),
            'range' => $range,
            'canOpenAds' => $request->user()->isSupervisorOrAbove(),
        ]);
    }

    /**
     * Without from/to the page reads this Cairo month (F5 default), written into the request so the filters, the
     * queries and the page props all see the same range.
     *
     * @return array{from: ?string, to: ?string}
     */
    private function defaultRange(Request $request): array
    {
        $tz = (string) config('crm.timezone_display', 'Africa/Cairo');
        $today = CarbonImmutable::now($tz);

        // Triage presets («متوقف», «مش متطابق», «مستني الدفع», the «النهارده» waiting link) show every matching
        // order whatever its date: no month default, and no range unless the URL gives one.
        if (! $request->filled('from') && ! $request->filled('to') && $this->isTriage($request)) {
            return ['from' => null, 'to' => null];
        }

        if (! $request->filled('from') && ! $request->filled('to')) {
            $request->merge(['from' => $today->startOfMonth()->toDateString(), 'to' => $today->toDateString()]);
        }

        $from = (string) ($request->input('from') ?: $today->startOfMonth()->toDateString());
        $to = (string) ($request->input('to') ?: max($from, $today->toDateString()));

        return ['from' => $from, 'to' => $to];
    }

    /**
     * `date` sorts by the order date the filters use (coalesce(placed_at, created_at)); the other keys by column.
     *
     * @param  Builder<Order>  $query
     */
    private function applySort(?SortParam $sort, Builder $query): void
    {
        if ($sort === null) {
            return;
        }

        if ($sort->key === 'date') {
            $query->reorder()->orderByRaw(OrdersAnalytics::ORDER_DATE.' '.($sort->direction === 'desc' ? 'desc' : 'asc'))->orderBy('orders.id', 'desc');

            return;
        }

        $sort->apply($query);
    }

    /** stuck / mismatch / older_than / awaiting payment: the triage views that are not bound to a period. */
    private function isTriage(Request $request): bool
    {
        return $request->boolean('stuck')
            || $request->boolean('mismatch')
            || $request->filled('older_than')
            || $request->query('status') === 'awaiting_payment';
    }

    /** @return list<array{value: string, label: string}> the governorate filter (province codes, Arabic names) */
    private function governorateOptions(): array
    {
        return collect((array) config('crm.eg_provinces', []))
            ->map(fn (string $label, string $code) => ['value' => $code, 'label' => $label])
            ->sortBy('label')->values()->all();
    }

    public function show(Request $request, Order $order): Response
    {
        Gate::authorize('view', $order);

        return Inertia::render('Orders/Show', [
            'order' => $this->orderResource($order)->resolve($request),
            'canManage' => $request->user()->isSupervisorOrAbove(),
        ]);
    }

    /** Seconds one Shopify call of the per-order refresh may take (spec §3.2: 10 s limit). */
    private const REFRESH_TIMEOUT_SECONDS = 10;

    /** The on-view refresh asks for orders not read from Shopify for this long. */
    private const STALE_AFTER_MINUTES = 30;

    /** One on-view refresh per order per this many seconds, whoever asks. */
    private const STALE_LOCK_SECONDS = 300;

    /**
     * «تحديث من شوبيفاي»: reads the order from Shopify now and returns it fresh.
     * 409 when the order is not on Shopify, 503 when Shopify fails or times out.
     */
    public function refresh(Order $order, OrderRefresher $refresher, ShopifyClient $client): OrderResource|JsonResponse
    {
        Gate::authorize('view', $order);

        if ($order->shopify_order_id === null) {
            return response()->json(['message' => __('errors.orders.not_on_shopify')], 409);
        }

        try {
            $refresher->usingClient($client->withTimeout(self::REFRESH_TIMEOUT_SECONDS))->refresh([$order->id], throw: true);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => __('errors.orders.refresh_failed')], 503);
        }

        return $this->orderResource($order->fresh());
    }

    /**
     * On-view refresh (R8): queues one background refresh of the given orders that
     * are open, on Shopify, visible to the user and not read for 30 minutes; each
     * order at most once per 5 minutes. The rows then update via OrderUpdated.
     */
    public function refreshStale(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'max:50'],
            'ids.*' => ['integer'],
        ]);

        $ids = Order::query()
            ->whereKey(array_values(array_unique(array_map('intval', $data['ids']))))
            ->openForSync()
            ->whereNotNull('shopify_order_id')
            ->where(fn ($q) => $q->whereNull('last_synced_at')->orWhere('last_synced_at', '<', now()->subMinutes(self::STALE_AFTER_MINUTES)))
            ->orderBy('id')
            ->get()
            ->filter(fn (Order $order) => $request->user()->can('view', $order))
            ->filter(fn (Order $order) => Cache::add("orders.refresh.{$order->id}", 1, self::STALE_LOCK_SECONDS))
            ->map(fn (Order $order) => (int) $order->id)
            ->values()
            ->all();

        if ($ids !== []) {
            RefreshShopifyOrders::dispatch($ids);
        }

        return response()->json(['queued' => count($ids)], 202);
    }
}
