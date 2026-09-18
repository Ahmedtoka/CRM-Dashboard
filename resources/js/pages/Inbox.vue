<script setup lang="ts">
import ChatThread from '@/components/crm/ChatThread.vue';
import ConversationList from '@/components/crm/ConversationList.vue';
import ConversationTagMenu from '@/components/crm/ConversationTagMenu.vue';
import CreateOrderDrawer from '@/components/crm/CreateOrderDrawer.vue';
import CustomerPanel from '@/components/crm/CustomerPanel.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { useConversationList } from '@/composables/inbox/useConversationList';
import { useConversationThread } from '@/composables/inbox/useConversationThread';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useShortcuts } from '@/composables/useShortcuts';
import { useToast } from '@/composables/useToast';
import AppLayout from '@/layouts/AppLayout.vue';
import type { SharedData } from '@/types';
import type {
    Attachment,
    City,
    Conversation,
    ConversationAction,
    ConversationPriority,
    CursorPage,
    InboxFilters,
    Order,
    QuickReply,
    QuickReplyCategory,
    SupportCase,
    Tag,
    TemplatePayload,
} from '@/types/crm';
import { Head, usePage } from '@inertiajs/vue3';
import { useMediaQuery } from '@vueuse/core';
import { CircleAlert, MessageSquareText } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps<{
    conversations: CursorPage<Conversation>;
    filters: InboxFilters;
    quickReplies: QuickReply[];
    quickReplyCategories: QuickReplyCategory[];
    tags: Tag[];
    cities: City[];
}>();

const page = usePage<SharedData>();
const me = page.props.auth.user;
const { t, dir } = useI18n();
const isXl = useMediaQuery('(min-width: 1280px)');

const api = useApi();
const toast = useToast();
const selectedId = ref<number | null>(null);
const customerOpen = ref(false);
const orderOpen = ref(false);
const editingOrder = ref<Order | null>(null);
const addingNote = ref(false);
const flash = ref<string | null>(null);
const drafts = ref<Record<number, string>>({});
const threadView = ref<InstanceType<typeof ChatThread> | null>(null);
const tagMenu = ref<{ id: number; x: number; y: number } | null>(null);
// Blocks a second `mod+enter` from resolving twice (or resolving a conversation
// whose send is still in flight) while one send-and-resolve is already running.
const resolvingSend = ref(false);
let flashTimer: number | undefined;
let readTimer: number | undefined;

const thread = useConversationThread({ me, onRead: (id) => list.applyConversation({ id, unread_count: 0 }) });

const list = useConversationList(props.conversations, props.filters, {
    me,
    selectedId,
    handlers: {
        onMessage: (message) => thread.mergeMessage(message),
        onConversation: (patch) => {
            thread.applyConversation(patch);
            // New customer messages in the open thread are read immediately.
            if (patch.id === selectedId.value && (patch.unread_count ?? 0) > 0) {
                window.clearTimeout(readTimer);
                readTimer = window.setTimeout(() => thread.markRead(patch.id), 1000);
            }
        },
        onOrder: (order) => thread.applyOrder(order),
    },
});

const { detail, messages, hasMore, loading: loadingThread, loadingOlder, viewers, typingNames, lockHolder, mentionable, busyAction, retrying, retryingAttachments, error } = thread;
const { conversations, filters, loading, loadingMore, nextCursor, live } = list;

const canDiscount = computed(() => me.role === 'admin' || me.role === 'supervisor');
const breadcrumbs = computed(() => [{ title: t('inbox.title'), href: '/inbox' }]);

// One draft per conversation so switching threads never loses typed text.
const draft = computed({
    get: () => (selectedId.value !== null ? (drafts.value[selectedId.value] ?? '') : ''),
    set: (value: string) => {
        if (selectedId.value !== null) drafts.value[selectedId.value] = value;
    },
});

function syncSelectionUrl(id: number | null): void {
    const url = new URL(window.location.href);
    if (id === null) url.searchParams.delete('c');
    else url.searchParams.set('c', String(id));
    window.history.replaceState(window.history.state, '', url);
}

function select(id: number): void {
    if (selectedId.value === id) return;
    selectedId.value = id;
    customerOpen.value = false;
    syncSelectionUrl(id);
    void thread.open(id);
}

function back(): void {
    selectedId.value = null;
    syncSelectionUrl(null);
    void thread.open(null);
}

function showFlash(message: string): void {
    flash.value = message;
    window.clearTimeout(flashTimer);
    flashTimer = window.setTimeout(() => (flash.value = null), 4000);
}

