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
        class="flex h-[72px] w-full items-center gap-3 px-3 text-start outline-none transition-colors focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring"
        :class="active ? 'bg-surface-accent' : 'hover:bg-muted'"
        @click="emit('select', conversation.id, $event.detail > 0)"
        @pointerenter="onPointerEnter"
        @pointerleave="onPointerLeave"
        @focus="emit('intent', conversation.id)"
        @contextmenu.prevent="emit('contextmenu', conversation.id, $event)"
    >
        <span class="relative flex size-10 shrink-0 items-center justify-center rounded-full bg-elevated text-xs font-semibold text-muted-foreground">
            {{ getInitials(name) }}
            <span class="absolute -bottom-1 -end-1">
                <PlatformBadge :platform="conversation.platform" size="xs" />
            </span>
        </span>

        <span class="flex min-w-0 flex-1 flex-col gap-1">
            <span class="flex min-w-0 items-center gap-1.5">
                <span class="min-w-0 truncate text-sm" :class="unread ? 'font-bold text-foreground' : 'font-medium text-foreground/90'" dir="auto">{{ name }}</span>
                <span v-if="tags.length" class="flex shrink-0 items-center gap-0.5" :title="tagTitle" role="img" :aria-label="tagTitle">
                    <span v-for="tag in tags.slice(0, 2)" :key="tag.id" class="size-2 rounded-full" :style="{ backgroundColor: dotColor(tag.color) }" />
                    <span v-if="tags.length > 2" class="text-2xs leading-none text-muted-foreground tabular-nums">+{{ formatCount(tags.length - 2, locale) }}</span>
                </span>
                <span
                    v-if="waiting"
                    class="ms-auto shrink-0 rounded-full bg-amber-500/15 px-1.5 text-2xs font-semibold tabular-nums text-amber-800 dark:text-amber-200"
                    data-waiting-age
                    >{{ t('inbox.waiting_age', { time: formatAge(waiting.seconds, locale) }) }}</span
                >
                <RelativeTime
                    v-else
                    :iso="conversation.last_message_at"
                    mode="stamp"
                    class="ms-auto shrink-0 text-2xs"
                    :class="unread ? 'font-semibold text-primary' : 'text-muted-foreground'"
                />
            </span>

            <span class="flex min-w-0 items-center gap-1.5">
                <span
                    v-if="state?.key === 'test'"
                    class="inline-flex h-5 shrink-0 items-center rounded-full bg-violet-500/12 px-2 text-2xs font-medium text-violet-700 dark:bg-violet-400/20 dark:text-violet-200"
                >
                    {{ state.label }}
                </span>
                <StatusChip v-else-if="state" :label="state.label" :tone="state.tone" />
                <span
                    v-if="conversation.is_load_test"
                    class="inline-flex h-5 shrink-0 items-center rounded-full bg-orange-500/15 px-2 text-2xs font-semibold text-orange-800 dark:bg-orange-400/20 dark:text-orange-200"
                    data-load-test-chip
                >
                    {{ t('inbox.load_test_badge') }}
                </span>
                <span class="min-w-0 flex-1 truncate text-xs" :class="unread ? 'font-semibold text-foreground' : 'text-muted-foreground'">
                    <span v-if="ours" class="text-muted-foreground">{{ t('inbox.you_prefix') }}</span>
                    <span dir="auto">{{ conversation.last_message_preview || '—' }}</span>
                </span>
                <span
                    v-if="unread"
                    class="flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full bg-primary px-1.5 text-2xs font-semibold text-primary-foreground tabular-nums"
                    :aria-label="t('inbox.unread', { n: conversation.unread_count })"
                >
                    {{ formatCount(conversation.unread_count, locale) }}
                </span>
            </span>
        </span>
    </button>
</template>
