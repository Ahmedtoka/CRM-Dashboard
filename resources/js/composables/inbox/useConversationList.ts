import { useApi } from '@/composables/useApi';
import { useEcho } from '@/composables/useEcho';
import { useI18n } from '@/composables/useI18n';
import { syncInertiaUrl, useUrlFilters } from '@/composables/useUrlFilters';
import { listenInbox } from '@/lib/inboxChannels';
import { compareConversations, matchesInboxFilters } from '@/lib/inboxListOrder';
import type { User } from '@/types';
import type { Conversation, ConversationPatch, CursorPage, InboxCounts, InboxFilters, InboxQuickFilter, Message, Order, PlatformValue } from '@/types/crm';
import { computed, onScopeDispose, ref, watch, type Ref } from 'vue';

export interface InboxRealtimeHandlers {
    onMessage?: (message: Message) => void;
    onConversation?: (patch: ConversationPatch) => void;
    onOrder?: (order: Order) => void;
}

interface Options {
    me: User;
    selectedId: Ref<number | null>;
    handlers: InboxRealtimeHandlers;
}

const COUNTS_DEBOUNCE_MS = 300;
const COUNTS_INTERVAL_MS = 30_000;

/** The URL shape of the filters (useUrlFilters keeps strings and string lists). */
const URL_DEFAULTS = {
    status: null as string | null,
    queue: null as string | null,
    assignee: null as string | null,
    flags: [] as string[],
    platform: null as string | null,
    tag: null as string | null,
    q: null as string | null,
};
type UrlFilters = typeof URL_DEFAULTS;

const toUrl = (f: InboxFilters): UrlFilters => ({
    status: f.status ?? null,
    queue: f.queue ?? null,
    assignee: f.assignee !== null && f.assignee !== undefined ? String(f.assignee) : null,
    flags: [...(f.flags ?? [])],
    platform: f.platform ?? null,
    tag: f.tag ? String(f.tag) : null,
    q: f.q ?? null,
});

const fromUrl = (u: UrlFilters): InboxFilters => ({
    status: (u.status || null) as InboxFilters['status'],
    queue: (u.queue || null) as InboxFilters['queue'],
    assignee: u.assignee || null,
    flags: (u.flags ?? []) as InboxQuickFilter[],
    platform: (u.platform || null) as PlatformValue | null,
    tag: u.tag ? Number(u.tag) || null : null,
    q: u.q || null,
});

const sameFilters = (a: UrlFilters, b: UrlFilters) => JSON.stringify(a) === JSON.stringify(b);

/**
 * Conversation list state: filters in the URL (useUrlFilters, history entries so back/forward restore
 * them; `c` is the page's own), cursor paging, the per-state counts, and realtime upserts from the inbox
 * channels (`private-inbox`, or `private-inbox.platform.<p>` for a moderator) with a 5 s poll of the
 * first page when the socket is down.
 */
