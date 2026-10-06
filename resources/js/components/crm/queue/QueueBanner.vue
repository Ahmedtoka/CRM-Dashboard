<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { useMyQueueContext } from '@/composables/useMyQueue';
import { formatCount, formatSeconds } from '@/lib/format';
import { queueReason } from '@/lib/queueReason';
import type { Conversation, QueuePriority } from '@/types/crm';
import { ArrowUpCircle, Bot, FolderOpen, Hand, Hourglass, Moon, Star, Ticket, type LucideIcon } from 'lucide-vue-next';
import { computed, inject, ref, watch } from 'vue';

/**
 * The queue's part of the thread header (spec §1.2): `chips` renders inline in header row 2
 * (ticket, window, queue priority, open case, the bot's summary, since / with, the countdown
 * text); `bar` is the 2 px countdown line under row 2. With the queue off neither renders.
 */
const props = withDefaults(defineProps<{ conversation: Conversation; meId: number; part?: 'chips' | 'bar' }>(), { part: 'chips' });

const { t, locale } = useI18n();
const queue = useMyQueueContext();
/** The inbox's details panel: the bot-summary chips open it (the full summary lives there, C 2.1). */
const details = inject<{ toggle: () => void } | null>('inboxDetails', null);

const LAST_SECONDS = 60;
/** The first hand-off value seen per ticket: the bar's full width (the payload has no total). */
const handoffSpan = ref(new Map<number, number>());

