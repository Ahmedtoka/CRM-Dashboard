<script setup lang="ts">
import PlatformBadge from '@/components/crm/PlatformBadge.vue';
import { Button, buttonVariants } from '@/components/ui/button';
import { Popover, PopoverContent } from '@/components/ui/popover';
import { useI18n } from '@/composables/useI18n';
import { useMyQueueContext } from '@/composables/useMyQueue';
import { formatClock, formatCount, formatSeconds } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { QueueEntry, QueuePriority } from '@/types/crm';
import {
    ArrowUpCircle,
    Coffee,
    FolderOpen,
    Hand,
    Hourglass,
    LogIn,
    LogOut,
    MessageCircleReply,
    Moon,
    Play,
    Star,
    Undo2,
    type LucideIcon,
} from 'lucide-vue-next';
import { PopoverAnchor } from 'radix-vue';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    /** The conversation open in the thread, so its window is marked. */
    selectedId: number | null;
    /** Unread customer messages per conversation id (from the list the inbox already holds). */
    unread: Record<number, number>;
}>();
const emit = defineEmits<{ select: [conversationId: number, pointer: boolean] }>();

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
    /** The first name only: the card is one compact line. */
    first: string;
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
            first: (entry.customer?.name || t('queue.customer_fallback')).trim().split(/\s+/)[0],
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

/** No new chats while she is not checked in or is on her way out: no free window to show then. */
const freeSlots = computed(() => {
    const s = queue?.member.value?.status;
    if (!s || s === 'checking_out') return 0;

    return Math.max(0, (queue?.cap.value ?? 0) - cards.value.length);
});
const status = computed(() => queue?.member.value?.status ?? null);

const statusDot = computed(() => {
    switch (status.value) {
        case 'available':
        case 'busy':
            return 'bg-success';
        case 'break':
            return queue?.breakOver.value ? 'bg-destructive' : 'bg-warning';
        case 'pending_break':
        case 'checking_out':
            return 'bg-warning';
        default:
            return 'bg-muted-foreground/50';
    }
});

const statusLabel = computed(() => (status.value ? t(`queue.status.${status.value}`) : t('queue.status.off_shift')));
const breakSince = computed(() =>
    queue?.breakSince.value === null || queue?.breakSince.value === undefined ? null : formatSeconds(queue.breakSince.value, locale.value),
);
const breakOver = computed(() => queue?.breakOver.value === true);

interface Action {
    key: string;
    label: string;
    hint: string;
    icon: LucideIcon;
    variant: 'default' | 'outline' | 'ghost';
    /** The `busy` key of the request it sends (its spinner). */
    busy: string;
    disabled?: boolean;
    flip?: boolean;
    run: () => void;
}

/** «رجّعي شبابيكي للصالة» asks first: one click would pull every customer off her desk. */
const confirmingHandBack = ref(false);
watch(status, () => (confirmingHandBack.value = false));

/**
 * Her buttons (attendance §3): «بدأت شغل» before she checks in (enabled only while a shift
 * runs), «استراحة» and «خروج» at her desk, «رجعت» on a break, «رجّعي شبابيكي للصالة» (after a
 * confirm) or «رجعت» (she changed her mind: back at her desk) on her way out.
 */
