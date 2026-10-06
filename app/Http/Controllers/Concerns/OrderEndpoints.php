<?php

namespace App\Http\Controllers\Concerns;

use App\Analytics\MetricsService;
use App\Commerce\OrderService;
use App\Commerce\StuckOrderScope;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\Platform;
use App\Http\Resources\OrderResource;
use App\Http\Support\DateRange;
use App\Http\Support\ModeratorScope;
use App\Models\Order;
use App\Orders\GovernorateKey;
use App\Orders\OrdersAnalytics;
use App\Shopify\Connection\IntegrationRepository;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Order listing and order actions shared by the web Orders screens and API v1.
 */
trait OrderEndpoints
{
    public function cancel(Request $request, Order $order, OrderService $orders): OrderResource
    {
        Gate::authorize('cancel', $order);

        $data = $request->validate(['restock' => ['nullable', 'boolean']]);

        try {
            $cancelled = $orders->cancel($order, $request->user(), restock: (bool) ($data['restock'] ?? true));
        } catch (DomainException $e) {
            abort(response()->json([
                'message' => $e->getMessage() === 'already_fulfilled'
                    ? __('errors.orders.cancel_fulfilled')
                    : $e->getMessage(),
            ], 422));
        }

        return $this->orderResource($cancelled);
    }

    /**
     * Re-sends a failed order to Shopify (422 unless the order failed).
     */
    public function retry(Request $request, Order $order, OrderService $orders): OrderResource
    {
        Gate::authorize('retry', $order);

        return $this->orderResource($orders->retry($order, $request->user()));
    }

    public function markPaid(Request $request, Order $order, OrderService $orders): OrderResource
    {
        Gate::authorize('markPaid', $order);

        // OrderService::markPaid() would otherwise resurrect cancelled/failed orders.
        if ($order->status !== OrderStatus::AwaitingPayment) {
            abort(response()->json([
                'message' => "Only orders awaiting payment can be marked paid (status: {$order->status?->value}).",
            ], 422));
        }

        return $this->orderResource($orders->markPaid($order));
    }

    protected function orderResource(Order $order): OrderResource
    {
        return new OrderResource($order->loadMissing(['items', 'createdBy', 'customer']));
    }

    /**
     * `from`/`to` are Cairo calendar dates (Y-m-d), like the report filters.
     *
     * Additive params (control room S4), all optional, so API v1 callers that omit them see no change:
     * - `older_than` (minutes, web and API): orders made at least that long ago.
     * - web only (ignored on /api): `real=1` drops the statuses the reports never count (cancelled, failed).
     * The carrier filters (`shipment_step`, `step_from`, `step_to`) went with the CRM shipments (fresh-orders F4).
     *
     * @return array{status?: ?string, type?: ?string, platform?: ?string, q?: ?string, created_by?: ?int, from?: ?string, to?: ?string, source?: ?string, financial_status?: ?string, fulfillment_status?: ?string, mismatch?: ?bool, stuck?: ?bool, older_than?: ?int, real?: ?bool, governorate?: ?string, ad_platform?: ?string}
     */
    protected function orderFilters(Request $request): array
    {
        return $request->validate([
            'status' => ['nullable', Rule::enum(OrderStatus::class)],
            'type' => ['nullable', Rule::enum(OrderType::class)],
            'platform' => ['nullable', Rule::enum(Platform::class)],
            'q' => ['nullable', 'string', 'max:100'],
            'created_by' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'source' => ['nullable', Rule::enum(OrderSource::class)],
            'financial_status' => ['nullable', 'string', 'max:50'],
            'fulfillment_status' => ['nullable', 'string', 'max:50'],
            'mismatch' => ['nullable', 'boolean'],
            'stuck' => ['nullable', 'boolean'],
            // «النهارده» urgent strip (control room S4): orders still waiting this many minutes after they were made.
            'older_than' => ['nullable', 'integer', 'min:1', 'max:43200'],
            // Fresh-orders F5: governorate (province code or Shopify province name) and the source ad platform.
            'governorate' => ['nullable', 'string', 'max:60'],
            'ad_platform' => ['nullable', Rule::in(['meta', 'tiktok', 'google', 'direct'])],
        ] + ($request->is('api/*') ? [] : [
            'real' => ['nullable', 'boolean'],
        ]));
    }

