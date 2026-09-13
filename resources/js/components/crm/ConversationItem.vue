<script setup lang="ts">
import PlatformBadge from '@/components/crm/PlatformBadge.vue';
import { useI18n } from '@/composables/useI18n';
import { useInitials } from '@/composables/useInitials';
import { formatListStamp, formatSince } from '@/lib/format';
import type { Conversation } from '@/types/crm';
import { Bot, Clock, PenLine } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{ conversation: Conversation; active: boolean; now: number }>();
const emit = defineEmits<{ select: [id: number] }>();

const { t, locale } = useI18n();
const { getInitials } = useInitials();

const name = computed(() => props.conversation.customer?.name || `#${props.conversation.id}`);
const unread = computed(() => props.conversation.unread_count > 0);

const waitMinutes = computed(() =>
    props.conversation.waiting_since ? (props.now - Date.parse(props.conversation.waiting_since)) / 60000 : null,
);
const waitTone = computed(() => {
    if (waitMinutes.value === null) return '';
    if (waitMinutes.value >= 30) return 'bg-red-50 text-red-700';
    if (waitMinutes.value >= 10) return 'bg-amber-50 text-amber-800';
    return 'bg-muted text-muted-foreground';
});

const hasMeta = computed(() => {
    const c = props.conversation;
    return !!(c.waiting_since || c.needs_human || c.handler === 'bot' || c.locked_by || c.tags?.length || c.priority !== 'normal');
});

const tagStyle = (color: string | null) => ({
    backgroundColor: `${color && color.length === 7 ? color : '#64748b'}1a`,
    color: color || '#475569',
});
</script>

<template>
    <button
        type="button"
        :data-conversation-id="conversation.id"
        :aria-current="active ? 'true' : undefined"
        class="relative flex w-full gap-3 border-b px-3 py-2.5 text-start transition-colors hover:bg-muted/60 focus-visible:z-10 focus-visible:ring-inset"
        :class="active ? 'bg-primary/5 hover:bg-primary/5' : ''"
        @click="emit('select', conversation.id)"
    >
        <span v-if="active" class="absolute inset-y-0 start-0 w-0.5 bg-primary" aria-hidden="true" />

        <span
            class="relative mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full bg-slate-200 text-xs font-semibold text-slate-600 dark:bg-slate-700 dark:text-slate-200"
        >
            {{ getInitials(name) }}
            <span class="absolute -bottom-1 -end-1">
                <PlatformBadge :platform="conversation.platform" size="xs" />
            </span>
        </span>

        <span class="min-w-0 flex-1">
            <span class="flex items-center gap-2">
                <span class="truncate text-sm" :class="unread ? 'font-semibold text-foreground' : 'font-medium text-foreground/90'">{{ name }}</span>
                <span class="ms-auto shrink-0 text-2xs tabular-nums text-muted-foreground">
                    {{ formatListStamp(conversation.last_message_at, locale, now) }}
                </span>
            </span>

            <span class="mt-0.5 flex items-center gap-2">
                <span class="truncate text-xs" :class="unread ? 'text-foreground/80' : 'text-muted-foreground'" dir="auto">
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

            <span v-if="hasMeta" class="mt-1.5 flex flex-wrap items-center gap-1.5 text-2xs">
                <span v-if="conversation.priority === 'spam'" class="rounded bg-red-50 px-1.5 py-0.5 font-medium text-red-700">{{ t('inbox.filters.spam') }}</span>
                <span v-else-if="conversation.priority === 'low'" class="rounded bg-slate-100 px-1.5 py-0.5 font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ t('inbox.filters.low_priority') }}</span>
                <span v-if="conversation.waiting_since" class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 font-medium tabular-nums" :class="waitTone">
                    <Clock class="size-3" aria-hidden="true" />
                    {{ formatSince(conversation.waiting_since, locale, now) }}
                </span>
                <span v-if="conversation.needs_human" class="rounded bg-red-50 px-1.5 py-0.5 font-medium text-red-700">{{ t('thread.needs_human') }}</span>
                <span v-else-if="conversation.handler === 'bot'" class="inline-flex items-center gap-1 rounded bg-indigo-50 px-1.5 py-0.5 text-indigo-700">
                    <Bot class="size-3" aria-hidden="true" />{{ t('thread.handler_bot') }}
                </span>
                <span v-if="conversation.locked_by" class="inline-flex items-center gap-1 text-muted-foreground">
                    <PenLine class="size-3" aria-hidden="true" />{{ conversation.locked_by.name }}
                </span>
                <span v-for="tag in conversation.tags ?? []" :key="tag.id" class="rounded px-1.5 py-0.5" :style="tagStyle(tag.color)">{{ tag.name }}</span>
            </span>
        </span>
    </button>
</template>
