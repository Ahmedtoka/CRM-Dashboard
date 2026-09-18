import { useApi } from '@/composables/useApi';
import { useEcho } from '@/composables/useEcho';
import { useI18n } from '@/composables/useI18n';
import { compareConversations, matchesInboxFilters } from '@/lib/inboxListOrder';
import type { User } from '@/types';
import type { Conversation, ConversationPatch, CursorPage, InboxFilters, Message, Order } from '@/types/crm';
import { onScopeDispose, ref, type Ref } from 'vue';

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

const FILTER_KEYS = ['platform', 'status', 'filter', 'q', 'tag'] as const;

/**
 * Conversation list state: filters, cursor paging, and realtime upserts from `private-inbox`
 * (ConversationUpdated / MessageCreated), with a 5 s poll of the first page when the socket is down.
 */
export function useConversationList(initial: CursorPage<Conversation>, initialFilters: InboxFilters, options: Options) {
    const api = useApi();
    const { echo, live, poll } = useEcho();
    const { t } = useI18n();

    const conversations = ref<Conversation[]>([...(initial.data ?? [])]);
    const nextCursor = ref<string | null>(initial.meta?.next_cursor ?? null);
    const filters = ref<InboxFilters>({
        platform: initialFilters.platform ?? null,
        status: initialFilters.status ?? null,
        filter: initialFilters.filter ?? null,
        q: initialFilters.q ?? null,
        tag: initialFilters.tag ?? null,
    });
    const loading = ref(false);
    const loadingMore = ref(false);

    let requestSeq = 0;
    let refreshTimer: number | undefined;

    const params = () => Object.fromEntries(Object.entries(filters.value).filter(([, v]) => v !== null && v !== ''));

    // Same ordering as the server for the active filter (queues: priority, then oldest customer message).
    function sortList(): void {
        conversations.value.sort(compareConversations(filters.value.filter));
    }

    function syncUrl(): void {
        const url = new URL(window.location.href);
        for (const key of FILTER_KEYS) {
            const value = filters.value[key];
            if (value) url.searchParams.set(key, String(value));
            else url.searchParams.delete(key);
        }
        window.history.replaceState(window.history.state, '', url);
    }

    function upsertFull(conversation: Conversation): void {
        const index = conversations.value.findIndex((c) => c.id === conversation.id);
        if (index === -1) conversations.value.push(conversation);
        else conversations.value[index] = conversation;
    }

    async function reload(): Promise<void> {
        const seq = ++requestSeq;
        loading.value = true;
        try {
            const { data } = await api.get<CursorPage<Conversation>>('/inbox/conversations', { params: params() });
            if (seq !== requestSeq) return;
            conversations.value = data.data;
            nextCursor.value = data.meta?.next_cursor ?? null;
            sortList();
        } finally {
            if (seq === requestSeq) loading.value = false;
        }
    }

    function setFilters(next: InboxFilters): void {
        filters.value = { ...next };
        syncUrl();
        void reload().catch(() => undefined);
    }

    async function loadMore(): Promise<void> {
        if (!nextCursor.value || loadingMore.value) return;
        const seq = requestSeq;
        loadingMore.value = true;
        try {
            const { data } = await api.get<CursorPage<Conversation>>('/inbox/conversations', { params: { ...params(), cursor: nextCursor.value } });
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
        sortList();
    }

    function scheduleRefresh(): void {
        window.clearTimeout(refreshTimer);
        refreshTimer = window.setTimeout(() => void refreshFirstPage().catch(() => undefined), 400);
    }

    function canSee(platform: string | null | undefined): boolean {
        const me = options.me;

        return me.role !== 'moderator' || !platform || (me.platforms ?? []).some((p) => p === platform);
    }

    function matchesFilters(c: Conversation): boolean {
        return matchesInboxFilters(c, filters.value);
    }

    function applyConversation(patch: ConversationPatch): void {
        if (!canSee(patch.platform)) return;

        const index = conversations.value.findIndex((c) => c.id === patch.id);
        if (index === -1) {
            // Payload lacks tags/source/etc.; fetch the real row (also enforces server-side scoping).
            scheduleRefresh();
            return;
        }

        const merged = { ...conversations.value[index], ...patch };
        if (!matchesFilters(merged) && merged.id !== options.selectedId.value) {
            conversations.value.splice(index, 1);
        } else {
            conversations.value[index] = merged;
        }
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
        if (message.sender_type !== 'system') {
            if (message.direction === 'in') {
                c.last_customer_message_at = message.created_at;
                c.waiting_since = c.waiting_since ?? message.created_at;
            } else {
                c.waiting_since = null;
            }
        }
        conversations.value[index] = c;
        sortList();
    }

    // Named handlers so dispose can detach exactly these. The `inbox` channel is
    // shared with useNotifications (sound, desktop alerts, badge refresh), so this
    // composable must never `leave('inbox')` — that would unbind every listener on it.
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

    const inboxChannel = echo
        ?.private('inbox')
        .listen('ConversationUpdated', onConversationUpdated)
        .listen('MessageCreated', onMessageCreated)
        .listen('MessageUpdated', onMessageUpdated)
        .listen('OrderUpdated', onOrderUpdated);

    poll(refreshFirstPage);

    onScopeDispose(() => {
        inboxChannel
            ?.stopListening('ConversationUpdated', onConversationUpdated)
            .stopListening('MessageCreated', onMessageCreated)
            .stopListening('MessageUpdated', onMessageUpdated)
            .stopListening('OrderUpdated', onOrderUpdated);
        window.clearTimeout(refreshTimer);
    });

    return { conversations, nextCursor, filters, loading, loadingMore, live, setFilters, loadMore, reload, applyConversation };
}
