<script setup lang="ts">
import TickText from '@/components/board/TickText.vue';
import { useI18n } from '@/composables/useI18n';
import { getInitials } from '@/composables/useInitials';
import { useBoardContext } from '@/lib/board/context';
import { notOnline, teamColour, windowSlots } from '@/lib/board/state';
import { formatCount } from '@/lib/format';
import type { BoardMember, BoardSelection } from '@/types/board';
import type { QueueEntry } from '@/types/crm';
import { computed } from 'vue';

/**
 * The board on a phone (< 768 px), where the scaled room is unreadable: the desks as cards (her
 * windows as chips) and the lounge as a list. Tapping picks the same things as in the room.
 */
const props = defineProps<{ selection: BoardSelection }>();
defineEmits<{ select: [selection: BoardSelection] }>();

const { t, locale } = useI18n();
const board = useBoardContext();
const n = (value: number | null | undefined) => formatCount(value ?? 0, locale.value);

/** Whose break ran over, as a key that changes only when somebody's does (the cards do not tick). */
const overrun = computed(() =>
    board.members.value
        .filter((m) => board.breakOver(m))
        .map((m) => m.id)
        .join(','),
);

const leaderId = computed(() => board.shift.value?.leader?.id ?? null);

/** The leader's desk first, as in the room. */
const desks = computed(() =>
    [...board.members.value].sort((a, b) => Number(isLeader(b)) - Number(isLeader(a))).map((member) => ({ member, ...deskView(member) })),
);

function isLeader(m: BoardMember): boolean {
    return m.is_leader === true || (leaderId.value !== null && m.user?.id === leaderId.value);
}

function deskView(m: BoardMember) {
    const windows = m.user ? board.windowsOf(m.user.id) : [];
    const today = m.today ?? {};
    const tone =
        m.status === 'break'
            ? overrun.value.split(',').includes(String(m.id))
                ? 'overrun'
                : 'break'
            : m.status === 'pending_break' || m.status === 'checking_out'
              ? 'break'
              : notOnline(m) || m.status === 'offline'
                ? 'off'
                : windows.length > 0
                  ? 'busy'
                  : 'free';

    return {
        leader: isLeader(m),
        initials: getInitials(m.user?.name ?? ''),
        colour: teamColour(m.user),
        tone,
        status:
            m.status === 'break'
                ? null
                : m.status === 'checking_out'
                  ? t('board.status.checking_out')
                  : m.status === 'pending_break'
                    ? t('board.status.pending_break')
                    : m.status === 'offline'
                      ? t('board.status.offline')
                      : notOnline(m)
                        ? t('board.status.not_online')
                        : windows.length > 0
                          ? t('board.status.busy', { n: n(windows.length) })
                          : t('board.status.available'),
        slots: windowSlots(windows, m.cap).filter((e): e is QueueEntry => e !== null),
        cap: Math.max(m.cap, windows.length),
        stats: t('board.desk.stats', {
            received: n(today.received),
            manual: n((today.inquiry ?? 0) + (today.problem ?? 0) + (today.case ?? 0)),
            auto: n(today.auto),
        }),
    };
}

const breakTemplate = computed(() => t('board.status.break_since', { time: '{time}' }));
const lateTemplate = computed(() => t('board.desk.late', { time: '{time}' }));
const handoffTemplate = computed(() => t('board.window.handoff', { time: '{time}' }));
const waitingTemplate = computed(() => t('board.lounge.waiting_for', { time: '{time}' }));

const lounge = computed(() =>
    board.waiting.value.map((entry) => {
        const reservedFor = entry.reserved_user_id !== null ? board.userName(entry.reserved_user_id) : null;

        return {
            id: entry.id,
            ticket: entry.ticket % 100000,
            name: entry.customer?.name || t('queue.customer_fallback'),
            platform: entry.platform ?? 'facebook',
            priority: entry.priority,
            badge: entry.priority === 'live' ? null : t(`board.priority.${entry.priority}`),
            request: entry.request_line ?? t('board.lounge.no_request'),
            openCase: entry.open_case_id,
            enqueued: entry.enqueued_at,
            overnight: entry.priority === 'overnight',
            eta: entry.eta_seconds === null || entry.priority === 'overnight' ? null : Math.max(1, Math.ceil(entry.eta_seconds / 60)),
            reservedFor,
        };
    }),
);

const picked = (kind: 'member' | 'entry', id: number) => props.selection?.kind === kind && props.selection.id === id;

const TONES: Record<string, string> = {
    free: 'bg-success/15 text-emerald-800 dark:bg-success/25 dark:text-emerald-200',
    busy: 'bg-warning/20 text-amber-900 dark:bg-warning/25 dark:text-amber-100',
    break: 'bg-muted text-muted-foreground',
    overrun: 'bg-destructive/10 text-destructive dark:bg-destructive/25 dark:text-red-200',
    off: 'bg-muted text-muted-foreground',
};
</script>

