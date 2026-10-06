<script setup lang="ts">
import ChatThread from '@/components/crm/ChatThread.vue';
import ConversationList from '@/components/crm/ConversationList.vue';
import ConversationTagMenu from '@/components/crm/ConversationTagMenu.vue';
import CreateOrderDrawer from '@/components/crm/CreateOrderDrawer.vue';
import CustomerPanel from '@/components/crm/CustomerPanel.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import InlineError from '@/components/crm/InlineError.vue';
import MyWindowsStrip from '@/components/crm/queue/MyWindowsStrip.vue';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { INBOX_OUTCOME, useConversationContext } from '@/composables/inbox/useConversationContext';
import { useConversationList } from '@/composables/inbox/useConversationList';
import { useDetailsPanel } from '@/composables/inbox/useDetailsPanel';
import { useConversationThread } from '@/composables/inbox/useConversationThread';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useMyQueue } from '@/composables/useMyQueue';
import { useShortcuts } from '@/composables/useShortcuts';
import { useToast } from '@/composables/useToast';
import { syncInertiaUrl } from '@/composables/useUrlFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { stripBidiControls } from '@/lib/orderStatus';
import type { SharedData } from '@/types';
import type {
    Attachment,
    City,
    Conversation,
    ConversationAction,
    ConversationPriority,
    CursorPage,
    InboxFilters,
    InboxModerator,
    Order,
    OutcomePayload,
    QueueEntry,
    QuickReply,
    QuickReplyCategory,
    SupportCase,
    Tag,
    TemplatePayload,
} from '@/types/crm';
import { Head, router, usePage } from '@inertiajs/vue3';
import { useEventListener, useMediaQuery } from '@vueuse/core';
import { MessageSquareText } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, provide, ref, watch } from 'vue';

const props = defineProps<{
    conversations: CursorPage<Conversation>;
    filters: InboxFilters;
    moderators: InboxModerator[];
    queueEnabled: boolean;
    quickReplies: QuickReply[];
    quickReplyCategories: QuickReplyCategory[];
    tags: Tag[];
    cities: City[];
    /** Control room S3: the queue's first-reply target, the list's «مستنية ٧ د» threshold. */
    firstReplyTargetSeconds?: number;
}>();

const page = usePage<SharedData>();
const me = page.props.auth.user;
const { t, dir } = useI18n();
const isXl = useMediaQuery('(min-width: 1280px)');

// The details column (R2): closed by default under 1600 px, her choice remembered. Below xl it is
// the customer sheet instead (customerOpen). Task 6 puts the toggle in the thread header.
function initialDetails(): boolean {
    try {
        const saved = window.localStorage.getItem('inbox:details');
        if (saved === '1' || saved === '0') return saved === '1';
    } catch {
        // Storage blocked: fall back to the width rule.
    }
    return window.matchMedia('(min-width: 1600px)').matches;
}
// Control room S3 (G16): the column, the sheet below xl, and the overlay a delivered window opens.
const details = useDetailsPanel({ isXl, initialOpen: initialDetails() });
watch(details.open, (open) => {
    try {
        window.localStorage.setItem('inbox:details', open ? '1' : '0');
    } catch {
        // Not remembered this time; still toggles.
    }
});
const toggleDetails = details.toggle;
const showDetails = details.showColumn;
/** The sheet below xl (her toggle), the customer panel's home on a phone or a tablet. */
const customerOpen = details.sheet;
/** What the header's details toggle reports as pressed: the column (or its overlay) on xl, the sheet below it. */
provide('inboxDetails', { open: details.open, active: details.active, toggle: details.toggle });
// Escape closes the overlay (not while a dialog or a menu has it).
useEventListener(document, 'keydown', (e: KeyboardEvent) => {
    if (e.key === 'Escape' && details.overlay.value && !document.querySelector('[role="dialog"], [role="alertdialog"], [role="menu"]')) {
        details.overlay.value = false;
    }
});

const api = useApi();
const toast = useToast();
const selectedId = ref<number | null>(null);
/**
 * The composer takes the focus when the chat was opened by a pointer click (row or window card),
 * never by j/k, the keyboard on a row, a URL or a queue assignment: the next `j` must not type
 * into it (Task 6 controller addition). `r` / `n` and a click in the box focus it as always.
 */
const focusComposer = ref(false);
const orderOpen = ref(false);
const editingOrder = ref<Order | null>(null);
const addingNote = ref(false);
const flash = ref<string | null>(null);
const drafts = ref<Record<number, string>>({});
const threadView = ref<InstanceType<typeof ChatThread> | null>(null);
const listView = ref<InstanceType<typeof ConversationList> | null>(null);
const tagMenu = ref<{ id: number; x: number; y: number } | null>(null);
// Blocks a second `mod+enter` from resolving twice (or resolving a conversation
// whose send is still in flight) while one send-and-resolve is already running.
const resolvingSend = ref(false);
let flashTimer: number | undefined;
let readTimer: number | undefined;

