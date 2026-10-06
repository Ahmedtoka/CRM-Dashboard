<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Concerns\OrderEndpoints;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Http\Support\SortParam;
use App\Models\Order;
use App\Models\User;
use App\Shopify\Client\ShopifyClient;
use App\Shopify\Jobs\RefreshShopifyOrders;
use App\Shopify\Sync\OrderRefresher;
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
    private const SORTS = ['created_at' => 'created_at', 'total' => 'total'];

    /**
     * The Orders page, or (for a plain `Accept: application/json` request, e.g. the
     * activity/report widgets or a test) the same list as the API returns.
     */
    public function index(Request $request): Response|AnonymousResourceCollection
    {
        $filters = $this->orderFilters($request);
        $query = $this->orderQuery($request);
        $sort = SortParam::parse($request->query('sort'), self::SORTS);
        $sort?->apply($query);
        $orders = OrderResource::collection($query->paginate(30)->withQueryString());

        if ($request->wantsJson()) {
            return $orders;
        }

        return Inertia::render('Orders/Index', [
            'orders' => $orders,
            'filters' => array_merge([
                'status' => null, 'type' => null, 'platform' => null, 'q' => null, 'created_by' => null, 'from' => null, 'to' => null,
                'source' => null, 'financial_status' => null, 'fulfillment_status' => null, 'shipment_step' => null, 'mismatch' => null, 'stuck' => null,
            ], $filters, ['sort' => $sort?->value()]),
            // Options for the "created by" filter.
            'team' => User::query()->where('is_active', true)->inboxStaff()->orderBy('name')->get(['id', 'name']),
        ]);
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
