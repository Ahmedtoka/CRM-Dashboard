import { useApi } from '@/composables/useApi';
import { useEcho } from '@/composables/useEcho';
import { listenInbox } from '@/lib/inboxChannels';
import type { SharedData } from '@/types';
import type { Order } from '@/types/crm';
import { usePage } from '@inertiajs/vue3';
import { onScopeDispose, watch, type Ref } from 'vue';

/** The on-view refresh asks for orders not read from Shopify for this long (the server checks again). */
export const STALE_AFTER_MINUTES = 30;
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
}

export function isStaleForRefresh(o: Order, now: number): boolean {
    if (!(o.on_shopify ?? !!o.shopify_order_id) || o.is_final) return false;
    if (!o.last_synced_at) return true;

    return now - new Date(o.last_synced_at).getTime() > STALE_AFTER_MINUTES * 60_000;
}

/** Applies a broadcast to a row in place, keeping `display` in step with the raw columns it came from. */
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
    ] as const;
    for (const key of keys) {
        if (key in p) (row as unknown as Record<string, unknown>)[key] = p[key];
    }
    if (row.display) {
        row.display = {
            ...row.display,
            payment: p.financial_status ?? row.display.payment,
            fulfillment: p.fulfillment_status ?? row.display.fulfillment,
            shipment_step: p.shipment?.status ?? row.display.shipment_step,
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
                .filter((o) => !asked.has(o.id) && isStaleForRefresh(o, now))
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