const actions = computed<Action[]>(() => {
    const q = queue;
    if (!q) return [];

    const checkOut: Action = {
        key: 'check-out',
        label: t('queue.attendance.check_out'),
        hint: t('queue.attendance.check_out_hint'),
        icon: LogOut,
        variant: 'ghost',
        busy: 'check-out',
        flip: true,
        run: () => void q.checkOut(),
    };
    const back = (label: string, hint: string): Action => ({
        key: 'back',
        label,
        hint,
        icon: Play,
        variant: 'default',
        busy: 'status',
        flip: true,
        run: () => void q.setStatus('available'),
    });

    switch (status.value) {
        case null:
            return [
                {
                    key: 'check-in',
                    label: t('queue.attendance.check_in'),
                    hint: t('queue.attendance.check_in_hint'),
                    icon: LogIn,
                    variant: 'default',
                    busy: 'check-in',
                    flip: true,
                    disabled: q.attendance.value?.shift_open !== true,
                    run: () => void q.checkIn(),
                },
            ];
        case 'available':
        case 'busy':
            return [
                {
                    key: 'break',
                    label: t('queue.go_break'),
                    hint: t('queue.go_break_hint'),
                    icon: Coffee,
                    variant: 'outline',
                    busy: 'status',
                    run: () => void q.setStatus('break'),
                },
                checkOut,
            ];
        case 'pending_break':
            return [back(t('queue.cancel_break'), t('queue.cancel_break')), checkOut];
        case 'break':
            return [back(t('queue.back_to_work'), t('queue.back_to_work_hint'))];
        case 'offline':
            return [back(t('queue.back_to_work'), t('queue.back_to_work_hint')), checkOut];
        case 'checking_out':
            return [
                {
                    key: 'hand-back',
                    label: t('queue.attendance.hand_back'),
                    hint: t('queue.attendance.hand_back_hint'),
                    icon: Undo2,
                    variant: 'default',
                    busy: 'hand-back',
                    run: () => {
                        confirmingHandBack.value = true;
                        // The second click of a double-click must not land on the confirm.
                        q.holdAttendance();
                    },
                },
                back(t('queue.back_to_work'), t('queue.attendance.cancel_check_out_hint')),
            ];
        default:
            return [];
    }
});

/** A click on one of her attendance buttons, ignored for a moment after the last one completed. */
function press(run: () => void): void {
    if (queue?.attendanceHeld()) return;
    run();
}

function confirmHandBack(): void {
    press(() => void queue?.handBack());
}

/** Under the buttons: when the shift starts (while «بدأت شغل» is disabled), or what «بتقفلي» means. */
const note = computed(() => {
    if (status.value === 'checking_out') return confirmingHandBack.value ? null : t('queue.attendance.closing');
    const a = queue?.attendance.value ?? null;
    if (status.value !== null || a === null || a.shift_open) return null;

    return a.next_starts_at ? t('queue.attendance.starts_at', { time: formatClock(a.next_starts_at, locale.value) }) : t('queue.attendance.no_shift');
});

const cardTone: Record<Card['tone'], string> = {
    calm: 'border-border bg-background hover:bg-elevated',
    warning: 'border-warning/60 bg-warning/10 hover:bg-warning/15',
    last: 'border-destructive/50 bg-destructive/10 hover:bg-destructive/15',
    overdue: 'border-overdue/70 bg-overdue/10 hover:bg-overdue/15',
};

/**
 * The hand-back confirm (attendance review, deferred item): an alert dialog anchored to the button.
 * It is opened only through the button's `press(action.run)` (the same hold guard as her other
 * buttons); focus lands on the confirm button, Escape or an outside click cancels, and focus goes
 * back to the button that opened it.
 */
function onHandBackOpen(open: boolean): void {
    if (!open) confirmingHandBack.value = false;
}
function focusHandBack(event: Event): void {
    event.preventDefault();
    document.querySelector<HTMLButtonElement>('[data-hand-back]')?.focus();
}
function focusConfirm(event: Event): void {
    event.preventDefault();
    // The popover sits in the actions' v-for (a template ref there would be an array): find it in the DOM.
    document.querySelector<HTMLButtonElement>('[data-hand-back-confirm]')?.focus();
}
const silenceTone: Record<Card['tone'], string> = {
    calm: 'text-muted-foreground',
    warning: 'font-semibold text-foreground',
    last: 'font-semibold text-destructive',
    // Text keeps the overdue chip's text pair (StatusChip): the raw --overdue orange is ~2.8:1 on white;
    // the token itself colours the icon (and the card's border / tint in cardTone).
    overdue: 'font-semibold text-orange-800 dark:text-orange-100 [&>svg]:text-overdue',
};
</script>

