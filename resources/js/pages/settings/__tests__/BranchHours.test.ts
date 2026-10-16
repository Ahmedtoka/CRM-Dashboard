import { flushPromises, mount } from '@vue/test-utils';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

const put = vi.hoisted(() => vi.fn());
const push = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/vue3', () => ({
    router: { reload: vi.fn() },
    usePage: () => ({ props: { errors: {}, auth: { user: { id: 1, role: 'admin' } } }, url: '/settings/branch-hours' }),
    Head: { template: '<div />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));
vi.mock('@/composables/useApi', () => ({ useApi: () => ({ put }), apiErrorMessage: (_e: unknown, f: string) => f }));
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ push }) }));

import { setCurrentLocale } from '@/composables/useI18n';
import { activitySentence } from '@/lib/activity';
import BranchHours from '@/pages/settings/BranchHours.vue';
import type { ActivityLogItem } from '@/types/admin';

const branches = [
    { id: 1, name: 'El Marghany', area_ar: 'مصر الجديدة', area_en: 'Heliopolis', hours: 'يوميًا ١٠ ص - ١١ م', phone: '01094538159', is_active: true },
    { id: 2, name: 'Degla', area_ar: 'المعادي', area_en: 'Maadi', hours: null, phone: null, is_active: false },
];
const stubs = { AppLayout: { template: '<div><slot /></div>' } };

beforeAll(() => setCurrentLocale('ar'));
beforeEach(() => {
    put.mockReset().mockResolvedValue({ data: {} });
    push.mockReset();
    vi.spyOn(window, 'confirm').mockReturnValue(true);
});

describe('settings › مواعيد الفروع', () => {
    it('lists each branch with its area, hours, phone and status, and offers no add or delete', () => {
        const w = mount(BranchHours, { props: { branches }, global: { stubs } });
        const text = w.text();

        expect(text).toContain('El Marghany');
        expect(text).toContain('مصر الجديدة');
        expect(text).toContain('يوميًا ١٠ ص - ١١ م');
        expect(text).toContain('01094538159');
        expect(text).toContain('مفيش مواعيد');
        expect(text).toContain('موقوف');
        expect(w.find('[aria-label^="حذف"]').exists()).toBe(false);
    });

    it('switches a branch off (after a confirm) and on with only is_active', async () => {
        const w = mount(BranchHours, { props: { branches }, global: { stubs } });

        await w.get('[aria-label="إيقاف El Marghany"]').trigger('click');
        await flushPromises();
        expect(window.confirm).toHaveBeenCalledWith(expect.stringContaining('El Marghany'));
        expect(put).toHaveBeenCalledWith('/settings/branch-hours/1', { is_active: false });

        await w.get('[aria-label="إظهار Degla"]').trigger('click');
        await flushPromises();
        expect(put).toHaveBeenLastCalledWith('/settings/branch-hours/2', { is_active: true });
        // Showing a branch again asks nothing.
        expect(window.confirm).toHaveBeenCalledTimes(1);
    });

    it('does not stop a branch when the confirm is cancelled', async () => {
        vi.mocked(window.confirm).mockReturnValue(false);
        const w = mount(BranchHours, { props: { branches }, global: { stubs } });

        await w.get('[aria-label="إيقاف El Marghany"]').trigger('click');
        await flushPromises();
        expect(put).not.toHaveBeenCalled();
    });

    it('shows an error toast when the switch fails', async () => {
        put.mockRejectedValue(new Error('boom'));
        const w = mount(BranchHours, { props: { branches }, global: { stubs } });

        await w.get('[aria-label="إظهار Degla"]').trigger('click');
        await flushPromises();
        expect(push).toHaveBeenCalledWith(expect.any(String), 'error');
    });

    it('caps the hours at 60 characters with a live counter', async () => {
        const w = mount(BranchHours, { props: { branches }, global: { stubs }, attachTo: document.body });

        await w.get('[aria-label="تعديل Degla"]').trigger('click');
        await flushPromises();
        const hours = document.querySelector('textarea') as HTMLTextAreaElement;
        expect(hours.maxLength).toBe(60);
        expect(document.body.textContent).toContain('٠ / ٦٠');
        hours.value = 'abc';
        hours.dispatchEvent(new Event('input'));
        await flushPromises();
        expect(document.body.textContent).toContain('٣ / ٦٠');
        w.unmount();
    });

    it('saves the edited hours and phone of the branch', async () => {
        const w = mount(BranchHours, { props: { branches }, global: { stubs }, attachTo: document.body });

        await w.get('[aria-label="تعديل Degla"]').trigger('click');
        await flushPromises();
        const hours = document.querySelector('textarea') as HTMLTextAreaElement;
        const phone = document.querySelector('input[type="tel"]') as HTMLInputElement;
        hours.value = 'يوميًا من ١٠ لـ ١٠';
        hours.dispatchEvent(new Event('input'));
        phone.value = '01000000000';
        phone.dispatchEvent(new Event('input'));
        (document.querySelector('form') as HTMLFormElement).dispatchEvent(new Event('submit', { cancelable: true }));
        await flushPromises();

        expect(put).toHaveBeenCalledWith('/settings/branch-hours/2', { hours: 'يوميًا من ١٠ لـ ١٠', phone: '01000000000' });
        w.unmount();
    });

    it('describes a branch edit in the activity log', () => {
        const log = {
            action: 'branch.updated',
            actor_type: 'user',
            user: { name: 'Ahmed' },
            meta: { name: 'Degla', changed: ['hours'] },
        } as unknown as ActivityLogItem;

        expect(activitySentence(log, 'ar', [])).toBe('⁨Ahmed⁩ عدّل بيانات فرع «⁨Degla⁩»');
        expect(activitySentence(log, 'en', [])).toBe('⁨Ahmed⁩ edited branch “⁨Degla⁩”');
    });
});
