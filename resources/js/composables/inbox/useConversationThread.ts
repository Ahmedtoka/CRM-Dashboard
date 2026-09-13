import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useEcho } from '@/composables/useEcho';
import { useI18n } from '@/composables/useI18n';
import type { User } from '@/types';
import type {
    Conversation,
    ConversationAction,
    ConversationDetail,
    ConversationPatch,
    ConversationPriority,
    Customer,
    Message,
    Note,
    Order,
    TemplatePayload,
    UserRef,
} from '@/types/crm';
import { computed, onScopeDispose, ref } from 'vue';

type SendPayload = { body: string } | { template: TemplatePayload };

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
    const busyAction = ref<string | null>(null);
    const retrying = ref<Array<number | string>>([]);
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
            return;
        }
        messages.value.push(message);

        // A customer message can reopen the reply window.
        if (message.direction === 'in' && detail.value && detail.value.window.mode !== 'open') {
            scheduleReload();
        }
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
        api.post(`/inbox/conversations/${id}/read`).catch(() => undefined);
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
        const { data } = await api.get<ConversationDetail>(`/inbox/conversations/${id}`);
        if (seq === loadSeq && id === current.value) applyDetail(data, true);
    }

    function scheduleReload(): void {
        window.clearTimeout(reloadTimer);
        reloadTimer = window.setTimeout(() => void silentReload().catch(() => undefined), 500);
    }

    async function deliver(local: Message, payload: SendPayload): Promise<void> {
        const key = local.client_key as string;
        pendingPayloads.set(key, payload);
        try {
            const { data } = await api.post<{ data: Message }>(`/inbox/conversations/${local.conversation_id}/messages`, payload);
            const server = data.data;
            const localIndex = messages.value.findIndex((m) => m.client_key === key && m.id < 0);
            const serverIndex = messages.value.findIndex((m) => m.id === server.id);
            if (serverIndex !== -1) {
                // The broadcast arrived first: drop the optimistic copy.
                if (localIndex !== -1) messages.value.splice(localIndex, 1);
            } else if (localIndex !== -1) {
                messages.value[localIndex] = { ...messages.value[localIndex], ...server, client_key: key };
            }
            pendingPayloads.delete(key);
        } catch (e) {
            const index = messages.value.findIndex((m) => m.client_key === key && m.id < 0);
            if (index !== -1) {
                messages.value[index] = { ...messages.value[index], status: 'failed', error: apiErrorMessage(e, t('thread.failed')) };
            }
            scheduleReload(); // window / lock may have changed
        }
    }

    function optimistic(id: number, body: string, isTemplate: boolean): Message {
        const message: Message = {
            id: -Date.now(),
            conversation_id: id,
            direction: 'out',
            sender_type: 'user',
            user: me,
            body,
            attachments: [],
            status: 'queued',
            error: null,
            is_template: isTemplate,
            created_at: new Date().toISOString(),
            client_key: `local-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
        };
        messages.value.push(message);
        return message;
    }

    async function send(body: string): Promise<void> {
        if (current.value === null || !body.trim()) return;
        await deliver(optimistic(current.value, body, false), { body });
    }

    async function sendTemplate(template: TemplatePayload): Promise<void> {
        if (current.value === null) return;
        await deliver(optimistic(current.value, `[template] ${template.name}`, true), { template });
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
            api.post<{ locked: boolean; holder: UserRef | null }>(`/inbox/conversations/${id}/typing`)
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

    async function addNote(body: string): Promise<boolean> {
        const id = current.value;
        if (id === null) return false;
        try {
            const { data } = await api.post<{ data: Note }>(`/inbox/conversations/${id}/notes`, { body });
            if (id === current.value && detail.value) detail.value.notes = [data.data, ...detail.value.notes];
            return true;
        } catch (e) {
            error.value = apiErrorMessage(e, t('common.error'));
            return false;
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
        busyAction,
        retrying,
        error,
        open,
        silentReload,
        mergeMessage,
        markRead,
        send,
        sendTemplate,
        retry,
        typing,
        action,
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
