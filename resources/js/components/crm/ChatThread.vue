<script setup lang="ts">
import Composer from '@/components/crm/Composer.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import MediaLightbox from '@/components/crm/media/MediaLightbox.vue';
import MessageBubble from '@/components/crm/MessageBubble.vue';
import TemplatePicker from '@/components/crm/TemplatePicker.vue';
import NoteGroup from '@/components/crm/thread/NoteGroup.vue';
import NoteLine from '@/components/crm/thread/NoteLine.vue';
import ThreadHeader from '@/components/crm/ThreadHeader.vue';
import WindowBanner from '@/components/crm/WindowBanner.vue';
import { useAnchoredVirtualList } from '@/composables/inbox/useAnchoredVirtualList';
import { useChatSkin } from '@/composables/inbox/useChatSkin';
import type { ComposerMode } from '@/composables/inbox/useComposerShortcuts';
import { THREAD_GALLERY, useThreadGallery } from '@/composables/inbox/useThreadGallery';
import { useI18n } from '@/composables/useI18n';
import { useNow } from '@/composables/useNow';
import { usePlatform } from '@/composables/usePlatform';
import { mergeTimeline, type TimelineEntry } from '@/lib/chatTimeline';
import { formatDay } from '@/lib/format';
import type { ThreadView } from '@/lib/threadCache';
import type {
    Attachment,
    ConversationAction,
    ConversationDetail,
    ConversationPriority,
    Message,
    OutcomePayload,
    QuickReply,
    QuickReplyCategory,
    RenderedQuickReply,
    Tag,
    TemplatePayload,
    UserRef,
} from '@/types/crm';
import { CircleAlert, LoaderCircle, MessageSquareDashed, PenLine, X } from 'lucide-vue-next';
import { computed, provide, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
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
    /** Put the caret in the composer on open: a pointer click on the row, never j/k (Task 6 controller addition). */
    autofocus?: boolean;
    /** Where to scroll a chat reopened from the cache (Task 6c); null: the bottom. */
    restoreView?: ThreadView | null;
}>(),
    { autofocus: false, restoreView: null },
);

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
    resolve: [payload: OutcomePayload];
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

// Messages and internal notes interleaved by time, image runs and note runs folded, run starts
// marked (lib/chatTimeline). Rebuilt incrementally: a new message or an older page only regroups
// the rows at that end (Task 6c).
let previousTimeline: TimelineEntry[] = [];
// While older pages remain, a note older than the oldest loaded message waits for its page: shown
// now it would sit at the top above messages from another time (and pin the scroll anchor there).
const notesInView = computed(() => {
    const oldest = props.messages.find((m) => m.id > 0)?.created_at;
    if (!props.hasMore || !oldest) return props.detail.notes;
    const from = Date.parse(oldest);
    const shown = props.detail.notes.filter((n) => !n.created_at || Date.parse(n.created_at) >= from);
    return shown.length === props.detail.notes.length ? props.detail.notes : shown;
});
const timeline = computed<TimelineEntry[]>(() => {
    const next = mergeTimeline(previousTimeline, props.messages, notesInView.value);
    previousTimeline = next;
    return next;
});

// One gallery for the whole thread (Task 6b): every stored image / video, in timeline order.
const threadMessages = computed<Message[]>(() => timeline.value.flatMap((entry) => (entry.message ? [entry.message, ...(entry.group ?? [])] : [])));
const gallery = useThreadGallery(threadMessages);
provide(THREAD_GALLERY, gallery);
const { items: galleryItems, currentId: galleryId, opener: galleryOpener } = gallery;

/** One row of the virtual list: the «older» button, a day label, or a timeline entry. */
type Row = { kind: 'older'; key: string } | { kind: 'day'; key: string; iso: string | null } | { kind: 'entry'; key: string; entry: TimelineEntry };

const rows = computed<Row[]>(() => {
    const out: Row[] = [];
    if (props.hasMore || props.loadingOlder) out.push({ kind: 'older', key: '__older' });
    let day: string | null = null;
    for (const entry of timeline.value) {
        if (entry.day !== day) {
            day = entry.day;
            out.push({ kind: 'day', key: `d-${entry.day}`, iso: entry.iso });
        }
        out.push({ kind: 'entry', key: entry.key, entry });
    }
    return out;
});

/** Message id → its row (a message folded into an image run maps to the run's row). */
const rowOfMessage = computed(() => {
    const map = new Map<number, number>();
    rows.value.forEach((row, index) => {
        if (row.kind !== 'entry' || !row.entry.message) return;
        map.set(row.entry.message.id, index);
        row.entry.group?.forEach((m) => map.set(m.id, index));
    });
    return map;
});

