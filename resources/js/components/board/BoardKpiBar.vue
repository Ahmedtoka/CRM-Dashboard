<script setup lang="ts">
import RelativeTime from '@/components/crm/RelativeTime.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { useI18n } from '@/composables/useI18n';
import { useBoardContext } from '@/lib/board/context';
import { formatCount } from '@/lib/format';
import { Maximize2, Minimize2, Presentation, Users } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * The board's numbers in HTML above the room (never scaled with it), the shift, the connection,
 * and the two buttons that used to float over the room. On the phone: a 2 × 3 grid, and
 * «ملء الشاشة» becomes «عرض الصالة».
 */
const props = withDefaults(
    defineProps<{
        /** The room fills the screen now. */
        big?: boolean;
        /** The roster panel is open. */
        rosterOpen?: boolean;
        /** She may open the roster (and a shift is open). */
        canRoster?: boolean;
        phone?: boolean;
        /** Only the title, the connection and the buttons (the phone's room, where the stage needs the height). */
        compact?: boolean;
    }>(),
    { big: false, rosterOpen: false, canRoster: false, phone: false, compact: false },
);
defineEmits<{ full: []; roster: [] }>();

const { t, locale } = useI18n();
const board = useBoardContext();
const n = (value: number | null | undefined) => formatCount(value ?? 0, locale.value);

/** The oldest customer of the lounge who is waiting now (the night's backlog waits for the shift). */
const oldest = computed(() => {
    let at: string | null = null;
    for (const e of board.waiting.value) {
        if (e.priority === 'overnight' || !e.enqueued_at) continue;
        if (at === null || Date.parse(e.enqueued_at) < Date.parse(at)) at = e.enqueued_at;
    }

    return at;
});

const tiles = computed(() => {
    const k = board.kpis.value;
    const overdue = board.open.value.filter((e) => e.reply_overdue === true).length;

    return {
        lounge: n(Math.max(k?.waiting ?? 0, board.waiting.value.length)),
        windows: n(board.open.value.length),
        capacity: k?.capacity ? n(k.capacity) : null,
        overdue,
        breaks: n(board.members.value.filter((m) => m.status === 'break' || m.status === 'pending_break').length),
        bot: n(board.withBot.value),
        closed: n(k?.closed_total),
        closedHint: k
            ? (['inquiry', 'problem', 'case', 'auto', 'escalation'] as const)
                  .map((key) => `${t(`board.kpi.${key}`)} ${n(k.closed?.[key])}`)
                  .join(t('board.platforms.separator'))
            : '',
        sla: k?.sla_pct === null || k?.sla_pct === undefined ? null : { value: `${n(k.sla_pct)}%`, good: k.sla_pct >= k.sla_target_pct },
    };
});
</script>

<template>
    <div class="board-kpis" :class="{ phone }">
        <div class="kpi-head">
            <div class="min-w-0">
                <h1 class="truncate text-base font-bold leading-tight text-foreground">{{ t('board.title') }}</h1>
                <p class="truncate text-xs text-muted-foreground">
                    <template v-if="board.shift.value">
                        <b class="font-semibold text-foreground">{{ board.shift.value.name }}</b>
                        <template v-if="board.shift.value.leader"> · {{ t('board.leader.plate', { name: board.shift.value.leader.name }) }}</template>
                    </template>
                    <template v-else-if="board.loaded.value">{{ t('board.shift.none') }}</template>
                    <template v-else>{{ t('board.description') }}</template>
                </p>
            </div>
            <span
                class="kpi-live"
                :class="{ live: board.live.value }"
                role="status"
                :title="board.live.value ? t('board.connection.live_hint') : t('board.connection.polling_hint')"
            >
                <i aria-hidden="true" />
                {{ board.live.value ? t('board.connection.live') : t('board.connection.polling') }}
            </span>
        </div>

        <dl v-if="board.shift.value && !compact" class="kpi-tiles">
            <div class="kpi">
                <dt>{{ t('board.kpi.lounge') }}</dt>
                <dd class="val num">{{ tiles.lounge }}</dd>
                <dd v-if="oldest" class="sub">{{ t('board.kpi.oldest') }} <RelativeTime :iso="oldest" /></dd>
            </div>
            <div class="kpi">
                <dt>{{ t('board.kpi.windows') }}</dt>
                <dd class="val num">{{ tiles.windows }}</dd>
                <dd v-if="tiles.capacity" class="sub num">{{ t('board.kpi.of_capacity', { n: tiles.capacity }) }}</dd>
            </div>
            <div class="kpi" :class="{ hot: tiles.overdue > 0 }">
                <dt>
                    <StatusChip v-if="tiles.overdue > 0" tone="overdue" dot :label="t('board.kpi.overdue')" />
                    <template v-else>{{ t('board.kpi.overdue') }}</template>
                </dt>
                <dd class="val num">{{ n(tiles.overdue) }}</dd>
            </div>
            <div class="kpi">
                <dt>{{ t('board.kpi.on_break') }}</dt>
                <dd class="val num">{{ tiles.breaks }}</dd>
            </div>
            <div class="kpi">
                <dt>{{ t('board.kpi.with_bot') }}</dt>
                <dd class="val num">{{ tiles.bot }}</dd>
            </div>
            <div class="kpi" :title="tiles.closedHint">
                <dt>{{ t('board.kpi.closed_today') }}</dt>
                <dd class="val num">{{ tiles.closed }}</dd>
                <dd v-if="tiles.sla && !phone" class="sub num" :class="tiles.sla.good ? 'good' : 'bad'">
                    {{ t('board.kpi.sla') }} {{ tiles.sla.value }}
                </dd>
            </div>
        </dl>

        <div class="kpi-actions">
            <button v-if="canRoster" type="button" class="kpi-btn" :aria-pressed="rosterOpen" @click="$emit('roster')">
                <Users class="size-4" aria-hidden="true" />
                {{ t('board.roster.button') }}
            </button>
            <button type="button" class="kpi-btn" :aria-pressed="props.big" @click="$emit('full')">
                <Presentation v-if="phone && !props.big" class="size-4" aria-hidden="true" />
                <Minimize2 v-else-if="props.big" class="size-4" aria-hidden="true" />
                <Maximize2 v-else class="size-4" aria-hidden="true" />
                {{ props.big ? t('board.fullscreen.exit') : phone ? t('board.phone.show_room') : t('board.fullscreen.enter') }}
            </button>
        </div>
    </div>
</template>
