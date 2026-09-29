<script setup lang="ts">
import RoomDesk from '@/components/board/RoomDesk.vue';
import RoomKpiCard from '@/components/board/RoomKpiCard.vue';
import RoomLounge from '@/components/board/RoomLounge.vue';
import RoomReception from '@/components/board/RoomReception.vue';
import RoomSymbols from '@/components/board/RoomSymbols.vue';
import RoomWalker from '@/components/board/RoomWalker.vue';
import RoomWall from '@/components/board/RoomWall.vue';
import { useI18n } from '@/composables/useI18n';
import { useBoardContext } from '@/lib/board/context';
import { CARPET, deskLayout, LEADER, MAX_DESKS, seatCentre, slotCentre, STAGE_H, STAGE_W, stageScale, type DeskBox } from '@/lib/board/layout';
import { outfitOf, windowSlots } from '@/lib/board/state';
import { formatCount } from '@/lib/format';
import type { BoardMember, BoardSelection } from '@/types/board';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

defineProps<{
    selection: BoardSelection;
    /** The start of the day or a notice lies over the room. */
    veiled: boolean;
    /** The side panel is open. */
    sided: boolean;
    /** She may change the roster (the button is shown). */
    canManage: boolean;
}>();
defineEmits<{ select: [selection: BoardSelection] }>();

const { t, locale, dir } = useI18n();
const board = useBoardContext();

const wrap = ref<HTMLElement | null>(null);
const scale = ref(1);
const offset = ref({ x: 0, y: 0 });
const height = ref<number | null>(null);
const full = ref(false);
/** Fullscreen was refused (an embedded browser, an old TV): the room fills the window instead. */
const max = ref(false);
const big = computed(() => full.value || max.value);

/** The strip of buttons above the stage. */
const BAR = 48;

/** How many empty desks stand in the room while no shift is open. */
const EMPTY_DESKS = 8;

const leaderBox: DeskBox = { x: LEADER.x, y: LEADER.y, scale: 1 };

const leaderMember = computed<BoardMember | null>(() => {
    const leaderId = board.shift.value?.leader?.id ?? null;

    return board.members.value.find((m) => m.is_leader === true || (leaderId !== null && m.user?.id === leaderId)) ?? null;
});
const desks = computed(() => board.members.value.filter((m) => m.id !== leaderMember.value?.id));
const shown = computed(() => desks.value.slice(0, MAX_DESKS));
const boxes = computed(() => deskLayout(shown.value.length));
const hidden = computed(() => Math.max(0, desks.value.length - MAX_DESKS));
/** No shift open: the room's eight desks stand empty, as in the approved picture. */
const emptyBoxes = computed(() => (board.loaded.value && board.shift.value === null ? deskLayout(EMPTY_DESKS) : []));

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

    return slotCentre(isLeader ? leaderBox : boxes.value[index], at, slots.length, isLeader);
}

const walkers = computed(() =>
    board.moves.value.flatMap((move) => {
        const to = target(move.userId, move.entryId);
        const entry = board.open.value.find((e) => e.id === move.entryId);
        if (to === null || !entry) return [];

        return [{ id: move.id, from: seatCentre(move.seat), to, ticket: move.ticket % 100000, outfit: outfitOf(entry) }];
    }),
);

/**
 * The fixed stage is scaled to the wrapper: to its width on the page (never taller than the
 * window leaves room for), to the whole screen when it is big. A wrapper without a size yet
 * keeps the scale it had.
 */
function fit(): void {
    const el = wrap.value;
    if (el === null) return;

    const width = el.clientWidth;
    if (width <= 0) return;

    if (big.value) {
        const h = el.clientHeight - BAR;
        const s = stageScale(width, h > 0 ? h : null, scale.value);
        scale.value = s;
        offset.value = { x: Math.max(0, (width - STAGE_W * s) / 2), y: BAR + (h > 0 ? Math.max(0, (h - STAGE_H * s) / 2) : 0) };
        height.value = null;

        return;
    }

    // On the page: as wide as the column, never taller than the window leaves room for.
    const s = stageScale(width, Math.max(420, window.innerHeight - 170 - BAR), scale.value);
    scale.value = s;
    offset.value = { x: Math.max(0, (width - STAGE_W * s) / 2), y: BAR };
    height.value = Math.round(STAGE_H * s) + BAR;
}

async function toggleFull(): Promise<void> {
    const el = wrap.value;
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
    full.value = document.fullscreenElement !== null && document.fullscreenElement === wrap.value;
    void refit();
}

function onKey(event: KeyboardEvent): void {
    if (event.key === 'Escape' && max.value) {
        max.value = false;
        void refit();
    }
}

let observer: ResizeObserver | undefined;

onMounted(() => {
    fit();
    if (typeof ResizeObserver === 'function' && wrap.value?.parentElement) {
        observer = new ResizeObserver(fit);
        observer.observe(wrap.value.parentElement);
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
    <div ref="wrap" class="board-room" :class="{ 'is-max': max }" :style="height !== null && !big ? { height: `${height}px` } : undefined">
        <RoomSymbols />

        <div class="room-bar" :dir="dir">
            <button type="button" class="room-btn" :aria-pressed="big" @click="toggleFull">
                <svg aria-hidden="true"><use href="#br-i-full" /></svg>
                {{ big ? t('board.fullscreen.exit') : t('board.fullscreen.enter') }}
            </button>
            <button
                v-if="canManage && board.shift.value"
                type="button"
                class="room-btn"
                :aria-pressed="selection?.kind === 'roster'"
                @click="$emit('select', selection?.kind === 'roster' ? null : { kind: 'roster' })"
            >
                <svg aria-hidden="true"><use href="#br-i-team" /></svg>
                {{ t('board.roster.button') }}
            </button>
            <span class="grow">
                <template v-if="board.shift.value">
                    <b>{{ board.shift.value.name }}</b>
                    <template v-if="board.shift.value.leader"> · {{ t('board.leader.plate', { name: board.shift.value.leader.name }) }}</template>
                </template>
                <template v-else-if="board.loaded.value">{{ t('board.shift.none') }}</template>
            </span>
            <span
                class="room-chip"
                :class="{ live: board.live.value }"
                role="status"
                :title="board.live.value ? t('board.connection.live_hint') : t('board.connection.polling_hint')"
            >
                <i aria-hidden="true" />
                {{ board.live.value ? t('board.connection.live') : t('board.connection.polling') }}
            </span>
        </div>

        <div class="stage" dir="ltr" :inert="veiled" :style="{ transform: `translate(${offset.x}px, ${offset.y}px) scale(${scale})` }">
            <div class="carpet" :dir="dir" :style="{ left: `${CARPET.x}px`, top: `${CARPET.y}px`, width: `${CARPET.w}px`, height: `${CARPET.h}px` }">
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
            <RoomKpiCard />
            <RoomReception />
            <RoomLounge :selection="selection" @select="$emit('select', $event)" />

            <RoomDesk
                v-for="(member, i) in shown"
                :key="member.id"
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
</template>

<style src="./room.css"></style>
