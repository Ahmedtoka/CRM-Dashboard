import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

const { get, reload } = vi.hoisted(() => ({ get: vi.fn(), reload: vi.fn() }));
vi.mock('@/composables/useApi', () => ({ useApi: () => ({ get }), apiErrorMessage: (_e: unknown, f: string) => f }));
vi.mock('@inertiajs/vue3', () => ({ router: { reload }, usePage: () => ({ props: { ads: { canWrite: true } } }), Link: { template: '<a><slot /></a>' } }));

import AdDrawer from '@/components/ads/AdDrawer.vue';

const data = {
    ad: {
        id: 7, name: 'Eid Abaya V2', platform: 'meta', account: 'LV', account_id: 1, external_id: '123', status: 'ACTIVE', effective_status: 'ACTIVE', objective: 'messages',
        conversations: 4, real_orders: 1, spend: 100, spend_tax: 114, spend_today: 10, real_roas: 2, roas: null, purchases: 0, clicks: 3, health: [], series: [], can_write: true,
        parent_paused: false, preview_html: null, thumbnail_url: null, image_url: null, video_url: null, type: 'image', created_time: null, currency: 'EGP',
    },
    reasons: [],
    decisions: [],
    funnel: null,
    levels: ['ad'],
    history: [
        { id: 1, ad_id: 7, at: '2026-10-06T08:00:00Z', user: 'Bakinam', user_id: 2, platform: 'meta', account: 'LV', account_id: 1, level: 'ad', name: 'Eid', external_id: '123', from_status: 'ACTIVE', to_status: 'PAUSED', reason: null, result: 'ok', error: null, source: 'ui' },
    ],
};

const passthrough = { template: '<div><slot /></div>' };
const stubs = { AdStatusButton: true, Sparkline: true, CreativeThumb: true, WhyList: true, Sheet: passthrough, SheetContent: passthrough, SheetTitle: { template: '<h2><slot /></h2>' }, SheetDescription: { template: '<p><slot /></p>' } };
const filters = { from: '2026-09-29', to: '2026-10-05', platform: null, buyer: null };

describe('AdDrawer', () => {
    it('loads the ad with the page range and shows history; no funnel block until S3', async () => {
        get.mockResolvedValueOnce({ data });
        const w = mount(AdDrawer, { props: { adId: 7, filters }, global: { stubs } });
        await flushPromises();
        expect(get.mock.calls[0][0]).toBe('/ads/ad/7?from=2026-09-29&to=2026-10-05');
        expect(w.text()).toContain('Eid Abaya V2');
        expect(w.text()).toContain('Bakinam');
        expect(w.find('[data-test="funnel"]').exists()).toBe(false);
    });

    it('renders the funnel slot when S3 passes one', async () => {
        get.mockResolvedValueOnce({ data });
        const w = mount(AdDrawer, {
            props: { adId: 7, filters, funnel: { chats: 4, to_agent: 2, orders: 1, delivered: 1, returned: 0, reasons: {} } },
            slots: { funnel: '<div>funnel here</div>' },
            global: { stubs },
        });
        await flushPromises();
        expect(w.find('[data-test="funnel"]').text()).toContain('funnel here');
    });

    it('reloads the ad and only the named list props after a Stop', async () => {
        get.mockResolvedValue({ data });
        const w = mount(AdDrawer, { props: { adId: 7, filters, reloadOnly: ['result'] }, global: { stubs: { ...stubs, AdStatusButton: { name: 'AdStatusButton', template: '<i />' } } } });
        await flushPromises();
        w.findComponent({ name: 'AdStatusButton' }).vm.$emit('done', 'paused');
        await flushPromises();
        expect(get).toHaveBeenCalledTimes(2);
        expect(reload).toHaveBeenCalledWith({ only: ['result'] });
        get.mockReset();
    });

    it('offers a retry when the ad cannot be loaded', async () => {
        get.mockRejectedValueOnce(new Error('x')).mockResolvedValueOnce({ data });
        const w = mount(AdDrawer, { props: { adId: 7, filters }, global: { stubs } });
        await flushPromises();
        expect(w.find('[role="alert"]').exists()).toBe(true);
        await w.find('[role="alert"] button').trigger('click');
        await flushPromises();
        expect(w.text()).toContain('Eid Abaya V2');
    });
});
