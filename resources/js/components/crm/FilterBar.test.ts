import FilterBar from '@/components/crm/FilterBar.vue';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

describe('FilterBar', () => {
    afterEach(() => vi.useRealTimers());

    it('renders presets as links with the active one marked, and the summary', () => {
        const w = mount(FilterBar, {
            props: {
                searchPlaceholder: 'بحث',
                chips: [],
                summary: '٣ من ٨ حسابات',
                presets: [
                    { key: 'mine', label: 'حساباتي', href: '/ads?preset=mine', active: true },
                    { key: 'win', label: 'الكسبانين', href: '/ads?preset=win', active: false },
                ],
            },
        });
        const links = w.findAll('[data-preset]');
        expect(links).toHaveLength(2);
        expect(links[0].attributes('href')).toBe('/ads?preset=mine');
        expect(links[0].attributes('aria-current')).toBe('true');
        expect(links[1].attributes('aria-current')).toBeUndefined();
        expect(w.find('[data-filter-summary]').text()).toBe('٣ من ٨ حسابات');
    });

    it('has no search box without a placeholder', () => {
        expect(mount(FilterBar, { props: { chips: [] } }).find('input[type="search"]').exists()).toBe(false);
    });

    it('debounces search by 300 ms', async () => {
        vi.useFakeTimers();
        const w = mount(FilterBar, { props: { searchPlaceholder: 'بحث', chips: [] } });
        await w.find('input[type="search"]').setValue(' أحمد ');
        await vi.advanceTimersByTimeAsync(299);
        expect(w.emitted('update:search')).toBeUndefined();
        await vi.advanceTimersByTimeAsync(1);
        expect(w.emitted('update:search')?.[0]).toEqual(['أحمد']);
    });

    it('shows chips with remove and clear all', async () => {
        const w = mount(FilterBar, { props: { searchPlaceholder: 'بحث', chips: [{ key: 'status', label: 'مدفوع' }] } });
        await w.find('[role="group"] button').trigger('click');
        expect(w.emitted('remove')?.[0]).toEqual(['status']);
    });

    it('mounts the inline filters once: inside the popover on phones', async () => {
        const w = mount(FilterBar, {
            props: { chips: [], open: true },
            slots: { inline: '<select id="inline-filter"></select>' },
            attachTo: document.body,
        });
        await flushPromises();
        expect(document.querySelectorAll('#inline-filter')).toHaveLength(1);
        w.unmount();
    });

    it('mounts the inline filters once: in the bar from sm up, with no empty «فلاتر» popover', async () => {
        const original = window.matchMedia;
        window.matchMedia = ((query: string) => ({ ...original(query), matches: true })) as typeof window.matchMedia;
        try {
            const w = mount(FilterBar, { props: { chips: [] }, slots: { inline: '<select id="inline-filter"></select>' }, attachTo: document.body });
            await flushPromises();
            expect(document.querySelectorAll('#inline-filter')).toHaveLength(1);
            expect(w.find('[aria-haspopup="dialog"]').exists()).toBe(false);
            w.unmount();
        } finally {
            window.matchMedia = original;
        }
    });
});
