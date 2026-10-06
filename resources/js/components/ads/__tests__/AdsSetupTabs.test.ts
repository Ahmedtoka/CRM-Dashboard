import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ url: '/ads/sync' }), Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } }));

import AdsSetupTabs from '@/components/ads/AdsSetupTabs.vue';

describe('AdsSetupTabs', () => {
    it('lists the four setup tabs and marks the current one', () => {
        const w = mount(AdsSetupTabs);
        expect(w.findAll('a').map((a) => a.attributes('href'))).toEqual(['/ads/accounts', '/ads/sync', '/ads/setup/buyers', '/ads/setup/rules']);
        expect(w.find('a[aria-current="page"]').attributes('href')).toBe('/ads/sync');
    });
});
