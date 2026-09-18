<script setup lang="ts">
import HandlerAvatar from '@/components/crm/HandlerAvatar.vue';
import PlatformBadge from '@/components/crm/PlatformBadge.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { useI18n } from '@/composables/useI18n';
import { useInitials } from '@/composables/useInitials';
import { formatListStamp, formatSince } from '@/lib/format';
import type { Conversation } from '@/types/crm';
import { computed } from 'vue';

const props = defineProps<{ conversation: Conversation; active: boolean; now: number }>();
const emit = defineEmits<{ select: [id: number]; contextmenu: [id: number, event: MouseEvent] }>();

const { t, locale } = useI18n();
const { getInitials } = useInitials();

const name = computed(() => props.conversation.customer?.name || `#${props.conversation.id}`);
const unread = computed(() => props.conversation.unread_count > 0);

const waitMinutes = computed(() =>
    props.conversation.waiting_since ? (props.now - Date.parse(props.conversation.waiting_since)) / 60000 : null,
);
const waitTone = computed<'negative' | 'warning' | 'neutral'>(() => {
    if (waitMinutes.value === null) return 'neutral';
    if (waitMinutes.value >= 30) return 'negative';
    if (waitMinutes.value >= 10) return 'warning';
    return 'neutral';
});

const hasMeta = computed(() => {
    const c = props.conversation;
    return !!(c.waiting_since || c.needs_human || c.handler === 'bot' || c.handling || c.tags?.length || c.priority !== 'normal' || c.handover_category_label);
});

// Handover priority badge (Task 5 ruling 5): high = urgent, medium = warning, low = no badge.
const priorityBadge = computed(() => {
    const level = props.conversation.priority_level;
    if (level === 'high') return { label: t('inbox.priority_level.high'), tone: 'negative' as const };
    if (level === 'medium') return { label: t('inbox.priority_level.medium'), tone: 'warning' as const };
    return null;
});

// 15% alpha ("26" hex suffix, i.e. 0x26 = 38/255 ≈ 15%) tag background.
const tagStyle = (color: string | null) => ({
    backgroundColor: `${color && color.length === 7 ? color : '#64748b'}26`,
    color: color || '#475569',
});
</script>

<template>
    <button
        type="button"
        :data-conversation-id="conversation.id"
        :aria-current="active ? 'true' : undefined"
        class="relative mx-1.5 my-0.5 flex w-[calc(100%-0.75rem)] gap-3 rounded-lg px-3 py-2 text-start transition-colors hover:bg-muted focus-visible:z-10 focus-visible:ring-inset"
        :class="active ? 'bg-surface-accent hover:bg-surface-accent' : ''"
        @click="emit('select', conversation.id)"
        @contextmenu.prevent="emit('contextmenu', conversation.id, $event)"
    >
        <span class="relative mt-0.5 flex size-11 shrink-0 items-center justify-center rounded-full bg-elevated text-xs font-semibold text-muted-foreground">
            {{ getInitials(name) }}
            <span class="absolute -bottom-1 -end-1">
                <PlatformBadge :platform="conversation.platform" size="xs" />
            </span>
        </span>

        <span class="min-w-0 flex-1">
            <span class="flex items-center gap-1.5">
                <span class="truncate text-sm" :class="unread ? 'font-bold text-foreground' : 'font-medium text-foreground/90'">{{ name }}</span>
                <span v-if="unread" class="size-2.5 shrink-0 rounded-full bg-primary" aria-hidden="true" />
                <span class="ms-auto shrink-0 text-2xs tabular-nums" :class="unread ? 'font-semibold text-primary' : 'text-muted-foreground'">
                    {{ formatListStamp(conversation.last_message_at, locale, now) }}
                </span>
            </span>

            <span class="mt-0.5 flex items-center gap-2">
                <span class="truncate text-xs" :class="unread ? 'font-bold text-foreground' : 'text-muted-foreground'" dir="auto">
                    {{ conversation.last_message_preview || '—' }}
                </span>
                <span
                    v-if="unread"
                    class="ms-auto flex h-4 min-w-4 shrink-0 items-center justify-center rounded-full bg-primary px-1 text-2xs font-semibold text-primary-foreground"
                    :aria-label="t('inbox.unread', { n: conversation.unread_count })"
                >
                    {{ conversation.unread_count }}
                </span>
            </span>

            <span v-if="hasMeta" class="mt-1.5 flex flex-wrap items-center gap-1.5">
                <StatusChip v-if="conversation.priority === 'spam'" :label="t('inbox.filters.spam')" tone="negative" />
                <StatusChip v-else-if="conversation.priority === 'low'" :label="t('inbox.filters.low_priority')" tone="neutral" />
                <StatusChip v-if="conversation.waiting_since" :label="formatSince(conversation.waiting_since, locale, now)" :tone="waitTone" />
                <StatusChip v-if="priorityBadge" :label="priorityBadge.label" :tone="priorityBadge.tone" />
                <StatusChip v-if="conversation.needs_human" :label="t('thread.needs_human')" tone="negative" />
                <StatusChip v-else-if="conversation.handler === 'bot'" :label="t('thread.handler_bot')" tone="info" />
                <span
                    v-if="conversation.handover_category_label"
                    class="inline-flex h-5 min-w-0 max-w-[9rem] items-center rounded-full border border-border px-2 text-2xs text-muted-foreground"
                    :title="t('inbox.category', { label: conversation.handover_category_label })"
                    dir="auto"
                >
                    <span class="truncate">{{ conversation.handover_category_label }}</span>
                </span>
                <HandlerAvatar :handling="conversation.handling" size="xs" />
                <span
                    v-for="tag in conversation.tags ?? []"
                    :key="tag.id"
                    class="rounded-full px-2 py-0.5 text-2xs"
                    :style="tagStyle(tag.color)"
                >
                    {{ tag.name }}
                </span>
            </span>
        </span>
    </button>
</template>
