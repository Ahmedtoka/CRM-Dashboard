<script setup lang="ts">
import Composer from '@/components/crm/Composer.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import MessageBubble from '@/components/crm/MessageBubble.vue';
import TemplatePicker from '@/components/crm/TemplatePicker.vue';
import ThreadHeader from '@/components/crm/ThreadHeader.vue';
import WindowBanner from '@/components/crm/WindowBanner.vue';
import { useChatSkin } from '@/composables/inbox/useChatSkin';
import type { ComposerMode } from '@/composables/inbox/useComposerShortcuts';
import { useI18n } from '@/composables/useI18n';
import { useNow } from '@/composables/useNow';
import { usePlatform } from '@/composables/usePlatform';
import { groupImageRuns } from '@/lib/chatTimeline';
import { cairoDayKey, formatDay } from '@/lib/format';
import type {
    Attachment,
    ConversationAction,
    ConversationDetail,
    ConversationPriority,
    Message,
    Note,
    QuickReply,
    QuickReplyCategory,
    RenderedQuickReply,
    Tag,
    TemplatePayload,
    UserRef,
} from '@/types/crm';
import { CircleAlert, LoaderCircle, MessageSquareDashed, PenLine, X } from 'lucide-vue-next';
import { computed, nextTick, onMounted, ref, watch } from 'vue';

const props = defineProps<{
    detail: ConversationDetail;
    messages: Message[];
    hasMore: boolean;
    loadingOlder: boolean;
    viewers: UserRef[];
    typing: string[];
    lockHolder: UserRef | null;
    meId: number;
    quickReplies: QuickReply[];
    quickReplyCategories: QuickReplyCategory[];
    renderReply: (reply: QuickReply) => Promise<RenderedQuickReply>;
    tags: Tag[];
    busyAction: string | null;
    retrying: Array<number | string>;
    retryingAttachments: number[];
    error: string | null;
    flash: string | null;
    /** @mentions autocomplete data source (Task 15). */
    mentionable: UserRef[];
    /** True while the last internal-note POST is still in flight. */
    addingNote: boolean;
}>();

const draft = defineModel<string>('draft', { required: true });

const emit = defineEmits<{
    back: [];
    openCustomer: [];
    loadOlder: [];
    send: [body: string, attachments: Attachment[], quickReplyId: number | null];
    sendAndResolve: [body: string, attachments: Attachment[], quickReplyId: number | null];
    note: [body: string, mentions: number[], done: () => void];
    sendTemplate: [template: TemplatePayload];
    retry: [message: Message];
    retryAttachment: [attachment: Attachment];
    typing: [];
    action: [name: ConversationAction];
    priority: [value: ConversationPriority];
    toggleTag: [id: number];
    claim: [];
    windowExpired: [];
    dismissError: [];
}>();

const { t, locale } = useI18n();
const now = useNow();
const platform = usePlatform(() => props.detail.conversation.platform);
const { skin, classes: skinClasses } = useChatSkin(() => props.detail.conversation.platform);
const customerName = computed(() => props.detail.customer?.name ?? null);

interface Entry {
    key: string;
    at: number;
    day: string;
    iso: string | null;
    message?: Message;
    note?: Note;
}

// Messages and internal notes interleaved by time, grouped by Cairo day.
const timeline = computed<Entry[]>(() => {
    const entries: Entry[] = [
        ...props.messages.map((m) => ({
            key: `m-${m.client_key ?? m.id}`,
            at: m.created_at ? Date.parse(m.created_at) : Date.now(),
            day: cairoDayKey(m.created_at),
            iso: m.created_at,
            message: m,
        })),
        ...props.detail.notes.map((n) => ({
            key: `n-${n.id}`,
            at: n.created_at ? Date.parse(n.created_at) : Date.now(),
            day: cairoDayKey(n.created_at),
            iso: n.created_at,
            note: n,
        })),
    ];
    return entries.sort((a, b) => a.at - b.at);
});

// Consecutive image-only messages from the same sender fold into one bubble (spec §1.5).
const groupedTimeline = computed(() => groupImageRuns(timeline.value));

const FIVE_MINUTES = 5 * 60 * 1000;

// A "run" is a block of consecutive bubbles from the same sender within 5 minutes of
// each other; the first bubble of a run gets the whatsapp tail / suite avatar.
function senderKey(entry: Entry): string {
    if (entry.note) return `note:${entry.note.user?.id ?? '_'}`;
    const m = entry.message;
    if (!m) return 'unknown';
    if (m.sender_type === 'system') return 'system';
    return `${m.direction}:${m.sender_type}:${m.user?.id ?? '_'}`;
}

