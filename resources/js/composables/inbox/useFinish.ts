import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import { UNDO_TOAST_MS } from '@/lib/finish';
import type { AutoClosedWindow, Conversation, FinishBody, FinishResult, OutcomePayload, QueueEntry } from '@/types/crm';
import { AxiosError } from 'axios';
import { inject, provide, ref, type InjectionKey, type Ref } from 'vue';

/** What the inbox does when «خلصت» or its «تراجع» changed a chat (or found it changed under her). */
export interface FinishHooks {
    finished?: (result: FinishResult) => void;
    undone?: (conversation: Conversation, entry: QueueEntry | null) => void;
    /** The chat is not what the sheet showed (stale, already closed, not hers): read it again. */
    stale?: (conversationId: number) => void;
}

/** A refused «خلصت»: the sheet stays open for `outcome`, `waiting`, `case_exists` and `error`; it closes for `stale`. */
export type FinishOutcome =
    | { ok: true; result: FinishResult }
    | { ok: false; kind: 'outcome'; message: string }
    | { ok: false; kind: 'waiting'; lastMessage: string | null }
    | { ok: false; kind: 'case_exists'; caseId: number | null }
    | { ok: false; kind: 'stale' }
    | { ok: false; kind: 'error'; message: string };

export interface FinishApi {
    /** POST /finish. Toasts the success (with «تراجع» while the server allows it) and the stale refusals itself. */
    finish: (conversationId: number, name: string, body: FinishBody) => Promise<FinishOutcome>;
    /** Q6: the outcome of a window the silence closed. True when saved. */
    amend: (conversationId: number, payload: OutcomePayload) => Promise<boolean>;
    /** Her windows the silence closed today with no outcome (GET /queue/me/auto-closed). */
    autoClosed: Readonly<Ref<AutoClosedWindow[]>>;
    /** Reads the list again unless it was read in the last 30 s (`force` reads it now). */
    loadAutoClosed: (force?: boolean) => Promise<void>;
}

const KEY: InjectionKey<FinishApi> = Symbol('inbox-finish');
const AUTO_CLOSED_TTL_MS = 30_000;

type ErrorBody = { code?: string; message?: string; last_message?: string | null; case_id?: number | null; errors?: Record<string, string[]> };

/** The finish cycle for one page: created once by the inbox (provided), or locally by a sheet outside it. */
export function createFinish(hooks: FinishHooks = {}): FinishApi {
    const api = useApi();
    const toast = useToast();
    const { t } = useI18n();
    const autoClosed = ref<AutoClosedWindow[]>([]);
    let autoClosedAt = 0;
    let autoClosedLoading: Promise<void> | null = null;

    async function undo(conversationId: number): Promise<void> {
        try {
            const { data } = await api.post<{ data: { conversation: Conversation; entry: QueueEntry | null } }>(
                `/inbox/conversations/${conversationId}/finish/undo`,
            );
            hooks.undone?.(data.data.conversation, data.data.entry);
            toast.push(t('finish.undone'));
        } catch (e) {
            const code = e instanceof AxiosError ? (e.response?.data as ErrorBody | undefined)?.code : undefined;
            toast.push(code === 'undo_expired' ? t('finish.undo_expired') : apiErrorMessage(e, t('common.error')), 'error');
            hooks.stale?.(conversationId);
        }
    }

    async function finish(conversationId: number, name: string, body: FinishBody): Promise<FinishOutcome> {
        try {
            const { data } = await api.post<{ data: FinishResult }>(`/inbox/conversations/${conversationId}/finish`, body);
            const result = data.data;
            hooks.finished?.(result);

            const rated = result.closing === 'queued' && body.after === 'thanks' && result.path === 'window';
            const message = t('finish.done', { name }) + (rated ? t('finish.done_rating') : '');
            const canUndo = result.undo_until !== null && Date.parse(result.undo_until) > Date.now();
            toast.push(message, 'success', undefined, canUndo ? { label: t('finish.undo'), run: () => void undo(conversationId) } : undefined, canUndo ? UNDO_TOAST_MS : undefined);
            void loadAutoClosed(true);

            return { ok: true, result };
        } catch (e) {
            if (!(e instanceof AxiosError) || !e.response) return { ok: false, kind: 'error', message: apiErrorMessage(e, t('common.error')) };
            const status = e.response.status;
            const payload = (e.response.data ?? {}) as ErrorBody;

            if (status === 422 && payload.errors?.outcome) return { ok: false, kind: 'outcome', message: payload.errors.outcome[0] ?? t('outcomes.pick_first') };
            if (status === 422 && payload.code === 'case_exists') return { ok: false, kind: 'case_exists', caseId: payload.case_id ?? null };
            if (status === 409 && payload.code === 'customer_waiting') return { ok: false, kind: 'waiting', lastMessage: payload.last_message ?? null };
            if (status === 403 || (status === 409 && (payload.code === 'stale_state' || payload.code === 'already_closed'))) {
                toast.push(status === 409 && payload.code === 'stale_state' ? t('finish.stale') : apiErrorMessage(e, t('finish.stale')), 'error');
                hooks.stale?.(conversationId);

                return { ok: false, kind: 'stale' };
            }

            return { ok: false, kind: 'error', message: apiErrorMessage(e, t('common.error')) };
        }
    }

    async function amend(conversationId: number, payload: OutcomePayload): Promise<boolean> {
        try {
            await api.post(`/inbox/conversations/${conversationId}/outcome`, payload);
            autoClosed.value = autoClosed.value.filter((row) => row.conversation_id !== conversationId);
            toast.push(t('finish.amend.saved'));

            return true;
        } catch (e) {
            toast.push(apiErrorMessage(e, t('common.error')), 'error');
            void loadAutoClosed(true);

            return false;
        }
    }

    function loadAutoClosed(force = false): Promise<void> {
        if (autoClosedLoading) return autoClosedLoading;
        if (!force && Date.now() - autoClosedAt < AUTO_CLOSED_TTL_MS) return Promise.resolve();
        autoClosedLoading = api
            .get<{ data: AutoClosedWindow[] }>('/queue/me/auto-closed', { silent: true })
            .then(({ data }) => {
                autoClosed.value = data.data;
                autoClosedAt = Date.now();
            })
            .catch(() => undefined)
            .finally(() => (autoClosedLoading = null));

        return autoClosedLoading;
    }

    return { finish, amend, autoClosed, loadAutoClosed };
}

/** The inbox's finish cycle, for the header and its sheet below it. */
export function provideFinish(hooks: FinishHooks): FinishApi {
    const finish = createFinish(hooks);
    provide(KEY, finish);

    return finish;
}

/** The provided finish cycle; outside the inbox a local one (no inbox to update). */
export function useFinish(): FinishApi {
    return inject(KEY, null) ?? createFinish();
}
