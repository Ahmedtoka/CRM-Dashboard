import FinishSheet from '@/components/crm/finish/FinishSheet.vue';
import { INBOX_OUTCOME } from '@/composables/inbox/useConversationContext';
import type { FinishTarget } from '@/lib/finish';
import type { Conversation, OutcomeState } from '@/types/crm';
import { flushPromises, mount } from '@vue/test-utils';
import { AxiosError, AxiosHeaders } from 'axios';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { computed, nextTick } from 'vue';

const push = vi.fn();
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ push }) }));

const post = vi.fn();
const get = vi.fn(async () => ({ data: { data: [] } }));
vi.mock('@/composables/useApi', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@/composables/useApi')>();

    return { ...actual, useApi: () => ({ post, get }) };
});

const conversation = { id: 9, customer: { id: 1, name: 'رشا', avatar_url: null }, open_case_id: null } as unknown as Conversation;
const windowTarget: Extract<FinishTarget, { kind: 'window' }> = { kind: 'window', entryId: 41, windowNo: 8, ticket: 100008, assignedUserId: 3, openCaseId: null };

const ok = (over: Record<string, unknown> = {}) => ({
    data: { data: { conversation, entry: null, path: 'window', end_mode: 'thanks', closing: 'queued', skipped_reason: null, support_case_id: null, undo_until: null, ...over } },
});

function refusal(status: number, data: unknown): AxiosError {
    return new AxiosError('refused', String(status), undefined, undefined, {
        status,
        statusText: '',
        data,
        headers: {},
        config: { headers: new AxiosHeaders() },
    });
}

function mountSheet(props: Record<string, unknown> = {}, outcome: Partial<OutcomeState> = {}) {
    const state = computed<OutcomeState>(() => ({ current: null, source: null, auto: null, ...outcome }));

    return mount(FinishSheet, {
        props: { open: true, conversation, target: windowTarget, ...props },
        global: { provide: { [INBOX_OUTCOME as symbol]: state } },
        attachTo: document.body,
    });
}

async function opened(w: ReturnType<typeof mountSheet>): Promise<void> {
    // The sheet resets when it opens: mount it closed, then open it the way the header does.
    await w.setProps({ open: false });
    await w.setProps({ open: true });
    await flushPromises();
}

const $ = (sel: string) => document.body.querySelector<HTMLElement>(sel);
const sheet = () => $('[data-finish-sheet]')!;
const key = (k: string, target: HTMLElement = sheet()) => target.dispatchEvent(new KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true }));

afterEach(() => {
    document.body.innerHTML = '';
    post.mockReset();
    push.mockReset();
});