function send(body: string, attachments: Attachment[], quickReplyId: number | null): void {
    void thread.send(body, attachments, quickReplyId);
    draft.value = '';
}

// `mod+enter`: send, then resolve — but only once the send actually succeeded
// (a resolved-then-failed-send would silently drop the message), only for the
// conversation the send was for (never whatever the moderator has since
// switched to), and never twice at once for an overlapping press.
async function sendAndResolve(body: string, attachments: Attachment[], quickReplyId: number | null): Promise<void> {
    if (resolvingSend.value) return;
    const id = selectedId.value;
    if (id === null) return;
    resolvingSend.value = true;
    // Cleared synchronously, before the `await` below, straight into the specific
    // conversation's slot — never through the `draft` computed, which always
    // targets whichever conversation is *currently* selected and would clear the
    // wrong one if the moderator switches away while the send is in flight.
    drafts.value[id] = '';
    try {
        const ok = await thread.send(body, attachments, quickReplyId);
        if (ok && selectedId.value === id) await runAction('resolve');
    } finally {
        resolvingSend.value = false;
    }
}

function onNote(body: string, mentions: number[], done: () => void): void {
    void addNote(body, done, mentions);
}

async function claim(): Promise<void> {
    const conversation = await thread.claim();
    if (conversation) list.applyConversation(conversation);
}

function sendTemplate(template: TemplatePayload): void {
    void thread.sendTemplate(template);
}

async function runAction(name: ConversationAction): Promise<void> {
    const conversation = await thread.action(name);
    if (conversation) list.applyConversation(conversation);
}

async function setPriority(value: ConversationPriority): Promise<void> {
    const conversation = await thread.setPriority(value);
    if (conversation) list.applyConversation(conversation);
}

async function toggleTag(id: number): Promise<void> {
    const conversation = await thread.toggleTag(id);
    if (conversation) list.applyConversation(conversation);
}

async function addNote(body: string, done: () => void, mentions: number[] = []): Promise<void> {
    addingNote.value = true;
    try {
        if (await thread.addNote(body, mentions)) done();
    } finally {
        addingNote.value = false;
    }
}

// Right-click (or `t`) quick-tag menu on a conversation row — works on any row,
// not just the open thread, so it posts the full id list directly rather than
// going through `thread.toggleTag` (which only knows the open conversation).
function openTagMenu(id: number, x: number, y: number): void {
    tagMenu.value = { id, x, y };
}

// One promise chain per conversation id: a second toggle on the same row while
// the first is still in flight waits for it, so it computes the next tag list
// from the just-applied result instead of the stale snapshot it started with.
// Different rows never block each other.
const tagToggleChains = new Map<number, Promise<void>>();

async function applyTagToggle(id: number, tagId: number): Promise<void> {
    const current = conversations.value.find((c) => c.id === id);
    const ids = current?.tags.map((tag) => tag.id) ?? [];
    const next = ids.includes(tagId) ? ids.filter((x) => x !== tagId) : [...ids, tagId];
    try {
        const { data } = await api.post<{ data: Conversation }>(`/inbox/conversations/${id}/tags`, { tag_ids: next });
        list.applyConversation(data.data);
        thread.applyConversation(data.data);
    } catch (e) {
        toast.push(apiErrorMessage(e, t('common.error')), 'error');
    }
}

function quickToggleTag(tagId: number): void {
    const row = tagMenu.value;
    if (!row) return;
    const id = row.id;
    const chain = (tagToggleChains.get(id) ?? Promise.resolve()).then(() => applyTagToggle(id, tagId));
    tagToggleChains.set(
        id,
        chain.catch(() => undefined),
    );
}

function openOrderDrawer(): void {
    editingOrder.value = null;
    orderOpen.value = true;
}

function editOrder(order: Order): void {
    editingOrder.value = order;
    orderOpen.value = true;
}

// Mirrors the invoice-link insertion below: append the copied status line to whatever
// the user has already typed for this conversation, then confirm it landed in the reply
// (the button's own clipboard write is a silent best-effort extra, not what this toasts).
function onCopyStatus(text: string): void {
    draft.value = draft.value.trim() ? `${draft.value}\n${text}` : text;
    showFlash(t('order.copy_status_done'));
}

function onCaseUpdated(updated: SupportCase): void {
    if (!detail.value) return;
    detail.value.cases = detail.value.cases.map((c) => (c.id === updated.id ? updated : c));
}

