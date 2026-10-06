import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useEcho } from '@/composables/useEcho';
import { useI18n } from '@/composables/useI18n';
import type { User } from '@/types';
import type {
    Attachment,
    Conversation,
    ConversationAction,
    ConversationDetail,
    ConversationPatch,
    ConversationPriority,
    Customer,
    Message,
    Note,
    Order,
    QuickReply,
    RenderedQuickReply,
    TemplatePayload,
    UserRef,
} from '@/types/crm';
import { ThreadCache, type ThreadView } from '@/lib/threadCache';
import axios from 'axios';
import { computed, onScopeDispose, ref, toRaw } from 'vue';

type SendPayload = { body: string; attachment_ids?: number[]; quick_reply_id?: number } | { template: TemplatePayload };

interface Options {
    me: User;
    onRead: (conversationId: number) => void;
    /** Where the open thread is scrolled, saved with it when she leaves (ChatThread.viewState). */
    viewState?: () => ThreadView | null;
}

const TYPING_WHISPER_MS = 2500;
const TYPING_POST_MS = 10000;
const TYPING_VISIBLE_MS = 5000;
/** A prefetch skips a chat confirmed by the server this recently. */
const PREFETCH_FRESH_MS = 60_000;
/** Read receipts wait this long, so j/k through rows does not mark every one read. */
const READ_DEBOUNCE_MS = 400;
/** A chat left at the bottom keeps only its newest messages in the cache (older ones reload on scroll). */
const CACHE_TAIL = 300;
/** A chat left scrolled up keeps every row (her position needs them), unless it is this big: then it reloads. */
const CACHE_MAX = 1500;

/** Same message, nothing new to render (a broadcast or a reload repeating what is shown). */
function sameMessage(a: Message, b: Message): boolean {
    return JSON.stringify(a) === JSON.stringify(b);
}

/** Merge into a plain list: update in place (only when something changed) or append. Returns true when appended. */
function mergeInto(list: Message[], message: Message): boolean {
    const index = list.findIndex((m) => m.id === message.id);
    if (index === -1) {
        list.push(message);
        return true;
    }
    const merged = { ...list[index], ...message, client_key: list[index].client_key };
    if (!sameMessage(merged, list[index])) list[index] = merged;
    return false;
}

/**
 * The open conversation: detail load, presence (`presence-conversation.{id}`), optimistic send / retry,
 * typing whisper + soft lock, notes, actions. Polls the detail every 5 s when the socket is down.
 *
 * Task 6c (spec §1.3): the last 20 chats she left stay in an LRU (`ThreadCache`) with their scroll
 * position. Opening a cached chat paints it at once (no skeleton), then revalidates silently: the
 * messages newer than the cached copy (`after_id`) and the detail (header, notes, window). Realtime
 * events for a cached chat patch its entry; a hover / j-k prefetch fills the cache ahead of time;
 * switching aborts the detail request of the chat she left; @mentions are fetched once per
 * platform; the read receipt waits 400 ms.
 */
