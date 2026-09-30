import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useEcho } from '@/composables/useEcho';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import { applyEntry, diffEntries, mergeMember, prefersReducedMotion } from '@/lib/board/state';
import type {
    BoardCall,
    BoardDecision,
    BoardKpis,
    BoardMember,
    BoardMove,
    BoardSettings,
    BoardShift,
    BoardShiftRow,
    BoardSnapshot,
    BoardTemplate,
    BoardUser,
} from '@/types/board';
import type { QueueEntry } from '@/types/crm';
import { computed, onScopeDispose, ref, watch, type ComputedRef, type Ref } from 'vue';

/** How often the state is re-read while the websocket is down. */
export const BOARD_POLL_MS = 15_000;
/** A slow re-read while it is up: who is online and the day's numbers are not all broadcast. */
export const BOARD_REFRESH_MS = 60_000;
/** How long a customer walks from the lounge to her window, and a freed window glows. */
export const BOARD_MOVE_MS = 1600;
/** How long the robot's newest call stands out. */
export const BOARD_CALL_MS = 8000;

/** The queue's events live outside Echo's default `App.Events` namespace, hence the leading dot. */
const EVENT_ENTRY = '.App\\Queue\\Events\\QueueEntryUpdated';
const EVENT_MEMBER = '.App\\Queue\\Events\\QueueMemberUpdated';
const EVENT_SHIFT = '.App\\Queue\\Events\\ShiftUpdated';
const EVENT_DECISION = '.App\\Queue\\Events\\RouterDecided';

const DECISIONS_KEPT = 20;

export type SilenceTone = 'none' | 'calm' | 'warning' | 'last';

export interface Board {
    /** The queue is switched on (the page's own prop until the first state arrives). */
    enabled: Readonly<Ref<boolean>>;
    /** The first state has arrived. */
    loaded: Readonly<Ref<boolean>>;
    /** The first state could not be read. */
    failed: Readonly<Ref<boolean>>;
    /** The websocket is connected; otherwise the state is re-read every 15 seconds. */
    live: ComputedRef<boolean>;
    /** Today in Cairo ('Y-m-d'), the day the shifts and the numbers belong to. */
    businessDate: Readonly<Ref<string | null>>;
    shift: Readonly<Ref<BoardShift | null>>;
    shifts: Readonly<Ref<BoardShiftRow[]>>;
    members: Readonly<Ref<BoardMember[]>>;
    waiting: Readonly<Ref<QueueEntry[]>>;
    open: Readonly<Ref<QueueEntry[]>>;
    decisions: Readonly<Ref<BoardDecision[]>>;
    kpis: Readonly<Ref<BoardKpis | null>>;
    settings: Readonly<Ref<BoardSettings | null>>;
    templates: Readonly<Ref<BoardTemplate[]>>;
    users: Readonly<Ref<BoardUser[]>>;
    /** No shift open but its hours came: the next tick opens it. */
    shiftOpening: Readonly<Ref<boolean>>;
    /** When the next shift starts, while none is open. */
    nextShiftStartsAt: Readonly<Ref<string | null>>;
    withBot: Readonly<Ref<number>>;
    lastCall: Readonly<Ref<BoardCall | null>>;
    /** The newest call is fresh: the robot announces it. */
    calling: ComputedRef<boolean>;
    moves: Readonly<Ref<BoardMove[]>>;
    /** Windows that just became free, as `userId:windowNo`. */
    freed: Readonly<Ref<string[]>>;
    /** The server's clock, ticking every second. */
    now: Readonly<Ref<number>>;
    /** The action in flight, e.g. `assign-12`, `checkout-5`. */
    busy: Readonly<Ref<string | null>>;
    /** Why the last action was refused; cleared by the next one. */
    error: Readonly<Ref<string | null>>;
    secondsSince: (iso: string | null | undefined) => number;
    silenceLeft: (entry: QueueEntry) => number | null;
    silenceTone: (entry: QueueEntry) => SilenceTone;
    /** Seconds to the hand-off of a window whose customer waits for the moderator; null without that clock. */
    handoffLeft: (entry: QueueEntry) => number | null;
    /** Seconds since her break started; null when she is not on one (no automatic return). */
    breakSince: (member: BoardMember) => number | null;
    /** Her break is past `break_minutes`: the desk is red. */
    breakOver: (member: BoardMember) => boolean;
    userName: (id: number | null | undefined) => string | null;
    windowsOf: (userId: number) => QueueEntry[];
    refresh: () => Promise<void>;
    clearError: () => void;
    /** Her number of windows (null = the settings' default). */
    setMemberCap: (memberId: number, cap: number | null) => Promise<boolean>;
    /** «خروج» on her behalf: at once without windows, else «بتقفل» until they close. */
    checkOut: (memberId: number) => Promise<boolean>;
    /** «رجّعي شبابيكها للصالة» on her behalf, while she is checking out. */
    handBack: (memberId: number) => Promise<boolean>;
    setMemberStatus: (memberId: number, status: 'available' | 'break') => Promise<boolean>;
    assign: (entryId: number, userId: number) => Promise<boolean>;
    cancel: (entryId: number, reason: string) => Promise<boolean>;
}

