import { DOMWrapper, flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

const { get, post } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock('@/composables/useApi', () => ({ useApi: () => ({ get, post }), apiErrorMessage: (_e: unknown, f: string) => f }));

import SendProductPicker from '@/components/crm/SendProductPicker.vue';
import { setCurrentLocale } from '@/composables/useI18n';
import type { ProductVariant } from '@/types/crm';

beforeAll(() => setCurrentLocale('ar'));

const inStock = { id: 1, product_id: 10, product_title: 'فستان ليلي', title: 'أسود / M', sku: 'LV-1', price: 950, stock: 3, inventory_policy: 'deny', image_url: null } as unknown as ProductVariant;
const soldOut = { ...inStock, id: 2, title: 'أسود / L', stock: 0 } as ProductVariant;
const message = { id: 77, conversation_id: 5, direction: 'out', sender_type: 'user', body: 'فستان ليلي', attachments: [], status: 'queued', error: null, user: null, created_at: null };

// The picker is a sheet (a dialog in a portal): it is mounted on the page and read from the document.
let wrapper: VueWrapper | null = null;
function open(): VueWrapper {
    wrapper = mount(SendProductPicker, { props: { conversationId: 5 }, attachTo: document.body });

    return wrapper;
}
const q = (selector: string) => new DOMWrapper(document.querySelector(selector) as Element);
const qa = (selector: string) => document.querySelectorAll(selector);
afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    document.body.innerHTML = '';
});

beforeEach(() => {
    get.mockReset().mockResolvedValue({ data: { data: [inStock, soldOut] } });
    post.mockReset().mockResolvedValue({ data: { data: message } });
});

describe('SendProductPicker', () => {
    it('picks a card, names it on the send button, sends it and hands the message back', async () => {
        const w = open();
        await flushPromises();

        expect(q('[data-testid="send-product-send"]').attributes('disabled')).toBeDefined();
        await q('[data-testid="send-product-1"]').trigger('click');
        expect(post).not.toHaveBeenCalled();
        expect(q('[data-testid="send-product-1"]').attributes('aria-pressed')).toBe('true');
        expect(q('[data-testid="send-product-send"]').text()).toContain('فستان ليلي');

        await q('[data-testid="send-product-send"]').trigger('click');
        await flushPromises();

        expect(post).toHaveBeenCalledWith('/inbox/conversations/5/product', { variant_id: 1, confirm_out_of_stock: false });
        expect(w.emitted('sent')?.[0]).toEqual([message]);
        expect(w.emitted('close')).toBeTruthy();
    });

    it('badges an out-of-stock variant and asks before sending it', async () => {
        const w = open();
        await flushPromises();

        expect(qa('[data-testid="out-of-stock-badge"]')).toHaveLength(1);

        await q('[data-testid="send-product-2"]').trigger('click');
        await q('[data-testid="send-product-send"]').trigger('click');
        expect(post).not.toHaveBeenCalled();
        expect(q('[data-testid="send-product-confirm"]').text()).toContain('فستان ليلي');

        await q('[data-testid="send-product-confirm"] button').trigger('click');
        await flushPromises();

        expect(post).toHaveBeenCalledWith('/inbox/conversations/5/product', { variant_id: 2, confirm_out_of_stock: true });
        expect(w.emitted('sent')).toBeTruthy();
    });

    it('asks when the server says the variant just sold out', async () => {
        const conflict = Object.assign(new Error('409'), { isAxiosError: true, response: { status: 409, data: { code: 'out_of_stock' } } });
        post.mockRejectedValueOnce(conflict);
        const w = open();
        await flushPromises();

        // A double click sends at once (the one-step way from before the sheet).
        await q('[data-testid="send-product-1"]').trigger('dblclick');
        await flushPromises();

        expect(document.querySelector('[data-testid="send-product-confirm"]')).not.toBeNull();
        expect(w.emitted('sent')).toBeFalsy();
    });
});
