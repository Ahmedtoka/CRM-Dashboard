<script setup lang="ts">
import BarChart from '@/components/crm/BarChart.vue';
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import Leaderboard from '@/components/crm/Leaderboard.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import PeriodControls from '@/components/crm/reports/PeriodControls.vue';
import RatingsSection from '@/components/crm/reports/RatingsSection.vue';
import { teamTabs } from '@/components/crm/reports/teamTabs';
import SectionTabs from '@/components/crm/SectionTabs.vue';
import SkeletonList from '@/components/crm/SkeletonList.vue';
import Ledger, { type LedgerFigure } from '@/components/today/Ledger.vue';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { useReportFilters } from '@/composables/useReportFilters';
import { formatNumber } from '@/i18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatAvgSeconds, formatCount, formatMoney, formatSeconds } from '@/lib/format';
import { sortRows } from '@/lib/sort';
import type { SharedData } from '@/types';
import type { HeatmapGrid, LeaderboardRow, ReportRange, TeamMetrics } from '@/types/admin';
import type { PlatformValue } from '@/types/crm';
import type { TeamQueueReport, TeamQueueRow } from '@/types/queue';
import type { TeamRatings } from '@/types/today';
import { Head, router, usePage } from '@inertiajs/vue3';
import { BarChart3, Download, ListOrdered } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    range: ReportRange;
    platform: PlatformValue | null;
    metrics: TeamMetrics;
    leaderboard: LeaderboardRow[];
    heatmap: HeatmapGrid;
    online_user_ids: number[];
    ratings: TeamRatings;
    team_queue: TeamQueueReport;
}>();

const { t, locale } = useI18n();
const page = usePage<SharedData>();
const { visit, loading } = useReportFilters();
const n = (v: number) => formatCount(v, locale.value);
const tabs = computed(() => teamTabs(t, page.url));

// The period's ledger (mockup V.team): six figures; the bot's messages and the chats needing a person sit as notes.
const figures = computed<LedgerFigure[]>(() => {
    const m = props.metrics;
    return [
        { key: 'inbound', label: t('reports.cards.inbound'), value: n(m.inbound_messages) },
        {
            key: 'outbound',
            label: t('reports.cards.outbound'),
            value: n(m.outbound_messages),
            note: t('reports.hub.bot_note', { n: n(m.bot_messages) }),
        },
        { key: 'first_response', label: t('reports.cards.avg_first_response'), value: formatAvgSeconds(m.avg_first_response_sec, locale.value) },
        {
            key: 'waiting',
            label: t('reports.cards.waiting_now'),
            value: n(m.waiting_now),
            note: t('reports.hub.needs_human_note', { n: n(m.needs_human_now) }),
            tone: m.needs_human_now > 0 ? 'down' : 'neutral',
        },
        { key: 'orders', label: t('reports.cards.orders'), value: n(m.orders_count) },
        { key: 'revenue', label: t('reports.cards.revenue'), value: formatMoney(m.orders_total, locale.value) },
    ];
});

// Platform series: bars take the platform's brand color.
const byPlatform = computed(() =>
    (page.props.platforms ?? []).map((p) => ({
        key: p.value,
        label: p.label,
        color: p.color,
        value: props.metrics.by_platform?.[p.value]?.inbound ?? 0,
    })),
);
const revenueByPlatform = computed(() =>
    (page.props.platforms ?? []).map((p) => ({
        key: p.value,
        label: p.label,
        color: p.color,
        value: props.metrics.by_platform?.[p.value]?.orders_total ?? 0,
    })),
);

const byHour = computed(() =>
    (props.metrics.by_hour ?? []).map((value, hour) => ({
        key: String(hour),
        label: formatNumber(locale.value, hour, { useGrouping: false }),
        value,
    })),
);

function openUser(userId: number): void {
    router.visit(`/reports/users/${userId}?from=${props.range.from}&to=${props.range.to}`);
}

// Nothing happened in the range: no messages, no orders, nobody on the leaderboard.
const empty = computed(() => {
    const m = props.metrics;
    return m.inbound_messages + m.outbound_messages + m.bot_messages + m.orders_count === 0 && props.leaderboard.length === 0;
});

/*
 * «الموظفات في الطابور» (TeamReport): one row per moderator, the outcome columns built from the keys
 * the range actually holds — a renamed or new outcome is a column by itself, labelled by its
 * translation when the dictionary knows it and by the raw key otherwise. Sorted client-side.
 */
