<script setup lang="ts">
import ChatThread from '@/components/crm/ChatThread.vue';
import ConversationList from '@/components/crm/ConversationList.vue';
import CreateOrderDrawer from '@/components/crm/CreateOrderDrawer.vue';
import CustomerPanel from '@/components/crm/CustomerPanel.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { useConversationList } from '@/composables/inbox/useConversationList';
import { useConversationThread } from '@/composables/inbox/useConversationThread';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import type { SharedData } from '@/types';
import type { City, Conversation, ConversationAction, ConversationPriority, CursorPage, InboxFilters, Order, QuickReply, Tag, TemplatePayload } from '@/types/crm';
import { Head, usePage } from '@inertiajs/vue3';
import { useMediaQuery } from '@vueuse/core';
import { CircleAlert, MessageSquareText } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps<{
    conversations: CursorPage<Conversation>;
    filters: InboxFilters;
    quickReplies: QuickReply[];
    tags: Tag[];
    cities: City[];
}>();

const page = usePage<SharedData>();
const me = page.props.auth.user;
const { t, dir } = useI18n();
const isXl = useMediaQuery('(min-width: 1280px)');

const selectedId = ref<number | null>(null);
const customerOpen = ref(false);
const orderOpen = ref(false);
const addingNote = ref(false);
const flash = ref<string | null>(null);
const drafts = ref<Record<number, string>>({});
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

const { detail, messages, hasMore, loading: loadingThread, loadingOlder, viewers, typingNames, lockHolder, busyAction, retrying, error } = thread;
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

function send(body: string): void {
    void thread.send(body);
    draft.value = '';
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

async function addNote(body: string, done: () => void): Promise<void> {
    addingNote.value = true;
    try {
        if (await thread.addNote(body)) done();
    } finally {
        addingNote.value = false;
    }
}

function onOrderCreated(order: Order): void {
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
        <div class="grid min-h-0 flex-1 grid-cols-1 overflow-hidden md:grid-cols-[300px_minmax(0,1fr)] xl:grid-cols-[340px_minmax(0,1fr)_320px]">
            <ConversationList
                :class="selectedId !== null ? 'hidden md:flex' : 'flex'"
                :conversations="conversations"
                :filters="filters"
                :selected-id="selectedId"
                :loading="loading"
                :loading-more="loadingMore"
                :has-more="nextCursor !== null"
                :live="live"
                @update:filters="list.setFilters"
                @select="select"
                @load-more="list.loadMore"
            />

            <main class="min-h-0 min-w-0 flex-col bg-background" :class="selectedId !== null ? 'flex' : 'hidden md:flex'">
                <ChatThread
                    v-if="detail"
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
                    :tags="tags"
                    :busy-action="busyAction"
                    :retrying="retrying"
                    :error="error"
                    :flash="flash"
                    @back="back"
                    @open-customer="customerOpen = true"
                    @load-older="thread.loadOlder"
                    @send="send"
                    @send-template="sendTemplate"
                    @retry="thread.retry"
                    @typing="thread.typing"
                    @action="runAction"
                    @priority="setPriority"
                    @toggle-tag="toggleTag"
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
                    :adding-note="addingNote"
                    @add-note="addNote"
                    @create-order="orderOpen = true"
                />
            </div>
        </div>

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
                    :adding-note="addingNote"
                    @add-note="addNote"
                    @create-order="orderOpen = true"
                />
            </SheetContent>
        </Sheet>

        <CreateOrderDrawer
            v-if="detail"
            v-model:open="orderOpen"
            :conversation-id="detail.conversation.id"
            :customer="detail.customer"
            :cities="cities"
            :can-discount="canDiscount"
            @created="onOrderCreated"
        />
    </AppLayout>
</template>
