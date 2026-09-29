<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { useBoardContext } from '@/lib/board/context';
import { formatCount, formatSeconds } from '@/lib/format';
import { computed } from 'vue';

const { t, locale, dir } = useI18n();
const board = useBoardContext();

const n = (value: number | null | undefined) => formatCount(value ?? 0, locale.value);

/** The oldest customer of the lounge who is waiting now (the night's backlog waits for the shift). */
const longest = computed(() => {
    const live = board.waiting.value.filter((e) => e.priority !== 'overnight' && e.enqueued_at !== null);
    if (live.length === 0) return null;

    return Math.max(...live.map((e) => board.secondsSince(e.enqueued_at)));
});

const rows = computed(() => {
    const k = board.kpis.value;
    const closed = k?.closed ?? {};
    const waiting = Math.max(k?.waiting ?? 0, board.waiting.value.length);
    const slaTone = k?.sla_pct === null || k?.sla_pct === undefined ? '' : k.sla_pct >= k.sla_target_pct ? 'good' : 'bad';
    const waitLimit = board.settings.value?.sla_first_reply_seconds ?? 600;

    return {
        now: [
            { key: 'waiting', label: t('board.kpi.waiting'), value: n(waiting), tone: '' },
            {
                key: 'longest',
                label: t('board.kpi.longest_wait'),
                value: longest.value === null ? '—' : formatSeconds(longest.value, locale.value),
                tone: longest.value !== null && longest.value > waitLimit ? 'bad' : '',
            },
            {
                key: 'sla',
                label: t('board.kpi.sla'),
                value: k?.sla_pct === null || k?.sla_pct === undefined ? '—' : `${n(k.sla_pct)}%`,
                tone: slaTone,
            },
            { key: 'open', label: t('board.kpi.open'), value: `${n(board.open.value.length)}/${n(k?.capacity)}`, tone: '' },
        ],
        closed: [
            { key: 'inquiry', label: t('board.kpi.inquiry'), value: n(closed.inquiry), tone: '' },
            { key: 'problem', label: t('board.kpi.problem'), value: n(closed.problem), tone: '' },
            { key: 'case', label: t('board.kpi.case'), value: n(closed.case), tone: 'case' },
            { key: 'auto', label: t('board.kpi.auto'), value: n(closed.auto), tone: '' },
            { key: 'escalation', label: t('board.kpi.escalation'), value: n(closed.escalation), tone: '' },
            { key: 'issued', label: t('board.kpi.issued'), value: n(k?.issued), tone: '' },
        ],
    };
});
</script>

<template>
    <section class="kpicard" :dir="dir" :aria-label="t('board.kpi.title')">
        <div class="t">{{ t('board.kpi.title') }}</div>
        <div v-for="row in rows.now" :key="row.key" class="row" :class="row.tone">
            <span>{{ row.label }}</span>
            <b class="num">{{ row.value }}</b>
        </div>
        <div class="sep" aria-hidden="true" />
        <div class="t">{{ t('board.kpi.closed_today') }}</div>
        <div v-for="row in rows.closed" :key="row.key" class="row" :class="row.tone">
            <span>{{ row.label }}</span>
            <b class="num">{{ row.value }}</b>
        </div>
    </section>
</template>