function isIncomingCustomer(entry: TimelineEntry): boolean {
    return !!entry.message && entry.message.direction === 'in' && entry.message.sender_type === 'customer';
}

const lockedByOther = computed(() => (props.lockHolder && props.lockHolder.id !== props.meId ? props.lockHolder : null));
const mode = computed(() => props.detail.window.mode);

const retryKey = (m: Message) => m.client_key ?? m.id;

// The timeline is virtualised (spec §1.3): only the rows on screen (+ 6 above and below) are in
// the DOM. Bottom anchoring and prepend anchoring: composables/inbox/useAnchoredVirtualList.
const scroller = ref<HTMLElement | null>(null);
const list = useAnchoredVirtualList({
    scrollEl: scroller,
    rows,
    estimate: 72,
    overscan: 6,
    pinThreshold: 80,
    paddingStart: 16,
    paddingEnd: 8,
    // Only message / note rows anchor: a day row's key moves to the top of an older page of the same day.
    skipAnchor: (row) => row.kind !== 'entry',
});
const { items: virtualRows, totalSize, measure } = list;
const visibleRows = computed(() => virtualRows.value.flatMap((item) => (rows.value[item.index] ? [{ item, row: rows.value[item.index] }] : [])));
list.expect(props.restoreView);

// The floating day label: the day of the row at the top edge, once the list is scrolled.
const topDay = computed<string | null>(() => {
    const index = list.topIndex.value;
    // At the top the first day row is itself on screen (it sits after the «older» row, if any).
    const firstDay = rows.value.findIndex((row) => row.kind === 'day');
    if (index <= firstDay) return null;
    for (let i = index; i < rows.value.length; i++) {
        const row = rows.value[i];
        if (row.kind === 'entry') return formatDay(row.entry.iso, locale.value);
    }
    return null;
});

function onScroll(): void {
    list.track();
    const el = scroller.value;
    // Older pages load a little before the very top, so scrolling up rarely waits.
    if (el && el.scrollTop < 300 && props.hasMore && !props.loadingOlder) emit('loadOlder');
}

watch(
    () => props.detail.conversation.id,
    () => {
        galleryId.value = null;
        // A cached chat comes back where she left it; any other at the bottom.
        list.expect(props.restoreView);
    },
    { flush: 'pre' },
);

// The same chat asked to show a new view (a revalidation that replaced its list): apply it.
watch(
    () => props.restoreView,
    (view, before) => {
        if (view && view !== before) list.expect(view);
    },
    { flush: 'pre' },
);

/** Run `fn` with the element once the virtualiser has rendered it (a few frames at most). */
function whenRendered(find: () => HTMLElement | null | undefined, fn: (el: HTMLElement) => void, tries = 12): void {
    const el = find();
    if (el) fn(el);
    else if (tries > 0) requestAnimationFrame(() => whenRendered(find, fn, tries - 1));
}

/**
 * «روحي للرسالة»: close the gallery and bring the item's message into view. A message folded
 * into an image run has no bubble of its own, so its run's bubble (`data-group-ids`) stands in.
 */
function jumpToMessage(messageId: number): void {
    galleryId.value = null;
    const index = rowOfMessage.value.get(messageId);
    if (index === undefined) return;
    list.scrollToIndex(index, 'center');
    whenRendered(
        () =>
            scroller.value?.querySelector<HTMLElement>(`[data-message-id="${messageId}"]`) ??
            scroller.value?.querySelector<HTMLElement>(`[data-group-ids~="${messageId}"]`),
        (el) => el.animate([{ opacity: 0.55 }, { opacity: 1 }], { duration: 700, easing: 'cubic-bezier(0.16, 1, 0.3, 1)' }),
    );
}

const noteToggles = (root: Element) => Array.from(root.querySelectorAll<HTMLElement>('[data-note-toggle]')).filter((el) => el.offsetParent !== null);
const isNoteRow = (row: Row | undefined) => row?.kind === 'entry' && (!!row.entry.note || !!row.entry.notes);

/**
 * `[` / `]`: focus the previous / next note toggle (spec §1.2 keyboard), across the whole thread,
 * not only the rows in the DOM. With no toggle focused it starts from what is on screen: `]` the
 * first note row at the top edge or below, `[` the last one above the bottom edge. Toggles inside a
 * closed group are skipped.
 */