const thread = useConversationThread({
    me,
    onRead: (id) => list.applyConversation({ id, unread_count: 0 }),
    // Saved with the chat she leaves, so it reopens where she was (Task 6c thread cache).
    viewState: () => threadView.value?.viewState() ?? null,
});

const list = useConversationList(props.conversations, props.filters, {
    me,
    selectedId,
    handlers: {
        onMessage: (message) => {
            thread.mergeMessage(message);
            queue.noteMessage(message);
        },
        onConversation: (patch) => {
            thread.applyConversation(patch);
            // New customer messages in the open thread are read immediately.
            if (patch.id === selectedId.value && (patch.unread_count ?? 0) > 0) {
                window.clearTimeout(readTimer);
                readTimer = window.setTimeout(() => thread.markRead(patch.id), 1000);
            }
        },
        onOrder: (order) => {
            thread.applyOrder(order);
            if (order.conversation_id === selectedId.value) void ctx.reload();
        },
    },
});

// Control room S3: outcome state, bot digest and ad block for the open chat (web-only endpoint).
const ctx = useConversationContext(selectedId);
provide(
    INBOX_OUTCOME,
    computed(() => ctx.context.value?.outcome ?? null),
);

const {
    detail,
    messages,
    hasMore,
    loading: loadingThread,
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
} = thread;
// Renamed on the way out: the page's props are called `conversations` and `filters` too (the first page and the
// filters it was loaded with), and the live list must never be mistaken for them.
const { conversations: listRows, filters: listFilters, loading, loadingMore, loadMoreFailed, nextCursor, live, pollFailed, searchTruncated, counts, activeKeys } = list;

// Handover queue (the moderator's side). With the queue off, or for somebody who is not on the
// shift, this is one request and nothing of it is rendered.
const queue = useMyQueue({ userId: me.id, onAssigned: onWindowAssigned, onReleased: onWindowReleased });

const windowUnread = computed<Record<number, number>>(() => {
    const ids = new Set(queue.entries.value.map((e) => e.conversation_id));

    return Object.fromEntries(listRows.value.filter((c) => ids.has(c.id)).map((c) => [c.id, c.unread_count]));
});

let unmounted = false;

/**
 * Something on screen that switching chats would throw away or yank from under her: a reply or
 * note draft, files waiting in the composer, a recording, the order drawer, the customer sheet,
 * the quick-tag menu, a note being saved, the close-window menu or any open dialog / menu, or a
 * text box with text in it that has the focus.
 */
function isBusy(): boolean {
    const id = selectedId.value;
    if (id !== null && (drafts.value[id] ?? '').trim() !== '') return true;
    if (threadView.value?.composer?.hasWork() || threadView.value?.header?.closeMenuOpen()) return true;
    if (orderOpen.value || (customerOpen.value && !isXl.value) || tagMenu.value !== null || addingNote.value) return true;
    if (document.querySelector('[role="dialog"], [role="alertdialog"], [role="menu"]')) return true;

    const el = document.activeElement;
    if (el instanceof HTMLTextAreaElement || (el instanceof HTMLInputElement && ['text', 'search', 'tel', 'email', 'number', 'url'].includes(el.type))) {
        return el.value.trim() !== '';
    }

    return el instanceof HTMLElement && el.isContentEditable && (el.textContent ?? '').trim() !== '';
}

/** From the toast: in place (drafts kept) while the inbox is open, else a normal visit. */
function openFromToast(conversationId: number): void {
    if (unmounted) router.visit(`/inbox?c=${conversationId}`);
    else select(conversationId);
}

// A customer was just given to her: the chat opens by itself, unless she is in the middle of
// something (isBusy). Then only the toast, whose button opens the chat in place.
function onWindowAssigned(entry: QueueEntry): void {
    const busy = selectedId.value !== entry.conversation_id && isBusy();
    if (!busy) {
        select(entry.conversation_id);
        // C 2.1, G16: the details (bot summary, ad, orders) open by themselves for a delivered customer.
        details.onWindowDelivered();
    }
    toast.push(
        t('queue.assigned_toast', { ticket: entry.ticket % 100000 }),
        'info',
        undefined,
        busy ? { label: t('queue.assigned_open'), run: () => openFromToast(entry.conversation_id) } : undefined,
    );
}

