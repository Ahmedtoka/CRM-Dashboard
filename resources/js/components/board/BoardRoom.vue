<script setup lang="ts">
import BoardKpiBar from '@/components/board/BoardKpiBar.vue';
import RoomDesk from '@/components/board/RoomDesk.vue';
import RoomLounge from '@/components/board/RoomLounge.vue';
import RoomReception from '@/components/board/RoomReception.vue';
import RoomSymbols from '@/components/board/RoomSymbols.vue';
import RoomWalker from '@/components/board/RoomWalker.vue';
import RoomWall from '@/components/board/RoomWall.vue';
import SkeletonList from '@/components/crm/SkeletonList.vue';
import { useI18n } from '@/composables/useI18n';
import { useInView } from '@/composables/useInView';
import { useBoardContext } from '@/lib/board/context';
import { deskLayout, MAX_DESKS, roomGeometry, seatCentre, slotCentre, STAGE_H, STAGE_W, stageScale, type DeskBox } from '@/lib/board/layout';
import { outfitOf, windowSlots } from '@/lib/board/state';
import { formatCount } from '@/lib/format';
import type { BoardMember, BoardSelection } from '@/types/board';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        selection: BoardSelection;
        /** The start of the day or a notice lies over the room. */
        veiled: boolean;
        /** The side panel is open. */
        sided: boolean;
        /** She may change the roster (the button is shown). */
        canManage: boolean;
        /** The room fills its container (the phone's «عرض الصالة»): «ملء الشاشة» leaves it instead. */
        fill?: boolean;
        /** The day has not loaded yet: the numbers bar shows skeleton tiles (the room itself is veiled). */
        loading?: boolean;
    }>(),
    { fill: false, loading: false },
);
const emit = defineEmits<{ select: [selection: BoardSelection]; exit: [] }>();

const { t, locale, dir } = useI18n();
const board = useBoardContext();

const shell = ref<HTMLElement | null>(null);
const wrap = ref<HTMLElement | null>(null);
const scale = ref(1);
const offset = ref({ x: 0, y: 0 });
const height = ref<number | null>(null);
const full = ref(false);
/** Fullscreen was refused (an embedded browser, an old TV): the room fills the window instead. */
const max = ref(false);
const big = computed(() => full.value || max.value || props.fill);

/** Endless animations rest while the room is off screen or the tab is hidden. */
const { active } = useInView(wrap);

/** How many empty desks stand in the room while no shift is open. */
const EMPTY_DESKS = 8;

/** The lounge narrows when few wait; the carpet, and so the desks, take the width back. */
const geometry = computed(() => roomGeometry(board.waiting.value.length));
const leaderBox = computed<DeskBox>(() => geometry.value.leader);

const leaderMember = computed<BoardMember | null>(() => {
    const leaderId = board.shift.value?.leader?.id ?? null;

    return board.members.value.find((m) => m.is_leader === true || (leaderId !== null && m.user?.id === leaderId)) ?? null;
});
const desks = computed(() => board.members.value.filter((m) => m.id !== leaderMember.value?.id));
const shown = computed(() => desks.value.slice(0, MAX_DESKS));
const boxes = computed(() => deskLayout(shown.value.length, geometry.value.carpet));
const hidden = computed(() => Math.max(0, desks.value.length - MAX_DESKS));
/** No shift open: the room's eight desks stand empty, as in the approved picture. */
const emptyBoxes = computed(() => (board.loaded.value && board.shift.value === null ? deskLayout(EMPTY_DESKS, geometry.value.carpet) : []));

/** What a desk shows, as one key: a desk whose key is unchanged is not patched (v-memo). */
function deskKey(member: BoardMember): string {
    const windows = member.user ? board.windowsOf(member.user.id) : [];

    return [
        member.status,
        member.online,
        member.cap,
        member.is_leader,
        member.break_started_at,
        member.break_ends_at,
        member.user?.name,
        member.user?.color,
        JSON.stringify(member.today ?? {}),
        windows
            .map((e) => `${e.id}.${e.status}.${e.window_no}.${e.reply_overdue}.${e.open_case_id}.${e.silence_left_seconds}.${e.handoff_left_seconds}`)
            .join(','),
    ].join('|');
}

/** Where a customer who was called walks to: her window at the moderator's desk. */
function target(userId: number, entryId: number): { x: number; y: number } | null {
    const isLeader = leaderMember.value?.user?.id === userId;
    const index = isLeader ? 0 : shown.value.findIndex((m) => m.user?.id === userId);
    const member = isLeader ? leaderMember.value : shown.value[index];
    if (!member) return null;

    const slots = windowSlots(board.windowsOf(userId), member.cap);
    const at = Math.max(
        0,
        slots.findIndex((e) => e?.id === entryId),
    );

    return slotCentre(isLeader ? leaderBox.value : boxes.value[index], at, slots.length, isLeader);
}

const walkers = computed(() =>
    board.moves.value.flatMap((move) => {
        const to = target(move.userId, move.entryId);
        const entry = board.open.value.find((e) => e.id === move.entryId);
        if (to === null || !entry) return [];

        return [
            {
                id: move.id,
                from: seatCentre(move.seat, roomGeometry(move.waitingBefore).lounge),
                to,
                ticket: move.ticket % 100000,
                outfit: outfitOf(entry),
            },
        ];
    }),
);

/**
 * The fixed stage is scaled to the wrapper: to its width on the page (never taller than the
 * window leaves room for below it), to the whole room area when it is big. A wrapper without a
 * size yet keeps the scale it had.
 */
