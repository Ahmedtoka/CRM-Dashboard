import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

const role = vi.hoisted(() => ({ value: 'supervisor' }));

vi.mock('@inertiajs/vue3', () => ({
    router: { reload: vi.fn() },
    usePage: () => ({ props: { errors: {}, auth: { user: { id: 1, role: role.value } }, buyers: [] }, url: '/ads/setup/buyers' }),
    useForm: (data: Record<string, unknown>) => {
        const form: Record<string, unknown> = { ...data, errors: {}, processing: false, post: vi.fn(), put: vi.fn(), delete: vi.fn(), clearErrors: vi.fn() };
        form.defaults = () => ({ reset: vi.fn() });
        form.transform = () => form;
        return form;
    },
    Head: { template: '<div />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

import BuyersSetup from '@/pages/Ads/BuyersSetup.vue';

const props = {
    buyers: [{ id: 3, name: 'Sara', color: null, is_active: true, user: null, targets: [] }],
    users: [],
    settings: { tax_rate: 0.14, tax_rate_percent: 14, winner_thresholds: { winner: 3, promising: 2, loser: 1, loser_min_spend: 500, min_spend: 300, min_days: 3 } },
};
const stubs = { AppLayout: { template: '<div><slot /></div>' }, PageHeader: { template: '<div><slot /></div>' }, AdsSetupTabs: true };

describe('Ads setup › buyers (F7 useless buttons)', () => {
    it('has no header «add user» link, and none at all for a supervisor (the users page is admin-only)', async () => {
        role.value = 'supervisor';
        const w = mount(BuyersSetup, { props: props as never, global: { stubs }, attachTo: document.body });
        expect(w.find('a[href="/settings/users"]').exists()).toBe(false);
        await w.get('[data-test="add-buyer"]').trigger('click');
        await flushPromises();
        expect(document.querySelector('a[href="/settings/users"]')).toBeNull();
        w.unmount();
    });

    it('keeps the link inside the buyer dialog for an admin', async () => {
        role.value = 'admin';
        const w = mount(BuyersSetup, { props: props as never, global: { stubs }, attachTo: document.body });
        expect(w.find('a[href="/settings/users"]').exists()).toBe(false);
        await w.get('[data-test="add-buyer"]').trigger('click');
        await flushPromises();
        expect(document.querySelectorAll('a[href="/settings/users"]')).toHaveLength(1);
        w.unmount();
    });

    it('row actions are labelled icons', () => {
        role.value = 'admin';
        const w = mount(BuyersSetup, { props: props as never, global: { stubs } });
        const labels = w.findAll('[data-icon-action]').map((a) => a.attributes('aria-label'));
        expect(labels).toEqual(expect.arrayContaining(['تعديل Sara', 'مسح Sara']));
    });
});
