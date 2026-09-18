<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Concerns\OrderEndpoints;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    use OrderEndpoints;

    /**
     * The Orders page, or (for a plain `Accept: application/json` request, e.g. the
     * activity/report widgets or a test) the same list as the API returns.
     */
    public function index(Request $request): Response|AnonymousResourceCollection
    {
        $filters = $this->orderFilters($request);
        $orders = OrderResource::collection($this->orderQuery($request)->paginate(30)->withQueryString());

        if ($request->wantsJson()) {
            return $orders;
        }

        return Inertia::render('Orders/Index', [
            'orders' => $orders,
            'filters' => array_merge([
                'status' => null, 'type' => null, 'platform' => null, 'q' => null, 'created_by' => null, 'from' => null, 'to' => null,
                'source' => null, 'financial_status' => null, 'fulfillment_status' => null, 'shipment_step' => null, 'mismatch' => null, 'stuck' => null,
            ], $filters),
            // Options for the "created by" filter.
            'team' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
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
}