    /**
     * Moderators see orders on their platforms plus orders they created.
     *
     * @return Builder<Order>
     */
    protected function orderQuery(Request $request): Builder
    {
        return $this->orderBaseQuery($request)
            ->with(['customer', 'createdBy', 'items'])
            ->orderByDesc('id');
    }

    /**
     * The filtered, role-scoped orders without eager loads or ordering: the list, the analytics and the ads tab
     * (fresh-orders F5) all read this one set. `from`/`to` match the order date, coalesce(placed_at, created_at):
     * an imported Shopify order keeps the store time in placed_at (its created_at is the import time).
     *
     * @return Builder<Order>
     */
    protected function orderBaseQuery(Request $request): Builder
    {
        $f = $this->orderFilters($request);
        $user = $request->user();
        $date = OrdersAnalytics::ORDER_DATE;

        return Order::query()
            ->tap(fn (Builder $q) => ModeratorScope::orders($q, $user))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('orders.status', $v))
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('orders.type', $v))
            ->when($f['platform'] ?? null, fn ($q, $v) => $q->where('orders.platform', $v))
            ->when($f['source'] ?? null, fn ($q, $v) => $q->where('orders.source', $v))
            ->when($f['financial_status'] ?? null, fn ($q, $v) => $q->where('orders.financial_status', $v))
            ->when($f['fulfillment_status'] ?? null, fn ($q, $v) => $q->where('orders.fulfillment_status', $v))
            ->when($f['created_by'] ?? null, fn ($q, $v) => $q->where('orders.created_by_id', (int) $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->whereRaw("{$date} >= ?", [DateRange::startOfCairoDay($v)->toDateTimeString()]))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->whereRaw("{$date} <= ?", [DateRange::endOfCairoDay($v)->toDateTimeString()]))
            ->when($f['governorate'] ?? null, fn ($q, $v) => $q->whereRaw(GovernorateKey::sql().' = ?', [GovernorateKey::codeFor($v) ?? $v]))
            ->when($f['ad_platform'] ?? null, fn ($q, $v) => $v === 'direct'
                ? $q->whereNull('orders.ad_id')
                : $q->whereIn('orders.ad_id', fn ($s) => $s->select('ads.id')->from('ads')->join('ad_accounts', 'ad_accounts.id', '=', 'ads.ad_account_id')->where('ad_accounts.platform', $v)))
            ->when($f['older_than'] ?? null, fn ($q, $v) => $q->where('orders.created_at', '<=', now()->subMinutes((int) $v)))
            ->when(! empty($f['real']), fn ($q) => $q->whereNotIn('orders.status', MetricsService::EXCLUDED_ORDER_STATUSES))
            ->when(array_key_exists('mismatch', $f) && $f['mismatch'] !== null, fn ($q) => $q->where('orders.mismatch', (bool) $f['mismatch']))
            ->when(array_key_exists('stuck', $f) && $f['stuck'], fn (Builder $q) => StuckOrderScope::apply($q, $this->stuckOrderDays()))
            ->when(trim((string) ($f['q'] ?? '')), fn ($q, $term) => $q->where(fn (Builder $w) => $w
                ->where('orders.order_number', 'like', "%{$term}%")
                ->orWhere('orders.shipping_phone', 'like', "%{$term}%")
                ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%"))));
    }

    /**
     * Days without a Shopify change before an order counts as "stuck" (`?stuck=1`),
     * mirroring `CustomerOrderFlags::has_stuck_order`.
     */
    private function stuckOrderDays(): int
    {
        return (int) (app(IntegrationRepository::class)->current()?->settingsWithDefaults()['stuck_order_days'] ?? 5);
    }
}
