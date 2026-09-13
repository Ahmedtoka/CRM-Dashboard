<?php

namespace App\Http\Controllers\Web;

use App\Commerce\OrderService;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Post;
use App\Models\Shipment;
use App\Shipping\FakeShippingProvider;
use App\Shipping\ShipmentService;
use App\Simulator\Simulator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin demo simulator (spec §7.8). Customer messages/comments go through the real
 * webhook pipeline via App\Simulator\Simulator, which always uses the single canonical
 * `demo-{platform}` channel account (the same one DemoSeeder and crm:simulate use).
 */
class SimulatorController extends Controller
{
    public function __construct(private readonly Simulator $simulator) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Simulator', [
            'awaitingPayment' => OrderResource::collection(
                Order::with(['customer', 'createdBy', 'items', 'shipment.events'])
                    ->where('status', OrderStatus::AwaitingPayment->value)
                    ->orderByDesc('id')->limit(20)->get()
            )->resolve($request),
            'shipments' => Shipment::with('order')
                ->whereNotIn('status', [ShipmentStatus::Delivered->value, ShipmentStatus::Cancelled->value, ShipmentStatus::Returned->value])
                ->orderByDesc('id')->limit(20)->get()
                ->map(fn (Shipment $s) => [
                    'id' => $s->id,
                    'order_id' => $s->order_id,
                    'order_number' => $s->order?->order_number,
                    'status' => $s->status?->value,
                    'tracking_number' => $s->tracking_number,
                ]),
            'posts' => Post::orderByDesc('id')->limit(20)->get(['id', 'platform', 'external_id', 'caption', 'is_ad']),
        ]);
    }

    public function message(Request $request): JsonResponse
    {
        $data = $request->validate([
            'platform' => ['required', Rule::enum(Platform::class)],
            'customer_key' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:100'],
            'text' => ['required', 'string', 'max:2000'],
        ]);

        $event = $this->simulator->queueCustomerMessage(
            Platform::from($data['platform']),
            $data['customer_key'],
            $data['name'],
            $data['text'],
        );

        return response()->json(['data' => ['webhook_event_id' => $event->id]], 201);
    }

    public function comment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'platform' => ['required', Rule::enum(Platform::class)],
            'post_key' => ['required', 'string', 'max:100'],
            'is_ad' => ['boolean'],
            'customer_key' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:100'],
            'text' => ['required', 'string', 'max:2000'],
        ]);

        $event = $this->simulator->queueComment(
            Platform::from($data['platform']),
            $data['post_key'],
            $data['customer_key'],
            $data['name'],
            $data['text'],
            (bool) ($data['is_ad'] ?? false),
        );

        return response()->json(['data' => ['webhook_event_id' => $event->id]], 201);
    }

    public function burst(Request $request): JsonResponse
    {
        $data = $request->validate([
            'count' => ['required', 'integer', 'min:1', 'max:500'],
            'seconds' => ['required', 'integer', 'min:0', 'max:3600'],
            'platforms' => ['required', 'array', 'min:1'],
            'platforms.*' => [Rule::enum(Platform::class)],
        ]);

        $queued = $this->simulator->queueBurst((int) $data['count'], (int) $data['seconds'], $data['platforms']);

        return response()->json(['data' => ['queued' => $queued]], 202);
    }

    public function pay(Request $request, Order $order, OrderService $orders): OrderResource
    {
        if ($order->status !== OrderStatus::AwaitingPayment) {
            abort(response()->json([
                'message' => "Only orders awaiting payment can be paid (status: {$order->status?->value}).",
            ], 422));
        }

        return new OrderResource($orders->markPaid($order)->loadMissing(['items', 'shipment.events', 'createdBy', 'customer']));
    }

    public function advance(Request $request, Shipment $shipment, ShipmentService $shipments): OrderResource
    {
        $next = FakeShippingProvider::nextStatus($shipment->status);

        if ($next === null) {
            throw ValidationException::withMessages(['shipment' => "A {$shipment->status->value} shipment cannot be advanced."]);
        }

        $shipments->applyEvent($shipment, $next, 'Simulator: '.$next->value);

        return new OrderResource($shipment->order()->firstOrFail()->load(['items', 'shipment.events', 'createdBy', 'customer']));
    }
}