<template>
    <div class="space-y-4">
        <section :aria-label="t('board.desks.caption')" class="space-y-2">
            <h2 class="text-sm font-bold text-foreground">{{ t('board.desks.caption') }}</h2>
            <p v-if="desks.length === 0" class="rounded-xl border border-dashed border-border p-4 text-sm text-muted-foreground">
                {{ t('board.phone.no_desks') }}
            </p>
            <article
                v-for="desk in desks"
                :key="desk.member.id"
                class="rounded-xl border bg-card p-3 text-card-foreground"
                :class="picked('member', desk.member.id) ? 'border-ring ring-2 ring-ring/40' : 'border-border'"
            >
                <button
                    type="button"
                    class="flex min-h-11 w-full items-center gap-3 rounded-lg text-start focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    :aria-pressed="picked('member', desk.member.id)"
                    @click="$emit('select', { kind: 'member', id: desk.member.id })"
                >
                    <span
                        class="grid size-10 shrink-0 place-items-center rounded-full text-sm font-bold text-slate-900"
                        :class="{ 'opacity-50': desk.tone === 'off' }"
                        :style="{ background: desk.colour }"
                        aria-hidden="true"
                        >{{ desk.initials }}</span
                    >
                    <span class="min-w-0 flex-1">
                        <span class="flex items-center gap-1.5">
                            <span class="truncate text-[15px] font-bold" :title="desk.member.user?.name">{{ desk.member.user?.name }}</span>
                            <span v-if="desk.leader" class="shrink-0 rounded bg-amber-200 px-1.5 text-xs font-bold text-amber-950">{{
                                t('board.leader.title')
                            }}</span>
                        </span>
                        <span class="block truncate text-xs text-muted-foreground">{{ desk.stats }}</span>
                    </span>
                    <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold" :class="TONES[desk.tone]">
                        <TickText
                            v-if="desk.tone === 'overrun' && desk.member.break_ends_at"
                            :since="desk.member.break_ends_at"
                            format="short"
                            :template="lateTemplate"
                        />
                        <TickText
                            v-else-if="desk.member.status === 'break' && desk.member.break_started_at"
                            :since="desk.member.break_started_at"
                            :template="breakTemplate"
                        />
                        <template v-else>{{ desk.status ?? t('board.status.break') }}</template>
                    </span>
                </button>

                <div v-if="desk.slots.length > 0" class="mt-2 flex flex-wrap gap-1.5">
                    <button
                        v-for="entry in desk.slots"
                        :key="entry.id"
                        type="button"
                        class="inline-flex min-h-9 max-w-full items-center gap-1.5 rounded-lg border px-2 text-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        :class="[
                            entry.reply_overdue ? 'border-overdue bg-overdue/10 text-orange-900 dark:text-orange-100' : 'border-border bg-muted/50',
                            picked('entry', entry.id) ? 'ring-2 ring-ring' : '',
                        ]"
                        :aria-pressed="picked('entry', entry.id)"
                        @click="$emit('select', { kind: 'entry', id: entry.id })"
                    >
                        <b class="num rounded bg-amber-300 px-1 text-[13px] text-amber-950">{{ entry.ticket % 100000 }}</b>
                        <span class="max-w-[6rem] truncate">{{ (entry.customer?.name || t('queue.customer_fallback')).split(' ')[0] }}</span>
                        <TickText
                            v-if="entry.reply_overdue && board.deadline(entry, 'handoff')"
                            class="font-bold"
                            :until="board.deadline(entry, 'handoff')"
                            :template="handoffTemplate"
                        />
                        <TickText v-else class="font-bold" :since="entry.delivered_at" />
                        <span v-if="entry.open_case_id" class="num rounded bg-amber-500 px-1 font-bold text-amber-950">{{
                            t('board.window.case', { id: entry.open_case_id })
                        }}</span>
                    </button>
                </div>
            </article>
        </section>

        <section :aria-label="t('board.lounge.title')" class="space-y-2">
            <h2 class="flex items-baseline justify-between gap-2 text-sm font-bold text-foreground">
                {{ t('board.lounge.title') }}
                <span class="num text-xs font-normal text-muted-foreground">{{ t('board.lounge.count', { n: n(lounge.length) }) }}</span>
            </h2>
            <p v-if="lounge.length === 0" class="rounded-xl border border-dashed border-border p-4 text-sm text-muted-foreground">
                {{ t('board.lounge.empty') }}
            </p>
            <ul v-else class="divide-y divide-border overflow-hidden rounded-xl border border-border bg-card">
                <li v-for="row in lounge" :key="row.id">
                    <button
                        type="button"
                        class="flex min-h-14 w-full items-start gap-3 px-3 py-2 text-start focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring"
                        :class="picked('entry', row.id) ? 'bg-accent' : 'hover:bg-muted/60'"
                        :aria-pressed="picked('entry', row.id)"
                        @click="$emit('select', { kind: 'entry', id: row.id })"
                    >
                        <b class="num mt-0.5 shrink-0 text-sm text-amber-700 dark:text-amber-300">#{{ row.ticket }}</b>
                        <span class="min-w-0 flex-1">
                            <span class="flex items-center gap-1.5">
                                <span class="truncate text-sm font-semibold text-foreground">{{ row.name }}</span>
                                <span v-if="row.badge" class="shrink-0 rounded bg-muted px-1.5 text-xs font-semibold text-foreground">{{
                                    row.badge
                                }}</span>
                                <span v-if="row.openCase" class="num shrink-0 rounded bg-amber-500 px-1.5 text-xs font-bold text-amber-950">{{
                                    t('board.lounge.open_case', { id: row.openCase })
                                }}</span>
                            </span>
                            <span class="block truncate text-xs text-muted-foreground">{{ row.request }}</span>
                        </span>
                        <span class="shrink-0 text-end text-xs">
                            <span v-if="row.overnight" class="block text-muted-foreground">{{ t('board.lounge.overnight') }}</span>
                            <TickText
                                v-else
                                class="block font-bold text-amber-800 dark:text-amber-200"
                                :since="row.enqueued"
                                :template="waitingTemplate"
                            />
                            <span v-if="row.reservedFor" class="block text-muted-foreground">{{
                                t('board.lounge.reserved_for', { name: row.reservedFor })
                            }}</span>
                            <span v-else-if="row.eta !== null" class="num block text-muted-foreground">{{
                                t('board.lounge.eta', { n: n(row.eta) })
                            }}</span>
                        </span>
                    </button>
                </li>
            </ul>
        </section>
    </div>
</template>
