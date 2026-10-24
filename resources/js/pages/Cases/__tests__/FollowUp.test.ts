import { flushPromises, mount } from '@vue/test-utils';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

const patch = vi.hoisted(() => vi.fn());
const post = vi.hoisted(() => vi.fn());
const push = vi.hoisted(() => vi.fn());
const reload = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/vue3', () => ({
    router: { reload, get: vi.fn(), visit: vi.fn(), push: vi.fn(), replace: vi.fn(), on: vi.fn(() => () => {}) },
    usePage: () => ({ props: { errors: {}, auth: { user: { id: 7, role: 'supervisor' } } }, url: '/cases/follow-up' }),
    Head: { template: '<div />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));
vi.mock('@/composables/useApi', () => ({ useApi: () => ({ patch, post }), apiErrorMessage: (_e: unknown, f: string) => f }));
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ push }) }));

import { setCurrentLocale } from '@/composables/useI18n';
import FollowUp from '@/pages/Cases/FollowUp.vue';
import type { FollowUpRow } from '@/types/crm';

const rows: FollowUpRow[] = [
    {
        id: 5,
        type: 'exchange',
        type_label: 'استبدال',
        status: 'new',
        order_id: null,
        order_number: '#5566',
        conversation_id: 33,
        customer: { id: 1, name: 'Mona Youssef', phone: '+201099998888' },
        owner: { id: 9, name: 'Hoda' },
        assigned_to: null,
        opened_at: '2026-10-20T08:00:00+00:00',
        sla: { state: 'overdue', due_at: '2026-10-20T09:00:00+00:00', resolved_at: null },
        last_customer_message: 'هو فين الاستبدال؟',
        last_customer_message_at: '2026-10-20T10:00:00+00:00',
        last_note: { body: 'كلمتها', author: { id: 9, name: 'Hoda' }, at: '2026-10-20T09:30:00+00:00' },
        next_action: 'reply',
    },
    {
        id: 6,
        type: 'complaint',
        type_label: 'شكوى',
        status: 'in_progress',
        order_id: null,
        order_number: null,
        conversation_id: 34,
        customer: { id: 2, name: 'Sara Samy', phone: null },
        owner: null,
        assigned_to: null,
        opened_at: '2026-10-20T08:00:00+00:00',
        sla: { state: null, due_at: null, resolved_at: null },
        last_customer_message: null,
        last_customer_message_at: null,
        last_note: null,
        next_action: 'assign',
    },
];
const counts = { open: 2, overdue: 1, unassigned: 1, mine: 0 };
const team = [
    { id: 7, name: 'Me' },
    { id: 9, name: 'Hoda' },
];
// The drawer and the dialog are the kit's; the page's own wiring is what this test reads.
const stubs = {
    AppLayout: { template: '<div><slot /></div>' },
    CaseDrawer: { template: '<div data-stub-drawer />' },
    FormDialog: {
        props: ['open', 'title', 'busy', 'error', 'disabled'],
        emits: ['submit', 'update:open'],
        template: '<div v-if="open" data-stub-dialog><slot /><button type="button" data-stub-submit @click="$emit(\'submit\')">save</button></div>',
    },
};

beforeAll(() => setCurrentLocale('ar'));
beforeEach(() => {
    patch.mockReset().mockResolvedValue({ data: {} });
    post.mockReset().mockResolvedValue({ data: {} });
    push.mockReset();
    reload.mockReset();
    vi.spyOn(window, 'confirm').mockReturnValue(true);
});

function mountPage() {
    return mount(FollowUp, { props: { rows, counts, team, me: 7 }, global: { stubs } });
}

describe('«المتابعة»', () => {
    it('shows the tiles, each case with its owner, her last words, the note and the next step', () => {
        const w = mountPage();
        const text = w.text();

        expect(w.find('[data-follow-up-tiles]').text()).toContain('متأخرة');
        expect(text).toContain('Mona Youssef');
        expect(text).toContain('#5566');
        expect(text).toContain('Hoda');
        expect(text).toContain('هو فين الاستبدال؟');
        expect(text).toContain('كلمتها');
        expect(text).toContain('ردّي عليها');
        expect(text).toContain('محتاجة مسؤول');
        expect(text).toContain('غير معيّن');
        expect(text).toContain('ماكتبتش حاجة');
        // The table and the phone cards both render (CSS picks one): one take action per row in the table.
        expect(w.findAll('table [data-action="take"]')).toHaveLength(2);
    });

    it('«استلمي» assigns the case to me through the cases PATCH and reloads the list', async () => {
        const w = mountPage();

        await w.get('table [data-action="take"]').trigger('click');
        await flushPromises();

        expect(patch).toHaveBeenCalledWith('/cases/5', { assigned_to_id: 7 });
        expect(reload).toHaveBeenCalledWith({ only: ['rows', 'counts'] });
        expect(push).toHaveBeenCalledWith(expect.stringContaining('بقت معاكي'));
    });

    it('«اتحلّت» asks first, then marks the case resolved through the same PATCH', async () => {
        const w = mountPage();

        vi.spyOn(window, 'confirm').mockReturnValueOnce(false);
        await w.get('table [data-action="resolve"]').trigger('click');
        await flushPromises();
        expect(patch).not.toHaveBeenCalled();

        await w.get('table [data-action="resolve"]').trigger('click');
        await flushPromises();
        expect(window.confirm).toHaveBeenLastCalledWith(expect.stringContaining('اتحلّت فعلاً'));
        expect(patch).toHaveBeenCalledWith('/cases/5', { status: 'resolved' });
        expect(reload).toHaveBeenCalled();
    });

    it('«ملاحظة» posts a note on the case conversation', async () => {
        const w = mountPage();

        expect(w.find('[data-stub-dialog]').exists()).toBe(false);
        await w.get('table [data-action="note"]').trigger('click');
        await w.get('[data-note-body]').setValue('  كلمتها، هتستنى الاستبدال ');
        await w.get('[data-stub-submit]').trigger('click');
        await flushPromises();

        expect(post).toHaveBeenCalledWith('/inbox/conversations/33/notes', { body: 'كلمتها، هتستنى الاستبدال' });
        expect(reload).toHaveBeenCalled();
        expect(w.find('[data-stub-dialog]').exists()).toBe(false);
    });
});
