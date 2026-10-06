import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', () => ({ router: { reload: vi.fn() }, usePage: () => ({ props: { ads: { canWrite: true } } }) }));

import AdStatusButton from '@/components/ads/AdStatusButton.vue';

const base = { accountId: 1, account: 'LV', platform: 'meta', level: 'ad', externalId: '1', name: 'Ad', status: 'ACTIVE', canWrite: true };

describe('AdStatusButton', () => {
    it('is a 44 px target on phones even in the compact size (Stop always reachable)', () => {
        const cls = mount(AdStatusButton, { props: base as never, global: { stubs: { WriteActionDialog: true } } }).find('button').classes();
        expect(cls).toContain('h-11');
        expect(cls).toContain('md:h-7');
    });

    it('is hidden for Google and without write access', () => {
        expect(mount(AdStatusButton, { props: { ...base, platform: 'google' } as never, global: { stubs: { WriteActionDialog: true } } }).find('button').exists()).toBe(false);
        expect(mount(AdStatusButton, { props: { ...base, canWrite: false } as never, global: { stubs: { WriteActionDialog: true } } }).find('button').exists()).toBe(false);
    });
});
