import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useEcho } from '@/composables/useEcho';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import type { Message, MyQueuePayload, QueueCloseReason, QueueEntry, ShiftMember, SupportCaseType } from '@/types/crm';
import { AxiosError } from 'axios';
import { computed, inject, onScopeDispose, provide, ref, watch, type ComputedRef, type InjectionKey, type Ref } from 'vue';

/** How often `/queue/me` is re-read while the websocket is down and she has a desk or a window. */
export const QUEUE_POLL_MS = 30_000;

/** The same while the websocket is down and she has neither (she may be put on the roster any minute). */
export const QUEUE_IDLE_POLL_MS = 60_000;

/** Retries of the first `/queue/me` when it fails (a deploy, a network blip): then every minute until it answers. */
export const FIRST_LOAD_RETRY_MS = [5_000, 15_000, 30_000];
export const FIRST_LOAD_RETRY_EVERY_MS = 60_000;

/** The queue's events live outside Echo's default `App.Events` namespace, hence the leading dot. */
const EVENT_ENTRY = '.App\\Queue\\Events\\QueueEntryUpdated';
const EVENT_MEMBER = '.App\\Queue\\Events\\QueueMemberUpdated';
const EVENT_ASSIGNED = '.App\\Queue\\Events\\QueueAssigned';

const OPEN_STATUSES = ['called', 'active'];

export type MyStatus = 'available' | 'break';

export interface MyQueue {
    /** The queue is switched on (known after the first `/queue/me`). */
    enabled: Readonly<Ref<boolean>>;
    /** She is on the open shift or still holds a window: the strip, the banner and the close menu are shown. */
    active: ComputedRef<boolean>;
    member: Readonly<Ref<ShiftMember | null>>;
    /** Her open windows, by window number. */
    entries: Readonly<Ref<QueueEntry[]>>;
    /** How many windows she may hold. */
    cap: ComputedRef<number>;
    /** The leader of the open shift: a window of hers cannot be escalated (nobody above her). */
    leaderUserId: Readonly<Ref<number | null>>;
    /** The action in flight: `close-{id}`, `escalate-{id}` or `status`. */
    busy: Readonly<Ref<string | null>>;
    /** Seconds since the conversation reached her window. */
    elapsed: (entry: Pick<QueueEntry, 'delivered_at'>) => number;
    /** Seconds to the auto-close; null while the silence clock is not running. */
    silenceLeft: (entry: QueueEntry) => number | null;
    /** The countdown is in its warning stretch (the customer got the warning, or is about to). */
    silenceWarning: (entry: QueueEntry) => boolean;
    /** Seconds to the hand-off of a window whose customer waits for her reply; null without that clock (flow revision §4). */
    handoffLeft: (entry: QueueEntry) => number | null;
    /** Seconds left of her break; null when she is not on one. */
    breakLeft: ComputedRef<number | null>;
    entryOf: (conversationId: number) => QueueEntry | null;
    closeEntry: (id: number, reason: QueueCloseReason, caseType?: SupportCaseType | null) => Promise<boolean>;
    escalate: (id: number) => Promise<boolean>;
    setStatus: (status: MyStatus) => Promise<boolean>;
    /** Feed of the inbox's `MessageCreated`: a customer message stops the silence clock of her window. */
    noteMessage: (message: Pick<Message, 'direction' | 'conversation_id'>) => void;
    refresh: () => Promise<void>;
}

interface Options {
    userId: number;
    /** A window was just given to her (never fired for the windows she already had on load). */
    onAssigned?: (entry: QueueEntry) => void;
    /** A window was closed or transferred from here: the open thread and the list still show it. */
    onReleased?: (entryId: number) => void;
}

const KEY: InjectionKey<MyQueue> = Symbol('my-queue');

/** The inbox's queue state for the components below it; null outside the inbox. */
export function useMyQueueContext(): MyQueue | null {
    return inject(KEY, null);
}