<template>
    <!-- One 44 px row: her state and button, a divider, then her windows (scrolls sideways on a phone). -->
    <section
        v-if="queue?.shown.value"
        class="flex h-11 shrink-0 items-center gap-2 border-b bg-card px-3"
        :aria-label="t('queue.my_windows')"
        data-my-windows
    >
        <div class="flex shrink-0 items-center gap-1.5" role="group" :aria-label="t('queue.status.label')">
            <span
                class="inline-flex h-7 items-center gap-1.5 rounded-full bg-muted px-2.5 text-xs font-medium"
                :title="`${t('queue.my_windows')}: ${t('queue.windows_count', { open: formatCount(cards.length, locale), cap: formatCount(queue.cap.value, locale) })}`"
                data-attendance-chip
            >
                <span class="size-2 shrink-0 rounded-full" :class="statusDot" aria-hidden="true" />
                <!-- Announced when the status changes; the break clock stays out of the live region (no reading every second). -->
                <span role="status">
                    <span class="hidden sm:inline">{{ statusLabel }}</span>
                    <span class="sr-only sm:hidden">{{ statusLabel }}</span>
                </span>
                <span v-if="breakSince !== null" class="tabular-nums" :class="breakOver ? 'font-semibold text-destructive' : 'text-muted-foreground'">
                    {{ t('queue.attendance.break_since', { time: breakSince }) }}
                </span>
            </span>

            <template v-for="action in actions" :key="action.key">
                <Popover v-if="action.key === 'hand-back'" :open="confirmingHandBack" @update:open="onHandBackOpen">
                    <PopoverAnchor as-child>
                        <Button
                            type="button"
                            :variant="action.variant"
                            size="sm"
                            class="h-7 gap-1 rounded-full px-2.5 text-xs [&_svg]:size-3.5"
                            :loading="queue.busy.value === action.busy"
                            :disabled="queue.busy.value !== null || action.disabled === true"
                            :title="action.hint"
                            :aria-expanded="confirmingHandBack"
                            aria-haspopup="dialog"
                            data-hand-back
                            @click="press(action.run)"
                        >
                            <component :is="action.icon" aria-hidden="true" />
                            {{ action.label }}
                        </Button>
                    </PopoverAnchor>
                    <PopoverContent align="start" class="w-72 p-3" @open-auto-focus="focusConfirm" @close-auto-focus="focusHandBack">
                        <!-- The popover's own element is role="dialog" (Radix sets it and the wrapper drops attrs):
                             the confirm inside is announced as an alert dialog with its name and description. -->
                        <div
                            role="alertdialog"
                            :aria-label="t('queue.attendance.hand_back')"
                            aria-describedby="hand-back-confirm-hint"
                            data-hand-back-dialog
                        >
                            <p id="hand-back-confirm-hint" class="text-xs text-foreground">
                                {{ t('queue.attendance.hand_back_confirm_hint', { n: formatCount(cards.length, locale) }) }}
                            </p>
                            <div class="mt-3 flex items-center justify-end gap-1.5">
                                <button
                                    type="button"
                                    :class="cn(buttonVariants({ variant: 'outline', size: 'sm' }), 'h-8 px-3 text-xs')"
                                    :disabled="queue.busy.value !== null"
                                    @click="confirmingHandBack = false"
                                >
                                    {{ t('queue.attendance.hand_back_cancel') }}
                                </button>
                                <Button
                                    type="button"
                                    size="sm"
                                    class="h-8 gap-1 px-3 text-xs [&_svg]:size-3.5"
                                    :loading="queue.busy.value === 'hand-back'"
                                    :disabled="queue.busy.value !== null"
                                    data-hand-back-confirm
                                    @click="confirmHandBack"
                                >
                                    <Undo2 aria-hidden="true" />
                                    {{ t('queue.attendance.hand_back_confirm') }}
                                </Button>
                            </div>
                        </div>
                    </PopoverContent>
                </Popover>
                <Button
                    v-else
                    type="button"
                    :variant="action.variant"
                    size="sm"
                    class="h-7 gap-1 rounded-full px-2.5 text-xs [&_svg]:size-3.5"
                    :loading="queue.busy.value === action.busy"
                    :disabled="queue.busy.value !== null || action.disabled === true"
                    :title="action.hint"
                    @click="press(action.run)"
                >
                    <component :is="action.icon" :class="action.flip ? 'rtl-flip' : ''" aria-hidden="true" />
                    {{ action.label }}
                </Button>
            </template>
        </div>

        <span class="h-6 w-px shrink-0 bg-border" aria-hidden="true" />

        <ul class="scrollbar-none flex h-full min-w-0 flex-1 items-center gap-1.5 overflow-x-auto" role="list">
            <li v-for="card in cards" :key="card.entry.id" class="shrink-0">
                <button
                    type="button"
                    class="flex h-8 items-center gap-1.5 whitespace-nowrap rounded-lg border px-2 text-xs transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring"
                    :class="[cardTone[card.tone], card.selected ? 'ring-2 ring-inset ring-primary' : '']"
                    :aria-label="t('queue.open_chat', { name: card.name, ticket: card.ticket, window: card.entry.window_no ?? '' })"
                    :aria-current="card.selected ? 'true' : undefined"
                    :title="card.name"
                    :data-window="card.entry.window_no"
                    @click="emit('select', card.entry.conversation_id, $event.detail > 0)"
                >
                    <span class="font-bold tabular-nums" dir="ltr">#{{ formatCount(card.ticket, locale) }}</span>
                    <span class="max-w-[6rem] truncate font-medium" dir="auto">{{ card.first }}</span>
                    <span
                        v-if="card.badge"
                        class="inline-flex size-4 shrink-0 items-center justify-center rounded-full"
                        :class="card.badge.tone"
                        :title="card.badge.label"
                    >
                        <component :is="card.badge.icon" class="size-2.5" aria-hidden="true" />
                    </span>
                    <FolderOpen v-if="card.entry.open_case_id" class="size-3 shrink-0 text-primary" aria-hidden="true" />
                    <span
                        v-if="card.tone === 'overdue'"
                        class="inline-flex items-center gap-0.5 tabular-nums"
                        :class="silenceTone.overdue"
                        :title="t('queue.handoff_left')"
                    >
                        <Hourglass class="size-3 motion-safe:animate-pulse" aria-hidden="true" />{{ card.handoff ?? t('queue.reply_overdue') }}
                    </span>
                    <span
                        v-else-if="card.silence !== null"
                        class="inline-flex items-center gap-0.5 tabular-nums"
                        :class="silenceTone[card.tone]"
                        :title="t('queue.silence_left')"
                    >
                        <Hourglass class="size-3" :class="card.tone === 'last' ? 'motion-safe:animate-pulse' : ''" aria-hidden="true" />{{
                            card.silence
                        }}
                    </span>
                    <span
                        v-else
                        class="inline-flex items-center gap-0.5 tabular-nums text-primary"
                        :title="`${t('queue.waiting_reply')} · ${t('queue.timer')}`"
                    >
                        <MessageCircleReply class="size-3" aria-hidden="true" /><span class="sr-only">{{ t('queue.waiting_reply') }}: </span
                        >{{ card.elapsed }}
                    </span>
                    <span
                        v-if="card.unread > 0"
                        class="flex h-4 min-w-4 items-center justify-center rounded-full bg-primary px-1 text-2xs font-semibold text-primary-foreground"
                        :title="t('queue.unread')"
                    >
                        <span class="sr-only">{{ t('queue.unread') }}: </span>{{ formatCount(card.unread, locale) }}
                    </span>
                    <PlatformBadge :platform="card.entry.platform" size="xs" />
                </button>
            </li>

            <li
                v-for="slot in freeSlots"
                :key="`free-${slot}`"
                class="flex h-8 shrink-0 items-center rounded-lg border border-dashed border-border px-2.5 text-2xs text-muted-foreground"
                :title="t('queue.free_window_hint')"
            >
                {{ t('queue.free_window') }}
            </li>

            <li v-if="queue.member.value === null && cards.length === 0" class="shrink-0 text-xs text-muted-foreground">
                {{ t('queue.attendance.idle') }}
            </li>
            <li v-if="note" class="shrink-0 text-2xs text-muted-foreground">{{ note }}</li>
        </ul>
    </section>
</template>
