import { mount } from '@vue/test-utils';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

const { get, props } = vi.hoisted(() => ({
    get: vi.fn(),
    props: { ads: { isBuyer: false, buyerId: 5 } } as { ads: { isBuyer: boolean; buyerId: number | null } },
}));
vi.mock('@inertiajs/vue3', () => ({ router: { get }, usePage: () => ({ props, url: '/ads/explorer' }) }));

import AdsFilterBar from '@/components/ads/AdsFilterBar.vue';
import { setCurrentLocale } from '@/composables/useI18n';
import { EXPLORER_DEFAULTS } from '@/lib/adsFilters';

// ar prints Arabic-Indic digits; assert on Latin digits in en.
beforeAll(() => setCurrentLocale('en'));

const FilterBarStub = {
    name: 'FilterBar',
    props: ['search', 'searchPlaceholder', 'chips', 'moreCount', 'presets', 'summary'],
    emits: ['update:search', 'remove', 'clear'],
    template: '<div><slot name="inline" /><slot name="more" /></div>',
};

const base = {
    path: '/ads/explorer',
    filters: { from: '2026-09-29', to: '2026-10-05', range: 'last7', platform: null, buyer: null, accounts: [3], status: 'running', health: 'tired', sort: '-spend', view: 'table', q: null, objective: null, changed: null, per_page: 25, page: 1 },
    accountOptions: [{ id: 3, name: 'LV-Main', platform: 'meta' }, { id: 4, name: 'LV-2', platform: 'meta' }],
    buyers: [{ id: 5, name: 'Bakinam' }],
    platforms: ['meta'],
    defaults: EXPLORER_DEFAULTS,
};
const stubs = { FilterBar: FilterBarStub, Popover: { template: '<div><slot /></div>' }, PopoverTrigger: { template: '<button><slot /></button>' }, PopoverContent: { template: '<div><slot /></div>' } };

describe('AdsFilterBar', () => {
    beforeEach(() => {
        get.mockReset();
        window.history.replaceState({}, '', '/ads/explorer?range=last7&accounts=3&health=tired&ad=9');
    });

    it('shows presets, marks the active one and states what is hidden', () => {
        const w = mount(AdsFilterBar, { props: base as never, global: { stubs } });
        const fb = w.findComponent({ name: 'FilterBar' });
        const presets = fb.props('presets') as { key: string; active: boolean; href: string }[];
        expect(presets.map((p) => p.key)).toContain('mine');
        expect(presets.find((p) => p.key === 'tired')?.active).toBe(true);
        expect(presets.find((p) => p.key === 'no_result')?.href).toContain('health=no_result');
        expect(fb.props('summary')).toContain('1');
    });

    it('hides the my-accounts preset and the buyer select for a media buyer', () => {
        props.ads = { isBuyer: true, buyerId: 5 };
        const w = mount(AdsFilterBar, { props: { ...base, buyers: [] } as never, global: { stubs } });
        const presets = w.findComponent({ name: 'FilterBar' }).props('presets') as { key: string }[];
        expect(presets.map((p) => p.key)).not.toContain('mine');
        expect(w.find('[data-test="buyer-select"]').exists()).toBe(false);
        props.ads = { isBuyer: false, buyerId: 5 };
    });

    it('removes a chip by replacing the URL without it, keeping the drawer param', () => {
        const w = mount(AdsFilterBar, { props: base as never, global: { stubs } });
        w.findComponent({ name: 'FilterBar' }).vm.$emit('remove', 'health');
        expect(get).toHaveBeenCalledWith('/ads/explorer', { range: 'last7', accounts: '3', ad: '9' }, expect.objectContaining({ replace: true }));
    });

    it('clears the chips but keeps the range and the open drawer', () => {
        const w = mount(AdsFilterBar, { props: base as never, global: { stubs } });
        w.findComponent({ name: 'FilterBar' }).vm.$emit('clear');
        expect(get).toHaveBeenCalledWith('/ads/explorer', { range: 'last7', ad: '9' }, expect.objectContaining({ replace: true }));
    });

    it('keys presets on the applied filters, not on a stale address', () => {
        window.history.replaceState({}, '', '/ads/explorer');
        const w = mount(AdsFilterBar, { props: base as never, global: { stubs } });
        const presets = w.findComponent({ name: 'FilterBar' }).props('presets') as { key: string; active: boolean }[];
        expect(presets.find((p) => p.key === 'tired')?.active).toBe(true);
    });

    it('switches to a named range and drops custom dates', async () => {
        window.history.replaceState({}, '', '/ads/explorer?from=2026-09-01&to=2026-09-10');
        const w = mount(AdsFilterBar, { props: base as never, global: { stubs } });
        await w.find('select').setValue('last30');
        expect(get).toHaveBeenCalledWith('/ads/explorer', { range: 'last30' }, expect.anything());
    });
});
