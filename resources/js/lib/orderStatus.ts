import type { OrderRow } from '@/types/admin';

export type Tone = 'neutral' | 'positive' | 'warning' | 'negative' | 'info';

export const orderStatusTone: Record<string, Tone> = {
    submitting: 'info',
    awaiting_payment: 'warning',
    confirmed: 'positive',
    cancelled: 'neutral',
    failed: 'negative',
};

/** Payment state shown in lists: paid / unpaid (awaiting link) / on delivery (COD) / void. */
export function paymentState(order: OrderRow): { key: 'paid' | 'unpaid' | 'on_delivery' | 'void'; tone: Tone } {
    if (order.status === 'cancelled' || (order.status as string) === 'failed') return { key: 'void', tone: 'neutral' };
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