// A folded image-run entry's own `.at` is its FIRST image's timestamp; the gap to the
// next bubble must be measured from the group's LAST message instead.
function runEndAt(entry: Entry & { group?: Message[] }): number {
    const last = entry.group?.[entry.group.length - 1];
    return last?.created_at ? Date.parse(last.created_at) : entry.at;
}

const runStarts = computed<boolean[]>(() =>
    groupedTimeline.value.map((entry, index) => {
        if (index === 0) return true;
        const previous = groupedTimeline.value[index - 1];
        if (senderKey(previous) !== senderKey(entry)) return true;
        return entry.at - runEndAt(previous) > FIVE_MINUTES;
    }),
);

function isIncomingCustomer(entry: Entry): boolean {
    return !!entry.message && entry.message.direction === 'in' && entry.message.sender_type === 'customer';
}

const lockedByOther = computed(() => (props.lockHolder && props.lockHolder.id !== props.meId ? props.lockHolder : null));
const mode = computed(() => props.detail.window.mode);

const retryKey = (m: Message) => m.client_key ?? m.id;

// Scroll: stick to the bottom unless the user scrolled up; keep position when older pages prepend.
const scroller = ref<HTMLElement | null>(null);
let pinned = true;
let firstKey: string | undefined;

function scrollToBottom(): void {
    const el = scroller.value;
    if (el) el.scrollTop = el.scrollHeight;
    firstKey = timeline.value[0]?.key;
}

function onScroll(): void {
    const el = scroller.value;
    if (!el) return;
    pinned = el.scrollHeight - el.scrollTop - el.clientHeight < 80;
    if (el.scrollTop < 40 && props.hasMore && !props.loadingOlder) emit('loadOlder');
}

watch(
    () => props.detail.conversation.id,
    () => {
        pinned = true;
        nextTick(scrollToBottom);
    },
);

watch(
    () => timeline.value.length,
    () => {
        const el = scroller.value;
        if (!el) return;
        const oldHeight = el.scrollHeight;
        const oldTop = el.scrollTop;
        const wasPinned = pinned;
        const previousFirst = firstKey;
        nextTick(() => {
            const newFirst = timeline.value[0]?.key;
            if (previousFirst !== undefined && newFirst !== previousFirst && !wasPinned) {
                el.scrollTop = oldTop + (el.scrollHeight - oldHeight);
            } else if (wasPinned) {
                el.scrollTop = el.scrollHeight;
            }
            firstKey = newFirst;
        });
    },
    { flush: 'pre' },
);

onMounted(scrollToBottom);

function onSend(body: string, attachments: Attachment[], quickReplyId: number | null): void {
    pinned = true;
    emit('send', body, attachments, quickReplyId);
}

const composer = ref<InstanceType<typeof Composer> | null>(null);
const header = ref<InstanceType<typeof ThreadHeader> | null>(null);
const composerMode = ref<ComposerMode>('reply');
const dragging = ref(false);
// No attachments while only templates are allowed (Composer isn't even rendered
// then), the window is fully closed, or the composer itself is in note mode
// (notes never take attachments).
const attachmentsAllowed = computed(() => mode.value !== 'template_only' && mode.value !== 'closed' && composerMode.value === 'reply');
let dragCounter = 0;

function hasFiles(event: DragEvent): boolean {
    return !!event.dataTransfer?.types.includes('Files');
}

function onDragEnter(event: DragEvent): void {
    if (!attachmentsAllowed.value || !hasFiles(event)) return;
    dragCounter++;
    dragging.value = true;
}

function onDragOver(event: DragEvent): void {
    // Only claim the drop (and show the "no drop" cursor otherwise) when we can
    // actually use it — a real file drag while attachments are allowed here.
    if (attachmentsAllowed.value && hasFiles(event)) event.preventDefault();
}

function onDragLeave(): void {
    if (!dragging.value) return;
    dragCounter = Math.max(0, dragCounter - 1);
    if (dragCounter === 0) dragging.value = false;
}

function onDrop(event: DragEvent): void {
    dragCounter = 0;
    dragging.value = false;
    if (!attachmentsAllowed.value) return;
    composer.value?.addFiles(Array.from(event.dataTransfer?.files ?? []));
}

defineExpose({ composer, header });
</script>

