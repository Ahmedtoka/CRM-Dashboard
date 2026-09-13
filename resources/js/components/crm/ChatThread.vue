<script setup lang="ts">
import Composer from '@/components/crm/Composer.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import MessageBubble from '@/components/crm/MessageBubble.vue';
import TemplatePicker from '@/components/crm/TemplatePicker.vue';
import ThreadHeader from '@/components/crm/ThreadHeader.vue';
import WindowBanner from '@/components/crm/WindowBanner.vue';
import { useI18n } from '@/composables/useI18n';
import { useNow } from '@/composables/useNow';
import { usePlatform } from '@/composables/usePlatform';
import { cairoDayKey, formatDay } from '@/lib/format';
import type { ConversationAction, ConversationDetail, ConversationPriority, Message, Note, QuickReply, Tag, TemplatePayload, UserRef } from '@/types/crm';
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
    tags: Tag[];
    busyAction: string | null;
    retrying: Array<number | string>;
    error: string | null;
    flash: string | null;
}>();

const draft = defineModel<string>('draft', { required: true });

const emit = defineEmits<{
    back: [];
    openCustomer: [];
    loadOlder: [];
    send: [body: string];
    sendTemplate: [template: TemplatePayload];
    retry: [message: Message];
    typing: [];
    action: [name: ConversationAction];
    priority: [value: ConversationPriority];
    toggleTag: [id: number];
    windowExpired: [];
    dismissError: [];
}>();

const { t, locale } = useI18n();
const now = useNow();
const platform = usePlatform(() => props.detail.conversation.platform);

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

function onSend(body: string): void {
    pinned = true;
    emit('send', body);
}
</script>

<template>
    <section class="flex min-h-0 flex-1 flex-col">
        <ThreadHeader
            :conversation="detail.conversation"
            :viewers="viewers"
            :me-id="meId"
            :typing="typing"
            :tags="tags"
            :busy-action="busyAction"
            @back="emit('back')"
            @open-customer="emit('openCustomer')"
            @action="emit('action', $event)"
            @priority="emit('priority', $event)"
            @toggle-tag="emit('toggleTag', $event)"
        />

        <div v-if="lockedByOther" role="status" class="flex items-center gap-2 border-b border-amber-200 bg-amber-50 px-4 py-1.5 text-xs text-amber-900">
            <PenLine class="size-3.5 shrink-0" aria-hidden="true" />{{ t('thread.replying', { name: lockedByOther.name }) }}
        </div>
        <div v-if="error" role="alert" class="flex items-center gap-2 border-b border-red-200 bg-red-50 px-4 py-1.5 text-xs text-red-700">
            <CircleAlert class="size-3.5 shrink-0" aria-hidden="true" />
            <span class="min-w-0 flex-1">{{ error }}</span>
            <button type="button" class="rounded p-0.5 hover:bg-red-100" :aria-label="t('common.close')" @click="emit('dismissError')">
                <X class="size-3.5" />
            </button>
        </div>
        <div v-if="flash" role="status" class="border-b border-emerald-200 bg-emerald-50 px-4 py-1.5 text-xs text-emerald-800">{{ flash }}</div>

        <div ref="scroller" role="log" class="scrollbar-thin min-h-0 flex-1 space-y-2 overflow-y-auto px-3 py-4 md:px-6" @scroll.passive="onScroll">
            <div v-if="hasMore || loadingOlder" class="flex justify-center">
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-full border bg-card px-3 py-1 text-xs text-muted-foreground hover:text-foreground"
                    :disabled="loadingOlder"
                    @click="emit('loadOlder')"
                >
                    <LoaderCircle v-if="loadingOlder" class="size-3 animate-spin" aria-hidden="true" />{{ t('thread.load_older') }}
                </button>
            </div>

            <EmptyState v-if="!timeline.length" :icon="MessageSquareDashed" :title="t('thread.empty')" />

            <template v-for="(entry, index) in timeline" :key="entry.key">
                <div v-if="index === 0 || timeline[index - 1].day !== entry.day" class="sticky top-0 z-[1] flex justify-center py-1">
                    <span class="rounded-full border bg-card/95 px-2.5 py-0.5 text-2xs text-muted-foreground shadow-sm">{{ formatDay(entry.iso, locale) }}</span>
                </div>
                <MessageBubble
                    :message="entry.message"
                    :note="entry.note"
                    :platform-color="platform.color"
                    :retrying="entry.message ? retrying.includes(retryKey(entry.message)) : false"
                    @retry="emit('retry', $event)"
                />
            </template>
        </div>

        <WindowBanner :mode="detail.window.mode" :expires-at="detail.window.expires_at" :now="now" @expired="emit('windowExpired')" />
        <TemplatePicker v-if="mode === 'template_only'" @send="emit('sendTemplate', $event)" />
        <Composer
            v-else
            v-model="draft"
            :disabled="mode === 'closed'"
            :lock-holder-name="lockedByOther?.name ?? null"
            :quick-replies="quickReplies"
            :platform="detail.conversation.platform"
            @send="onSend"
            @typing="emit('typing')"
        />
    </section>
</template>
