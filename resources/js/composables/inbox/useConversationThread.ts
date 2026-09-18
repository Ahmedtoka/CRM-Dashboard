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
import { computed, onScopeDispose, ref } from 'vue';

type SendPayload = { body: string; attachment_ids?: number[]; quick_reply_id?: number } | { template: TemplatePayload };

interface Options {
    me: User;
    onRead: (conversationId: number) => void;
}

const TYPING_WHISPER_MS = 2500;
const TYPING_POST_MS = 10000;
const TYPING_VISIBLE_MS = 5000;

/**
 * The open conversation: detail load, presence (`presence-conversation.{id}`), optimistic send / retry,
 * typing whisper + soft lock, notes, actions. Polls the detail every 5 s when the socket is down.
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
    /** @mentions autocomplete data source (Task 15) — refreshed each time a conversation is opened. */
    const mentionable = ref<UserRef[]>([]);
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
        if (message.conversation_id !== current.value) return;

        const index = messages.value.findIndex((m) => m.id === message.id);
        if (index !== -1) {
            messages.value[index] = { ...messages.value[index], ...message, client_key: messages.value[index].client_key };
            resolveRetriedAttachments(message);
            return;
        }
        messages.value.push(message);
        resolveRetriedAttachments(message);

        // A customer message can reopen the reply window.
        if (message.direction === 'in' && detail.value && detail.value.window.mode !== 'open') {
            scheduleReload();
        }
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

    async function open(id: number | null): Promise<void> {
        if (current.value === id) return;
        leavePresence();
        window.clearTimeout(reloadTimer);
        current.value = id;
        detail.value = null;
        messages.value = [];
        viewers.value = [];
        typingUsers.value = {};
        lockHolder.value = null;
        mentionable.value = [];
        error.value = null;
        lastTypingPost = 0;
        if (id === null) return;

        const seq = ++loadSeq;
        loading.value = true;
        try {
            const { data } = await api.get<ConversationDetail>(`/inbox/conversations/${id}`);
            if (seq !== loadSeq) return;
            applyDetail(data);
            joinPresence(id);
            markRead(id);
            api.get<{ data: UserRef[] }>(`/inbox/conversations/${id}/mentionable`, { silent: true })
                .then(({ data: res }) => {
                    if (id === current.value) mentionable.value = res.data;
                })
                .catch(() => undefined);
        } catch (e) {
            if (seq === loadSeq) error.value = apiErrorMessage(e, t('common.error'));
        } finally {
            if (seq === loadSeq) loading.value = false;
        }
    }

    async function silentReload(): Promise<void> {
        const id = current.value;
        if (id === null || !detail.value) return;
        const seq = loadSeq;
        const { data } = await api.get<ConversationDetail>(`/inbox/conversations/${id}`, { silent: true });
        if (seq === loadSeq && id === current.value) applyDetail(data, true);
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
            const localIndex = messages.value.findIndex((m) => m.client_key === key && m.id < 0);
            const serverIndex = messages.value.findIndex((m) => m.id === server.id);
            if (serverIndex !== -1) {
                // The broadcast arrived first: drop the optimistic copy.
                if (localIndex !== -1) messages.value.splice(localIndex, 1);
            } else if (localIndex !== -1) {
                messages.value[localIndex] = { ...messages.value[localIndex], ...server, client_key: key };
            }
            // A multi-attachment send creates one message per attachment; the first
            // is the optimistic bubble above, the rest arrive only in this response.
            (data.messages ?? []).slice(1).forEach(mergeMessage);
            pendingPayloads.delete(key);
            return true;
        } catch (e) {
            const index = messages.value.findIndex((m) => m.client_key === key && m.id < 0);
            if (index !== -1) {
                messages.value[index] = { ...messages.value[index], status: 'failed', error: apiErrorMessage(e, t('thread.failed')) };
            }
            scheduleReload(); // window / lock may have changed
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

    async function action(name: ConversationAction): Promise<Conversation | null> {
        const id = current.value;
        if (id === null || busyAction.value) return null;
        busyAction.value = name;
        try {
            const { data } = await api.post<{ data: Conversation }>(`/inbox/conversations/${id}/${name}`);
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
            if (id !== current.value) return;
            const known = new Set(messages.value.map((m) => m.id));
            messages.value = [...data.data.filter((m) => !known.has(m.id)), ...messages.value];
            hasMore.value = data.has_more;
        } catch (e) {
            error.value = apiErrorMessage(e, t('common.error'));
        } finally {
            loadingOlder.value = false;
        }
    }

    function applyConversation(patch: ConversationPatch): void {
        if (patch.id !== current.value || !detail.value) return;
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
        busyAction,
        retrying,
        retryingAttachments,
        error,
        open,
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