/**
 * The moderator's own desk inside the inbox: her open windows with their timers, her status,
 * closing and transferring a window.
 *
 * One `/queue/me` on start, retried with a backoff until it answers. With the queue off nothing
 * else happens: no listeners, no timers, no further requests. With it on, she listens on her own
 * `user.{id}` channel only (the bell already holds it, so it costs nothing): `QueueAssigned`,
 * `QueueEntryUpdated` of her windows and `QueueMemberUpdated` of her desks, so being put on (or
 * taken off) the roster after the page loaded shows at once. While the websocket is down
 * `/queue/me` is re-read every 30 s (every 60 s while she has no desk and no window), and once
 * more when the tab comes back or the socket reconnects. Everything stops with the calling scope.
 *
 * A late answer never undoes what an event already told us: an answer to a request that started
 * before the last event is dropped and asked again, and a window known to be closed never comes
 * back.
 */
export function useMyQueue(options: Options): MyQueue {
    const api = useApi();
    const toast = useToast();
    const { t } = useI18n();
    const { echo, live } = useEcho();

    const enabled = ref(false);
    const member = ref<ShiftMember | null>(null);
    const entries = ref<QueueEntry[]>([]);
    const settings = ref<MyQueuePayload['settings']>(null);
    const leaderUserId = ref<number | null>(null);
    const busy = ref<string | null>(null);
    const tick = ref(Date.now());

    /** When each entry's `silence_left_seconds` was true, on this machine's clock. */
    const stampedAt = new Map<number, number>();
    /** Every window she has been shown, so an assignment is announced once. */
    const known = new Set<number>();
    /** Windows closed, transferred or not hers: an old answer or a late event never brings them back. */
    const gone = new Set<number>();
    /** Server clock minus this machine's, so a wrong local clock does not skew the chat timers. */
    let skew = 0;
    let loaded = false;
    let disposed = false;
    let listening = false;
    let loadSeq = 0;
    /** Bumped by every queue event: an answer to a request older than the last event is stale. */
    let eventSeq = 0;
    let firstLoadFailures = 0;
    let ticker: number | undefined;
    let poller: number | undefined;
    let refetchTimer: number | undefined;
    let retryTimer: number | undefined;

    const active = computed(() => enabled.value && (member.value !== null || entries.value.length > 0));
    const cap = computed(() => Math.max(member.value?.cap ?? settings.value?.windows_per_moderator ?? 0, entries.value.length));
    const breakLeft = computed(() => {
        const m = member.value;
        if (!m || m.status !== 'break' || !m.break_ends_at) return null;

        return Math.max(0, Math.round((Date.parse(m.break_ends_at) - (tick.value + skew)) / 1000));
    });

    function sort(list: QueueEntry[]): QueueEntry[] {
        return [...list].sort((a, b) => (a.window_no ?? 99) - (b.window_no ?? 99) || a.id - b.id);
    }

    function announce(entry: QueueEntry): void {
        if (known.has(entry.id)) return;
        known.add(entry.id);
        if (loaded) options.onAssigned?.(entry);
    }

    function isMine(entry: QueueEntry): boolean {
        return entry.assigned_user_id === options.userId && OPEN_STATUSES.includes(entry.status);
    }

    function upsert(entry: QueueEntry): void {
        if (gone.has(entry.id)) return;
        stampedAt.set(entry.id, Date.now());
        entries.value = sort([...entries.value.filter((e) => e.id !== entry.id), entry]);
        announce(entry);
    }

    function remove(id: number): void {
        gone.add(id);
        stampedAt.delete(id);
        if (entries.value.some((e) => e.id === id)) entries.value = entries.value.filter((e) => e.id !== id);
    }

    function apply(payload: MyQueuePayload): void {
        const at = Date.now();
        const serverNow = Date.parse(payload.server_time);
        const list = payload.entries.filter((e) => !gone.has(e.id));
        skew = Number.isNaN(serverNow) ? 0 : serverNow - at;
        enabled.value = payload.enabled;
        member.value = payload.member;
        settings.value = payload.settings;
        leaderUserId.value = payload.leader_user_id ?? null;
        stampedAt.clear();
        list.forEach((e) => stampedAt.set(e.id, at));
        entries.value = sort(list);
        list.forEach(announce);
        loaded = true;
    }

    async function refresh(): Promise<void> {
        const seq = ++loadSeq;
        const since = eventSeq;
        const { data } = await api.get<{ data: MyQueuePayload }>('/queue/me', { silent: true });
        if (disposed || seq !== loadSeq) return; // answered for a newer request, or after the inbox closed

        // An event arrived while this request was on its way: the answer may predate it. The
        // event already updated what it is about; ask again for the rest.
        if (loaded && since !== eventSeq) {
            refreshSoon();

            return;
        }

        apply(data.data);
    }

    /** Several events of one change arrive together: one request for all of them. */
    function refreshSoon(): void {
        window.clearTimeout(refetchTimer);
        refetchTimer = window.setTimeout(() => void refresh().catch(() => undefined), 300);
    }

    /** The first `/queue/me`: until it answers the inbox does not know whether the queue is on, so keep asking. */
    function firstLoad(): void {
        window.clearTimeout(retryTimer);
        if (disposed || loaded) return;

        refresh().catch(() => {
            if (disposed || loaded) return;
            const wait = FIRST_LOAD_RETRY_MS[firstLoadFailures] ?? FIRST_LOAD_RETRY_EVERY_MS;
            firstLoadFailures++;
            retryTimer = window.setTimeout(firstLoad, wait);
        });
    }

    function onEntry(entry: QueueEntry): void {
        eventSeq++;
        if (isMine(entry)) upsert(entry);
        else remove(entry.id);
    }

    function onMember(payload: ShiftMember): void {
        if (payload.user?.id !== options.userId) return;
        eventSeq++;

        // Her desk changed in place: take it. Put on (or taken off) a shift, or another desk of
        // hers: the server knows which one is open now.
        if (member.value?.id === payload.id && payload.status !== 'left') member.value = payload;
        else refreshSoon();
    }

    function onAssigned(): void {
        eventSeq++;
        refreshSoon();
    }

    // Her own channel is shared with the bell: our callbacks are detached, the channel is never
    // left. A socket already gone has nothing left to detach.
    function listen(on: boolean): void {
        if (!echo || on === listening) return;
        listening = on;
        try {
            const channel = echo.private(`user.${options.userId}`);
            if (on) channel.listen(EVENT_ASSIGNED, onAssigned).listen(EVENT_ENTRY, onEntry).listen(EVENT_MEMBER, onMember);
            else channel.stopListening(EVENT_ASSIGNED, onAssigned).stopListening(EVENT_ENTRY, onEntry).stopListening(EVENT_MEMBER, onMember);
        } catch {
            // see above
        }
    }

    function elapsed(entry: Pick<QueueEntry, 'delivered_at'>): number {
        const from = entry.delivered_at ? Date.parse(entry.delivered_at) : NaN;

        return Number.isNaN(from) ? 0 : Math.max(0, Math.floor((tick.value + skew - from) / 1000));
    }

    function silenceLeft(entry: QueueEntry): number | null {
        if (entry.silence_left_seconds === null) return null;
        const since = stampedAt.get(entry.id) ?? tick.value;

        return Math.max(0, Math.round(entry.silence_left_seconds - Math.max(0, tick.value - since) / 1000));
    }

    function silenceWarning(entry: QueueEntry): boolean {
        const left = silenceLeft(entry);
        if (left === null) return false;
        const s = settings.value;

        return entry.silence_warned || (s !== null && left <= s.silence_close_seconds - s.silence_warn_seconds);
    }

    function handoffLeft(entry: QueueEntry): number | null {
        if (entry.handoff_left_seconds === null || entry.handoff_left_seconds === undefined) return null;
        const since = stampedAt.get(entry.id) ?? tick.value;

        return Math.max(0, Math.round(entry.handoff_left_seconds - Math.max(0, tick.value - since) / 1000));
    }

    function entryOf(conversationId: number): QueueEntry | null {
        return entries.value.find((e) => e.conversation_id === conversationId) ?? null;
    }

    function noteMessage(message: Pick<Message, 'direction' | 'conversation_id'>): void {
        if (message.direction !== 'in') return;
        const entry = entryOf(message.conversation_id);
        if (!entry || entry.silence_left_seconds === null) return;

        entries.value = entries.value.map((e) => (e.id === entry.id ? { ...e, silence_left_seconds: null, silence_warned: false } : e));
    }

    async function act<T>(key: string, request: () => Promise<T>, done: (result: T) => void): Promise<boolean> {
        if (busy.value !== null) return false;
        busy.value = key;
        try {
            done(await request());

            return true;
        } catch (e) {
            toast.push(apiErrorMessage(e, t('common.error')), 'error');
            // The window is not what we thought (closed meanwhile, queue switched off…): read it again.
            if (e instanceof AxiosError && [404, 409].includes(e.response?.status ?? 0)) void refresh().catch(() => undefined);

            return false;
        } finally {
            busy.value = null;
        }
    }

    function release(id: number): void {
        eventSeq++;
        remove(id);
        options.onReleased?.(id);
    }

    function closeEntry(id: number, reason: QueueCloseReason, caseType: SupportCaseType | null = null): Promise<boolean> {
        return act(
            `close-${id}`,
            () => api.post(`/queue/entries/${id}/close`, reason === 'case' ? { reason, case_type: caseType } : { reason }),
            () => release(id),
        );
    }

    function escalate(id: number): Promise<boolean> {
        return act(
            `escalate-${id}`,
            () => api.post(`/queue/entries/${id}/escalate`),
            () => release(id),
        );
    }

    function setStatus(status: MyStatus): Promise<boolean> {
        return act(
            'status',
            () => api.post<{ data: ShiftMember }>('/queue/me/status', { status }),
            (response) => {
                eventSeq++;
                member.value = response.data.data;
            },
        );
    }

    function onVisible(): void {
        if (!document.hidden && enabled.value && !disposed) refreshSoon();
    }

    // The one-second clock runs only while there is something to count.
    watch(
        () => entries.value.length > 0 || member.value?.status === 'break',
        (counting) => {
            window.clearInterval(ticker);
            ticker = undefined;
            if (counting && !disposed) {
                tick.value = Date.now();
                ticker = window.setInterval(() => (tick.value = Date.now()), 1000);
            }
        },
    );

    watch([enabled, active], ([on, mine]) => {
        if (disposed) return;

        listen(on);

        window.clearInterval(poller);
        poller = undefined;
        if (on) {
            poller = window.setInterval(
                () => {
                    if (!live.value && !document.hidden) void refresh().catch(() => undefined);
                },
                mine ? QUEUE_POLL_MS : QUEUE_IDLE_POLL_MS,
            );
        }
    });

    // Back on the websocket after a drop: whatever was missed meanwhile.
    watch(live, (now, before) => {
        if (now && before === false && enabled.value) refreshSoon();
    });

    document.addEventListener('visibilitychange', onVisible);

    onScopeDispose(() => {
        disposed = true;
        listen(false);
        document.removeEventListener('visibilitychange', onVisible);
        window.clearInterval(ticker);
        window.clearInterval(poller);
        window.clearTimeout(refetchTimer);
        window.clearTimeout(retryTimer);
    });

    firstLoad();

    const queue: MyQueue = {
        enabled,
        active,
        member,
        entries,
        cap,
        leaderUserId,
        busy,
        elapsed,
        silenceLeft,
        silenceWarning,
        handoffLeft,
        breakLeft,
        entryOf,
        closeEntry,
        escalate,
        setStatus,
        noteMessage,
        refresh,
    };
    provide(KEY, queue);

    return queue;
}
