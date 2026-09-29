<script setup lang="ts">
import PlatformBadge from '@/components/crm/PlatformBadge.vue';
import { buttonVariants } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { useMyQueueContext } from '@/composables/useMyQueue';
import { formatCount, formatSeconds } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { QueueEntry, QueuePriority } from '@/types/crm';
import { ArrowUpCircle, Clock, Coffee, FolderOpen, Hand, Hourglass, LoaderCircle, MessageCircleReply, Moon, Play, Star, type LucideIcon } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    /** The conversation open in the thread, so its window is marked. */
    selectedId: number | null;
    /** Unread customer messages per conversation id (from the list the inbox already holds). */
    unread: Record<number, number>;
}>();
const emit = defineEmits<{ select: [conversationId: number] }>();

const { t, locale } = useI18n();
const queue = useMyQueueContext();

/** The last minute before the auto-close gets the strongest colour. */
const LAST_SECONDS = 60;

const badges: Partial<Record<QueuePriority, { icon: LucideIcon; tone: string }>> = {
    returning: { icon: Star, tone: 'bg-warning/20 text-foreground' },
    escalation: { icon: ArrowUpCircle, tone: 'bg-destructive/10 text-destructive' },
    overnight: { icon: Moon, tone: 'bg-surface-accent text-primary' },
    manual: { icon: Hand, tone: 'bg-muted text-muted-foreground' },
};

interface Card {
    entry: QueueEntry;
    ticket: number;
    name: string;
    selected: boolean;
    unread: number;
    elapsed: string;
    silence: string | null;
    /** Time to the hand-off while she is late with her reply (flow revision §4). */
    handoff: string | null;
    tone: 'calm' | 'warning' | 'last' | 'overdue';
    badge: { icon: LucideIcon; tone: string; label: string } | null;
}

const cards = computed<Card[]>(() =>
    (queue?.entries.value ?? []).map((entry) => {
        const left = queue!.silenceLeft(entry);
        const handoff = queue!.handoffLeft(entry);
        const badge = badges[entry.priority];

        return {
            entry,
            ticket: entry.ticket % 100000,
            name: entry.customer?.name || t('queue.customer_fallback'),
            selected: entry.conversation_id === props.selectedId,
            unread: props.unread[entry.conversation_id] ?? 0,
            elapsed: formatSeconds(queue!.elapsed(entry), locale.value),
            silence: left === null ? null : formatSeconds(left, locale.value),
            handoff: handoff === null ? null : formatSeconds(handoff, locale.value),
            tone: entry.reply_overdue
                ? 'overdue'
                : left === null || !queue!.silenceWarning(entry)
                  ? 'calm'
                  : left <= LAST_SECONDS
                    ? 'last'
                    : 'warning',
            badge: badge ? { ...badge, label: t(`queue.priority.${entry.priority}`) } : null,
        };
    }),
);

const freeSlots = computed(() => Math.max(0, (queue?.cap.value ?? 0) - cards.value.length));
const status = computed(() => queue?.member.value?.status ?? null);
const busyStatus = computed(() => queue?.busy.value === 'status');

const statusDot = computed(() => {
    switch (status.value) {
        case 'available':
        case 'busy':
            return 'bg-success';
        case 'break':
        case 'pending_break':
            return 'bg-warning';
        default:
            return 'bg-muted-foreground/50';
    }
});

const statusLabel = computed(() => (status.value ? t(`queue.status.${status.value}`) : t('queue.status.off_shift')));
const breakLeft = computed(() =>
    queue?.breakLeft.value === null || queue?.breakLeft.value === undefined ? null : formatSeconds(queue.breakLeft.value, locale.value),
);
/** What the one status button does from here: off to a break, or back to work. */
const nextStatus = computed<'available' | 'break' | null>(() => {
    if (status.value === 'available' || status.value === 'busy') return 'break';
    if (status.value === 'break' || status.value === 'pending_break' || status.value === 'offline') return 'available';

    return null;
});
const nextLabel = computed(() => {
    if (nextStatus.value === 'break') return t('queue.go_break');

    return status.value === 'pending_break' ? t('queue.cancel_break') : t('queue.back_to_work');
});
const nextHint = computed(() =>
    nextStatus.value === 'break'
        ? t('queue.go_break_hint')
        : status.value === 'pending_break'
          ? t('queue.cancel_break')
          : t('queue.back_to_work_hint'),
);

