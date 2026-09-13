<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\OrderEndpoints;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class OrderController extends Controller
{
    use OrderEndpoints;

    public function index(Request $request): AnonymousResourceCollection
    {
        return OrderResource::collection($this->orderQuery($request)->paginate(30)->withQueryString());
    }

    public function show(Request $request, Order $order): OrderResource
    {
        Gate::authorize('view', $order);

        return $this->orderResource($order);
    }
}
