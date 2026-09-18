<?php

namespace App\Inbox\SavedReplies;

use App\Commerce\OrderStatusResolver;
use App\Models\Order;

/**
 * Arabic label for `{order_status}` (spec §2.2): the shipment's latest carrier
 * step when there is one, otherwise the order's own status.
 */
final class OrderStatusLabel
{
    private const SHIPMENT = [
        'created' => 'تم تجهيز الشحنة', 'picked_up' => 'استلمتها شركة الشحن', 'in_transit' => 'في الطريق',
        'out_for_delivery' => 'خرج للتوصيل', 'delivered' => 'تم التوصيل', 'failed_attempt' => 'محاولة توصيل لم تنجح',
        'returned' => 'مرتجع', 'cancelled' => 'الشحنة ملغية',
    ];

    private const ORDER = ['submitting' => 'جاري التسجيل', 'awaiting_payment' => 'مستني الدفع', 'confirmed' => 'مؤكد', 'cancelled' => 'ملغي', 'failed' => 'لم يتم التسجيل'];

    public function __construct(private readonly OrderStatusResolver $resolver) {}

    public function for(Order $order): string
    {
        $step = $this->resolver->resolve($order)->shipmentStep;

        return self::SHIPMENT[$step ?? ''] ?? (self::ORDER[$order->status->value] ?? $order->status->value);
    }
}