<template>
    <section
        class="relative flex min-h-0 flex-1 flex-col"
        @dragenter="onDragEnter"
        @dragover="onDragOver"
        @dragleave="onDragLeave"
        @drop.prevent="onDrop"
    >
        <div
            v-if="dragging"
            class="pointer-events-none absolute inset-0 z-20 flex items-center justify-center border-2 border-dashed border-primary bg-primary/5 text-sm font-medium text-primary"
        >
            {{ t('media.drop_here') }}
        </div>

        <ThreadHeader
            ref="header"
            :conversation="detail.conversation"
            :viewers="viewers"
            :me-id="meId"
            :typing="typing"
            :tags="tags"
            :busy-action="busyAction"
            :skin="skin"
            @back="emit('back')"
            @open-customer="emit('openCustomer')"
            @action="emit('action', $event)"
            @priority="emit('priority', $event)"
            @toggle-tag="emit('toggleTag', $event)"
            @claim="emit('claim')"
        />

        <div v-if="lockedByOther" role="status" class="flex items-center gap-2 border-b bg-warning/15 px-4 py-1.5 text-xs text-foreground">
            <PenLine class="size-3.5 shrink-0" aria-hidden="true" />{{ t('thread.replying', { name: lockedByOther.name }) }}
        </div>
        <div v-if="error" role="alert" class="flex items-center gap-2 border-b bg-destructive/10 px-4 py-1.5 text-xs text-destructive">
            <CircleAlert class="size-3.5 shrink-0" aria-hidden="true" />
            <span class="min-w-0 flex-1">{{ error }}</span>
            <button type="button" class="rounded p-0.5 hover:bg-destructive/20" :aria-label="t('common.close')" @click="emit('dismissError')">
                <X class="size-3.5" />
            </button>
        </div>
        <div v-if="flash" role="status" class="border-b bg-success/10 px-4 py-1.5 text-xs text-success">{{ flash }}</div>

        <div
            ref="scroller"
            role="log"
            class="scrollbar-thin min-h-0 flex-1 space-y-2 overflow-y-auto px-3 py-4 md:px-6"
            :class="skinClasses.wallpaper"
            @scroll.passive="onScroll"
        >
            <div v-if="hasMore || loadingOlder" class="flex justify-center">
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-full bg-card px-3 py-1 text-xs shadow-card text-muted-foreground hover:text-foreground"
                    :disabled="loadingOlder"
                    @click="emit('loadOlder')"
                >
                    <LoaderCircle v-if="loadingOlder" class="size-3 animate-spin" aria-hidden="true" />{{ t('thread.load_older') }}
                </button>
            </div>

            <EmptyState v-if="!timeline.length" :icon="MessageSquareDashed" :title="t('thread.empty')" />

            <template v-for="(entry, index) in groupedTimeline" :key="entry.key">
                <div v-if="index === 0 || groupedTimeline[index - 1].day !== entry.day" class="sticky top-0 z-[1] flex justify-center py-1">
                    <span class="rounded-md bg-card/90 px-2.5 py-0.5 text-2xs font-medium text-muted-foreground shadow-card">{{ formatDay(entry.iso, locale) }}</span>
                </div>
                <MessageBubble
                    :message="entry.message"
                    :note="entry.note"
                    :group="entry.group"
                    :platform-color="platform.color"
                    :retrying="entry.message ? retrying.includes(retryKey(entry.message)) : false"
                    :retrying-attachments="retryingAttachments"
                    :skin="skin"
                    :show-avatar="runStarts[index] && isIncomingCustomer(entry)"
                    :tail="runStarts[index]"
                    :customer-name="customerName"
                    :mentionable="mentionable"
                    @retry="emit('retry', $event)"
                    @retry-attachment="emit('retryAttachment', $event)"
                />
            </template>
        </div>

        <WindowBanner :mode="detail.window.mode" :expires-at="detail.window.expires_at" :now="now" @expired="emit('windowExpired')" />
        <TemplatePicker v-if="mode === 'template_only'" @send="emit('sendTemplate', $event)" />
        <Composer
            v-else
            ref="composer"
            v-model="draft"
            v-model:mode="composerMode"
            :disabled="mode === 'closed'"
            :lock-holder-name="lockedByOther?.name ?? null"
            :quick-replies="quickReplies"
            :categories="quickReplyCategories"
            :render-reply="renderReply"
            :platform="detail.conversation.platform"
            :conversation-id="detail.conversation.id"
            :mentionable="mentionable"
            :adding-note="addingNote"
            :me-id="meId"
            @send="onSend"
            @send-and-resolve="(...args) => emit('sendAndResolve', ...args)"
            @note="(...args) => emit('note', ...args)"
            @typing="emit('typing')"
        />
    </section>
</template>