const secs = (v: number | null | undefined) => (v === null || v === undefined ? '—' : formatSeconds(v, locale.value));
const avg = (v: number | null) => (v === null ? '—' : v.toLocaleString(locale.value === 'ar' ? 'ar-EG' : 'en', { maximumFractionDigits: 1 }));
const queueSort = ref<string | null>(null);
const queueExportHref = computed(
    () => `/reports/team/queue-export?${new URLSearchParams({ from: props.range.from, to: props.range.to }).toString()}`,
);

// translate() falls back to the key itself when neither dictionary has it: that is the "unknown key" signal.
function outcomeLabel(key: string): string {
    const label = t(`outcomes.${key}`);
    return label === `outcomes.${key}` ? key : label;
}

const queueRows = computed(() =>
    sortRows(
        props.team_queue.rows.map((r) => ({ ...r, id: r.user.id })),
        queueSort.value,
        (row: TeamQueueRow & { id: number }, key: string) => {
            if (key.startsWith('outcome_')) return row.outcomes[key.slice(8)] ?? 0;
            if (key === 'user') return row.user.name;
            return (row as unknown as Record<string, unknown>)[key];
        },
    ),
);

const queueColumns = computed<Column[]>(() => [
    { key: 'user', label: t('reports.team_queue.moderator'), sortable: true },
    { key: 'received', label: t('reports.team_queue.received'), numeric: true, sortable: true },
    { key: 'handled', label: t('reports.team_queue.handled'), numeric: true, sortable: true },
    { key: 'first_reply_median_seconds', label: t('reports.team_queue.first_reply_median'), numeric: true, sortable: true },
    { key: 'auto_closes', label: t('reports.team_queue.auto_closes'), numeric: true, sortable: true },
    { key: 'hand_offs', label: t('reports.team_queue.hand_offs'), numeric: true, sortable: true },
    { key: 'rating_avg', label: t('reports.team_queue.rating'), numeric: true, sortable: true },
    { key: 'points', label: t('reports.team_queue.points'), numeric: true, sortable: true },
    { key: 'open_cases', label: t('reports.team_queue.open_cases'), numeric: true, sortable: true },
    ...props.team_queue.outcome_keys.map((key) => ({ key: `outcome_${key}`, label: outcomeLabel(key), numeric: true, sortable: true })),
]);

const totals = computed(() => props.team_queue.totals);

/** The footer's cell for a column: the same shape as the rows, from the team's totals. */
function totalCell(key: string): string {
    const tt = totals.value;
    if (key === 'user') return t('reports.team_queue.totals');
    if (key === 'first_reply_median_seconds') return secs(tt.first_reply_median_seconds);
    if (key === 'rating_avg') return tt.rating_count ? `${avg(tt.rating_avg)} (${n(tt.rating_count)})` : '—';
    if (key === 'hand_offs') return n(tt.hand_offs);
    if (key.startsWith('outcome_')) return n(tt.outcomes[key.slice(8)] ?? 0);
    return n((tt as unknown as Record<string, number>)[key] ?? 0);
}

const breadcrumbs = computed(() => [{ title: t('reports.team_title'), href: '/reports/team' }]);
</script>