function moveNote(step: 1 | -1): void {
    const root = scroller.value;
    if (!root) return;
    const active = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    const rowEl = active?.closest<HTMLElement>('[data-index]');
    let from: number;
    if (active?.hasAttribute('data-note-toggle') && rowEl && root.contains(rowEl)) {
        const inRow = noteToggles(rowEl);
        const next = inRow[inRow.indexOf(active) + step];
        if (next) {
            next.focus({ preventScroll: true });
            next.scrollIntoView({ block: 'nearest' });
            return;
        }
        from = Number(rowEl.dataset.index) + step;
    } else {
        from = step === 1 ? list.topIndex.value : (list.indexAt(root.scrollTop + root.clientHeight - 1) ?? rows.value.length - 1);
    }
    for (let i = from; i >= 0 && i < rows.value.length; i += step) {
        if (!isNoteRow(rows.value[i])) continue;
        list.scrollToIndex(i, 'auto');
        whenRendered(
            () => {
                const el = root.querySelector<HTMLElement>(`[data-index="${i}"]`);
                const toggles = el ? noteToggles(el) : [];
                return step === 1 ? toggles[0] : toggles[toggles.length - 1];
            },
            (el) => el.focus({ preventScroll: true }),
        );
        return;
    }
}

function onSend(body: string, attachments: Attachment[], quickReplyId: number | null): void {
    list.pinned.value = true;
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

defineExpose({ composer, header, viewState: list.viewState, moveNote });
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
            :customer="detail.customer"
            :viewers="viewers"
            :me-id="meId"
            :typing="typing"
            :tags="tags"
            :busy-action="busyAction"
            :skin="skin"
            @back="emit('back')"
            @open-customer="emit('openCustomer')"
            @action="emit('action', $event)"
            @resolve="emit('resolve', $event)"
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

        <div class="relative flex min-h-0 flex-1 flex-col">
            <div
                ref="scroller"
                role="log"
                :data-thread-id="detail.conversation.id"
                class="scrollbar-thin min-h-0 flex-1 overflow-y-auto overflow-x-hidden overscroll-contain"
                :class="skinClasses.wallpaper"
                @scroll.passive="onScroll"
            >
                <EmptyState v-if="!timeline.length" class="py-8" :icon="MessageSquareDashed" :title="t('thread.empty')" />

                <div v-else class="relative w-full" :style="{ height: `${totalSize}px` }">
                    <div
                        v-for="{ item, row } in visibleRows"
                        :key="String(item.key)"
                        :ref="measure"
                        :data-index="item.index"
                        class="absolute inset-x-0 top-0 px-3 pb-2 md:px-6"
                        :style="{ transform: `translateY(${item.start}px)` }"
                    >
                        <div v-if="row.kind === 'older'" class="flex justify-center">
                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-full bg-card px-3 py-1 text-xs shadow-card text-muted-foreground hover:text-foreground"
                                :disabled="loadingOlder"
                                @click="emit('loadOlder')"
                            >
                                <LoaderCircle v-if="loadingOlder" class="size-3 animate-spin" aria-hidden="true" />{{ t('thread.load_older') }}
                            </button>
                        </div>
                        <div v-else-if="row.kind === 'day'" class="flex justify-center py-1">
                            <span class="rounded-md bg-card/90 px-2.5 py-0.5 text-2xs font-medium text-muted-foreground shadow-card">{{
                                formatDay(row.iso, locale)
                            }}</span>
                        </div>
                        <NoteGroup v-else-if="row.entry.notes" :notes="row.entry.notes" :mentionable="mentionable" />
                        <NoteLine v-else-if="row.entry.note" :note="row.entry.note" :mentionable="mentionable" />
                        <MessageBubble
                            v-else-if="row.entry.message"
                            :message="row.entry.message"
                            :group="row.entry.group"
                            :platform-color="platform.color"
                            :retrying="retrying.includes(retryKey(row.entry.message))"
                            :retrying-attachments="retryingAttachments"
                            :skin="skin"
                            :show-avatar="row.entry.runStart && isIncomingCustomer(row.entry)"
                            :tail="row.entry.runStart"
                            :customer-name="customerName"
                            @retry="emit('retry', $event)"
                            @retry-attachment="emit('retryAttachment', $event)"
                        />
                    </div>
                </div>
            </div>
            <!-- The day of the rows at the top edge (the day rows themselves scroll away). -->
            <div v-if="topDay" class="pointer-events-none absolute inset-x-0 top-2 z-[1] flex justify-center" aria-hidden="true">
                <span class="rounded-md bg-card/90 px-2.5 py-0.5 text-2xs font-medium text-muted-foreground shadow-card">{{ topDay }}</span>
            </div>
        </div>

        <MediaLightbox
            v-model:current-id="galleryId"
            :items="galleryItems"
            :opener="galleryOpener"
            can-jump
            @jump="jumpToMessage"
        />

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
            :autofocus="autofocus"
            @send="onSend"
            @send-and-resolve="(...args) => emit('sendAndResolve', ...args)"
            @note="(...args) => emit('note', ...args)"
            @typing="emit('typing')"
        />
    </section>
</template>
