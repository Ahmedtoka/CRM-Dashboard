<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { useMyQueueContext } from '@/composables/useMyQueue';
import { formatCount, formatSeconds } from '@/lib/format';
import type { Conversation, QueuePriority } from '@/types/crm';
import { ArrowUpCircle, Bot, FolderOpen, Hand, Hourglass, Moon, Star, Ticket, type LucideIcon } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{ conversation: Conversation; meId: number }>();

const { t, locale } = useI18n();
const queue = useMyQueueContext();

const LAST_SECONDS = 60;

const badges: Partial<Record<QueuePriority, { icon: LucideIcon; tone: string }>> = {
    returning: { icon: Star, tone: 'bg-warning/20 text-foreground' },
    escalation: { icon: ArrowUpCircle, tone: 'bg-destructive/10 text-destructive' },
    overnight: { icon: Moon, tone: 'bg-card text-primary' },
    manual: { icon: Hand, tone: 'bg-card text-muted-foreground' },
};

const entry = computed(() => (queue?.enabled.value ? props.conversation.queue_entry : null));
/** Her open support case: on the ticket, else on the conversation (she can hold a case before a ticket is called). */
const caseId = computed(() => (queue?.enabled.value ? (props.conversation.open_case_id ?? entry.value?.open_case_id ?? null) : null));
const mine = computed(() => entry.value?.assigned_user_id === props.meId);
/** Her own window as the strip knows it: that is where the silence clock lives. */
const myWindow = computed(() => (mine.value ? (queue?.entryOf(props.conversation.id) ?? null) : null));
const badge = computed(() => (entry.value ? (badges[entry.value.priority] ?? null) : null));

const since = computed(() => (mine.value && entry.value ? formatSeconds(queue!.elapsed(entry.value), locale.value) : null));
const silenceLeft = computed(() => (myWindow.value ? queue!.silenceLeft(myWindow.value) : null));
/**
 * What a screen reader hears: only when the countdown crosses into its warning stretch or its
 * last minute, never the seconds ticking (the countdown itself is outside the live region).
 */
const announcement = computed(() => {
    if (!myWindow.value || silenceLeft.value === null || !queue!.silenceWarning(myWindow.value)) return '';

    return silenceLeft.value <= LAST_SECONDS ? t('queue.announce.last') : t('queue.announce.warning');
});
const silenceTone = computed(() => {
    if (!myWindow.value || silenceLeft.value === null || !queue!.silenceWarning(myWindow.value)) return 'text-muted-foreground';

    return silenceLeft.value <= LAST_SECONDS ? 'font-semibold text-destructive' : 'rounded-full bg-warning/20 px-1.5 font-semibold text-foreground';
});

const text = (value: unknown): string => (typeof value === 'string' || typeof value === 'number' ? String(value).trim() : '');

/** What the bot already learned: topic, order, reason; its full lines on hover. */
const summary = computed(() => {
    const s = entry.value?.bot_summary ?? null;
    if (!s) return { chips: [] as string[], lines: '' };
    const order = text(s.order_number);

    return {
        chips: [text(s.topic), order ? t('queue.banner.order', { number: order }) : '', text(s.reason)].filter(Boolean),
        lines: Array.isArray(s.lines) ? s.lines.map(text).filter(Boolean).join('\n') : '',
    };
});
</script>

<template>
    <section
        v-if="entry || caseId"
        class="scrollbar-thin flex items-center gap-2 overflow-x-auto whitespace-nowrap border-b bg-surface-accent px-4 py-1.5 text-xs"
        :aria-label="t('queue.banner.label')"
        data-queue-banner
    >
        <template v-if="entry">
            <span class="inline-flex shrink-0 items-center gap-1 font-bold text-primary">
                <Ticket class="size-3.5" aria-hidden="true" />
                <span class="tabular-nums">{{ t('queue.ticket', { n: formatCount(entry.ticket % 100000, locale) }) }}</span>
            </span>
            <span v-if="entry.window_no !== null" class="shrink-0 rounded bg-card px-1.5 text-2xs font-medium text-foreground">
                {{ t('queue.banner.window', { n: formatCount(entry.window_no, locale) }) }}
            </span>
            <span v-if="badge" class="inline-flex h-4 shrink-0 items-center gap-0.5 rounded-full px-1.5 text-2xs font-medium" :class="badge.tone">
                <component :is="badge.icon" class="size-2.5" aria-hidden="true" />{{ t(`queue.priority.${entry.priority}`) }}
            </span>
        </template>
        <span v-if="caseId" class="inline-flex h-4 shrink-0 items-center gap-0.5 rounded-full bg-card px-1.5 text-2xs font-medium text-primary">
            <FolderOpen class="size-2.5" aria-hidden="true" />{{ t('queue.open_case', { id: caseId }) }}
        </span>

        <span v-if="since !== null" class="shrink-0 tabular-nums text-muted-foreground">{{ t('queue.banner.received_since', { time: since }) }}</span>
        <span v-else-if="conversation.assignee?.name" class="shrink-0 text-muted-foreground" dir="auto">{{
            t('queue.banner.with', { name: conversation.assignee.name })
        }}</span>

        <span v-if="summary.chips.length" class="flex min-w-0 shrink items-center gap-1" :title="summary.lines || undefined">
            <Bot class="size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
            <span class="sr-only">{{ t('queue.banner.bot_summary') }}: </span>
            <span
                v-for="chip in summary.chips"
                :key="chip"
                class="max-w-[14rem] truncate rounded-full border border-border bg-card px-2 text-2xs text-foreground"
                dir="auto"
                >{{ chip }}</span
            >
        </span>

        <span v-if="silenceLeft !== null" class="ms-auto inline-flex shrink-0 items-center gap-1 tabular-nums" :class="silenceTone">
            <Hourglass class="size-3.5" aria-hidden="true" />{{ t('queue.silence_left') }} {{ formatSeconds(silenceLeft, locale) }}
        </span>
        <span class="sr-only" role="status">{{ announcement }}</span>
    </section>
</template>