const chip = 'inline-flex h-5 shrink-0 items-center gap-1 whitespace-nowrap rounded-full px-2 text-2xs font-medium';
const badges: Partial<Record<QueuePriority, { icon: LucideIcon; tone: string }>> = {
    returning: { icon: Star, tone: 'bg-warning/20 text-amber-900 dark:bg-warning/25 dark:text-amber-100' },
    escalation: { icon: ArrowUpCircle, tone: 'bg-destructive/10 text-destructive dark:bg-destructive/25 dark:text-red-200' },
    overnight: { icon: Moon, tone: 'bg-info/10 text-blue-800 dark:bg-info/25 dark:text-blue-100' },
    manual: { icon: Hand, tone: 'bg-muted text-muted-foreground' },
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
const handoffLeft = computed(() => (myWindow.value?.reply_overdue ? queue!.handoffLeft(myWindow.value) : null));
// Remember the largest hand-off value seen per ticket (the bar's full width), outside the computed.
watch(
    handoffLeft,
    (left) => {
        const id = myWindow.value?.id;
        if (id === undefined || left === null || left <= (handoffSpan.value.get(id) ?? 0)) return;
        handoffSpan.value.set(id, left);
    },
    { immediate: true },
);

type Tone = 'calm' | 'warning' | 'last' | 'overdue';
const tone = computed<Tone>(() => {
    if (myWindow.value?.reply_overdue) return 'overdue';
    if (!myWindow.value || silenceLeft.value === null || !queue!.silenceWarning(myWindow.value)) return 'calm';

    return silenceLeft.value <= LAST_SECONDS ? 'last' : 'warning';
});

/**
 * What a screen reader hears: only when the countdown crosses into its warning stretch or its
 * last minute, never the seconds ticking (the countdown itself is outside the live region).
 */
const announcement = computed(() => {
    if (tone.value === 'warning') return t('queue.announce.warning');
    if (tone.value === 'last') return t('queue.announce.last');

    return '';
});

/** The countdown chip: the hand-off while she is late with her reply, else the silence auto-close. */
const countdown = computed(() => {
    if (tone.value === 'overdue') {
        return { label: t('queue.handoff_left'), time: handoffLeft.value === null ? null : formatSeconds(handoffLeft.value, locale.value) };
    }
    if (silenceLeft.value === null) return null;

    return { label: t('queue.silence_left'), time: formatSeconds(silenceLeft.value, locale.value) };
});
const countdownTone: Record<Tone, string> = {
    calm: 'bg-muted text-muted-foreground',
    warning: 'bg-warning/20 text-amber-900 dark:bg-warning/25 dark:text-amber-100',
    last: 'bg-destructive/10 text-destructive dark:bg-destructive/25 dark:text-red-200',
    overdue: 'bg-overdue/15 text-orange-800 dark:bg-overdue/25 dark:text-orange-100',
};

/** The 2 px bar: share of the clock still left (silence: of `silence_close_seconds`; hand-off: of its first value). */
const barShare = computed<number | null>(() => {
    const w = myWindow.value;
    if (!w) return null;
    if (tone.value === 'overdue') {
        const left = handoffLeft.value;
        if (left === null) return 1;
        return left / Math.max(handoffSpan.value.get(w.id) ?? 0, left, 1);
    }
    const left = silenceLeft.value;
    const total = queue?.silenceTotal.value ?? null;
    if (left === null || !total) return null;

    return Math.min(1, Math.max(0, left / total));
});
const barTone: Record<Tone, string> = { calm: 'bg-primary/60', warning: 'bg-warning', last: 'bg-destructive', overdue: 'bg-overdue' };

const text = (value: unknown): string => (typeof value === 'string' || typeof value === 'number' ? String(value).trim() : '');

/** What the bot already learned: topic, order, reason (translated, unknown reasons hidden); its full lines in the details panel, one click away. */
const summary = computed(() => {
    const s = entry.value?.bot_summary ?? null;
    if (!s) return { chips: [] as string[] };
    const order = text(s.order_number);
    const topic = text(s.topic);

    return {
        chips: [
            topic && topic !== text(props.conversation.handover_topic) ? topic : '',
            order ? t('queue.banner.order_chip', { number: order }) : '',
            queueReason(s.reason, t) ?? '',
        ].filter(Boolean),
    };
});
</script>

<template>
    <template v-if="part === 'chips'">
        <template v-if="entry">
            <span :class="[chip, 'bg-primary/10 font-semibold text-primary']" data-queue-ticket>
                <Ticket class="size-3" aria-hidden="true" />
                <span class="tabular-nums">{{ t('queue.ticket', { n: formatCount(entry.ticket % 100000, locale) }) }}</span>
            </span>
            <span v-if="entry.window_no !== null" :class="[chip, 'bg-muted text-foreground']">
                {{ t('queue.banner.window', { n: formatCount(entry.window_no, locale) }) }}
            </span>
            <span v-if="badge" :class="[chip, badge.tone]">
                <component :is="badge.icon" class="size-3" aria-hidden="true" />{{ t(`queue.priority.${entry.priority}`) }}
            </span>
        </template>
        <span v-if="caseId" :class="[chip, 'bg-primary/10 text-primary']" :title="t('queue.open_case', { id: caseId })">
            <FolderOpen class="size-3" aria-hidden="true" />{{ t('queue.banner.case_chip', { id: caseId }) }}
        </span>
        <button
            v-if="summary.chips.length"
            type="button"
            class="inline-flex shrink-0 items-center gap-1 rounded-full focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            :aria-label="`${t('inbox.bot_summary.title')}: ${summary.chips.join('، ')}`"
            data-bot-summary
            @click="details?.toggle()"
        >
            <Bot class="size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
            <span class="sr-only">{{ t('queue.banner.bot_summary') }}: </span>
            <span
                v-for="item in summary.chips"
                :key="item"
                :class="[chip, 'max-w-[14rem] border border-border bg-card font-normal text-foreground']"
                dir="auto"
            >
                <span class="truncate">{{ item }}</span>
            </span>
        </button>
        <span v-if="since !== null" class="shrink-0 text-2xs tabular-nums text-muted-foreground">{{
            t('queue.banner.received_since', { time: since })
        }}</span>
        <span v-if="countdown" :class="[chip, 'tabular-nums', countdownTone[tone]]" data-countdown>
            <Hourglass class="size-3" :class="tone === 'last' || tone === 'overdue' ? 'motion-safe:animate-pulse' : ''" aria-hidden="true" />
            {{ countdown.time === null ? t('queue.reply_overdue') : `${countdown.label} ${countdown.time}` }}
        </span>
        <span class="sr-only" role="status">{{ announcement }}</span>
    </template>
    <div v-else-if="barShare !== null" class="h-0.5 w-full bg-muted" aria-hidden="true" data-countdown-bar>
        <div
            class="h-full transition-[width] duration-1000 ease-linear motion-reduce:transition-none"
            :class="barTone[tone]"
            :style="{ width: `${barShare * 100}%` }"
        />
    </div>
</template>
