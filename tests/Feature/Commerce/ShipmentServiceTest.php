<?php

use App\Shipping\ShipmentService;
use App\Enums\ShipmentStatus;
use App\Models\Order;
use Illuminate\Support\Facades\Event;
it('records shipment events in order', function () {
    Event::fake();
    $s = app(ShipmentService::class)->createFor(Order::factory()->create());
    app(ShipmentService::class)->applyEvent($s, ShipmentStatus::OutForDelivery, 'مع المندوب', 'Cairo');
    expect($s->fresh()->status)->toBe(ShipmentStatus::OutForDelivery)->and($s->events()->count())->toBe(2)
        ->and($s->tracking_number)->toStartWith('TRK');
});
