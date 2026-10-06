import { mount } from '@vue/test-utils';
import { beforeAll, describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', () => ({ router: { reload: vi.fn(), get: vi.fn() }, usePage: () => ({ props: { ads: { canWrite: true } }, url: '/ads/explorer' }), Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } }));

import AdCard from '@/components/ads/AdCard.vue';
import AdRow from '@/components/ads/AdRow.vue';
import { setCurrentLocale } from '@/composables/useI18n';
import { adColumns, columnSort, serverSort } from '@/lib/adsColumns';
import type { AdRowData } from '@/types/ads';

// ar-EG prints Arabic-Indic digits; assert on Latin digits in en.
beforeAll(() => setCurrentLocale('en'));

const row = (over: Partial<AdRowData> = {}): AdRowData =>
    ({
        id: 7, external_id: '123', name: 'Eid Abaya V2', platform: 'meta', account: 'LV-Main', account_id: 1, campaign: 'Eid', adset: 'S1', type: 'video',
        status: 'ACTIVE', effective_status: 'ACTIVE', parent_paused: false, can_write: true, thumbnail_url: null, image_url: null, video_url: null, preview_url: null,
        permalink_url: null, instagram_permalink_url: null, object_story_id: null, headline: null, body: null, created_time: null, impressions: 0, clicks: 12, ctr: null,
        purchases: 3, spend: 1000, spend_tax: 1140, purchase_value: 2000, roas: 2, real_orders: 9, buyer: 'Bakinam', trend: { roas_pct: null, spend_pct: null, dir: 'flat' },
        fatigue: { flag: false, ctr_drop_pct: null, frequency: null }, objective: 'messages', conversations: 46, real_revenue: 2100, real_roas: 2.1, currency: 'EGP', spend_today: 412,
        need_stop: false, tier: null, health: ['losing', 'tired', 'too_early'], series: [], ...over,
    }) as AdRowData;

const stubs = { AdStatusButton: true, Sparkline: true, CreativeThumb: true };

describe('AdRow', () => {
    it('shows the Messages result as chats then orders', () => {
        const text = mount(AdRow, { props: { row: row(), part: 'result' }, global: { stubs } }).text();
        expect(text).toContain('46');
        expect(text).toContain('9');
    });

    it('shows purchases and real orders for a Sales ad', () => {
        const text = mount(AdRow, { props: { row: row({ objective: 'sales' }), part: 'result' }, global: { stubs } }).text();
        expect(text).toContain('3');
        expect(text).toContain('9');
    });

    it('puts real ROAS first and hides Meta ROAS for Messages ads', () => {
        const w = mount(AdRow, { props: { row: row(), part: 'return' }, global: { stubs } });
        expect(w.find('[data-test="real-roas"]').text()).toContain('2.1');
        expect(w.find('[data-test="meta-roas"]').text()).toContain('—');
    });

    it('renders a dash when real ROAS is unknown (non-EGP account)', () => {
        const w = mount(AdRow, { props: { row: row({ real_roas: null, currency: 'USD', objective: 'sales' }), part: 'return' }, global: { stubs } });
        expect(w.find('[data-test="real-roas"]').text()).toContain('—');
        expect(mount(AdRow, { props: { row: row({ currency: 'USD' }), part: 'spend' }, global: { stubs } }).text()).toContain('USD');
    });

    it('shows the creative thumb unless thumb is off (no density any more)', () => {
        expect(mount(AdRow, { props: { row: row(), part: 'creative' }, global: { stubs } }).findComponent({ name: 'CreativeThumb' }).exists()).toBe(true);
        expect(mount(AdRow, { props: { row: row(), part: 'creative', thumb: false }, global: { stubs } }).findComponent({ name: 'CreativeThumb' }).exists()).toBe(false);
    });

    it('shows at most two health badges and the name with today spend', () => {
        const w = mount(AdRow, { props: { row: row(), part: 'creative' }, global: { stubs } });
        expect(w.text()).toContain('Eid Abaya V2');
        expect(w.text()).toContain('Losing');
        expect(w.text()).toContain('Tired');
        expect(w.text()).not.toContain('Too early');
        expect(mount(AdRow, { props: { row: row(), part: 'spend' }, global: { stubs } }).text()).toContain('412');
    });

    it('opens the drawer from «ليه؟» and from a number', async () => {
        const w = mount(AdRow, { props: { row: row(), part: 'status' }, global: { stubs } });
        await w.find('[data-test="why"]').trigger('click');
        expect(w.emitted('open')?.[0]).toEqual([7]);
        const s = mount(AdRow, { props: { row: row(), part: 'spend' }, global: { stubs } });
        await s.find('button').trigger('click');
        expect(s.emitted('open')?.[0]).toEqual([7]);
    });
});

describe('AdCard', () => {
    it('shows the name and the Stop button', () => {
        const w = mount(AdCard, { props: { row: row() }, global: { stubs } });
        expect(w.text()).toContain('Eid Abaya V2');
        expect(w.findComponent({ name: 'AdStatusButton' }).exists()).toBe(true);
    });
});

describe('adColumns', () => {
    it('always keeps the trend column and maps the result sort to conversations', () => {
        const t = (k: string) => k;
        expect(adColumns(t).map((c) => c.key)).toContain('trend');
        expect(columnSort('-conversations')).toBe('-result');
        expect(columnSort('-roas')).toBe('');
        expect(serverSort('result')).toBe('conversations');
    });

    it('caps the card preview on phones so two cards fit a screen (final fix 9)', () => {
        const w = mount(AdCard, { props: { row: row() }, global: { stubs } });
        const media = w.get('[data-test="card-media"]');
        expect(media.classes()).toEqual(expect.arrayContaining(['max-h-60', 'md:max-h-none', 'overflow-hidden']));
        expect(w.findComponent({ name: 'CreativeThumb' }).classes()).toEqual(expect.arrayContaining(['max-md:h-full', 'max-md:aspect-auto']));
    });
});