const cardTone: Record<Card['tone'], string> = {
    calm: 'border-border bg-background hover:bg-elevated',
    warning: 'border-warning/60 bg-warning/10 hover:bg-warning/15',
    last: 'border-destructive/50 bg-destructive/10 hover:bg-destructive/15',
    overdue: 'border-orange-500/70 bg-orange-500/10 hover:bg-orange-500/15',
};
const silenceTone: Record<Card['tone'], string> = {
    calm: 'text-muted-foreground',
    warning: 'font-semibold text-foreground',
    last: 'font-semibold text-destructive',
    overdue: 'font-semibold text-orange-700 dark:text-orange-300',
};
</script>

<template>
    <section
        v-if="queue?.active.value"
        class="flex shrink-0 items-stretch gap-2 border-b bg-card px-3 py-2 sm:gap-3"
        :aria-label="t('queue.my_windows')"
        data-my-windows
    >
        <div class="hidden shrink-0 flex-col justify-center sm:flex">
            <h2 class="text-sm font-bold leading-5">{{ t('queue.my_windows') }}</h2>
            <p class="text-2xs tabular-nums text-muted-foreground">
                {{ t('queue.windows_count', { open: formatCount(cards.length, locale), cap: formatCount(queue.cap.value, locale) }) }}
            </p>
        </div>

        <ul class="scrollbar-thin -my-1 flex min-w-0 flex-1 items-stretch gap-2 overflow-x-auto py-1" role="list">
            <li v-for="card in cards" :key="card.entry.id" class="shrink-0">
                <button
                    type="button"
                    class="relative flex h-full w-52 flex-col gap-1 rounded-lg border px-2.5 py-1.5 text-start transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    :class="[cardTone[card.tone], card.selected ? 'ring-2 ring-primary' : '']"
                    :aria-label="t('queue.open_chat', { name: card.name, ticket: card.ticket, window: card.entry.window_no ?? '' })"
                    :aria-current="card.selected ? 'true' : undefined"
                    :data-window="card.entry.window_no"
                    @click="emit('select', card.entry.conversation_id)"
                >
                    <span class="flex items-center gap-1.5">
                        <span class="rounded bg-elevated px-1.5 text-2xs font-medium text-muted-foreground">{{
                            t('queue.window', { n: formatCount(card.entry.window_no, locale) })
                        }}</span>
                        <span class="text-sm font-bold tabular-nums" dir="ltr">#{{ formatCount(card.ticket, locale) }}</span>
                        <span
                            v-if="card.badge"
                            class="inline-flex h-4 items-center gap-0.5 rounded-full px-1.5 text-2xs font-medium"
                            :class="card.badge.tone"
                        >
                            <component :is="card.badge.icon" class="size-2.5" aria-hidden="true" />{{ card.badge.label }}
                        </span>
                        <span class="ms-auto flex items-center gap-1">
                            <span
                                v-if="card.unread > 0"
                                class="flex h-4 min-w-4 items-center justify-center rounded-full bg-primary px-1 text-2xs font-semibold text-primary-foreground"
                                :title="t('queue.unread')"
                            >
                                <span class="sr-only">{{ t('queue.unread') }}: </span>{{ formatCount(card.unread, locale) }}
                            </span>
                            <PlatformBadge :platform="card.entry.platform" size="xs" />
                        </span>
                    </span>

                    <span class="flex min-w-0 items-center gap-1">
                        <span class="truncate text-xs font-medium" dir="auto">{{ card.name }}</span>
                        <span
                            v-if="card.entry.open_case_id"
                            class="inline-flex h-4 shrink-0 items-center gap-0.5 rounded-full bg-primary/10 px-1.5 text-2xs font-medium text-primary"
                        >
                            <FolderOpen class="size-2.5" aria-hidden="true" />{{ t('queue.open_case', { id: card.entry.open_case_id }) }}
                        </span>
                    </span>

                    <span class="flex items-center gap-2 text-2xs tabular-nums">
                        <span class="inline-flex items-center gap-1 text-muted-foreground" :title="t('queue.timer')">
                            <Clock class="size-3" aria-hidden="true" /><span class="sr-only">{{ t('queue.timer') }}: </span>{{ card.elapsed }}
                        </span>
                        <span
                            v-if="card.tone === 'overdue'"
                            class="ms-auto inline-flex items-center gap-1"
                            :class="silenceTone.overdue"
                            :title="t('queue.handoff_left')"
                        >
                            <Hourglass class="size-3 motion-safe:animate-pulse" aria-hidden="true" />
                            <span class="sr-only">{{ t('queue.reply_overdue') }}: </span>{{ card.handoff ?? t('queue.reply_overdue') }}
                        </span>
                        <span
                            v-else-if="card.silence !== null"
                            class="ms-auto inline-flex items-center gap-1"
                            :class="silenceTone[card.tone]"
                            :title="t('queue.silence_left')"
                        >
                            <Hourglass class="size-3" :class="card.tone === 'last' ? 'motion-safe:animate-pulse' : ''" aria-hidden="true" />
                            <span class="sr-only">{{ t('queue.silence_left') }}: </span>{{ card.silence }}
                        </span>
                        <span v-else class="ms-auto inline-flex items-center gap-1 text-primary">
                            <MessageCircleReply class="size-3" aria-hidden="true" />{{ t('queue.waiting_reply') }}
                        </span>
                    </span>
                </button>
            </li>

            <li
                v-for="slot in freeSlots"
                :key="`free-${slot}`"
                class="flex w-36 shrink-0 flex-col items-center justify-center rounded-lg border border-dashed border-border px-2 py-1.5 text-center"
            >
                <span class="text-xs font-medium text-muted-foreground">{{ t('queue.free_window') }}</span>
                <span class="text-2xs text-muted-foreground/80">{{ t('queue.free_window_hint') }}</span>
            </li>
        </ul>

        <div class="flex shrink-0 flex-col items-end justify-center gap-1" role="group" :aria-label="t('queue.status.label')">
            <p class="flex items-center gap-1.5 text-xs font-medium">
                <span class="size-2 rounded-full" :class="statusDot" aria-hidden="true" />
                <!-- Announced when the status changes; the break countdown stays out of the live region (no reading every second). -->
                <span role="status">
                    <span class="hidden md:inline">{{ statusLabel }}</span>
                    <span class="sr-only md:hidden">{{ statusLabel }}</span>
                </span>
                <span v-if="breakLeft !== null" class="tabular-nums text-muted-foreground">{{ t('queue.break_left', { time: breakLeft }) }}</span>
            </p>
            <button
                v-if="nextStatus"
                type="button"
                :class="
                    cn(
                        buttonVariants({ variant: nextStatus === 'break' ? 'outline' : 'default', size: 'sm' }),
                        'h-7 gap-1 rounded-full px-2.5 text-xs',
                    )
                "
                :disabled="queue.busy.value !== null"
                :title="nextHint"
                :aria-label="nextLabel"
                @click="queue.setStatus(nextStatus)"
            >
                <LoaderCircle v-if="busyStatus" class="size-3.5 animate-spin" aria-hidden="true" />
                <Coffee v-else-if="nextStatus === 'break'" class="size-3.5" aria-hidden="true" />
                <Play v-else class="rtl-flip size-3.5" aria-hidden="true" />
                {{ nextLabel }}
            </button>
        </div>
    </section>
</template>
