import { useApi } from '@/composables/useApi';
import { useEcho } from '@/composables/useEcho';
import { listenInbox } from '@/lib/inboxChannels';
import { isSyncStale, REFRESH_AFTER_MINUTES } from '@/lib/orderStatus';
import type { SharedData } from '@/types';
import type { MismatchReason, Order, OrderDisplay } from '@/types/crm';
import { usePage } from '@inertiajs/vue3';
import { onScopeDispose, watch, type Ref } from 'vue';

/** The server accepts at most this many ids per request. */
const MAX_IDS = 50;

/** What OrderUpdated carries (App\Events\OrderUpdated::broadcastWith). */
export interface OrderUpdatedPayload {
    id: number;
    status?: Order['status'];
    order_number?: string | null;
    shopify_order_name?: string | null;
    financial_status?: string | null;
    fulfillment_status?: string | null;
    note?: string | null;
    shopify_updated_at?: string | null;
    last_synced_at?: string | null;
    updated_at?: string | null;
    is_final?: boolean;
    invoice_url?: string | null;
    shipment?: { status: string | null; tracking_number: string | null } | null;
    /** Shopify's own shipment status column (the fulfilment's), not the carrier step. */
    shipment_status?: string | null;
    mismatch?: boolean;
    mismatch_reason?: MismatchReason | null;
    /** OrderStatusResolver's output, the same `display` the resource carries. */
    display?: OrderDisplay;
}

/** Applies a broadcast to a row in place, `display` and the mismatch flag included. */
export function applyOrderUpdate(row: Order, p: OrderUpdatedPayload): void {
    const keys = [
        'status',
        'order_number',
        'shopify_order_name',
        'financial_status',
        'fulfillment_status',
        'note',
        'shopify_updated_at',
        'last_synced_at',
        'updated_at',
        'is_final',
        'invoice_url',
        'mismatch',
        'mismatch_reason',
    ] as const;
    for (const key of keys) {
        if (key in p) (row as unknown as Record<string, unknown>)[key] = p[key];
    }
    if (p.display) {
        // The server resolved it (OrderStatusResolver): take it as is.
        row.display = { ...p.display };
    } else if (row.display) {
        // Older payloads: keep `display` in step with the raw columns. The resolver's step is the carrier
        // shipment's, so the CRM shipment's status comes first and Shopify's shipment_status second.
        row.display = {
            ...row.display,
            payment: p.financial_status ?? row.display.payment,
            fulfillment: p.fulfillment_status ?? row.display.fulfillment,
            shipment_step: p.shipment?.status ?? p.shipment_status ?? row.display.shipment_step,
        };
    }
    if (row.shipment && p.shipment) row.shipment = { ...row.shipment, status: p.shipment.status as typeof row.shipment.status };
}

/**
 * On-view refresh (spec §3.2, R8): once per page view, and again whenever the visible rows change, posts
 * the ids of visible on-Shopify, non-final orders not read for 30 minutes to /orders/refresh-stale. The
 * server queues one background refresh (deduplicated for 5 minutes per order); the fresh state comes back
 * through OrderUpdated, which this applies to the matching rows (`listen: false` when the caller already
 * reloads on that event, as the inbox does).
 */
export function useStaleOrderRefresh(orders: Readonly<Ref<Order[]>>, opts: { listen?: boolean } = {}): void {
    const api = useApi();
    const asked = new Set<number>();

    watch(
        () => orders.value.map((o) => o.id).join(','),
        () => {
            const now = Date.now();
            const ids = orders.value
                .filter((o) => !asked.has(o.id) && isSyncStale(o, now, REFRESH_AFTER_MINUTES))
                .map((o) => o.id)
                .slice(0, MAX_IDS);
            if (!ids.length) return;
            ids.forEach((id) => asked.add(id));
            // Best effort: a failed ask only means the rows stay as they are until the scheduled refresh.
            api.post('/orders/refresh-stale', { ids }).catch(() => undefined);
        },
        { immediate: true },
    );

    if (opts.listen === false) return;

    const { echo } = useEcho();
    const page = usePage<SharedData>();
    const detach = listenInbox(echo, page.props.auth.user, {
        OrderUpdated: (payload: OrderUpdatedPayload) => {
            const row = orders.value.find((o) => o.id === payload.id);
            if (row) applyOrderUpdate(row, payload);
        },
    });
    onScopeDispose(detach);
}
