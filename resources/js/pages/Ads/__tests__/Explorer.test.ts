import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';

vi.mock('@inertiajs/vue3', () => ({
    router: { get: vi.fn(), reload: vi.fn(), push: vi.fn(), replace: vi.fn() },
    usePage: () => ({ props: { ads: { canWrite: true, isBuyer: false, buyerId: null } }, url: '/ads/explorer' }),
    Head: { template: '<div />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

import Explorer from '@/pages/Ads/Explorer.vue';

const base = {
    filters: { from: '2026-09-29', to: '2026-10-05', range: 'last7', platform: null, buyer: null, accounts: [], view: 'tree', status: 'running', objective: null, health: null, changed: null, q: null, sort: '-spend', per_page: 25, page: 1 },
    buyers: [], platforms: ['meta'], currency: 'EGP', data_health: { reasons: [] }, numbers_under_review: false, clamped_to_history: false, account_options: [], freshness: null,
    result: null, tier_counts: null,
    tree: [{ level: 'campaign', placeholder: false, id: 12, external_id: '12', account_id: 1, account: 'LV', platform: 'meta', name: 'C', status: 'ACTIVE', objective: null, naming_ok: true, parent_paused: false, metrics: {}, children: [] }],
};
const stubs = {
    AppLayout: { template: '<div><slot /></div>' }, AdsFilterBar: true, AdDrawer: true, DataHealthBanner: true, PageHeader: true,
    CampaignTreeView: { name: 'CampaignTreeView', props: ['open', 'nodes'], template: '<div />' },
};

describe('Explorer', () => {
    it('re-reads the open tree nodes on Back / Forward', async () => {
        window.history.replaceState({}, '', '/ads/explorer?view=tree&open=c:12');
        const w = mount(Explorer, { props: base as never, global: { stubs } });
        expect(w.findComponent({ name: 'CampaignTreeView' }).props('open')).toEqual(['c:12']);
        window.history.replaceState({}, '', '/ads/explorer?view=tree');
        window.dispatchEvent(new PopStateEvent('popstate'));
        await nextTick();
        expect(w.findComponent({ name: 'CampaignTreeView' }).props('open')).toEqual([]);
    });
});
