import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', () => ({ router: { reload: vi.fn() }, usePage: () => ({ props: { ads: { canWrite: true } } }) }));

import CampaignTreeView from '@/components/ads/CampaignTreeView.vue';
import { adStatusLabel } from '@/lib/ads';
import type { CampaignNode } from '@/types/ads';

const metrics = { spend: 100, spend_tax: 114, purchase_value: 0, roas: null, purchases: 0, cpa: null, impressions: 0, clicks: 0, ctr: null, reach: 0, cpm: null, cpc: null, real_orders: 0 };
const node = (level: CampaignNode['level'], id: number, children: CampaignNode[] = [], over: Partial<CampaignNode> = {}): CampaignNode =>
    ({
        level, placeholder: false, id, ad_id: level === 'ad' ? id : undefined, external_id: String(id), account_id: 1, account: 'LV', platform: 'meta', name: `${level} ${id}`,
        status: 'ACTIVE', objective: null, naming_ok: true, parent_paused: false, can_write: true, metrics, children, ...over,
    }) as CampaignNode;

const tree = [node('campaign', 12, [node('adset', 40, [node('ad', 7)])], { naming_ok: false })];

describe('CampaignTreeView', () => {
    it('shows only open levels and asks to open a node', async () => {
        const w = mount(CampaignTreeView, { props: { nodes: tree, open: [] }, global: { stubs: { AdStatusButton: true } } });
        expect(w.text()).toContain('campaign 12');
        expect(w.text()).not.toContain('adset 40');
        await w.find('[data-test="toggle-c:12"]').trigger('click');
        expect(w.emitted('update:open')?.[0]).toEqual([['c:12']]);
    });

    it('renders open children, the naming chip only when the name is bad, and opens the ad drawer', async () => {
        const w = mount(CampaignTreeView, { props: { nodes: tree, open: ['c:12', 's:40'] }, global: { stubs: { AdStatusButton: true } } });
        expect(w.text()).toContain('ad 7');
        expect(w.findAll('[data-test="naming-bad"]')).toHaveLength(1);
        await w.find('[data-test="open-ad-7"]').trigger('click');
        expect(w.emitted('openAd')?.[0]).toEqual([7]);
    });

    it('closes an open node', async () => {
        const w = mount(CampaignTreeView, { props: { nodes: tree, open: ['c:12'] }, global: { stubs: { AdStatusButton: true } } });
        await w.find('[data-test="toggle-c:12"]').trigger('click');
        expect(w.emitted('update:open')?.[0]).toEqual([[]]);
    });
});

describe('adStatusLabel', () => {
    it('says running / stopped for every platform spelling', () => {
        const t = (k: string) => k;
        expect(adStatusLabel('ACTIVE', t)).toBe('ads.control.row.running');
        expect(adStatusLabel('ENABLE', t)).toBe('ads.control.row.running');
        expect(adStatusLabel('PAUSED', t)).toBe('ads.control.row.stopped');
        expect(adStatusLabel(null, t)).toBe('—');
    });
});