<template>
    <Head :title="t('reports.team_title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-7xl space-y-4 p-3 md:p-6">
            <PageHeader :title="t('reports.hub.title')" :description="t('reports.hub.description')">
                <PeriodControls :range="range" :platform="platform" @change="visit" />
            </PageHeader>
            <SectionTabs :tabs="tabs" :label="t('section_tabs.team.label')" />

            <SkeletonList v-if="loading" variant="tiles" :count="6" />
            <Ledger v-else :figures="figures" :label="t('reports.hub.figures')" />

            <div v-if="empty && !loading" class="rounded-lg border border-line bg-card">
                <EmptyState :icon="BarChart3" :title="t('reports.no_data')" />
            </div>
            <template v-else>
                <section class="space-y-2" :aria-label="t('reports.leaderboard')">
                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5">
                        <h2 class="text-base font-bold">{{ t('reports.leaderboard') }}</h2>
                        <p class="text-13 text-fg-2">{{ t('reports.leaderboard_hint') }}</p>
                    </div>
                    <Leaderboard :rows="leaderboard" :online-user-ids="online_user_ids" @open="openUser" />
                </section>

                <div class="grid gap-4 lg:grid-cols-2 [&>*]:min-w-0">
                    <BarChart :title="t('reports.by_platform')" :items="byPlatform" :format="n" />
                    <BarChart :title="t('reports.revenue_by_platform')" :items="revenueByPlatform" :format="(v) => formatMoney(v, locale)" />
                </div>
                <BarChart :title="t('reports.by_hour')" :items="byHour" orientation="vertical" :label-every="3" :format="n" />
            </template>

            <section class="space-y-2" :aria-label="t('reports.team_queue.title')" data-team-queue>
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5">
                        <h2 class="text-base font-bold">{{ t('reports.team_queue.title') }}</h2>
                        <p class="text-13 text-fg-2">{{ t('reports.team_queue.hint') }}</p>
                    </div>
                    <Button
                        v-if="team_queue.rows.length > 0"
                        as="a"
                        :href="queueExportHref"
                        variant="secondary"
                        size="sm"
                        :aria-label="t('reports.team_queue.export')"
                        data-team-queue-export
                    >
                        <Download aria-hidden="true" />
                        {{ t('reports.hub.csv') }}
                    </Button>
                </div>

                <SkeletonList v-if="loading" variant="table" :count="4" />
                <p v-else-if="team_queue.too_long" class="rounded-lg border border-line bg-card p-4 text-13 text-fg-2">
                    {{ t('reports.team_queue.too_long', { n: 92 }) }}
                </p>
                <div v-else-if="team_queue.rows.length === 0" class="rounded-lg border border-line bg-card">
                    <EmptyState :icon="ListOrdered" :title="t('reports.team_queue.empty')" />
                </div>
                <DataTable
                    table-id="report-team-queue"
                    v-else
                    :sort="queueSort"
                    @update:sort="queueSort = $event"
                    :columns="queueColumns"
                    :rows="queueRows"
                    :empty="t('reports.team_queue.empty')"
                    :caption="t('reports.team_queue.title')"
                    mobile="scroll"
                >
                    <template #cell-user="{ row }">
                        <span class="inline-flex items-center gap-2 whitespace-nowrap font-semibold">
                            <span
                                class="size-2.5 shrink-0 rounded-full"
                                :style="{ backgroundColor: row.user.color ?? 'hsl(var(--fg-3))' }"
                                aria-hidden="true"
                            />
                            {{ row.user.name }}
                        </span>
                    </template>
                    <template #cell-first_reply_median_seconds="{ value }"
                        ><span class="tabular-nums">{{ secs(value as number | null) }}</span></template
                    >
                    <template #cell-auto_closes="{ value }">
                        <span class="tabular-nums" :class="(value as number) > 0 ? 'text-danger' : ''">{{ n(value as number) }}</span>
                    </template>
                    <template #cell-hand_offs="{ row }">
                        <span class="tabular-nums">{{ n(row.hand_offs) }}</span>
                        <span v-if="row.hand_offs" class="ms-1 whitespace-nowrap text-xs tabular-nums text-fg-3">
                            {{
                                t('reports.team_queue.hand_offs_split', { no_reply: n(row.hand_offs_no_reply), transfer: n(row.hand_offs_transfer) })
                            }}
                        </span>
                    </template>
                    <template #cell-rating_avg="{ row }">
                        <span class="tabular-nums">{{ avg(row.rating_avg) }}</span>
                        <span v-if="row.rating_count" class="ms-1 text-xs tabular-nums text-fg-3">({{ n(row.rating_count) }})</span>
                    </template>
                    <template #cell-points="{ value }">
                        <span class="font-bold tabular-nums" :class="(value as number) < 0 ? 'text-danger' : ''">{{ n(value as number) }}</span>
                    </template>
                    <template #cell-open_cases="{ value }">
                        <span class="tabular-nums" :class="(value as number) > 0 ? 'text-danger' : ''">{{ n(value as number) }}</span>
                    </template>
                    <template v-for="key in team_queue.outcome_keys" :key="key" #[`cell-outcome_${key}`]="{ row }">
                        <span class="tabular-nums">{{ n(row.outcomes[key] ?? 0) }}</span>
                    </template>
                    <template #totals="{ columns }">
                        <tr data-team-queue-totals>
                            <td v-for="c in columns" :key="c.key" class="px-3 py-2.5 text-[13px]" :class="c.numeric ? 'text-end tabular-nums' : ''">
                                {{ totalCell(c.key) }}
                            </td>
                        </tr>
                    </template>
                </DataTable>
            </section>

            <RatingsSection :ratings="ratings" :range="range" />
        </div>
    </AppLayout>
</template>