/**
 * The live board's state: one snapshot from `/board/state`, kept current by the `board`
 * channel (each event is applied at once, then the state is re-read shortly after for the
 * numbers), and re-read every 15 seconds while the websocket is down. Timers tick locally
 * from the server's `now`. Everything stops with the calling scope.
 */
export function useBoard(options: { enabled: boolean }): Board {
    const api = useApi();
    const toast = useToast();
    const { t } = useI18n();
    const { echo, live } = useEcho();

    const enabled = ref(options.enabled);
    const loaded = ref(false);
    const failed = ref(false);
    const businessDate = ref<string | null>(null);
    const shift = ref<BoardShift | null>(null);
    const shifts = ref<BoardShiftRow[]>([]);
    const members = ref<BoardMember[]>([]);
    const waiting = ref<QueueEntry[]>([]);
    const open = ref<QueueEntry[]>([]);
    const decisions = ref<BoardDecision[]>([]);
    const kpis = ref<BoardKpis | null>(null);
    const settings = ref<BoardSettings | null>(null);
    const templates = ref<BoardTemplate[]>([]);
    const users = ref<BoardUser[]>([]);
    const shiftOpening = ref(false);
    const nextShiftStartsAt = ref<string | null>(null);
    const withBot = ref(0);
    const lastCall = ref<BoardCall | null>(null);
    const calledAt = ref(0);
    const moves = ref<BoardMove[]>([]);
    const freed = ref<string[]>([]);
    const now = ref(Date.now());
    const busy = ref<string | null>(null);
    const error = ref<string | null>(null);

    /** When each open entry's `silence_left_seconds` was true, on this machine's clock. */
    const stampedAt = new Map<number, number>();
    /** Server clock minus this machine's. */
    let skew = 0;
    let disposed = false;
    let listening = false;
    let loadSeq = 0;
    let moveSeq = 0;
    let refetchTimer: number | undefined;
    const timers = new Set<number>();

    const calling = computed(() => lastCall.value !== null && calledAt.value > 0 && now.value - calledAt.value < BOARD_CALL_MS);

    function later(fn: () => void, ms: number): void {
        const id = window.setTimeout(() => {
            timers.delete(id);
            if (!disposed) fn();
        }, ms);
        timers.add(id);
    }

    function userName(id: number | null | undefined): string | null {
        if (id === null || id === undefined) return null;

        return users.value.find((u) => u.id === id)?.name ?? members.value.find((m) => m.user?.id === id)?.user?.name ?? null;
    }

    /** The lounge and the windows change together, so what moved between them is seen here. */
    function setEntries(nextWaiting: QueueEntry[], nextOpen: QueueEntry[], animate: boolean): void {
        const change = diffEntries(waiting.value, open.value, nextOpen);
        const at = Date.now();

        nextOpen.forEach((e) => stampedAt.set(e.id, at));
        [...stampedAt.keys()].filter((id) => !nextOpen.some((e) => e.id === id)).forEach((id) => stampedAt.delete(id));
        waiting.value = nextWaiting;
        open.value = nextOpen;

        if (!animate || !loaded.value) return;

        const newest = change.called[change.called.length - 1];
        if (newest) {
            lastCall.value = {
                entry_id: newest.entry.id,
                ticket: newest.entry.ticket,
                window_no: newest.entry.window_no,
                user_id: newest.entry.assigned_user_id,
                name: userName(newest.entry.assigned_user_id),
                at: new Date(at + skew).toISOString(),
            };
            calledAt.value = now.value;
        }

        if (prefersReducedMotion()) return;

        for (const { entry, seat } of change.called) {
            if (entry.assigned_user_id === null) continue;
            const move: BoardMove = {
                id: ++moveSeq,
                entryId: entry.id,
                ticket: entry.ticket,
                seat,
                userId: entry.assigned_user_id,
                windowNo: entry.window_no,
            };
            moves.value = [...moves.value, move];
            later(() => (moves.value = moves.value.filter((m) => m.id !== move.id)), BOARD_MOVE_MS);
        }

        for (const key of change.freed) {
            freed.value = [...freed.value.filter((k) => k !== key), key];
            later(() => (freed.value = freed.value.filter((k) => k !== key)), BOARD_MOVE_MS);
        }
    }

    function apply(snapshot: BoardSnapshot, animate = true): void {
        const serverNow = Date.parse(snapshot.now);
        skew = Number.isNaN(serverNow) ? 0 : serverNow - Date.now();
        now.value = Date.now() + skew;
        enabled.value = snapshot.enabled;
        failed.value = false;

        if (!snapshot.enabled) {
            loaded.value = true;

            return;
        }

        businessDate.value = snapshot.business_date ?? null;
        users.value = snapshot.users ?? [];
        shift.value = snapshot.shift ?? null;
        shifts.value = snapshot.shifts ?? [];
        members.value = snapshot.members ?? [];
        decisions.value = snapshot.decisions ?? [];
        kpis.value = snapshot.kpis ?? null;
        settings.value = snapshot.settings ?? null;
        templates.value = snapshot.templates ?? [];
        shiftOpening.value = snapshot.shift_opening === true;
        nextShiftStartsAt.value = snapshot.next_shift_starts_at ?? null;
        withBot.value = snapshot.reception?.with_bot ?? 0;
        setEntries(snapshot.waiting ?? [], snapshot.open ?? [], animate);

        // The state's own last call wins, unless the one announced here is newer.
        const stated = snapshot.last_call ?? null;
        if (
            stated !== null &&
            (lastCall.value === null ||
                Date.parse(stated.at ?? '') >= Date.parse(lastCall.value.at ?? '') ||
                stated.entry_id === lastCall.value.entry_id)
        ) {
            lastCall.value = { ...stated, name: stated.name ?? userName(stated.user_id) };
        } else if (stated === null && !calling.value) {
            lastCall.value = null;
        }

        loaded.value = true;
    }

    async function refresh(): Promise<void> {
        const seq = ++loadSeq;
        try {
            const { data } = await api.get<{ data: BoardSnapshot }>('/board/state', { silent: true });
            if (disposed || seq !== loadSeq) return; // answered for a newer request, or after the page closed

            apply(data.data);
        } catch (e) {
            if (!loaded.value && !disposed) failed.value = true;
            throw e;
        }
    }

    /** Several events of one assignment arrive together: one request for all of them. */
    function refreshSoon(): void {
        window.clearTimeout(refetchTimer);
        refetchTimer = window.setTimeout(() => void refresh().catch(() => undefined), 600);
    }

    function onEntry(entry: QueueEntry): void {
        if (!loaded.value || !enabled.value) return;
        const next = applyEntry(waiting.value, open.value, entry);
        setEntries(next.waiting, next.open, true);
        refreshSoon();
    }

    function onMember(payload: BoardMember): void {
        if (!loaded.value || !enabled.value) return;
        const next = mergeMember(members.value, payload, shift.value?.id ?? null);
        if (next !== null) members.value = next;
        refreshSoon();
    }

    function onDecision(decision: BoardDecision): void {
        if (!loaded.value || !enabled.value) return;
        decisions.value = [decision, ...decisions.value.filter((d) => d.id !== decision.id)].slice(0, DECISIONS_KEPT);
    }

    // The channel is shared with the inbox's strip: our callbacks are detached, the channel is never left.
    function listen(on: boolean): void {
        if (!echo || on === listening) return;
        listening = on;
        try {
            const channel = echo.private('board');
            if (on)
                channel
                    .listen(EVENT_ENTRY, onEntry)
                    .listen(EVENT_MEMBER, onMember)
                    .listen(EVENT_SHIFT, refreshSoon)
                    .listen(EVENT_DECISION, onDecision);
            else
                channel
                    .stopListening(EVENT_ENTRY, onEntry)
                    .stopListening(EVENT_MEMBER, onMember)
                    .stopListening(EVENT_SHIFT, refreshSoon)
                    .stopListening(EVENT_DECISION, onDecision);
        } catch {
            // A socket already gone has nothing left to detach.
        }
    }

    function secondsSince(iso: string | null | undefined): number {
        const from = iso ? Date.parse(iso) : NaN;

        return Number.isNaN(from) ? 0 : Math.max(0, Math.floor((now.value - from) / 1000));
    }

    function silenceLeft(entry: QueueEntry): number | null {
        if (entry.silence_left_seconds === null) return null;
        const since = stampedAt.get(entry.id) ?? Date.now();

        return Math.max(0, Math.round(entry.silence_left_seconds - Math.max(0, now.value - skew - since) / 1000));
    }

    function silenceTone(entry: QueueEntry): SilenceTone {
        const left = silenceLeft(entry);
        if (left === null) return 'none';
        if (left <= 60) return 'last';
        const s = settings.value;

        return entry.silence_warned || (s !== null && left <= s.silence_close_seconds - s.silence_warn_seconds) ? 'warning' : 'calm';
    }

    function handoffLeft(entry: QueueEntry): number | null {
        if (entry.handoff_left_seconds === null || entry.handoff_left_seconds === undefined) return null;
        const since = stampedAt.get(entry.id) ?? Date.now();

        return Math.max(0, Math.round(entry.handoff_left_seconds - Math.max(0, now.value - skew - since) / 1000));
    }

    function breakSince(member: BoardMember): number | null {
        if (member.status !== 'break' || !member.break_started_at) return null;
        const from = Date.parse(member.break_started_at);

        return Number.isNaN(from) ? null : Math.max(0, Math.floor((now.value - from) / 1000));
    }

    function breakOver(member: BoardMember): boolean {
        if (member.status !== 'break' || !member.break_ends_at) return false;
        const until = Date.parse(member.break_ends_at);

        return !Number.isNaN(until) && now.value >= until;
    }

    function windowsOf(userId: number): QueueEntry[] {
        return open.value.filter((e) => e.assigned_user_id === userId);
    }

    async function act(key: string, request: () => Promise<{ data: { data: BoardSnapshot } }>): Promise<boolean> {
        if (busy.value !== null) return false;
        busy.value = key;
        error.value = null;
        try {
            const { data } = await request();
            ++loadSeq; // a state that was on its way is older than this answer
            if (!disposed) apply(data.data);

            return true;
        } catch (e) {
            const message = apiErrorMessage(e, t('common.error'));
            error.value = message;
            toast.push(message, 'error');
            // The room is not what we thought (she was called meanwhile, the queue was switched off…).
            void refresh().catch(() => undefined);

            return false;
        } finally {
            busy.value = null;
        }
    }

    const ticker = window.setInterval(() => (now.value = Date.now() + skew), 1000);
    const poller = window.setInterval(() => {
        if (enabled.value && !live.value && !document.hidden) void refresh().catch(() => undefined);
    }, BOARD_POLL_MS);
    const refresher = window.setInterval(() => {
        if (enabled.value && live.value && !document.hidden) void refresh().catch(() => undefined);
    }, BOARD_REFRESH_MS);

    watch(enabled, (on) => listen(on), { immediate: true });

    // Back on the websocket after a drop: whatever was missed meanwhile.
    watch(live, (is, was) => {
        if (is && was === false && enabled.value) refreshSoon();
    });

    onScopeDispose(() => {
        disposed = true;
        listen(false);
        window.clearInterval(ticker);
        window.clearInterval(poller);
        window.clearInterval(refresher);
        window.clearTimeout(refetchTimer);
        timers.forEach((id) => window.clearTimeout(id));
    });

    if (options.enabled) void refresh().catch(() => undefined);
    else loaded.value = true;

    return {
        enabled,
        loaded,
        failed,
        live,
        businessDate,
        shift,
        shifts,
        members,
        waiting,
        open,
        decisions,
        kpis,
        settings,
        templates,
        users,
        shiftOpening,
        nextShiftStartsAt,
        withBot,
        lastCall,
        calling,
        moves,
        freed,
        now,
        busy,
        error,
        secondsSince,
        silenceLeft,
        silenceTone,
        handoffLeft,
        breakSince,
        breakOver,
        userName,
        windowsOf,
        refresh,
        clearError: () => (error.value = null),
        setMemberCap: (memberId, cap) => act(`cap-${memberId}`, () => api.post(`/board/members/${memberId}/cap`, { windows_cap: cap })),
        checkOut: (memberId) => act(`checkout-${memberId}`, () => api.post(`/board/members/${memberId}/check-out`)),
        handBack: (memberId) => act(`handback-${memberId}`, () => api.post(`/board/members/${memberId}/hand-back`)),
        setMemberStatus: (memberId, status) => act(`status-${memberId}`, () => api.post(`/board/members/${memberId}/status`, { status })),
        assign: (entryId, userId) => act(`assign-${entryId}`, () => api.post(`/board/entries/${entryId}/assign`, { user_id: userId })),
        cancel: (entryId, reason) => act(`cancel-${entryId}`, () => api.post(`/board/entries/${entryId}/cancel`, { reason })),
    };
}