describe('FinishSheet', () => {
    it('a window: says which window, no outcome invented, and refuses «خلصت» until one is picked', async () => {
        const w = mountSheet();
        await opened(w);

        expect($('[data-finish-line]')?.textContent).toContain('شباك #٨ هيفضى');
        expect($('[data-finish-smart]')?.textContent).toContain('اختاري نتيجة المحادثة');
        expect(document.body.querySelectorAll('[data-outcome][aria-checked="true"]').length).toBe(0);

        $('[data-finish-confirm]')!.click();
        await flushPromises();
        expect(post).not.toHaveBeenCalled();
        expect($('[data-finish-error]')?.textContent).toContain('اختاري نتيجة المحادثة الأول');
        w.unmount();
    });

    it('a digit picks, Enter confirms with the window payload, the sheet closes and the toast offers «تراجع»', async () => {
        post.mockResolvedValueOnce(ok({ undo_until: new Date(Date.now() + 15000).toISOString() }));
        const w = mountSheet();
        await opened(w);

        key('1');
        await nextTick();
        expect($('[data-outcome="price"]')?.getAttribute('aria-checked')).toBe('true');
        expect($('[data-finish-closing] [role="switch"]')?.getAttribute('aria-checked')).toBe('true');

        key('Enter');
        await flushPromises();
        expect(post).toHaveBeenCalledWith('/inbox/conversations/9/finish', { entry_id: 41, outcome: 'price', after: 'thanks', send_closing: true });
        expect(w.emitted('update:open')?.at(-1)).toEqual([false]);
        expect(push).toHaveBeenCalledWith('اتقفلت المحادثة مع رشا · اتبعت طلب التقييم', 'success', undefined, expect.objectContaining({ label: 'تراجع' }), 10000);
        w.unmount();
    });

    it('Enter while typing the «other» note does not confirm', async () => {
        const w = mountSheet();
        await opened(w);
        $('[data-outcome="other"]')!.click();
        await nextTick();
        const input = $('[data-outcome-note]')!;
        key('Enter', input);
        await flushPromises();
        expect(post).not.toHaveBeenCalled();
        w.unmount();
    });

    it('«البوت يكمّل معاها»: no closing switch, «بتتفرج» picked ahead, no message flag', async () => {
        post.mockResolvedValueOnce(ok({ end_mode: 'bot', closing: 'skipped' }));
        const w = mountSheet({ initialAfter: 'bot', lastFromCustomer: { body: 'لسه مستنية' } });
        await opened(w);

        expect($('[data-finish-closing]')).toBeNull();
        expect($('[data-finish-waiting]')).toBeNull();
        expect($('[data-outcome="browsing"]')?.getAttribute('aria-checked')).toBe('true');
        expect($('[data-finish-smart]')?.textContent).toContain('اخترنالك «بتتفرج / هترجع»');

        $('[data-finish-confirm]')!.click();
        await flushPromises();
        expect(post).toHaveBeenCalledWith('/inbox/conversations/9/finish', { entry_id: 41, outcome: 'browsing', after: 'bot' });

        // Back on «شكر» the suggestion goes away again.
        w.unmount();
    });

    it('back on «شكر + تقييم» after «البوت يكمّل» drops the suggested outcome', async () => {
        const w = mountSheet({ initialAfter: 'bot' });
        await opened(w);
        $('[data-after="thanks"]')!.click();
        await nextTick();
        expect(document.body.querySelectorAll('[data-outcome][aria-checked="true"]').length).toBe(0);
        expect($('[data-finish-closing]')).not.toBeNull();
        w.unmount();
    });

    it('a waiting customer: the amber warning with her words, the switch off, and confirm_waiting sent', async () => {
        post.mockResolvedValueOnce(ok({ closing: 'skipped', skipped_reason: 'waiting' }));
        const w = mountSheet({ lastFromCustomer: { body: 'المقاس ده متاح؟' } });
        await opened(w);

        expect($('[data-finish-waiting]')?.textContent).toContain('العميلة لسه مستنية رد');
        expect($('[data-finish-waiting-text]')?.textContent).toContain('المقاس ده متاح؟');
        expect($('[data-finish-closing] [role="switch"]')?.getAttribute('aria-checked')).toBe('false');

        key('2');
        await nextTick();
        $('[data-finish-confirm]')!.click();
        await flushPromises();
        expect(post).toHaveBeenCalledWith('/inbox/conversations/9/finish', {
            entry_id: 41,
            outcome: 'size_out',
            after: 'thanks',
            send_closing: false,
            confirm_waiting: true,
        });
        expect(push).toHaveBeenCalledWith('اتقفلت المحادثة مع رشا', 'success', undefined, undefined, undefined);
        w.unmount();
    });

    it('she can still send the thanks to a waiting customer («ابعتي الشكر برضه»)', async () => {
        post.mockResolvedValueOnce(ok());
        const w = mountSheet({ awaitingSince: '2026-10-18T10:00:00Z' });
        await opened(w);
        $('[data-finish-closing] [role="switch"]')!.click();
        key('3');
        await nextTick();
        $('[data-finish-confirm]')!.click();
        await flushPromises();
        expect(post.mock.calls[0][1]).toMatchObject({ send_closing: true, confirm_waiting: true });
        w.unmount();
    });

    it('409 customer_waiting keeps the sheet open with her last message, and the next try confirms it', async () => {
        post.mockRejectedValueOnce(refusal(409, { code: 'customer_waiting', last_message: 'فين الأوردر؟' })).mockResolvedValueOnce(ok());
        const w = mountSheet();
        await opened(w);
        key('1');
        await nextTick();
        $('[data-finish-confirm]')!.click();
        await flushPromises();

        expect(w.emitted('update:open')).toBeUndefined();
        expect($('[data-finish-waiting-text]')?.textContent).toContain('فين الأوردر؟');
        expect($('[data-finish-closing] [role="switch"]')?.getAttribute('aria-checked')).toBe('false');

        $('[data-finish-confirm]')!.click();
        await flushPromises();
        expect(post.mock.calls[1][1]).toMatchObject({ confirm_waiting: true, send_closing: false });
        w.unmount();
    });

    it('a stale state closes the sheet with a toast', async () => {
        post.mockRejectedValueOnce(refusal(409, { code: 'stale_state', entry_id: 99 }));
        const w = mountSheet();
        await opened(w);
        key('1');
        await nextTick();
        key('Enter');
        await flushPromises();
        expect(w.emitted('update:open')?.at(-1)).toEqual([false]);
        expect(push).toHaveBeenCalledWith(expect.stringContaining('المحادثة اتغيرت'), 'error');
        w.unmount();
    });

    it('an ordered chat: the smart box says why, the outcome is locked, nothing to send for it', async () => {
        post.mockResolvedValueOnce(ok());
        const w = mountSheet({}, { auto: 'ordered', auto_order: { id: 3, number: '1384', created_at: new Date().toISOString() } });
        await opened(w);
        expect($('[data-finish-smart]')?.textContent).toContain('اخترنالك «اتعمل أوردر»');
        expect($('[data-finish-smart]')?.textContent).toContain('أوردر #1384');
        expect($('[data-outcome-locked]')).not.toBeNull();
        key('Enter');
        await flushPromises();
        expect(post).toHaveBeenCalledWith('/inbox/conversations/9/finish', { entry_id: 41, after: 'thanks', send_closing: true });
        w.unmount();
    });

    it('the lounge: its own line, no «بعدها», no case row, the outcome optional', async () => {
        post.mockResolvedValueOnce(ok({ path: 'lounge', closing: 'queued' }));
        const w = mountSheet({ target: { kind: 'lounge', entryId: 77 } });
        await opened(w);
        expect($('[data-finish-line]')?.textContent).toContain('هتتشال من الصالة');
        expect($('[data-after="bot"]')).toBeNull();
        expect($('[data-finish-case]')).toBeNull();
        key('Enter');
        await flushPromises();
        expect(post).toHaveBeenCalledWith('/inbox/conversations/9/finish', { entry_id: 77 });
        expect(push).toHaveBeenCalledWith('اتقفلت المحادثة مع رشا', 'success', undefined, undefined, undefined);
        w.unmount();
    });

    it('no window: entry_id null, thanks without a rating, no case row', async () => {
        post.mockResolvedValueOnce(ok({ path: 'none' }));
        const w = mountSheet({ target: { kind: 'none' } });
        await opened(w);
        expect($('[data-finish-line]')?.textContent).toContain('المحادثة هترجع للبوت.');
        expect($('[data-finish-closing]')?.textContent).toContain('ابعتي رسالة الختام');
        expect($('[data-finish-closing]')?.textContent).not.toContain('التقييم');
        expect($('[data-finish-case]')).toBeNull();
        key('4');
        await nextTick();
        key('Enter');
        await flushPromises();
        expect(post).toHaveBeenCalledWith('/inbox/conversations/9/finish', { entry_id: null, outcome: 'browsing', after: 'thanks', send_closing: true });
        w.unmount();
    });

    it('her open case can be marked resolved; without one a new request can be opened', async () => {
        post.mockResolvedValue(ok());
        const w = mountSheet({ target: { ...windowTarget, openCaseId: 12 } });
        await opened(w);
        expect($('[data-finish-case]')?.textContent).toContain('عندها طلب #١٢ مفتوح');
        ($('[data-finish-resolve-case]') as HTMLInputElement).click();
        key('1');
        await nextTick();
        $('[data-finish-confirm]')!.click();
        await flushPromises();
        expect(post.mock.calls[0][1]).toMatchObject({ resolve_case_id: 12 });
        w.unmount();

        const v = mountSheet();
        await opened(v);
        ($('[data-finish-open-case]') as HTMLInputElement).click();
        await nextTick();
        expect(($('[data-finish-case-type]') as HTMLSelectElement).value).toBe('complaint');
        key('1');
        await nextTick();
        $('[data-finish-confirm]')!.click();
        await flushPromises();
        expect(post.mock.calls[1][1]).toMatchObject({ open_case: 'complaint' });
        v.unmount();
    });
});