// Closed or transferred from here: the header and the list drop the window at once (the
// broadcast, or the next poll, brings the rest).
function onWindowReleased(entryId: number): void {
    const row = listRows.value.find((c) => c.queue_entry?.id === entryId);
    if (row) list.applyConversation({ id: row.id, queue_entry: null, assignee: null });
    if (detail.value?.conversation.queue_entry?.id === entryId) {
        thread.applyConversation({ id: detail.value.conversation.id, queue_entry: null, assignee: null });
        void thread.silentReload().catch(() => undefined);
    }
}

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
    syncInertiaUrl(url);
}

function select(id: number, pointer = false): void {
    if (selectedId.value === id) {
        // Same rule as on open: only where the composer is next to the list (md and up).
        if (pointer && window.matchMedia('(min-width: 768px)').matches) threadView.value?.composer?.focus();
        return;
    }
    focusComposer.value = pointer;
    selectedId.value = id;
    details.onSelect();
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
    // An open queue window is never ended without a reason: resolve and return-to-bot (and their
    // shortcuts, and send-and-resolve) open the reasons; somebody else's window is not hers to end.
    if (name === 'resolve' || name === 'return-to-bot') {
        const header = threadView.value?.header;
        const gate = header?.openCloseWindow(name === 'return-to-bot' ? 'bot' : 'close') ?? 'none';
        if (gate === 'menu') return;
        if (gate === 'blocked') {
            toast.push(t('queue.held_by', { name: header?.holder() ?? '' }), 'error');

            return;
        }
        // D13: outside the queue «حل» (and `e`, and send-and-resolve) opens the outcome menu.
        if (name === 'resolve' && header?.openResolve()) return;
    }

    const conversation = await thread.action(name);
    if (conversation) list.applyConversation(conversation);
}

/** «حل ▾» confirmed with its outcome (control room S3). */
async function resolveWith(payload: OutcomePayload): Promise<void> {
    const conversation = await thread.action('resolve', { ...payload });
    if (conversation) {
        list.applyConversation(conversation);
        void ctx.reload();
    }
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
    const current = listRows.value.find((c) => c.id === id);
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
    // Defensive: whatever built `text`, no invisible bidi controls reach the customer.
    const clean = stripBidiControls(text);
    draft.value = draft.value.trim() ? `${draft.value}\n${clean}` : clean;
    showFlash(t('order.copy_status_done'));
}

function onCaseUpdated(updated: SupportCase): void {
    if (!detail.value) return;
    detail.value.cases = detail.value.cases.map((c) => (c.id === updated.id ? updated : c));
}

/** One set of props and listeners for the three CustomerPanel homes (column, overlay, sheet), so they never drift. */
const panelProps = computed(() =>
    detail.value
        ? {
              customer: detail.value.customer,
              notes: detail.value.notes,
              participants: detail.value.participants,
              cases: detail.value.cases,
              addingNote: addingNote.value,
              conversationId: detail.value.conversation.id,
              mentionable: mentionable.value,
              meId: me.id,
              handover: ctx.context.value?.handover ?? null,
              ad: ctx.context.value?.ad ?? null,
          }
        : null,
);
const panelListeners = { addNote, createOrder: openOrderDrawer, editOrder, copyStatus: onCopyStatus, caseUpdated: onCaseUpdated };

function onOrderCreated(order: Order): void {
    // A new order (not an edit of an older one) locks the close menu to «اتعمل أوردر» at once.
    if (editingOrder.value === null && order.conversation_id === selectedId.value) ctx.markOrdered();
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

// `j`/`k`/arrow-down/arrow-up: moves the selection by one row and scrolls it into view. The row
// after that one is prefetched, so the next press opens from the cache (Task 6c).
function move(delta: 1 | -1): void {
    const rows = listRows.value;
    if (!rows.length) return;
    const index = rows.findIndex((c) => c.id === selectedId.value);
    const nextIndex = Math.min(rows.length - 1, Math.max(0, index === -1 ? 0 : index + delta));
    const next = rows[nextIndex];
    if (next) {
        select(next.id);
        listView.value?.scrollToId(next.id);
        const ahead = rows[nextIndex + delta];
        if (ahead) thread.prefetch(ahead.id);
    }
}

const hasThread = () => detail.value !== null;

/** `[` / `]`: the previous / next note toggle in the thread (spec §1.2); the virtualised thread finds it. */
function moveNote(step: 1 | -1): void {
    threadView.value?.moveNote(step);
}

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
    { id: 'inbox.search', keys: ['/'], labelKey: 'shortcuts.focus_search', group: 'inbox', handler: () => listView.value?.focusSearch() },
    { id: 'inbox.filters', keys: ['f'], labelKey: 'shortcuts.open_filters', group: 'inbox', handler: () => listView.value?.openFilters() },
    { id: 'inbox.details', keys: ['i'], labelKey: 'shortcuts.toggle_details', group: 'inbox', handler: () => toggleDetails() },
    { id: 'inbox.prev_note', keys: ['['], labelKey: 'shortcuts.prev_note', group: 'inbox', handler: () => hasThread() && moveNote(-1) },
    { id: 'inbox.next_note', keys: [']'], labelKey: 'shortcuts.next_note', group: 'inbox', handler: () => hasThread() && moveNote(1) },
    { id: 'inbox.attach', keys: ['a'], labelKey: 'shortcuts.attach', group: 'inbox', handler: () => hasThread() && threadView.value?.composer?.openFilePicker() },
]);

