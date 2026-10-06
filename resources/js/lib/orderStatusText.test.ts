import { orderStatusText } from '@/lib/orderStatus';
import type { Order } from '@/types/crm';
import { describe, expect, it } from 'vitest';

const t = (key: string, params?: Record<string, unknown>) =>
    key === 'order.status_message' ? `${params?.number}|${params?.payment}|${params?.shipment}|${params?.tracking}` : key;

describe('orderStatusText', () => {
    it('builds the status line with the tracking link and no bidi controls', () => {
        const order = {
            id: 7,
            order_number: '1043',
            shopify_order_name: '\u2067#1043\u2069',
            display: { payment: 'paid', shipment_step: 'in_transit' },
            fulfillments: [{ tracking_url: 'https://track.test/1' }],
        } as unknown as Order;
        const text = orderStatusText(order, t as never);
        expect(text).toContain('https://track.test/1');
        expect(text).toContain('shipment.status.in_transit');
        expect(text).not.toMatch(/[\u202a-\u202e\u2066-\u2069]/);
    });

    it('leaves the tracking part empty without a link', () => {
        const order = { id: 8, order_number: '1044', display: { payment: null, shipment_step: null } } as unknown as Order;
        expect(orderStatusText(order, t as never).endsWith('|')).toBe(true);
    });
});
