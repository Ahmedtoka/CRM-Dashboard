// Round 2026-10-24 (x4): the shapes App\Shipping\ShippingTimeline and App\Cases\Returns\ReturnLifecycle send.

export type ShippingStep = 'created' | 'picked_up' | 'in_transit' | 'out_for_delivery' | 'delivered' | 'failed_attempt' | 'returned' | 'cancelled';

export interface ShippingEntry {
    step: ShippingStep;
    /** Shopify's own status word behind the step (in_transit, failure, …), null for the created step. */
    status: string | null;
    at: string;
    fulfillment_id: number;
    message: string | null;
    tracking_company: string | null;
    tracking_number: string | null;
    tracking_url: string | null;
}

/** Order.delivery — Order::shippingSummary(). */
export interface ShippingSummary {
    step: ShippingStep | null;
    step_at: string | null;
    failed_attempt: boolean;
    delivered_at: string | null;
    /** Shopify ever reported a carrier step after «created» for this order. */
    carrier_updates: boolean;
    tracking: { company: string | null; number: string | null; url: string | null } | null;
    timeline: ShippingEntry[];
}

export type ReturnStage = 'requested' | 'pickup_scheduled' | 'picked_up' | 'received' | 'exchange_shipped' | 'closed' | 'cancelled';

export const RETURN_STAGES: ReturnStage[] = ['requested', 'pickup_scheduled', 'picked_up', 'received', 'exchange_shipped', 'closed', 'cancelled'];

export interface ReturnTimelineEntry {
    stage: ReturnStage;
    at: string | null;
    by: string | null;
    meta: { pickup_date?: string; refund_amount?: number | null; exchange_order_number?: string; reason?: string; tracking_url?: string | null };
    /** null = an internal step (nothing is sent); false = not told yet or could not be told (see notify_error). */
    notified: boolean | null;
    notify_error: string | null;
}

/** SupportCase.return — ReturnLifecycle::present(). */
export interface ReturnView {
    stage: ReturnStage;
    stage_at: string | null;
    is_exchange: boolean;
    pickup_date: string | null;
    refund_amount: number | null;
    exchange_order: { id: number; number: string | null } | null;
    /** The steps the moderator may take now. */
    next: ReturnStage[];
    timeline: ReturnTimelineEntry[];
}

/** Order.return_cases — the compact list on the order resource. */
export interface ReturnCaseChip {
    id: number;
    type: string;
    is_exchange: boolean;
    stage: ReturnStage;
    stage_at: string | null;
    pickup_date: string | null;
}
