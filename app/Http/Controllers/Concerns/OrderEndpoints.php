<?php

namespace App\Http\Controllers\Concerns;

use App\Commerce\OrderService;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\Platform;
use App\Enums\ShipmentStatus;
use App\Http\Resources\OrderResource;
use App\Http\Support\DateRange;
use App\Http\Support\ModeratorScope;
use App\Models\Order;
use App\Shipping\ShipmentService;
use App\Shipping\StuckOrderScope;
use App\Shopify\Connection\IntegrationRepository;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
                    ? 'لا يمكن إلغاء طلب اتشحن كله أو جزء منه.'
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

    /**
     * Manual shipment creation for `crm.auto_create_shipment = false` (spec §5.8.4).
     */
    public function ship(Request $request, Order $order, ShipmentService $shipments): OrderResource
    {
        Gate::authorize('ship', $order);

        if ($order->status !== OrderStatus::Confirmed) {
            throw ValidationException::withMessages(['order' => 'Only confirmed orders can be shipped.']);
        }

        if ($order->shipment()->exists()) {
            throw ValidationException::withMessages(['order' => 'This order already has a shipment.']);
        }

        try {
            $shipments->createFor($order);
        } catch (UniqueConstraintViolationException) {
            abort(response()->json(['message' => 'This order has already been shipped.'], 422));
        }

        return $this->orderResource($order->fresh());
    }

    protected function orderResource(Order $order): OrderResource
    {
        return new OrderResource($order->loadMissing(['items', 'shipment.events', 'createdBy', 'customer']));
    }

    /**
     * `from`/`to` are Cairo calendar dates (Y-m-d), like the report filters.
     *
     * @return array{status?: ?string, type?: ?string, platform?: ?string, q?: ?string, created_by?: ?int, from?: ?string, to?: ?string, source?: ?string, financial_status?: ?string, fulfillment_status?: ?string, shipment_step?: ?string, mismatch?: ?bool, stuck?: ?bool}
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
            'shipment_step' => ['nullable', Rule::enum(ShipmentStatus::class)],
            'mismatch' => ['nullable', 'boolean'],
            'stuck' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * Moderators see orders on their platforms plus orders they created.
     *
     * @return Builder<Order>
     */
    protected function orderQuery(Request $request): Builder
    {
        $f = $this->orderFilters($request);
        $user = $request->user();

        return Order::query()
            ->with(['customer', 'createdBy', 'items', 'shipment.events'])
            ->tap(fn (Builder $q) => ModeratorScope::orders($q, $user))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($f['platform'] ?? null, fn ($q, $v) => $q->where('platform', $v))
            ->when($f['source'] ?? null, fn ($q, $v) => $q->where('source', $v))
            ->when($f['financial_status'] ?? null, fn ($q, $v) => $q->where('financial_status', $v))
            ->when($f['fulfillment_status'] ?? null, fn ($q, $v) => $q->where('fulfillment_status', $v))
            ->when($f['shipment_step'] ?? null, fn ($q, $v) => $q->whereHas('shipment', fn (Builder $s) => $s->where('status', $v)))
            ->when($f['created_by'] ?? null, fn ($q, $v) => $q->where('created_by_id', (int) $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', DateRange::startOfCairoDay($v)))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', DateRange::endOfCairoDay($v)))
            ->when(array_key_exists('mismatch', $f) && $f['mismatch'] !== null, fn ($q) => $q->where('mismatch', (bool) $f['mismatch']))
            ->when(array_key_exists('stuck', $f) && $f['stuck'], fn (Builder $q) => StuckOrderScope::apply($q, $this->stuckOrderDays()))
            ->when(trim((string) ($f['q'] ?? '')), fn ($q, $term) => $q->where(fn (Builder $w) => $w
                ->where('order_number', 'like', "%{$term}%")
                ->orWhere('shipping_phone', 'like', "%{$term}%")
                ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%"))))
            ->orderByDesc('id');
    }

    /**
     * Days of shipment silence before an order counts as "stuck" (`?stuck=1`),
     * mirroring `CustomerOrderFlags::has_stuck_order`.
     */
    private function stuckOrderDays(): int
    {
        return (int) (app(IntegrationRepository::class)->current()?->settingsWithDefaults()['stuck_order_days'] ?? 5);
    }
}
