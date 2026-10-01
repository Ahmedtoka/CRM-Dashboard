<script setup lang="ts">
import RoomWindow from '@/components/board/RoomWindow.vue';
import TickText from '@/components/board/TickText.vue';
import { useI18n } from '@/composables/useI18n';
import { useBoardContext } from '@/lib/board/context';
import { slotScale, type DeskBox } from '@/lib/board/layout';
import { notOnline, teamColour, windowKey, windowSlots } from '@/lib/board/state';
import { formatCount } from '@/lib/format';
import type { BoardMember, BoardSelection } from '@/types/board';
import type { UserRef } from '@/types/crm';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        /** Her desk; null for the leader's desk while she has none on the open shift. */
        member: BoardMember | null;
        box: DeskBox;
        leader?: boolean;
        /** The shift's leader, for the name plate (she may have no desk). */
        leaderUser?: UserRef | null;
        selection: BoardSelection;
    }>(),
    { leader: false, leaderUser: null },
);
defineEmits<{ select: [selection: BoardSelection] }>();

const { t, locale, dir } = useI18n();
const board = useBoardContext();

const windows = computed(() => (props.member?.user ? board.windowsOf(props.member.user.id) : []));
const slots = computed(() => (props.member ? windowSlots(windows.value, props.member.cap) : []));
const scale = computed(() => slotScale(slots.value.length));
const arriving = computed(() => new Set(board.moves.value.map((m) => m.entryId)));
/** One of her windows was just freed: she nods «خلصت». */
const nod = computed(() => {
    const id = props.member?.user?.id ?? null;

    return id !== null && board.freed.value.some((key) => key.startsWith(`${id}:`));
});

/**
 * The desk's mood: what colours the pill, the lamp and the screen's glow. It changes only when a
 * state does (a break running over, a silence turning red), so the desk does not tick with the clock.
 */
const mood = computed(() => {
    const m = props.member;
    if (m === null) return 'off';
    if (m.status === 'offline') return 'off';
    // Past `break_minutes`: red until she presses «رجعت» (attendance §3).
    if (m.status === 'break') return board.breakOver(m) ? 'overrun' : 'break';
    if (m.status === 'pending_break') return 'break';
    // «بتقفل»: she finishes her windows and takes no new chats.
    if (m.status === 'checking_out') return 'closing';
    // On the roster but not logged in: grey, the router skips her (flow revision §2).
    if (notOnline(m)) return 'notonline';
    if (windows.value.some((e) => ['warning', 'last'].includes(board.silenceTone(e)))) return 'alert';

    return windows.value.length > 0 ? 'busy' : 'free';
});

/** The pill: a word, or a ticking clock (TickText) for a break. */
const pill = computed<{ text: string; since?: string | null; template?: string }>(() => {
    const m = props.member;
    if (m === null) return { text: '' };

    switch (m.status) {
        case 'break':
            // Past `break_minutes`: «متأخرة ١٠ د» since the break should have ended.
            if (mood.value === 'overrun' && m.break_ends_at)
                return { text: '', since: m.break_ends_at, template: t('board.desk.late', { time: '{time}' }) };
            if (m.break_started_at) return { text: '', since: m.break_started_at, template: t('board.status.break_since', { time: '{time}' }) };

            return { text: t('board.status.break') };
        case 'checking_out':
            return { text: t('board.status.checking_out') };
        case 'pending_break':
            return { text: t('board.status.pending_break') };
        case 'offline':
            return { text: t('board.status.offline') };
        default:
            if (notOnline(m)) return { text: t('board.status.not_online') };

            return {
                text:
                    windows.value.length > 0
                        ? t('board.status.busy', { n: formatCount(windows.value.length, locale.value) })
                        : t('board.status.available'),
            };
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
    const status = pill.value.text || (mood.value === 'overrun' ? t('board.status.break_over_short') : t('board.status.break'));

    return t('board.desk.label', { name: m.user?.name ?? '', status, open: windows.value.length, cap: slots.value.length });
});

const selected = computed(() => props.selection?.kind === 'member' && props.member !== null && props.selection.id === props.member.id);
const plate = computed(() => props.member?.user?.name ?? props.leaderUser?.name ?? '');
const name = computed(() => props.member?.user?.name ?? '');
</script>

<template>
    <!-- The leader's desk before she checks in keeps her figure, grey (attendance §2); an empty desk has nobody. -->
    <div
        class="cell"
        :class="[
            leader ? 'leader' : 'flip',
            mood,
            {
                sel: selected,
                nod,
                onbreak: member?.status === 'break',
                away: member?.status === 'offline' || (member === null && !(leader && leaderUser)),
            },
        ]"
        :style="{ left: `${box.x}px`, top: `${box.y}px`, transform: box.scale === 1 ? undefined : `scale(${box.scale})` }"
        :dir="dir"
        role="group"
        :aria-label="label || (leader ? t('board.leader.none') : t('board.desks.empty'))"
    >
        <svg class="chair" aria-hidden="true"><use href="#br-chair" /></svg>
        <span class="fig" aria-hidden="true">
            <svg class="person" :style="{ color: teamColour(member?.user ?? leaderUser) }"><use href="#br-g-mod" /></svg>
        </span>
        <svg class="deskunit" aria-hidden="true"><use :href="leader ? '#br-deskleader' : '#br-deskunit'" /></svg>
        <svg v-if="member" class="lamp" aria-hidden="true"><use href="#br-lamp" /></svg>
        <svg v-if="mood === 'closing'" class="bag" aria-hidden="true"><use href="#br-bag" /></svg>
        <i v-if="mood === 'notonline'" class="offdot" aria-hidden="true" />

        <button
            v-if="member"
            type="button"
            class="hit"
            :aria-label="label"
            :aria-pressed="selected"
            @click="$emit('select', { kind: 'member', id: member.id })"
        />

        <template v-if="leader">
            <span class="plate" :title="plate">{{ plate ? t('board.leader.plate', { name: plate }) : t('board.leader.title') }}</span>
            <span v-if="!member" class="none">{{ leaderUser ? t('board.leader.no_desk', { name: leaderUser.name }) : t('board.leader.none') }}</span>
        </template>
        <template v-else-if="member">
            <div class="name" :title="name">
                <span>{{ name }}</span>
                <small v-if="member.is_leader">{{ t('board.leader.title') }}</small>
            </div>
            <div class="st num" :title="stats">{{ stats }}</div>
        </template>

        <template v-if="member">
            <span class="pill">
                <TickText v-if="pill.since" :since="pill.since" :format="mood === 'overrun' ? 'short' : 'mmss'" :template="pill.template" />
                <template v-else>{{ pill.text }}</template>
            </span>
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
