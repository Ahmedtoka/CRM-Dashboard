<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { useBoardContext } from '@/lib/board/context';
import { CARDS, cardSpot, HEADS } from '@/lib/board/layout';
import { outfitOf, platformVar, teamColour } from '@/lib/board/state';
import { formatCount, formatSeconds } from '@/lib/format';
import type { BoardSelection } from '@/types/board';
import type { QueueEntry } from '@/types/crm';
import { computed } from 'vue';

const props = defineProps<{ selection: BoardSelection }>();
defineEmits<{ select: [selection: BoardSelection] }>();

const { t, locale, dir } = useI18n();
const board = useBoardContext();

function describe(entry: QueueEntry) {
    const reservedFor = entry.reserved_user_id !== null ? board.members.value.find((m) => m.user?.id === entry.reserved_user_id) : undefined;
    const waited = formatSeconds(board.secondsSince(entry.enqueued_at), locale.value);
    const name = entry.customer?.name || t('queue.customer_fallback');
    const ticket = entry.ticket % 100000;

    return {
        entry,
        id: entry.id,
        ticket,
        name,
        platform: entry.platform ?? 'facebook',
        colour: platformVar(entry.platform),
        outfit: outfitOf(entry),
        priority: entry.priority,
        badge: entry.priority === 'live' ? null : t(`board.priority.${entry.priority}`),
        request: entry.request_line,
        openCase: entry.open_case_id,
        overnight: entry.priority === 'overnight',
        waited,
        eta: entry.eta_seconds === null || entry.priority === 'overnight' ? null : Math.max(1, Math.ceil(entry.eta_seconds / 60)),
        reservedFor: reservedFor?.user ? { name: reservedFor.user.name, colour: teamColour(reservedFor.user) } : null,
        selected: props.selection?.kind === 'entry' && props.selection.id === entry.id,
        label: t('board.lounge.card_label', { ticket, name, time: waited }),
    };
}

const seated = computed(() => board.waiting.value.slice(0, CARDS).map(describe));
const heads = computed(() => board.waiting.value.slice(CARDS, CARDS + HEADS).map(describe));
const total = computed(() => Math.max(board.kpis.value?.waiting ?? 0, board.waiting.value.length));
const beyond = computed(() => Math.max(0, total.value - seated.value.length - heads.value.length));

const call = computed(() => {
    const c = board.lastCall.value;
    if (c === null) return null;
    const ticket = formatCount(c.ticket % 100000, locale.value);
    const name = c.name ?? board.userName(c.user_id) ?? '';

    return c.window_no === null
        ? t('board.robot.call_no_window', { ticket, name })
        : t('board.robot.call', { ticket, window: formatCount(c.window_no, locale.value), name });
});
</script>

<template>
    <section class="lounge" :dir="dir" :aria-label="t('board.lounge.title')">
        <div class="robot" :class="{ calling: board.calling.value }" aria-hidden="true">
            <svg><use href="#br-g-robot" /></svg>
        </div>
        <!-- The robot's call is read out by screen readers when it changes. -->
        <div class="say" :class="{ calling: board.calling.value }" role="status" aria-live="polite">
            {{ call ?? t('board.robot.idle') }}
        </div>

        <div class="ttl">
            <b>{{ t('board.lounge.title') }}</b> · {{ t('board.lounge.count', { n: formatCount(total, locale) }) }}
        </div>

        <p v-if="board.loaded.value && total === 0" class="empty">{{ t('board.lounge.empty') }}</p>

        <div
            v-for="(seat, i) in seated"
            :key="seat.id"
            class="lseat"
            :class="{ sel: seat.selected }"
            :style="{ left: `${cardSpot(i).x}px`, top: `${cardSpot(i).y}px` }"
            :dir="dir"
        >
            <button
                type="button"
                class="card"
                :style="seat.reservedFor ? { borderInlineStart: `4px solid ${seat.reservedFor.colour}` } : undefined"
                :aria-label="seat.label"
                :aria-pressed="seat.selected"
                @click="$emit('select', { kind: 'entry', id: seat.id })"
            >
                <span class="nm">
                    <span class="tk num">#{{ seat.ticket }}</span>
                    <span class="pl" :style="{ background: `var(--${seat.colour})` }"
                        ><svg aria-hidden="true"><use :href="`#br-i-${seat.platform}`" /></svg
                    ></span>
                    <span class="who">{{ seat.name }}</span>
                    <span v-if="seat.badge" class="badge" :class="seat.priority">{{ seat.badge }}</span>
                    <span v-if="seat.openCase" class="badge case">{{ t('board.lounge.open_case', { id: seat.openCase }) }}</span>
                </span>
                <span class="tx" style="display: block">{{ seat.request ?? t('board.lounge.no_request') }}</span>
                <span class="wt num">
                    <span v-if="seat.overnight" class="night">{{ t('board.lounge.overnight') }}</span>
                    <span v-else>{{ t('board.lounge.waiting_for', { time: seat.waited }) }}</span>
                    <span v-if="seat.reservedFor" class="badge for" :style="{ '--for': seat.reservedFor.colour }">{{
                        t('board.lounge.reserved_for', { name: seat.reservedFor.name })
                    }}</span>
                    <span v-else-if="seat.eta !== null">{{ t('board.lounge.eta', { n: formatCount(seat.eta, locale) }) }}</span>
                </span>
            </button>
            <svg class="girl" :style="{ color: seat.outfit.color }" aria-hidden="true">
                <use :href="seat.outfit.alt ? '#br-g-girl2' : '#br-g-girl'" />
            </svg>
        </div>

        <div v-if="heads.length > 0" class="heads">
            <button
                v-for="head in heads"
                :key="head.id"
                type="button"
                class="head"
                :class="{ sel: head.selected }"
                :title="`#${head.ticket} ${head.name}`"
                :aria-label="head.label"
                :aria-pressed="head.selected"
                @click="$emit('select', { kind: 'entry', id: head.id })"
            >
                <svg :style="{ color: head.outfit.color }" aria-hidden="true"><use :href="head.outfit.alt ? '#br-g-girl2' : '#br-g-girl'" /></svg>
                <i class="num" :style="head.reservedFor ? { color: head.reservedFor.colour } : undefined">{{ head.ticket }}</i>
            </button>
        </div>

        <div v-if="beyond > 0" class="lmore num">{{ t('board.lounge.more', { n: formatCount(beyond, locale) }) }}</div>
    </section>
</template>
