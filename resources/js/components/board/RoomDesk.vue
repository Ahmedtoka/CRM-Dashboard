<script setup lang="ts">
import RoomWindow from '@/components/board/RoomWindow.vue';
import { useI18n } from '@/composables/useI18n';
import { useBoardContext } from '@/lib/board/context';
import { CELL, LEADER, slotScale, type DeskBox } from '@/lib/board/layout';
import { notOnline, teamColour, windowKey, windowSlots } from '@/lib/board/state';
import { formatCount, formatSeconds } from '@/lib/format';
import type { BoardMember, BoardSelection } from '@/types/board';
import type { UserRef } from '@/types/crm';
import { computed } from 'vue';

const props = defineProps<{
    /** Her desk; null for the leader's desk while she has none on the open shift. */
    member: BoardMember | null;
    box: DeskBox;
    leader?: boolean;
    /** The shift's leader, for the name plate (she may have no desk). */
    leaderUser?: UserRef | null;
    selection: BoardSelection;
}>();
defineEmits<{ select: [selection: BoardSelection] }>();

const { t, locale, dir } = useI18n();
const board = useBoardContext();

const windows = computed(() => (props.member?.user ? board.windowsOf(props.member.user.id) : []));
const slots = computed(() => (props.member ? windowSlots(windows.value, props.member.cap) : []));
const scale = computed(() => slotScale(slots.value.length, props.leader ? LEADER.w : CELL.w));
const arriving = computed(() => new Set(board.moves.value.map((m) => m.entryId)));

/** The desk's mood: what the simulator colours the pill and the screen's glow with. */
const mood = computed(() => {
    const m = props.member;
    if (m === null) return 'off';
    if (m.status === 'offline') return 'off';
    if (m.status === 'break' || m.status === 'pending_break') return 'break';
    // On the roster but not logged in: grey, the router skips her (flow revision §2).
    if (notOnline(m)) return 'notonline';
    if (windows.value.some((e) => ['warning', 'last'].includes(board.silenceTone(e)))) return 'alert';

    return windows.value.length > 0 ? 'busy' : 'free';
});

const pill = computed(() => {
    const m = props.member;
    if (m === null) return '';

    switch (m.status) {
        case 'break': {
            const left = board.breakLeft(m);

            return left === null ? t('board.status.break') : t('board.status.break_left', { time: formatSeconds(left, locale.value) });
        }
        case 'pending_break':
            return t('board.status.pending_break');
        case 'offline':
            return t('board.status.offline');
        default:
            if (notOnline(m)) return t('board.status.not_online');

            return windows.value.length > 0
                ? t('board.status.busy', { n: formatCount(windows.value.length, locale.value) })
                : t('board.status.available');
    }
});

const stats = computed(() => {
    const m = props.member;
    if (m === null) return '';
    const today = m.today ?? {};
    const n = (value: number | undefined) => formatCount(value ?? 0, locale.value);

    const base = t('board.desk.stats', {
        received: n(today.received),
        manual: n((today.inquiry ?? 0) + (today.problem ?? 0) + (today.case ?? 0)),
        auto: n(today.auto),
    });

    // «ما ردّتش» today: windows handed on because she did not reply (flow revision §4.4).
    return (today.no_reply ?? 0) > 0 ? t('board.desk.stats_no_reply', { stats: base, n: n(today.no_reply) }) : base;
});

const label = computed(() => {
    const m = props.member;
    if (m === null) return '';

    return t('board.desk.label', { name: m.user?.name ?? '', status: pill.value, open: windows.value.length, cap: slots.value.length });
});

const selected = computed(() => props.selection?.kind === 'member' && props.member !== null && props.selection.id === props.member.id);
const plate = computed(() => props.member?.user?.name ?? props.leaderUser?.name ?? '');
</script>

<template>
    <div
        class="cell"
        :class="[
            leader ? 'leader' : 'flip',
            mood,
            { sel: selected, away: member?.status === 'break' || member?.status === 'offline' || member === null },
        ]"
        :style="{ left: `${box.x}px`, top: `${box.y}px`, transform: box.scale === 1 ? undefined : `scale(${box.scale})` }"
        :dir="dir"
        role="group"
        :aria-label="label || (leader ? t('board.leader.none') : t('board.desks.empty'))"
    >
        <svg class="chair" aria-hidden="true"><use href="#br-chair" /></svg>
        <svg class="person" :style="{ color: teamColour(member?.user ?? leaderUser) }" aria-hidden="true"><use href="#br-g-mod" /></svg>
        <svg class="deskunit" aria-hidden="true"><use :href="leader ? '#br-deskleader' : '#br-deskunit'" /></svg>

        <button
            v-if="member"
            type="button"
            class="hit"
            :aria-label="label"
            :aria-pressed="selected"
            @click="$emit('select', { kind: 'member', id: member.id })"
        />

        <template v-if="leader">
            <span class="plate">{{ plate ? t('board.leader.plate', { name: plate }) : t('board.leader.title') }}</span>
            <span v-if="!member" class="none">{{ leaderUser ? t('board.leader.no_desk', { name: leaderUser.name }) : t('board.leader.none') }}</span>
        </template>
        <template v-else-if="member">
            <div class="name">
                {{ member.user?.name }}
                <small v-if="member.is_leader">{{ t('board.leader.title') }}</small>
            </div>
            <div class="st num">{{ stats }}</div>
        </template>

        <template v-if="member">
            <span class="pill num">{{ pill }}</span>
            <span class="load num" aria-hidden="true">{{ formatCount(windows.length, locale) }}/{{ formatCount(slots.length, locale) }}</span>

            <div class="slots" :style="{ transform: scale === 1 ? undefined : `scale(${scale})` }">
                <RoomWindow
                    v-for="(entry, i) in slots"
                    :key="entry ? `e${entry.id}` : `w${i}`"
                    :entry="entry"
                    :number="i + 1"
                    :selected="entry !== null && selection?.kind === 'entry' && selection.id === entry.id"
                    :freed="entry === null && board.freed.value.includes(windowKey(member.user?.id ?? null, i + 1))"
                    :arriving="entry !== null && arriving.has(entry.id)"
                    @select="$emit('select', { kind: 'entry', id: $event })"
                />
            </div>
        </template>
    </div>
</template>