export function useConversationThread(options: Options) {
    const api = useApi();
    const { echo, poll } = useEcho();
    const { t } = useI18n();
    const me: UserRef = { id: options.me.id, name: options.me.name, color: options.me.color ?? null };

    const current = ref<number | null>(null);
    const detail = ref<ConversationDetail | null>(null);
    const messages = ref<Message[]>([]);
    const hasMore = ref(false);
    const loading = ref(false);
    const loadingOlder = ref(false);
    const viewers = ref<UserRef[]>([]);
    const typingUsers = ref<Record<number, string>>({});
    const lockHolder = ref<UserRef | null>(null);
    /** @mentions autocomplete data source (Task 15): fetched once per platform per page load. */
    const mentionable = ref<UserRef[]>([]);
    /** The scroll position to show for the chat just opened from the cache (null: the bottom). */
    const restoredView = ref<ThreadView | null>(null);
    const busyAction = ref<string | null>(null);
    const retrying = ref<Array<number | string>>([]);
    const retryingAttachments = ref<number[]>([]);
    const error = ref<string | null>(null);

    const typingNames = computed(() => Object.values(typingUsers.value));
    const pendingPayloads = new Map<string, SendPayload>();
    const typingTimers = new Map<number, number>();
    let presenceName: string | null = null;
    let presence: ReturnType<NonNullable<typeof echo>['join']> | null = null;
    let loadSeq = 0;
    let lastWhisper = 0;
    let lastTypingPost = 0;
    let reloadTimer: number | undefined;
    let readTimer: number | undefined;
    const cache = new ThreadCache(20);
    /** Detail requests in flight (a prefetch, or the open), so the open can reuse a prefetch. */
    const inflight = new Map<number, { promise: Promise<ConversationDetail>; controller: AbortController }>();
    /** The open chat's own requests: aborted when she switches away. */
    let openController: AbortController | null = null;
    /** When the server last confirmed the open chat (saved into the cache with it). */
    let validatedAt = 0;
    const mentionableByPlatform = new Map<string, UserRef[]>();
    const mentionableLoading = new Set<string>();

    function normalizeCustomer(customer: Customer | null): Customer | null {
        // Tolerate both `orders: [...]` and the older `orders: {data: [...]}` shape.
        if (customer?.orders && !Array.isArray(customer.orders)) {
            customer.orders = (customer.orders as unknown as { data?: Order[] }).data ?? [];
        }
        return customer;
    }

    function applyDetail(data: ConversationDetail, keepMessages = false): void {
        data.customer = normalizeCustomer(data.customer);
        detail.value = data;
        lockHolder.value = data.lock.holder;
        if (keepMessages) {
            data.messages.forEach(mergeMessage);
        } else {
            messages.value = data.messages;
            hasMore.value = data.messages.length >= 50;
        }
    }

    function mergeMessage(message: Message): void {
        if (message.conversation_id !== current.value) {
            // A chat she left: keep its cached copy current (spec §1.3). A customer message that may
            // reopen its window makes the copy stale, so the next prefetch / open revalidates it.
            cache.patch(message.conversation_id, (entry) => {
                mergeInto(entry.messages, message);
                if (message.direction === 'in' && entry.detail.window.mode !== 'open') entry.at = 0;
            });
            return;
        }

        const appended = mergeInto(messages.value, message);
        resolveRetriedAttachments(message);

        // A customer message can reopen the reply window.
        if (appended && message.direction === 'in' && detail.value && detail.value.window.mode !== 'open') {
            scheduleReload();
        }
    }

    /** The message list of a chat, open or cached (a send that resolves after she switched away). */
    function listFor(conversationId: number): Message[] | null {
        if (conversationId === current.value) return messages.value;
        return cache.peek(conversationId)?.messages ?? null;
    }

    // A retried attachment stays in `retryingAttachments` (spinner, not the Retry
    // button) until a broadcast reports it left `pending` — stored or failed again.
    function resolveRetriedAttachments(message: Message): void {
        if (!retryingAttachments.value.length) return;
        const resolvedIds = new Set(message.attachments.filter((a) => a.status !== 'pending').map((a) => a.id));
        if (resolvedIds.size) retryingAttachments.value = retryingAttachments.value.filter((id) => !resolvedIds.has(id));
    }

    function clearTyping(userId: number): void {
        window.clearTimeout(typingTimers.get(userId));
        typingTimers.delete(userId);
        const next = { ...typingUsers.value };
        delete next[userId];
        typingUsers.value = next;
    }

    function joinPresence(id: number): void {
        if (!echo) return;
        presenceName = `conversation.${id}`;
        presence = echo
            .join(presenceName)
            .here((users: UserRef[]) => (viewers.value = users))
            .joining((user: UserRef) => {
                if (!viewers.value.some((v) => v.id === user.id)) viewers.value = [...viewers.value, user];
            })
            .leaving((user: UserRef) => {
                viewers.value = viewers.value.filter((v) => v.id !== user.id);
                clearTyping(user.id);
            })
            .listen('MessageCreated', mergeMessage)
            .listen('MessageUpdated', mergeMessage)
            .listenForWhisper('typing', (payload: { id: number; name: string }) => {
                if (payload.id === me.id) return;
                typingUsers.value = { ...typingUsers.value, [payload.id]: payload.name };
                window.clearTimeout(typingTimers.get(payload.id));
                typingTimers.set(payload.id, window.setTimeout(() => clearTyping(payload.id), TYPING_VISIBLE_MS));
            });
    }

    function leavePresence(): void {
        if (echo && presenceName) echo.leave(presenceName);
        presence = null;
        presenceName = null;
        typingTimers.forEach((timer) => window.clearTimeout(timer));
        typingTimers.clear();
    }

    function markRead(id: number): void {
        options.onRead(id);
        api.post(`/inbox/conversations/${id}/read`, {}, { silent: true }).catch(() => undefined);
    }

    /** Read only if she is still on the chat 400 ms later (j/k passing through does not count). */
    function scheduleRead(id: number): void {
        window.clearTimeout(readTimer);
        readTimer = window.setTimeout(() => {
            if (current.value === id) markRead(id);
        }, READ_DEBOUNCE_MS);
    }

    /**
     * The @mentions list is the same for every chat of a platform (the endpoint takes a
     * conversation only to scope it): fetched once per platform, reused on every switch.
     */
    function ensureMentionable(id: number, platform: string): void {
        const known = mentionableByPlatform.get(platform);
        if (known) {
            mentionable.value = known;
            return;
        }
        if (mentionableLoading.has(platform)) return;
        mentionableLoading.add(platform);
        api.get<{ data: UserRef[] }>(`/inbox/conversations/${id}/mentionable`, { silent: true })
            .then(({ data: res }) => {
                mentionableByPlatform.set(platform, res.data);
                if (detail.value?.conversation.platform === platform) mentionable.value = res.data;
            })
            .catch(() => undefined)
            .finally(() => mentionableLoading.delete(platform));
    }

    /** One detail request per chat at a time: the open reuses a prefetch already on its way. */
    function fetchDetail(id: number, silent: boolean): Promise<ConversationDetail> {
        const running = inflight.get(id);
        if (running) {
            if (!silent) openController = running.controller;
            return running.promise;
        }
        const controller = new AbortController();
        const promise = api
            .get<ConversationDetail>(`/inbox/conversations/${id}`, { silent, signal: controller.signal })
            .then(({ data }) => {
                data.customer = normalizeCustomer(data.customer);
                return data;
            })
            .finally(() => {
                if (inflight.get(id)?.controller === controller) inflight.delete(id);
            });
        inflight.set(id, { promise, controller });
        if (!silent) openController = controller;
        return promise;
    }

    /** The chat she is leaving goes into the cache with its scroll position. */
    function saveCurrent(): void {
        const id = current.value;
        if (id === null || !detail.value) return;
        const view = options.viewState?.() ?? null;
        const pinned = view?.pinned ?? true;
        let list = toRaw(messages.value);
        let more = hasMore.value;
        if (pinned && list.length > CACHE_TAIL) {
            // At the bottom: the newest 300 are all she will see on return; older pages reload on scroll.
            list = list.slice(-CACHE_TAIL);
            more = true;
        } else if (!pinned && list.length > CACHE_MAX) {
            // Scrolled deep into a huge thread: not worth holding in memory, it reopens fresh.
            cache.delete(id);
            return;
        }
        cache.set(id, {
            detail: toRaw(detail.value),
            messages: list,
            hasMore: more,
            scrollTop: view?.scrollTop ?? null,
            pinned,
            anchor: pinned ? null : (view?.anchor ?? null),
            at: validatedAt,
        });
    }

    /** Sends not confirmed yet (optimistic, failed): never dropped when a list is replaced. */
    const localOnly = (list: Message[]) => list.filter((m) => m.id < 0);

    async function open(id: number | null): Promise<void> {
        if (current.value === id) return;
        saveCurrent();
        leavePresence();
        window.clearTimeout(reloadTimer);
        window.clearTimeout(readTimer);
        // The chat she left no longer needs its detail / revalidation answers.
        openController?.abort();
        openController = null;
        const seq = ++loadSeq;
        current.value = id;
        viewers.value = [];
        typingUsers.value = {};
        error.value = null;
        lastTypingPost = 0;
        // An older page still on its way belongs to the chat she left (loadOlder writes it into its cache entry).
        loadingOlder.value = false;
        if (id === null) {
            detail.value = null;
            messages.value = [];
            lockHolder.value = null;
            restoredView.value = null;
            loading.value = false;
            return;
        }

        const cached = cache.get(id);
        if (cached) {
            // Paint at once from the cache, then catch up silently.
            cache.delete(id);
            restoredView.value = { scrollTop: cached.scrollTop, pinned: cached.pinned, anchor: cached.anchor ?? null };
            detail.value = cached.detail;
            messages.value = cached.messages;
            hasMore.value = cached.hasMore;
            lockHolder.value = cached.detail.lock.holder;
            validatedAt = cached.at;
            loading.value = false;
            joinPresence(id);
            scheduleRead(id);
            ensureMentionable(id, cached.detail.conversation.platform);
            void revalidate(id, seq).catch(() => undefined);
            return;
        }

        restoredView.value = null;
        detail.value = null;
        messages.value = [];
        lockHolder.value = null;
        loading.value = true;
        try {
            const data = await fetchDetail(id, false);
            if (seq !== loadSeq) return;
            applyDetail(data);
            validatedAt = Date.now();
            joinPresence(id);
            scheduleRead(id);
            ensureMentionable(id, data.conversation.platform);
        } catch (e) {
            if (seq === loadSeq && !axios.isCancel(e)) error.value = apiErrorMessage(e, t('common.error'));
        } finally {
            if (seq === loadSeq) loading.value = false;
        }
    }

    /**
     * Catch a cached copy up with the server: the messages after the newest one it has
     * (`after_id`, oldest first, max 200) and the detail (header, notes, window, the last 50
     * messages' statuses). `seq` is the open's sequence for the open chat, null for a cached one.
     * More than 200 new messages: the copy is too old to patch, the fresh last page replaces it.
     */
    async function revalidate(id: number, seq: number | null): Promise<void> {
        const list = seq !== null ? messages.value : cache.peek(id)?.messages;
        if (!list) return;
        // The newest server id in array order: realtime / sends only ever append, and ids grow with time, so
        // the last positive id is the newest one the copy has (optimistic rows have negative ids and are skipped).
        let lastId: number | null = null;
        for (let i = list.length - 1; i >= 0 && lastId === null; i--) if (list[i].id > 0) lastId = list[i].id;
        const controller = new AbortController();
        if (seq !== null) openController = controller;
        const [after, fresh] = await Promise.allSettled([
            lastId !== null
                ? api.get<{ data: Message[]; has_more_after?: boolean }>(`/inbox/conversations/${id}/messages`, {
                      params: { after_id: lastId },
                      silent: true,
                      signal: controller.signal,
                  })
                : Promise.resolve(null),
            api.get<ConversationDetail>(`/inbox/conversations/${id}`, { silent: true, signal: controller.signal }),
        ]);
        if (seq !== null ? seq !== loadSeq : id === current.value) return;
        const data = fresh.status === 'fulfilled' ? fresh.value.data : null;
        if (data) data.customer = normalizeCustomer(data.customer);
        const newer = after.status === 'fulfilled' ? (after.value?.data ?? null) : null;

        if (seq !== null) {
            if (newer?.has_more_after && data) {
                // Too far behind to patch: the fresh last page replaces the list, her unconfirmed sends
                // stay at its end, and the view goes to the bottom (the old anchor rows are gone).
                const local = localOnly(messages.value);
                restoredView.value = { scrollTop: null, pinned: true, anchor: null };
                applyDetail(data);
                if (local.length) messages.value = [...messages.value, ...local];
            } else {
                newer?.data.forEach(mergeMessage);
                if (data) applyDetail(data, true);
            }
            if (data) validatedAt = Date.now();
            return;
        }
        cache.patch(id, (entry) => {
            if (newer?.has_more_after && data) {
                entry.messages = [...data.messages, ...localOnly(entry.messages)];
                entry.hasMore = data.messages.length >= 50;
                entry.anchor = null;
                entry.pinned = true;
            } else {
                newer?.data.forEach((m) => mergeInto(entry.messages, m));
                data?.messages.forEach((m) => mergeInto(entry.messages, m));
            }
            if (data) {
                entry.detail = { ...data, messages: [] };
                entry.at = Date.now();
            }
        });
    }

    /**
     * Fill the cache ahead of a click (hover intent, focus, j/k). Silent; a no-op for the open
     * chat, a request already on its way, or a copy confirmed in the last 60 s. An older cached
     * copy is revalidated instead of refetched, so its scroll position and older pages stay.
     */
    function prefetch(id: number): void {
        if (id === current.value || inflight.has(id)) return;
        const cached = cache.peek(id);
        if (cached) {
            if (Date.now() - cached.at >= PREFETCH_FRESH_MS) {
                cached.at = Date.now(); // one revalidation at a time
                void revalidate(id, null).catch(() => undefined);
            }
            return;
        }
        fetchDetail(id, true)
            .then((data) => {
                if (id === current.value || cache.peek(id)) return; // opened meanwhile: open() used this answer
                cache.set(id, { detail: data, messages: data.messages, hasMore: data.messages.length >= 50, scrollTop: null, pinned: true, anchor: null, at: Date.now() });
            })
            .catch(() => undefined);
    }

    async function silentReload(): Promise<void> {
        const id = current.value;
        if (id === null || !detail.value) return;
        const seq = loadSeq;
        const { data } = await api.get<ConversationDetail>(`/inbox/conversations/${id}`, { silent: true });
        if (seq === loadSeq && id === current.value) {
            applyDetail(data, true);
            validatedAt = Date.now();
        }
    }

    function scheduleReload(): void {
        window.clearTimeout(reloadTimer);
        reloadTimer = window.setTimeout(() => void silentReload().catch(() => undefined), 500);
    }

    /** Resolves `true` only once the POST actually succeeded — a caller that needs to know
     *  whether the send really went through (e.g. send-and-resolve) checks this rather than
     *  just awaiting the promise, since a failed request is caught here, not rethrown. */
    async function deliver(local: Message, payload: SendPayload): Promise<boolean> {
        const key = local.client_key as string;
        pendingPayloads.set(key, payload);
        try {
            const { data } = await api.post<{ data: Message; messages?: Message[] }>(`/inbox/conversations/${local.conversation_id}/messages`, payload);
            const server = data.data;
            // She may have switched away meanwhile: the chat's list is then in the cache.
            const list = listFor(local.conversation_id) ?? [];
            const localIndex = list.findIndex((m) => m.client_key === key && m.id < 0);
            const serverIndex = list.findIndex((m) => m.id === server.id);
            if (serverIndex !== -1) {
                // The broadcast arrived first: drop the optimistic copy.
                if (localIndex !== -1) list.splice(localIndex, 1);
            } else if (localIndex !== -1) {
                list[localIndex] = { ...list[localIndex], ...server, client_key: key };
            }
            // A multi-attachment send creates one message per attachment; the first
            // is the optimistic bubble above, the rest arrive only in this response.
            (data.messages ?? []).slice(1).forEach(mergeMessage);
            pendingPayloads.delete(key);
            return true;
        } catch (e) {
            const list = listFor(local.conversation_id) ?? [];
            const index = list.findIndex((m) => m.client_key === key && m.id < 0);
            if (index !== -1) {
                list[index] = { ...list[index], status: 'failed', error: apiErrorMessage(e, t('thread.failed')) };
            }
            if (local.conversation_id === current.value) scheduleReload(); // window / lock may have changed
            return false;
        }
    }

    function optimistic(id: number, body: string, isTemplate: boolean, attachments: Attachment[] = []): Message {
        const message: Message = {
            id: -Date.now(),
            conversation_id: id,
            direction: 'out',
            sender_type: 'user',
            user: me,
            body,
            attachments,
            status: 'queued',
            error: null,
            is_template: isTemplate,
            created_at: new Date().toISOString(),
            client_key: `local-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
        };
        messages.value.push(message);
        return message;
    }

    async function send(body: string, attachments: Attachment[] = [], quickReplyId: number | null = null): Promise<boolean> {
        if (current.value === null || (!body.trim() && !attachments.length)) return false;
        const payload: { body: string; attachment_ids?: number[]; quick_reply_id?: number } = attachments.length
            ? { body, attachment_ids: attachments.map((a) => a.id) }
            : { body };
        if (quickReplyId !== null) payload.quick_reply_id = quickReplyId;
        return deliver(optimistic(current.value, body, false, attachments), payload);
    }

    async function renderQuickReply(reply: QuickReply): Promise<RenderedQuickReply> {
        const id = current.value;
        if (id === null) throw new Error('No open conversation');
        const { data } = await api.post<RenderedQuickReply>(`/inbox/conversations/${id}/quick-replies/${reply.id}/render`);
        return data;
    }

    async function sendTemplate(template: TemplatePayload): Promise<void> {
        if (current.value === null) return;
        await deliver(optimistic(current.value, `[template] ${template.name}`, true), { template });
    }

    function patchAttachment(updated: Attachment): void {
        const messageIndex = messages.value.findIndex((m) => m.attachments.some((a) => a.id === updated.id));
        if (messageIndex === -1) return;
        const message = messages.value[messageIndex];
        messages.value[messageIndex] = { ...message, attachments: message.attachments.map((a) => (a.id === updated.id ? updated : a)) };
    }

    async function retryAttachment(attachment: Attachment): Promise<void> {
        if (retryingAttachments.value.includes(attachment.id)) return;
        retryingAttachments.value = [...retryingAttachments.value, attachment.id];
        try {
            const { data } = await api.post<{ data: Attachment }>(`/media/${attachment.id}/retry`);
            patchAttachment(data.data);
            // Still pending: the download job hasn't finished yet, so keep the
            // spinner up until `resolveRetriedAttachments` sees it settle.
            if (data.data.status !== 'pending') {
                retryingAttachments.value = retryingAttachments.value.filter((id) => id !== attachment.id);
            }
        } catch (e) {
            retryingAttachments.value = retryingAttachments.value.filter((id) => id !== attachment.id);
            error.value = apiErrorMessage(e, t('media.download_failed'));
        }
    }

    async function retry(message: Message): Promise<void> {
        const key = message.client_key ?? message.id;
        if (retrying.value.includes(key)) return;
        retrying.value = [...retrying.value, key];
        try {
            if (message.id < 0 && message.client_key) {
                // Never reached the server: resend the original payload.
                const index = messages.value.findIndex((m) => m.client_key === message.client_key);
                if (index === -1) return;
                messages.value[index] = { ...messages.value[index], status: 'queued', error: null };
                await deliver(messages.value[index], pendingPayloads.get(message.client_key) ?? { body: message.body ?? '' });
            } else {
                const { data } = await api.post<{ data: Message }>(`/inbox/messages/${message.id}/retry`);
                mergeMessage(data.data);
            }
        } catch (e) {
            error.value = apiErrorMessage(e, t('thread.failed'));
        } finally {
            retrying.value = retrying.value.filter((k) => k !== key);
        }
    }

    function typing(): void {
        const id = current.value;
        if (id === null) return;
        const now = Date.now();

        if (now - lastWhisper > TYPING_WHISPER_MS) {
            lastWhisper = now;
            presence?.whisper('typing', { id: me.id, name: me.name });
        }

        if (now - lastTypingPost > TYPING_POST_MS) {
            lastTypingPost = now;
            api.post<{ locked: boolean; holder: UserRef | null }>(`/inbox/conversations/${id}/typing`, {}, { silent: true })
                .then(({ data }) => {
                    if (id === current.value) lockHolder.value = data.holder;
                })
                .catch(() => undefined);
        }
    }

    function replaceConversation(id: number, conversation: Conversation): void {
        if (id === current.value && detail.value) detail.value.conversation = conversation;
    }

    async function action(name: ConversationAction, body?: Record<string, unknown>): Promise<Conversation | null> {
        const id = current.value;
        if (id === null || busyAction.value) return null;
        busyAction.value = name;
        try {
            const { data } = await api.post<{ data: Conversation }>(`/inbox/conversations/${id}/${name}`, body);
            if (name === 'reset' && id === current.value) {
                messages.value = [];
                hasMore.value = false;
            }
            replaceConversation(id, data.data);
            return data.data;
        } catch (e) {
            error.value = apiErrorMessage(e, t('common.error'));
            return null;
        } finally {
            busyAction.value = null;
        }
    }

    async function setPriority(priority: ConversationPriority): Promise<Conversation | null> {
        const id = current.value;
        if (id === null || busyAction.value) return null;
        const name = `priority-${priority}`;
        busyAction.value = name;
        try {
            const { data } = await api.post<{ data: Conversation }>(`/inbox/conversations/${id}/priority`, { priority });
            replaceConversation(id, data.data);
            return data.data;
        } catch (e) {
            error.value = apiErrorMessage(e, t('common.error'));
            return null;
        } finally {
            busyAction.value = null;
        }
    }

    async function toggleTag(tagId: number): Promise<Conversation | null> {
        const conversation = detail.value?.conversation;
        if (!conversation) return null;
        const ids = conversation.tags.map((tag) => tag.id);
        const next = ids.includes(tagId) ? ids.filter((x) => x !== tagId) : [...ids, tagId];
        try {
            const { data } = await api.post<{ data: Conversation }>(`/inbox/conversations/${conversation.id}/tags`, { tag_ids: next });
            replaceConversation(conversation.id, data.data);
            return data.data;
        } catch (e) {
            error.value = apiErrorMessage(e, t('common.error'));
            return null;
        }
    }

    async function addNote(body: string, mentions: number[] = []): Promise<boolean> {
        const id = current.value;
        if (id === null) return false;
        try {
            const { data } = await api.post<{ data: Note }>(`/inbox/conversations/${id}/notes`, { body, mentions });
            if (id === current.value && detail.value) detail.value.notes = [data.data, ...detail.value.notes];
            return true;
        } catch (e) {
            error.value = apiErrorMessage(e, t('common.error'));
            return false;
        }
    }

    /**
     * Explicit "استلام" claim (spec §5.4, Task 15): a forced soft lock for
     * `crm.claim_lock_minutes`. Never blocks anyone else from sending — this is
     * purely a "who's on it" signal, same as the rest of the handling indicator.
     */
    async function claim(): Promise<Conversation | null> {
        const id = current.value;
        if (id === null || busyAction.value) return null;
        busyAction.value = 'claim';
        try {
            const { data } = await api.post<{ data: Conversation }>(`/inbox/conversations/${id}/claim`);
            replaceConversation(id, data.data);
            if (id === current.value) lockHolder.value = data.data.locked_by;
            return data.data;
        } catch (e) {
            error.value = apiErrorMessage(e, t('common.error'));
            return null;
        } finally {
            busyAction.value = null;
        }
    }

    async function loadOlder(): Promise<void> {
        const id = current.value;
        const oldest = messages.value.find((m) => m.id > 0);
        if (id === null || !oldest || loadingOlder.value) return;
        loadingOlder.value = true;
        try {
            const { data } = await api.get<{ data: Message[]; has_more: boolean }>(`/inbox/conversations/${id}/messages`, {
                params: { before_id: oldest.id },
            });
            if (id !== current.value) {
                // She switched away: the page still belongs to that chat's cached copy.
                cache.patch(id, (entry) => {
                    const known = new Set(entry.messages.map((m) => m.id));
                    if (!known.has(oldest.id)) return;
                    entry.messages = [...data.data.filter((m) => !known.has(m.id)), ...entry.messages];
                    entry.hasMore = data.has_more;
                });
                return;
            }
            const known = new Set(messages.value.map((m) => m.id));
            messages.value = [...data.data.filter((m) => !known.has(m.id)), ...messages.value];
            hasMore.value = data.has_more;
        } catch (e) {
            if (id === current.value) error.value = apiErrorMessage(e, t('common.error'));
        } finally {
            // A switch already reset it; never clear the flag of a load the new chat started since.
            if (id === current.value) loadingOlder.value = false;
        }
    }

    function applyConversation(patch: ConversationPatch): void {
        if (patch.id !== current.value) {
            cache.patch(patch.id, (entry) => {
                entry.detail.conversation = { ...entry.detail.conversation, ...patch };
                if ('locked_by' in patch) entry.detail.lock = { ...entry.detail.lock, holder: patch.locked_by ?? null };
            });
            return;
        }
        if (!detail.value) return;
        detail.value.conversation = { ...detail.value.conversation, ...patch };
        if ('locked_by' in patch) lockHolder.value = patch.locked_by ?? null;
    }

    function applyOrder(order: Pick<Order, 'id'> & { customer_id?: number | null; conversation_id?: number | null }): void {
        const customer = detail.value?.customer;
        if (customer && (order.customer_id === customer.id || order.conversation_id === current.value)) scheduleReload();
    }

    function onOrderCreated(order: Order): void {
        const customer = detail.value?.customer;
        if (!customer) return;
        customer.orders = [order, ...(customer.orders ?? []).filter((o) => o.id !== order.id)];
        customer.orders_count = (customer.orders_count ?? 0) + 1;
    }

    poll(silentReload);

    onScopeDispose(() => {
        leavePresence();
        window.clearTimeout(reloadTimer);
        window.clearTimeout(readTimer);
        openController?.abort();
        inflight.forEach(({ controller }) => controller.abort());
    });

    return {
        detail,
        messages,
        hasMore,
        loading,
        loadingOlder,
        viewers,
        typingNames,
        lockHolder,
        mentionable,
        restoredView,
        busyAction,
        retrying,
        retryingAttachments,
        error,
        open,
        prefetch,
        silentReload,
        mergeMessage,
        markRead,
        send,
        sendTemplate,
        renderQuickReply,
        retry,
        retryAttachment,
        typing,
        action,
        claim,
        setPriority,
        toggleTag,
        addNote,
        loadOlder,
        applyConversation,
        applyOrder,
        onOrderCreated,
        clearError: () => (error.value = null),
    };
}