function fit(): void {
    const el = wrap.value;
    if (el === null) return;

    const width = el.clientWidth;
    if (width <= 0) return;

    if (big.value) {
        const h = el.clientHeight;
        const s = stageScale(width, h > 0 ? h : null, scale.value);
        scale.value = s;
        offset.value = { x: Math.max(0, (width - STAGE_W * s) / 2), y: h > 0 ? Math.max(0, (h - STAGE_H * s) / 2) : 0 };
        height.value = null;

        return;
    }

    // On the page: as wide as the column, never taller than the window leaves room for under its top.
    const top = el.getBoundingClientRect().top + window.scrollY;
    const s = stageScale(width, Math.max(420, window.innerHeight - top - 16), scale.value);
    scale.value = s;
    offset.value = { x: Math.max(0, (width - STAGE_W * s) / 2), y: 0 };
    height.value = Math.round(STAGE_H * s);
}

async function toggleFull(): Promise<void> {
    if (props.fill) {
        emit('exit');

        return;
    }

    const el = shell.value;
    if (el === null) return;

    if (big.value) {
        max.value = false;
        if (document.fullscreenElement !== null) await document.exitFullscreen().catch(() => undefined);
        await refit();

        return;
    }

    try {
        if (typeof el.requestFullscreen !== 'function') throw new Error('no fullscreen');
        await el.requestFullscreen();
    } catch {
        max.value = true;
    }
    await refit();
}

async function refit(): Promise<void> {
    await nextTick();
    fit();
    // The browser finishes its own fullscreen animation a moment later.
    window.setTimeout(fit, 80);
}

function onFullscreenChange(): void {
    full.value = document.fullscreenElement !== null && document.fullscreenElement === shell.value;
    void refit();
}

function onKey(event: KeyboardEvent): void {
    if (event.key !== 'Escape') return;
    if (max.value) {
        max.value = false;
        void refit();
    } else if (props.fill && props.selection === null) {
        emit('exit');
    }
}

let observer: ResizeObserver | undefined;

onMounted(() => {
    fit();
    if (typeof ResizeObserver === 'function' && wrap.value) {
        observer = new ResizeObserver(fit);
        observer.observe(wrap.value);
        if (wrap.value.parentElement) observer.observe(wrap.value.parentElement);
    }
    window.addEventListener('resize', fit);
    document.addEventListener('fullscreenchange', onFullscreenChange);
    document.addEventListener('keydown', onKey);
});

onBeforeUnmount(() => {
    observer?.disconnect();
    window.removeEventListener('resize', fit);
    document.removeEventListener('fullscreenchange', onFullscreenChange);
    document.removeEventListener('keydown', onKey);
});

watch(big, () => void refit());
</script>

<template>
    <div ref="shell" class="board-shell" :class="{ 'is-max': max, 'is-big': big, paused: !active }">
        <SkeletonList v-if="loading" variant="tiles" :count="4" />
        <BoardKpiBar
            v-else
            :big="big"
            :compact="fill"
            :roster-open="selection?.kind === 'roster'"
            :can-roster="canManage && board.shift.value !== null"
            @full="toggleFull"
            @roster="$emit('select', selection?.kind === 'roster' ? null : { kind: 'roster' })"
        />

        <div ref="wrap" class="board-room" :style="height !== null && !big ? { height: `${height}px` } : undefined">
            <RoomSymbols />

            <div class="stage" dir="ltr" :inert="veiled" :style="{ transform: `translate(${offset.x}px, ${offset.y}px) scale(${scale})` }">
                <div
                    class="carpet"
                    :dir="dir"
                    :style="{
                        left: `${geometry.carpet.x}px`,
                        top: `${geometry.carpet.y}px`,
                        width: `${geometry.carpet.w}px`,
                        height: `${geometry.carpet.h}px`,
                    }"
                >
                    <span class="lbl">{{ t('board.desks.caption') }}</span>
                    <span v-if="hidden > 0" class="more num">{{ t('board.desks.more', { n: formatCount(hidden, locale) }) }}</span>
                </div>

                <RoomWall />
                <RoomDesk
                    leader
                    :member="leaderMember"
                    :leader-user="board.shift.value?.leader ?? null"
                    :box="leaderBox"
                    :selection="selection"
                    @select="$emit('select', $event)"
                />
                <RoomReception :reception="geometry.reception" />
                <RoomLounge :selection="selection" :lounge="geometry.lounge" @select="$emit('select', $event)" />

                <RoomDesk
                    v-for="(member, i) in shown"
                    :key="member.id"
                    v-memo="[member.id, deskKey(member), boxes[i].x, boxes[i].y, boxes[i].scale, selection, locale]"
                    :member="member"
                    :box="boxes[i]"
                    :selection="selection"
                    @select="$emit('select', $event)"
                />

                <RoomDesk v-for="(box, i) in emptyBoxes" :key="`empty-${i}`" :member="null" :box="box" :selection="null" />

                <RoomWalker
                    v-for="walker in walkers"
                    :key="walker.id"
                    :from="walker.from"
                    :to="walker.to"
                    :ticket="walker.ticket"
                    :colour="walker.outfit.color"
                    :alt="walker.outfit.alt"
                />
            </div>

            <div v-if="veiled" class="veil" :dir="dir">
                <slot name="veil" />
            </div>
            <aside v-if="sided && !veiled" class="side" :dir="dir">
                <slot name="side" />
            </aside>
        </div>
    </div>
</template>

<style src="./room.css"></style>