function onOrderCreated(order: Order): void {
    editingOrder.value = null;
    thread.onOrderCreated(order);
    if (order.type === 'payment_link' && order.invoice_url) {
        const line = t('order.invoice_message', { url: order.invoice_url });
        draft.value = draft.value.trim() ? `${draft.value}\n${line}` : line;
        customerOpen.value = false;
        showFlash(t('order.invoice_inserted'));
    } else {
        showFlash(t('order.created', { number: order.order_number ?? `#${order.id}` }));
    }
}

// `j`/`k`/arrow-down/arrow-up: moves the selection by one row and scrolls it into view.
function move(delta: 1 | -1): void {
    const rows = conversations.value;
    if (!rows.length) return;
    const index = rows.findIndex((c) => c.id === selectedId.value);
    const next = rows[Math.min(rows.length - 1, Math.max(0, index === -1 ? 0 : index + delta))];
    if (next) {
        select(next.id);
        document.querySelector(`[data-conversation-id="${next.id}"]`)?.scrollIntoView({ block: 'nearest' });
    }
}

const hasThread = () => detail.value !== null;

// `arrowdown`/`arrowup` only move the selection when focus is inside the
// conversation list (or nowhere in particular, i.e. `document.body`) — anywhere
// else, most importantly inside the thread, the arrow key is left alone to do
// whatever it would natively do there (e.g. scroll). `j`/`k` are unaffected —
// they work globally regardless of where focus is.
function arrowMayMove(event: KeyboardEvent): boolean {
    if (!event.key.toLowerCase().startsWith('arrow')) return true;
    const el = event.target as HTMLElement | null;
    return el === document.body || !!el?.closest('[data-conversation-list]');
}

useShortcuts([
    { id: 'inbox.next', keys: ['j', 'arrowdown'], labelKey: 'shortcuts.next', group: 'inbox', when: arrowMayMove, handler: () => move(1) },
    { id: 'inbox.prev', keys: ['k', 'arrowup'], labelKey: 'shortcuts.prev', group: 'inbox', when: arrowMayMove, handler: () => move(-1) },
    { id: 'inbox.reply', keys: ['r'], labelKey: 'shortcuts.reply', group: 'inbox', handler: () => hasThread() && threadView.value?.composer?.setMode('reply') },
    { id: 'inbox.note', keys: ['n'], labelKey: 'shortcuts.note', group: 'inbox', handler: () => hasThread() && threadView.value?.composer?.setMode('note') },
    { id: 'inbox.resolve', keys: ['e'], labelKey: 'shortcuts.resolve', group: 'inbox', handler: () => hasThread() && void runAction('resolve') },
    { id: 'inbox.reopen', keys: ['shift+e'], labelKey: 'shortcuts.reopen', group: 'inbox', handler: () => hasThread() && void runAction('reopen') },
    { id: 'inbox.bot', keys: ['b'], labelKey: 'shortcuts.return_to_bot', group: 'inbox', handler: () => hasThread() && void runAction('return-to-bot') },
    { id: 'inbox.tags', keys: ['t'], labelKey: 'shortcuts.tags', group: 'inbox', handler: () => hasThread() && threadView.value?.header?.openTags() },
    { id: 'inbox.order', keys: ['o'], labelKey: 'shortcuts.order', group: 'inbox', handler: () => hasThread() && openOrderDrawer() },
    { id: 'inbox.attach', keys: ['a'], labelKey: 'shortcuts.attach', group: 'inbox', handler: () => hasThread() && threadView.value?.composer?.openFilePicker() },
]);

onMounted(() => {
    const requested = Number(new URL(window.location.href).searchParams.get('c'));
    if (requested > 0) select(requested);
});

onBeforeUnmount(() => {
    window.clearTimeout(flashTimer);
    window.clearTimeout(readTimer);
});
</script>

