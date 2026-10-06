import type { StatusChipTone, Translate } from '@/lib/conversationState';
import type { Order } from '@/types/crm';

export type Tone = 'neutral' | 'positive' | 'warning' | 'negative' | 'info';

export const orderStatusTone: Record<string, Tone> = {
    submitting: 'info',
    awaiting_payment: 'warning',
    confirmed: 'positive',
    cancelled: 'neutral',
    failed: 'negative',
};

/** Payment state shown in lists: paid / unpaid (awaiting link) / on delivery (COD) / void. */
export function paymentState(order: Order & { paid_at?: string | null }): { key: 'paid' | 'unpaid' | 'on_delivery' | 'void'; tone: Tone } {
    if (order.status === 'cancelled' || order.status === 'failed') return { key: 'void', tone: 'neutral' };
    if (order.paid_at) return { key: 'paid', tone: 'positive' };
    if (order.status === 'awaiting_payment') return { key: 'unpaid', tone: 'warning' };
    return { key: 'on_delivery', tone: 'info' };
}

export function shipmentTone(status: string | null | undefined): Tone {
    if (status === 'delivered') return 'positive';
    if (status === 'failed_attempt' || status === 'returned') return 'negative';
    if (!status || status === 'cancelled') return 'neutral';
    return 'info';
}

/** Translates a Shopify value (an open-ended vocabulary), falling back to the raw value. */
export function statusLabel(t: Translate, prefix: string, value: string | null | undefined): string {
    if (!value) return '';
    const key = `${prefix}.${value}`;
    const label = t(key);
    return label === key ? value : label;
}

/** The three families as the CRM sees them now (a live patch updates the raw columns before `display`). */
export function orderFamilies(o: Order): { payment: string | null; fulfillment: string | null; step: string | null } {
    return {
        payment: o.financial_status ?? o.display?.payment ?? null,
        fulfillment: o.fulfillment_status ?? o.display?.fulfillment ?? null,
        step: o.display?.shipment_step ?? o.shipment?.status ?? null,
    };
}

const IN_MOTION = ['picked_up', 'in_transit', 'out_for_delivery'];

/**
 * ONE chip for the order's latest state. The first rule that matches wins:
 *  1. cancelled            — CRM status `cancelled`, or the payment was voided;
 *  2. failed               — never reached Shopify (CRM status `failed`);
 *  3. submitting           — being sent to Shopify right now;
 *  4. refunded             — payment `refunded` (then `partially_refunded`);
 *  5. returned / failed_attempt — the carrier's latest step went wrong;
 *  6. delivered            — the carrier's latest step is `delivered`;
 *  7. shipped (step)       — shipment created, then picked up / in transit / out for delivery;
 *  8. fulfilled / partial  — Shopify fulfilled it but no carrier step yet;
 *  9. paid + unfulfilled   — paid, waiting to be prepared;
 * 10. awaiting payment     — CRM `awaiting_payment`, or any payment-link order not paid yet
 *                            (pending, authorized, partially paid, unknown);
 * 11. otherwise            — a cash-on-delivery order: confirmed, paid on delivery.
 * `detail` spells out the three families («الدفع: … · التجهيز: … · الشحن: …») for the chip's tooltip.
 */
export function combinedStatus(o: Order, t: Translate): { label: string; tone: StatusChipTone; detail: string } {
    const { payment, fulfillment, step } = orderFamilies(o);

    const detail = [
        payment ? t('orders.combined.detail_payment', { value: statusLabel(t, 'orders.payment_status', payment) }) : null,
        fulfillment ? t('orders.combined.detail_fulfillment', { value: statusLabel(t, 'orders.fulfillment_status', fulfillment) }) : null,
        step ? t('orders.combined.detail_shipment', { value: statusLabel(t, 'shipment.status', step) }) : null,
    ]
        .filter(Boolean)
        .join(' · ');

    const chip = (key: string, tone: StatusChipTone, params?: Record<string, string>) => ({
        label: t(`orders.combined.${key}`, params),
        tone,
        detail,
    });

    if (o.status === 'cancelled' || payment === 'voided') return chip('cancelled', 'neutral');
    if (o.status === 'failed') return chip('failed', 'negative');
    if (o.status === 'submitting') return chip('submitting', 'info');
    if (payment === 'refunded') return chip('refunded', 'neutral');
    if (payment === 'partially_refunded') return chip('partially_refunded', 'warning');
    if (step === 'returned') return chip('returned', 'negative');
    if (step === 'failed_attempt') return chip('failed_attempt', 'negative');
    if (step === 'delivered') return chip('delivered', 'positive');
    if (step === 'created') return chip('shipment_created', 'info');
    if (step && IN_MOTION.includes(step)) return chip('shipped', 'info', { step: statusLabel(t, 'shipment.status', step) });
    if (fulfillment === 'fulfilled') return chip('fulfilled', 'info');
    if (fulfillment === 'partial') return chip('partial', 'info');
    if (payment === 'paid') return chip('paid_unfulfilled', 'positive');
    // A payment link not paid yet (pending, authorized, partially paid, or any other unpaid state) is
    // waiting for the money; only a cash-on-delivery order is «الدفع عند الاستلام».
    if (o.status === 'awaiting_payment' || o.type === 'payment_link') return chip('awaiting_payment', 'warning');

    return chip('cod_confirmed', 'info');
}