onMounted(() => {
    const requested = Number(new URL(window.location.href).searchParams.get('c'));
    if (requested > 0) select(requested);
});

// The order drawer belongs to the chat it was opened for: never carried over to the next one.
watch(selectedId, () => {
    orderOpen.value = false;
    editingOrder.value = null;
});

onBeforeUnmount(() => {
    unmounted = true;
    window.clearTimeout(flashTimer);
    window.clearTimeout(readTimer);
});
</script>

<template>
    <Head :title="t('inbox.title')" />

    <AppLayout :breadcrumbs="breadcrumbs" fill workspace>
        <MyWindowsStrip :selected-id="selectedId" :unread="windowUnread" @select="(id, pointer) => select(id, pointer)" />

        <!-- Fills the space left under the header and any admin alert strip (no fixed calc). -->
        <div
            class="relative grid min-h-0 flex-1 grid-cols-1 overflow-hidden bg-background"
            :class="showDetails ? 'md:grid-cols-[360px_minmax(0,1fr)_340px]' : 'md:grid-cols-[360px_minmax(0,1fr)]'"
        >
            <ConversationList
                ref="listView"
                :first-reply-target="firstReplyTargetSeconds ?? null"
                :class="selectedId !== null ? 'hidden md:flex' : 'flex'"
                :conversations="listRows"
                :filters="listFilters"
                :counts="counts"
                :selected-id="selectedId"
                :moderators="moderators"
                :tags="tags"
                :loading="loading"
                :loading-more="loadingMore"
                :load-more-failed="loadMoreFailed"
                :has-more="nextCursor !== null"
                :live="live"
                :poll-failed="pollFailed"
                :search-truncated="searchTruncated"
                :queue-enabled="queueEnabled"
                :filtered="activeKeys.length > 0"
                @update="list.setFilters"
                @clear="list.clearFilters"
                @refresh="list.reload().catch(() => undefined)"
                @select="(id, pointer) => select(id, pointer)"
                @intent="thread.prefetch"
                @load-more="(manual: boolean) => list.loadMore({ manual }).catch(() => undefined)"
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
                    :autofocus="focusComposer"
                    :restore-view="restoredView"
                    @back="back"
                    @open-customer="toggleDetails"
                    @load-older="thread.loadOlder"
                    @send="send"
                    @send-and-resolve="sendAndResolve"
                    @resolve="resolveWith"
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
                <div v-else-if="selectedId !== null && error" class="flex flex-1 flex-col items-center justify-center gap-3 p-6">
                    <InlineError :message="error" class="w-full max-w-md" @retry="thread.open(selectedId)" />
                    <button type="button" class="text-xs text-primary hover:underline" @click="back">{{ t('inbox.back') }}</button>
                </div>
                <EmptyState v-else :icon="MessageSquareText" :title="t('inbox.select_title')" :body="t('inbox.select_body')" />
            </main>

            <div v-if="showDetails" class="hidden min-h-0 flex-col border-s bg-card xl:flex">
                <CustomerPanel v-if="panelProps" class="flex-1" v-bind="panelProps" v-on="panelListeners" />
            </div>
            <div
                v-else-if="details.overlay.value && panelProps"
                class="absolute inset-y-0 end-0 z-30 hidden w-[340px] flex-col border-s bg-card shadow-xl xl:flex"
                role="complementary"
                :aria-label="t('thread.customer')"
                data-details-overlay
            >
                <CustomerPanel class="min-h-0 flex-1" v-bind="panelProps" v-on="panelListeners" />
            </div>
        </div>

        <ConversationTagMenu
            v-if="tagMenu"
            :tags="tags"
            :selected="(listRows.find((c) => c.id === tagMenu!.id)?.tags ?? []).map((tag) => tag.id)"
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
                <CustomerPanel v-if="panelProps" class="min-h-0 flex-1" v-bind="panelProps" v-on="panelListeners" />
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