<template>
    <Head :title="t('inbox.title')" />

    <AppLayout :breadcrumbs="breadcrumbs" fill>
        <!-- Fills the space left under the header and any admin alert strip (no fixed calc). -->
        <div class="grid min-h-0 flex-1 grid-cols-1 overflow-hidden bg-background md:grid-cols-[320px_minmax(0,1fr)] xl:grid-cols-[340px_minmax(0,1fr)_320px]">
            <ConversationList
                :class="selectedId !== null ? 'hidden md:flex' : 'flex'"
                :conversations="conversations"
                :filters="filters"
                :selected-id="selectedId"
                :loading="loading"
                :loading-more="loadingMore"
                :has-more="nextCursor !== null"
                :live="live"
                :tags="tags"
                @update:filters="list.setFilters"
                @select="select"
                @load-more="list.loadMore"
                @tag-menu="openTagMenu"
            />

            <main class="min-h-0 min-w-0 flex-col bg-background" :class="selectedId !== null ? 'flex' : 'hidden md:flex'">
                <ChatThread
                    v-if="detail"
                    ref="threadView"
                    v-model:draft="draft"
                    :detail="detail"
                    :messages="messages"
                    :has-more="hasMore"
                    :loading-older="loadingOlder"
                    :viewers="viewers"
                    :typing="typingNames"
                    :lock-holder="lockHolder"
                    :me-id="me.id"
                    :quick-replies="quickReplies"
                    :quick-reply-categories="quickReplyCategories"
                    :render-reply="thread.renderQuickReply"
                    :tags="tags"
                    :busy-action="busyAction"
                    :retrying="retrying"
                    :retrying-attachments="retryingAttachments"
                    :error="error"
                    :flash="flash"
                    :mentionable="mentionable"
                    :adding-note="addingNote"
                    @back="back"
                    @open-customer="customerOpen = true"
                    @load-older="thread.loadOlder"
                    @send="send"
                    @send-and-resolve="sendAndResolve"
                    @note="onNote"
                    @send-template="sendTemplate"
                    @retry="thread.retry"
                    @retry-attachment="thread.retryAttachment"
                    @typing="thread.typing"
                    @action="runAction"
                    @priority="setPriority"
                    @toggle-tag="toggleTag"
                    @claim="claim"
                    @window-expired="thread.silentReload().catch(() => undefined)"
                    @dismiss-error="thread.clearError"
                />
                <div v-else-if="selectedId !== null && loadingThread" class="flex flex-1 flex-col gap-4 p-6" aria-busy="true">
                    <Skeleton class="h-10 w-1/2" />
                    <Skeleton class="h-16 w-2/3" />
                    <Skeleton class="ms-auto h-16 w-1/2" />
                    <Skeleton class="h-12 w-3/5" />
                </div>
                <EmptyState v-else-if="selectedId !== null && error" :icon="CircleAlert" :title="error">
                    <button type="button" class="text-xs text-primary hover:underline" @click="back">{{ t('inbox.back') }}</button>
                </EmptyState>
                <EmptyState v-else :icon="MessageSquareText" :title="t('inbox.select_title')" :body="t('inbox.select_body')" />
            </main>

            <div v-if="isXl" class="hidden min-h-0 flex-col border-s bg-card xl:flex">
                <CustomerPanel
                    v-if="detail"
                    class="flex-1"
                    :customer="detail.customer"
                    :notes="detail.notes"
                    :participants="detail.participants"
                    :cases="detail.cases"
                    :adding-note="addingNote"
                    :conversation-id="detail.conversation.id"
                    :mentionable="mentionable"
                    :me-id="me.id"
                    @add-note="addNote"
                    @create-order="openOrderDrawer"
                    @edit-order="editOrder"
                    @copy-status="onCopyStatus"
                    @case-updated="onCaseUpdated"
                />
            </div>
        </div>

        <ConversationTagMenu
            v-if="tagMenu"
            :tags="tags"
            :selected="(conversations.find((c) => c.id === tagMenu!.id)?.tags ?? []).map((tag) => tag.id)"
            :x="tagMenu.x"
            :y="tagMenu.y"
            @toggle="quickToggleTag"
            @close="tagMenu = null"
        />

        <Sheet v-if="!isXl" v-model:open="customerOpen">
            <SheetContent :side="dir === 'rtl' ? 'left' : 'right'" class="flex w-full flex-col gap-0 p-0 sm:max-w-sm">
                <SheetHeader class="border-b px-4 py-3 text-start">
                    <SheetTitle class="text-sm">{{ t('thread.customer') }}</SheetTitle>
                </SheetHeader>
                <CustomerPanel
                    v-if="detail"
                    class="min-h-0 flex-1"
                    :customer="detail.customer"
                    :notes="detail.notes"
                    :participants="detail.participants"
                    :cases="detail.cases"
                    :adding-note="addingNote"
                    :conversation-id="detail.conversation.id"
                    :mentionable="mentionable"
                    :me-id="me.id"
                    @add-note="addNote"
                    @create-order="openOrderDrawer"
                    @edit-order="editOrder"
                    @copy-status="onCopyStatus"
                    @case-updated="onCaseUpdated"
                />
            </SheetContent>
        </Sheet>

        <CreateOrderDrawer
            v-if="detail"
            v-model:open="orderOpen"
            :conversation-id="detail.conversation.id"
            :customer="detail.customer"
            :can-discount="canDiscount"
            :retry-order="editingOrder"
            @created="onOrderCreated"
        />
    </AppLayout>
</template>