export function useConversationList(initial: CursorPage<Conversation>, initialFilters: InboxFilters, options: Options) {
    const api = useApi();
    const { echo, live, poll } = useEcho();
    const { t } = useI18n();

    const url = useUrlFilters(URL_DEFAULTS, { history: 'push', keep: ['c'] });

    // A fresh server render parsed the URL (and mapped the legacy single `filter=` into `flags`): its
    // `filters` prop is the truth. After a back/forward Inertia remounts the page with the props of the
    // entry it first rendered, so the URL is the truth and the first page may be stale (reloaded below).
    const legacyUrl = new URLSearchParams(window.location.search).has('filter');
    const fromProps = toUrl(initialFilters);
    if (legacyUrl) {
        url.filters.value = fromProps;
        const u = new URL(window.location.href);
        u.searchParams.delete('filter');
        for (const [key, value] of Object.entries(url.query.value)) u.searchParams.set(key, String(value));
        // Once Inertia has finished putting this page in (a visit during its first swap races its own
        // history write): on its next tick after load.
        const run = () => syncInertiaUrl(u);
        if (document.readyState === 'complete') window.setTimeout(run, 50);
        else window.addEventListener('load', () => window.setTimeout(run, 50), { once: true });
    }
    const stale = !sameFilters(url.filters.value, fromProps);

    const filters = computed<InboxFilters>(() => fromUrl(url.filters.value));
    const conversations = ref<Conversation[]>(stale ? [] : [...(initial.data ?? [])]);
    const nextCursor = ref<string | null>(stale ? null : (initial.meta?.next_cursor ?? null));
    const loading = ref(stale);
    const loadingMore = ref(false);
    const counts = ref<InboxCounts | null>(null);
    /** The 5 s poll itself failed (the list header icon turns amber). */
    const pollFailed = ref(false);

    let requestSeq = 0;
    // Set when the first page of a search used the server's substring fallback: later pages must too.
    let searchMode: 'like' | null = stale ? null : (initial.search_mode ?? null);
    let refreshTimer: number | undefined;
    // Rows a realtime change touched under a filter only the server can decide: the next first-page
    // refresh drops the ones that no longer belong.
    const unverified = new Set<number>();

    const params = (): Record<string, string | string[]> => url.query.value;

    // Same ordering as the server for the active filters.
    function sortList(): void {
        conversations.value.sort(compareConversations(filters.value));
    }

    function upsertFull(conversation: Conversation): void {
        const index = conversations.value.findIndex((c) => c.id === conversation.id);
        if (index === -1) conversations.value.push(conversation);
        else conversations.value[index] = conversation;
    }

    async function reload(): Promise<void> {
        const seq = ++requestSeq;
        loading.value = true;
        unverified.clear();
        scheduleCounts();
        try {
            const { data } = await api.get<CursorPage<Conversation>>('/inbox/conversations', { params: params() });
            if (seq !== requestSeq) return;
            conversations.value = data.data;
            searchMode = data.search_mode ?? null;
            nextCursor.value = data.meta?.next_cursor ?? null;
            sortList();
        } finally {
            if (seq === requestSeq) loading.value = false;
        }
    }

    function setFilters(patch: Partial<InboxFilters>): void {
        url.set(toUrl({ ...filters.value, ...patch }));
    }

    function clearFilters(): void {
        url.clear();
    }

    // Every filter change (ours, or back/forward through useUrlFilters' popstate) reloads the list.
    watch(
        () => JSON.stringify(url.filters.value),
        () => void reload().catch(() => undefined),
    );

    async function loadMore(): Promise<void> {
        if (!nextCursor.value || loadingMore.value || loading.value) return;
        const seq = requestSeq;
        loadingMore.value = true;
        try {
            const { data } = await api.get<CursorPage<Conversation>>('/inbox/conversations', {
                params: { ...params(), ...(searchMode ? { qmode: searchMode } : {}), cursor: nextCursor.value },
            });
            if (seq !== requestSeq) return;
            data.data.forEach(upsertFull);
            nextCursor.value = data.meta?.next_cursor ?? null;
            sortList();
        } finally {
            loadingMore.value = false;
        }
    }

    // Merges the first page without dropping rows already paged in below it.
    async function refreshFirstPage(): Promise<void> {
        const seq = requestSeq;
        // Background refresh (poll / debounced broadcast): never shows the loading bar.
        const { data } = await api.get<CursorPage<Conversation>>('/inbox/conversations', { params: params(), silent: true });
        if (seq !== requestSeq) return;
        data.data.forEach(upsertFull);

        // A row the server left out although it sorts inside the page it returned no longer matches.
        if (unverified.size) {
            const fresh = new Set(data.data.map((c) => c.id));
            const compare = compareConversations(filters.value);
            const last = data.data[data.data.length - 1];
            const complete = !data.meta?.next_cursor;
            conversations.value = conversations.value.filter((c) => {
                if (!unverified.has(c.id) || fresh.has(c.id) || c.id === options.selectedId.value) return true;
                return !(complete || (last && compare(c, last) <= 0));
            });
            unverified.clear();
        }
        sortList();
    }

    function scheduleRefresh(): void {
        window.clearTimeout(refreshTimer);
        refreshTimer = window.setTimeout(() => void refreshFirstPage().catch(() => undefined), 400);
    }

    // Counts: after every reload (debounced), then every 30 s while the tab is visible; never two at once.
    let countsTimer: number | undefined;
    let countsInFlight = false;

    async function fetchCounts(): Promise<void> {
        if (countsInFlight) return;
        countsInFlight = true;
        try {
            const { status: _s, queue: _q, ...rest } = params();
            void _s;
            void _q;
            const { data } = await api.get<InboxCounts>('/inbox/conversations/counts', { params: rest, silent: true });
            counts.value = data;
        } catch {
            // Counts are decoration on the tabs: a failure keeps the last numbers.
        } finally {
            countsInFlight = false;
        }
    }

    function scheduleCounts(): void {
        window.clearTimeout(countsTimer);
        countsTimer = window.setTimeout(() => void fetchCounts(), COUNTS_DEBOUNCE_MS);
    }

    const countsInterval = window.setInterval(() => {
        if (document.visibilityState === 'visible') void fetchCounts();
    }, COUNTS_INTERVAL_MS);

    function canSee(platform: string | null | undefined): boolean {
        const me = options.me;

        return me.role !== 'moderator' || !platform || (me.platforms ?? []).some((p) => p === platform);
    }

    /** Keeps or drops a changed row; a row only the server can judge stays and is re-checked by a refresh. */
    function place(index: number, row: Conversation): void {
        const { keep, undecided } = matchesInboxFilters(row, filters.value);
        if (!keep && row.id !== options.selectedId.value) {
            conversations.value.splice(index, 1);
            return;
        }
        conversations.value[index] = row;
        if (undecided) {
            unverified.add(row.id);
            scheduleRefresh();
        }
    }

    function applyConversation(patch: ConversationPatch): void {
        if (!canSee(patch.platform)) return;

        const index = conversations.value.findIndex((c) => c.id === patch.id);
        if (index === -1) {
            // Payload lacks tags/source/etc.; fetch the real row (also enforces server-side scoping).
            scheduleRefresh();
            return;
        }

        place(index, { ...conversations.value[index], ...patch });
        sortList();
    }

    function applyMessage(message: Message): void {
        const index = conversations.value.findIndex((c) => c.id === message.conversation_id);
        if (index === -1) {
            if (message.direction === 'in') scheduleRefresh();
            return;
        }

        const c = { ...conversations.value[index] };
        if (message.body) c.last_message_preview = message.body.length > 80 ? `${message.body.slice(0, 77)}...` : message.body;
        else if (message.attachments?.length) c.last_message_preview = t(`media.preview_${message.attachments[0].type}`);
        if (message.created_at) c.last_message_at = message.created_at;
        c.last_message_sender = message.sender_type;
        if (message.sender_type !== 'system') {
            if (message.direction === 'in') {
                c.last_customer_message_at = message.created_at;
                c.waiting_since = c.waiting_since ?? message.created_at;
            } else {
                c.waiting_since = null;
            }
        }
        place(index, c);
        sortList();
    }

    // Named handlers so dispose can detach exactly these. The inbox channels (`inbox` for supervisors,
    // `inbox.platform.<p>` for moderators) are shared with useNotifications (sound, desktop alerts,
    // badge refresh), so this composable must never `leave()` one — that would unbind every listener on it.
    const onConversationUpdated = (patch: ConversationPatch) => {
        applyConversation(patch);
        options.handlers.onConversation?.(patch);
    };
    const onMessageCreated = (message: Message) => {
        applyMessage(message);
        options.handlers.onMessage?.(message);
    };
    const onMessageUpdated = (message: Message) => options.handlers.onMessage?.(message);
    const onOrderUpdated = (order: Order) => options.handlers.onOrder?.(order);

    const detachInbox = listenInbox(echo, options.me, {
        ConversationUpdated: onConversationUpdated,
        MessageCreated: onMessageCreated,
        MessageUpdated: onMessageUpdated,
        OrderUpdated: onOrderUpdated,
    });

    poll(async () => {
        try {
            await refreshFirstPage();
            pollFailed.value = false;
        } catch {
            pollFailed.value = true;
        }
    });

    if (stale) void reload().catch(() => undefined);
    else scheduleCounts();

    onScopeDispose(() => {
        detachInbox();
        window.clearTimeout(refreshTimer);
        window.clearTimeout(countsTimer);
        window.clearInterval(countsInterval);
    });

    return {
        conversations,
        nextCursor,
        filters,
        activeKeys: url.activeKeys,
        counts,
        loading,
        loadingMore,
        live,
        pollFailed,
        setFilters,
        clearFilters,
        loadMore,
        reload,
        applyConversation,
    };
}
