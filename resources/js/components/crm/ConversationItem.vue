<script setup lang="ts">
import PlatformBadge from '@/components/crm/PlatformBadge.vue';
import RelativeTime from '@/components/crm/RelativeTime.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { useI18n } from '@/composables/useI18n';
import { useInitials } from '@/composables/useInitials';
import { useNow } from '@/composables/useNow';
import type { RowState } from '@/lib/conversationState';
import { formatAge, formatCount } from '@/lib/format';
import { waitingAge } from '@/lib/waitingAge';
import type { Conversation } from '@/types/crm';
import { computed, onBeforeUnmount } from 'vue';

/**
 * One inbox row, exactly 72 px (the list virtualises on that height): avatar + platform,
 * name · tag dots · time, then the one state badge · preview · unread pill (spec §1.2).
 */
const props = withDefaults(
    defineProps<{
        conversation: Conversation;
        state: RowState | null;
        active?: boolean;
        /** Control room S3 (G7): the first-reply target in seconds; past it the time becomes «مستنية ٧ د». */
        firstReplyTarget?: number | null;
    }>(),
    { active: false, firstReplyTarget: null },
);
/** `pointer`: opened by a mouse / touch click (the composer takes the focus), not by the keyboard. */
const emit = defineEmits<{ select: [id: number, pointer: boolean]; contextmenu: [id: number, event: MouseEvent]; intent: [id: number] }>();

// Intent to open (Task 6c): the pointer rests on the row for 150 ms, or the keyboard focuses it.
// The page prefetches that chat, so the click paints from the cache.
const INTENT_MS = 150;
let intentTimer: number | undefined;
function onPointerEnter(event: PointerEvent): void {
    if (event.pointerType === 'touch') return; // a tap opens at once; nothing to win
    window.clearTimeout(intentTimer);
    intentTimer = window.setTimeout(() => emit('intent', props.conversation.id), INTENT_MS);
}
function onPointerLeave(): void {
    window.clearTimeout(intentTimer);
}
onBeforeUnmount(() => window.clearTimeout(intentTimer));

const { t, locale } = useI18n();
const { getInitials } = useInitials();

const name = computed(() => props.conversation.customer?.name || `#${props.conversation.id}`);
const unread = computed(() => props.conversation.unread_count > 0);
// A moderator's reply is the preview: «إنتي: » in front (the bot's and the system's lines carry no prefix).
const ours = computed(() => !!props.conversation.last_message_preview && props.conversation.last_message_sender === 'user');

const tags = computed(() => props.conversation.tags ?? []);

const now = useNow();
/** Shown only once she waits past the target; before that the row keeps its time. */
const waiting = computed(() => {
    const age = waitingAge(props.conversation, now.value, props.firstReplyTarget);

    return age?.late ? age : null;
});
const tagTitle = computed(() => tags.value.map((tag) => tag.name).join('، '));
const dotColor = (color: string | null) => (color && /^#[0-9a-f]{6}$/i.test(color) ? color : '#64748b');
</script>

<template>
    <button
        type="button"
        :data-conversation-id="conversation.id"
        :aria-current="active ? 'true' : undefined"
        class="grid h-[72px] w-full grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-2.5 border-b border-line px-3 text-start outline-none transition-colors focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring"
        :class="active ? 'bg-brand-soft' : 'hover:bg-surface-2'"
        @click="emit('select', conversation.id, $event.detail > 0)"
        @pointerenter="onPointerEnter"
        @pointerleave="onPointerLeave"
        @focus="emit('intent', conversation.id)"
        @contextmenu.prevent="emit('contextmenu', conversation.id, $event)"
    >
        <span class="relative grid size-10 shrink-0 place-items-center rounded-full bg-brand-soft text-xs font-semibold text-brand-ink">
            {{ getInitials(name) }}
            <span class="absolute -bottom-0.5 -end-0.5 rounded-full ring-2 ring-card">
                <PlatformBadge :platform="conversation.platform" size="xs" />
            </span>
        </span>

        <span class="flex min-w-0 flex-col gap-1">
            <span class="flex min-w-0 items-center gap-1.5">
                <span class="min-w-0 truncate text-sm" :class="unread ? 'font-bold text-foreground' : 'font-medium text-foreground'" dir="auto">{{ name }}</span>
                <span v-if="tags.length" class="flex shrink-0 items-center gap-0.5" :title="tagTitle" role="img" :aria-label="tagTitle">
                    <span v-for="tag in tags.slice(0, 2)" :key="tag.id" class="size-2 rounded-full" :style="{ backgroundColor: dotColor(tag.color) }" />
                    <span v-if="tags.length > 2" class="text-2xs leading-none text-fg-3 tabular-nums">+{{ formatCount(tags.length - 2, locale) }}</span>
                </span>
                <span
                    v-if="conversation.is_load_test"
                    class="inline-flex h-5 shrink-0 items-center rounded-full bg-warn-soft px-2 text-2xs font-semibold text-warn"
                    data-load-test-chip
                >
                    {{ t('inbox.load_test_badge') }}
                </span>
            </span>
            <span class="min-w-0 truncate text-[12.5px]" :class="unread ? 'font-semibold text-foreground' : 'text-fg-2'">
                <span v-if="ours" class="text-fg-3">{{ t('inbox.you_prefix') }}</span>
                <span dir="auto">{{ conversation.last_message_preview || '—' }}</span>
            </span>
        </span>

        <span class="flex max-w-[9.5rem] shrink-0 flex-col items-end gap-1.5">
            <span
                v-if="waiting"
                class="inline-flex h-5 shrink-0 items-center rounded-full bg-warn-soft px-1.5 text-2xs font-semibold tabular-nums text-warn"
                data-waiting-age
                >{{ t('inbox.waiting_age', { time: formatAge(waiting.seconds, locale) }) }}</span
            >
            <RelativeTime
                v-else
                :iso="conversation.last_message_at"
                mode="stamp"
                class="shrink-0 text-[11.5px] tabular-nums"
                :class="unread ? 'font-semibold text-brand-ink' : 'text-fg-3'"
            />
            <span class="flex min-w-0 items-center gap-1">
                <StatusChip v-if="state?.key === 'test'" size="sm" :label="state.label" tone="brand" />
                <StatusChip v-else-if="state" size="sm" :label="state.label" :tone="state.tone" class="max-w-[7.5rem] truncate" />
                <span
                    v-if="unread"
                    class="flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full bg-brand px-1.5 text-2xs font-bold text-brand-on tabular-nums"
                    :aria-label="t('inbox.unread', { n: conversation.unread_count })"
                >
                    {{ formatCount(conversation.unread_count, locale) }}
                </span>
            </span>
        </span>
    </button>
</template>
