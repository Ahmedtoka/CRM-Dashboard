import ChatFunnelBlock from '@/components/ads/ChatFunnelBlock.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

const funnel = { chats: 320, to_agent: 110, orders: 42, delivered: 35, returned: 4, reasons: { shipping: 15, price: 38, no_answer: 20, size_out: 27 } };
const none = { chats: 0, to_agent: 0, orders: 0, delivered: 0, returned: 0, reasons: {} };

describe('ChatFunnelBlock', () => {
    it('shows the five stages, the order rate and the reasons as shares, largest first', () => {
        const w = mount(ChatFunnelBlock, { props: { funnel } });
        expect(w.findAll('[data-funnel-stage]').map((s) => s.attributes('data-funnel-stage'))).toEqual(['chats', 'to_agent', 'orders', 'delivered', 'returned']);
        expect(w.text()).toContain('محادثات');
        expect(w.find('[data-funnel-stage="chats"]').text()).toContain('٣٢٠');
        expect(w.find('[data-funnel-stage="orders"]').text()).toContain('١٣'); // 42 / 320
        const reasons = w.findAll('[data-funnel-reason]');
        expect(reasons.map((r) => r.attributes('data-funnel-reason'))).toEqual(['price', 'size_out', 'no_answer', 'shipping']);
        expect(reasons[0].text()).toContain('٣٨'); // 38 of 100
    });

    it('has an empty state, a no-reasons line, a skeleton while loading and an error with retry', async () => {
        expect(mount(ChatFunnelBlock, { props: { funnel: none } }).text()).toContain('مفيش محادثات');
        expect(mount(ChatFunnelBlock, { props: { funnel: { ...funnel, reasons: {} } } }).text()).toContain('لسه مفيش نتايج');
        expect(mount(ChatFunnelBlock, { props: { funnel: null, loading: true } }).find('[aria-busy="true"]').exists()).toBe(true);

        const failed = mount(ChatFunnelBlock, { props: { funnel: null, error: true } });
        expect(failed.find('[role="alert"]').exists()).toBe(true);
        await failed.find('[role="alert"] button').trigger('click');
        expect(failed.emitted('retry')).toHaveLength(1);
    });

    it('can hide its own heading when the host already has one', () => {
        expect(mount(ChatFunnelBlock, { props: { funnel, heading: false } }).find('h3').exists()).toBe(false);
    });
});