// Left-to-right isolates: «#1381» keeps its «#» on the left inside Arabic text. DISPLAY ONLY.
const LRI = String.fromCharCode(0x2066);
const PDI = String.fromCharCode(0x2069);

/**
 * The order's name for the SCREEN: Shopify's own («#1381») or «مسودة #id», wrapped in bidi isolates.
 * Never put it in text that leaves the screen (clipboard, reply draft): use orderName() there.
 */
export function orderLabel(o: Order, t: Translate): { text: string; draft: boolean } {
    if (o.shopify_order_name) return { text: LRI + o.shopify_order_name + PDI, draft: false };
    if ((o.on_shopify ?? !!o.shopify_order_id) && o.order_number) return { text: `${LRI}#${o.order_number.replace(/^#/, '')}${PDI}`, draft: false };

    return { text: t('orders.list.draft', { id: `${LRI}#${o.id}${PDI}` }), draft: true };
}

/**
 * The order's plain name for text a CUSTOMER may read (status message, clipboard, reply draft):
 * Shopify's name, else the order number, else «#id». No control characters, never «مسودة».
 */
export function orderName(o: Order): string {
    const name = o.shopify_order_name || (o.order_number ? `#${o.order_number.replace(/^#/, '')}` : `#${o.id}`);

    return stripBidiControls(name);
}

// Built from code points so the source carries no invisible characters.
const BIDI_CONTROLS = new RegExp(
    `[${String.fromCharCode(0x202a)}-${String.fromCharCode(0x202e)}${String.fromCharCode(0x2066)}-${String.fromCharCode(0x2069)}]`,
    'g',
);

/** Removes bidi embedding/isolate controls (U+202A–U+202E, U+2066–U+2069) from text leaving the screen. */
export function stripBidiControls(text: string): string {
    return text.replace(BIDI_CONTROLS, '');
}

/** Minutes after which an open order's «آخر مزامنة» turns amber. */
export const SYNC_STALE_MINUTES = 60;
/** The on-view refresh asks for orders not read from Shopify for this long (the server checks again). */
export const REFRESH_AFTER_MINUTES = 30;

/** An open order on Shopify not read for `minutes` (or never). Final orders and drafts are never stale. */
export function isSyncStale(o: Order, now: number, minutes: number = SYNC_STALE_MINUTES): boolean {
    if (!(o.on_shopify ?? !!o.shopify_order_id) || o.is_final) return false;
    if (!o.last_synced_at) return true;

    return now - new Date(o.last_synced_at).getTime() > minutes * 60_000;
}

/** Not on Shopify as an order yet: a payment-link draft waiting for payment, or never sent. */
export function notOnShopifyText(o: Order, t: Translate): string {
    return t(o.shopify_draft_order_id ? 'orders.sync.draft_on_shopify' : 'orders.sync.not_on_shopify');
}

/** The order status line sent to the customer (OrderCard «حطي الحالة في الرد», shortcut shift+o), bidi-clean. */
export function orderStatusText(o: Order, t: Translate): string {
    const payment = statusLabel(t, 'orders.payment_status', o.display?.payment);
    const shipment = o.display?.shipment_step ? t(`shipment.status.${o.display.shipment_step}`) : '';
    const trackingUrl = o.fulfillments?.find((f) => f.tracking_url)?.tracking_url ?? null;

    return stripBidiControls(t('order.status_message', { number: orderName(o), payment, shipment, tracking: trackingUrl ? ` ${trackingUrl}` : '' }));
}
