import { mount } from '@vue/test-utils';
import { beforeAll, describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', () => ({ router: { reload: vi.fn() }, usePage: () => ({ props: { ads: { canWrite: true } } }) }));

import CampaignTreeView from '@/components/ads/CampaignTreeView.vue';
import { setCurrentLocale } from '@/composables/useI18n';
import { adStatusLabel } from '@/lib/ads';
import type { CampaignNode } from '@/types/ads';

const metrics = { spend: 100, spend_tax: 114, purchase_value: 0, roas: null, purchases: 0, cpa: null, impressions: 0, clicks: 0, ctr: null, reach: 0, cpm: null, cpc: null, real_orders: 0 };
const node = (level: CampaignNode['level'], id: number, children: CampaignNode[] = [], over: Partial<CampaignNode> = {}): CampaignNode =>
    ({
        level, placeholder: false, id, ad_id: level === 'ad' ? id : undefined, external_id: String(id), account_id: 1, account: 'LV', platform: 'meta', name: `${level} ${id}`,
        status: 'ACTIVE', objective: null, naming_ok: true, parent_paused: false, can_write: true, metrics, children, ...over,
    }) as CampaignNode;

beforeAll(() => setCurrentLocale('en'));

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

describe('CampaignTreeView returns', () => {
    it('shows real ROAS as the figure with Meta small, and a dash without real revenue (D10)', () => {
        const nodes = [node('campaign', 1, [], { metrics: { ...metrics, roas: 3, real_roas: 2.5, real_revenue: 285 } }), node('campaign', 2, [], { metrics: { ...metrics, roas: 3, real_roas: null } })];
        const w = mount(CampaignTreeView, { props: { nodes, open: [] }, global: { stubs: { AdStatusButton: true } } });
        const real = w.findAll('[data-test="real-roas"]');
        expect(real[0].text()).toContain('2.50');
        expect(real[1].text()).toContain('—');
        expect(w.findAll('[data-test="meta-roas"]')[0].text()).toContain('3.00');
    });

    it('names the toggle with its verb', () => {
        const w = mount(CampaignTreeView, { props: { nodes: tree, open: [] }, global: { stubs: { AdStatusButton: true } } });
        expect(w.find('[data-test="toggle-c:12"]').attributes('aria-label')).toBe('Expand campaign 12');
        const o = mount(CampaignTreeView, { props: { nodes: tree, open: ['c:12'] }, global: { stubs: { AdStatusButton: true } } });
        expect(o.find('[data-test="toggle-c:12"]').attributes('aria-label')).toBe('Collapse campaign 12');
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
