import FinishSheet from '@/components/crm/finish/FinishSheet.vue';
import { INBOX_OUTCOME } from '@/composables/inbox/useConversationContext';
import type { FinishTarget } from '@/lib/finish';
import type { AgentOutcome, Conversation, OutcomeState, OutcomeSuggestion } from '@/types/crm';
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
const windowTarget: Extract<FinishTarget, { kind: 'window' }> = {
    kind: 'window',
    entryId: 41,
    windowNo: 8,
    ticket: 100008,
    assignedUserId: 3,
    openCaseId: null,
};

const ok = (over: Record<string, unknown> = {}) => ({
    data: {
        data: {
            conversation,
            entry: null,
            path: 'window',
            end_mode: 'thanks',
            closing: 'queued',
            skipped_reason: null,
            support_case_id: null,
            undo_until: null,
            ...over,
        },
    },
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

const suggest = (outcome: AgentOutcome, why: OutcomeSuggestion['why'], params: OutcomeSuggestion['params'] = {}): OutcomeSuggestion => ({
    outcome,
    why,
    params,
    case_id: params.case_id ?? null,
    order_id: null,
});

function mountSheet(props: Record<string, unknown> = {}, outcome: Partial<OutcomeState> = {}) {
    const state = computed<OutcomeState>(() => ({ current: null, source: null, auto: null, suggested: null, ...outcome }));

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
const key = (k: string, target: HTMLElement = sheet()) =>
    target.dispatchEvent(new KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true }));
const checked = () => [...document.body.querySelectorAll('[data-outcome][aria-checked="true"]')].map((el) => el.getAttribute('data-outcome'));
const confirm = async () => {
    $('[data-finish-confirm]')!.click();
    await flushPromises();
};

afterEach(() => {
    document.body.innerHTML = '';
    post.mockReset();
    push.mockReset();
});

describe('FinishSheet', () => {
    it('shows the three groups, picks nothing by itself, and refuses «خلصت» until one thing is picked', async () => {
        const w = mountSheet();
        await opened(w);

        expect($('[data-finish-line]')?.textContent).toContain('شباك #٨ هيفضى');
        expect($('[data-finish-smart]')?.textContent).toContain('اختاري نتيجة المحادثة');
        expect([...document.body.querySelectorAll('[data-outcome-group]')].map((g) => g.getAttribute('data-outcome-group'))).toEqual([
            'sale',
            'service',
            'rest',
        ]);
        expect(document.body.querySelectorAll('[data-outcome]').length).toBe(13);
        expect(checked()).toEqual([]);

        await confirm();
        expect(post).not.toHaveBeenCalled();
        expect($('[data-finish-error]')?.textContent).toContain('اختاري إيه اللي حصل في الشات الأول');
        w.unmount();
    });

    it('a digit picks, Enter confirms with the window payload, the sheet closes and the toast offers «تراجع»', async () => {
        post.mockResolvedValueOnce(ok({ undo_until: new Date(Date.now() + 15000).toISOString() }));
        const w = mountSheet();
        await opened(w);

        key('2');
        await nextTick();
        expect(checked()).toEqual(['price']);
        expect($('[data-finish-closing] [role="switch"]')?.getAttribute('aria-checked')).toBe('true');

        key('Enter');
        await flushPromises();
        expect(post).toHaveBeenCalledWith('/inbox/conversations/9/finish', { entry_id: 41, outcome: 'price', after: 'thanks', send_closing: true });
        expect(w.emitted('update:open')?.at(-1)).toEqual([false]);
        expect(push).toHaveBeenCalledWith(
            'اتقفلت المحادثة مع رشا · اتبعت طلب التقييم',
            'success',
            undefined,
            expect.objectContaining({ label: 'تراجع' }),
            10000,
        );
        w.unmount();
    });

    it('Enter while typing the «حاجة تانية» note does not confirm', async () => {
        const w = mountSheet();
        await opened(w);
        $('[data-outcome="other"]')!.click();
        await nextTick();
        key('Enter', $('[data-outcome-note]')!);
        await flushPromises();
        expect(post).not.toHaveBeenCalled();
        w.unmount();
    });

    it("preselects the server's suggestion with its reason; confirming sends it and says it was the suggestion", async () => {
        post.mockResolvedValueOnce(ok());
        const w = mountSheet({}, { suggested: suggest('return_case', 'case_open', { case_id: 12, type: 'return' }) });
        await opened(w);

        expect(checked()).toEqual(['return_case']);
        expect($('[data-finish-smart]')?.textContent).toContain('اخترنالك «مرتجع اتسجل»');
        expect($('[data-finish-why]')?.textContent).toContain('طلب مرتجع #١٢ مفتوح');

        key('Enter');
        await flushPromises();
        expect(post).toHaveBeenCalledWith('/inbox/conversations/9/finish', {
            entry_id: 41,
            outcome: 'return_case',
            suggested_outcome: 'return_case',
            after: 'thanks',
            send_closing: true,
        });
        w.unmount();
    });

    it('«ما ردّتش» suggested: no closing message by default, says so, and changing the pick turns the message on and records the change', async () => {
        post.mockResolvedValueOnce(ok({ closing: 'skipped', skipped_reason: 'no_reply' }));
        const w = mountSheet({}, { suggested: suggest('no_answer', 'no_reply', { minutes: 7 }) });
        await opened(w);

        expect(checked()).toEqual(['no_answer']);
        expect($('[data-finish-why]')?.textContent).toContain('ردّينا عليها ومابعتتش حاجة');
        expect($('[data-finish-will]')?.textContent).toContain('مش هيتبعتلها رسالة عشان ما ردّتش');
        expect($('[data-finish-closing] [role="switch"]')?.getAttribute('aria-checked')).toBe('false');

        $('[data-outcome="price"]')!.click();
        await nextTick();
        expect($('[data-finish-smart]')?.textContent).toContain('اقترحنا «ما ردّتش» وانتي اخترتي غيرها');
        expect($('[data-finish-will]')?.textContent).toContain('هيتبعتلها شكر + طلب تقييم');
        expect($('[data-finish-closing] [role="switch"]')?.getAttribute('aria-checked')).toBe('true');

        await confirm();
        expect(post).toHaveBeenCalledWith('/inbox/conversations/9/finish', {
            entry_id: 41,
            outcome: 'price',
            suggested_outcome: 'no_answer',
            after: 'thanks',
            send_closing: true,
        });
        w.unmount();
    });

    it('«ما ردّتش» with the thanks switched on by hand still sends send_closing', async () => {
        post.mockResolvedValueOnce(ok());
        const w = mountSheet({}, { suggested: suggest('no_answer', 'no_reply', { minutes: 0 }) });
        await opened(w);
        $('[data-finish-closing] [role="switch"]')!.click();
        await nextTick();
        await confirm();
        expect(post.mock.calls[0][1]).toMatchObject({ outcome: 'no_answer', send_closing: true });
        w.unmount();
    });

    it('a purchase suggestion names the order, and a late-arriving suggestion fills an empty pick but never replaces hers', async () => {
        post.mockResolvedValueOnce(ok());
        const order = suggest('ordered', 'order_placed', { number: '1384', created_at: new Date().toISOString() });
        const w = mountSheet({}, { auto: 'ordered', suggested: order, auto_order: { id: 3, number: '1384', created_at: new Date().toISOString() } });
        await opened(w);
        expect(checked()).toEqual(['ordered']);
        expect($('[data-finish-smart]')?.textContent).toContain('اخترنالك «اشترت / اتعمل أوردر»');
        expect($('[data-finish-why]')?.textContent).toContain('أوردر #1384');
        // Not locked: she may still change it.
        $('[data-outcome="return_case"]')!.click();
        await nextTick();
        expect(checked()).toEqual(['return_case']);
        w.unmount();
    });

    it('a case outcome tells what happens to the case: a new request in her name, or her open one linked', async () => {
        post.mockResolvedValue(ok({ support_case_id: 5 }));
        const w = mountSheet();
        await opened(w);
        $('[data-outcome="complaint_case"]')!.click();
        await nextTick();
        expect($('[data-finish-will]')?.textContent).toContain('هيتفتح طلب شكوى باسمك');
        await confirm();
        expect(post.mock.calls[0][1]).toEqual({ entry_id: 41, outcome: 'complaint_case', after: 'thanks', send_closing: true });
        expect(post.mock.calls[0][1]).not.toHaveProperty('open_case');
        w.unmount();

        const v = mountSheet({ target: { ...windowTarget, openCaseId: 12 } });
        await opened(v);
        $('[data-outcome="exchange_case"]')!.click();
        await nextTick();
        expect($('[data-finish-will]')?.textContent).toContain('طلبها #١٢ المفتوح هيتربط');
        ($('[data-finish-resolve-case]') as HTMLInputElement).click();
        await nextTick();
        expect($('[data-finish-will]')?.textContent).toContain('طلب #١٢ هيتعلّم «اتحل»');
        await confirm();
        expect(post.mock.calls[1][1]).toMatchObject({ outcome: 'exchange_case', resolve_case_id: 12 });
        v.unmount();
    });

    it('«سبام»: goes to the bot with no message, whatever «بعدها» says', async () => {
        post.mockResolvedValueOnce(ok({ end_mode: 'bot', closing: 'skipped' }));
        const w = mountSheet();
        await opened(w);
        $('[data-outcome="spam"]')!.click();
        await nextTick();

        expect($('[data-finish-closing]')).toBeNull();
        expect($('[data-finish-will]')?.textContent).toContain('الشات يرجع للبوت');
        await confirm();
        expect(post).toHaveBeenCalledWith('/inbox/conversations/9/finish', { entry_id: 41, outcome: 'spam', after: 'bot' });
        w.unmount();
    });

    it('«البوت يكمّل معاها»: no closing switch, «استفسرت وهترجع» picked ahead, no message flag', async () => {
        post.mockResolvedValueOnce(ok({ end_mode: 'bot', closing: 'skipped' }));
        const w = mountSheet({ initialAfter: 'bot', lastFromCustomer: { body: 'لسه مستنية' } });
        await opened(w);

        expect($('[data-finish-closing]')).toBeNull();
        expect($('[data-finish-waiting]')).toBeNull();
        expect(checked()).toEqual(['browsing']);
        expect($('[data-finish-smart]')?.textContent).toContain('اخترنالك «استفسرت وهترجع»');

        await confirm();
        expect(post).toHaveBeenCalledWith('/inbox/conversations/9/finish', { entry_id: 41, outcome: 'browsing', after: 'bot' });
        w.unmount();
    });

    it('back on «شكر + تقييم» after «البوت يكمّل» drops the hinted pick', async () => {
        const w = mountSheet({ initialAfter: 'bot' });
        await opened(w);
        $('[data-after="thanks"]')!.click();
        await nextTick();
        expect(checked()).toEqual([]);
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

        key('3');
        await nextTick();
        await confirm();
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
        key('4');
        await nextTick();
        await confirm();
        expect(post.mock.calls[0][1]).toMatchObject({ send_closing: true, confirm_waiting: true });
        w.unmount();
    });

    it('409 customer_waiting keeps the sheet open with her last message, and the next try confirms it', async () => {
        post.mockRejectedValueOnce(refusal(409, { code: 'customer_waiting', last_message: 'فين الأوردر؟' })).mockResolvedValueOnce(ok());
        const w = mountSheet();
        await opened(w);
        key('2');
        await nextTick();
        await confirm();

        expect(w.emitted('update:open')).toBeUndefined();
        expect($('[data-finish-waiting-text]')?.textContent).toContain('فين الأوردر؟');
        expect($('[data-finish-closing] [role="switch"]')?.getAttribute('aria-checked')).toBe('false');

        await confirm();
        expect(post.mock.calls[1][1]).toMatchObject({ confirm_waiting: true, send_closing: false });
        w.unmount();
    });

    it('a stale state closes the sheet with a toast', async () => {
        post.mockRejectedValueOnce(refusal(409, { code: 'stale_state', entry_id: 99 }));
        const w = mountSheet();
        await opened(w);
        key('2');
        await nextTick();
        key('Enter');
        await flushPromises();
        expect(w.emitted('update:open')?.at(-1)).toEqual([false]);
        expect(push).toHaveBeenCalledWith(expect.stringContaining('المحادثة اتغيرت'), 'error');
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
        key('5');
        await nextTick();
        key('Enter');
        await flushPromises();
        expect(post).toHaveBeenCalledWith('/inbox/conversations/9/finish', {
            entry_id: null,
            outcome: 'browsing',
            after: 'thanks',
            send_closing: true,
        });
        w.unmount();
    });
});
