<script setup lang="ts">
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
import DateRangePicker from '@/components/crm/DateRangePicker.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import StatCard from '@/components/crm/StatCard.vue';
import { useI18n } from '@/composables/useI18n';
import { useUrlFilters } from '@/composables/useUrlFilters';
import { useVisitLoading } from '@/composables/useVisitLoading';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCount, formatSeconds } from '@/lib/format';
import { sortRows } from '@/lib/sort';
import type { ReportRange } from '@/types/admin';
import type { PointsKind, QueueReport, QueueReportRow } from '@/types/queue';
import { Head, router } from '@inertiajs/vue3';
import { Download, ListOrdered } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * Reports › «الطابور» (queue Part 2): the queue's report for a range of business days — the day's
 * tiles and one row per moderator (closes, times, ratings, QA, cases, points per kind). Test and
 * load-test chats are never in it. Sorted client-side; the CSV carries the same rows.
 */
const props = defineProps<{ range: ReportRange; report: QueueReport }>();

const { t, locale } = useI18n();
const { filters: sorts, set: setSort } = useUrlFilters({ sort: '' });
const { loading, track } = useVisitLoading();
const n = (v: number | null | undefined) => (v === null || v === undefined ? '—' : formatCount(v, locale.value));
const secs = (v: number | null) => (v === null ? '—' : formatSeconds(v, locale.value));
const pct = (v: number | null) => (v === null ? '—' : `${formatCount(v, locale.value)}%`);
const avg = (v: number | null) => (v === null ? '—' : v.toLocaleString(locale.value === 'ar' ? 'ar-EG' : 'en', { maximumFractionDigits: 1 }));

function visit(range: ReportRange): void {
    router.get('/reports/queue', { from: range.from, to: range.to }, track({ preserveScroll: true }));
}

const exportHref = computed(() => `/reports/queue/export?${new URLSearchParams({ from: props.range.from, to: props.range.to }).toString()}`);

const KINDS: PointsKind[] = ['base', 'speed', 'case', 'review', 'qa', 'no_reply'];

const rows = computed(() =>
    sortRows(
        props.report.rows.map((r) => ({ ...r, id: r.user.id })),
        sorts.value.sort || null,
        (row: QueueReportRow & { id: number }, key: string) => {
            if (key.startsWith('closed_')) return row.closed[key.slice(7)] ?? 0;
            if (key.startsWith('points_')) return row.points[key.slice(7) as PointsKind] ?? 0;
            if (key === 'user') return row.user.name;
            return (row as unknown as Record<string, unknown>)[key];
        },
    ),
);

const columns = computed<Column[]>(() => [
    { key: 'user', label: t('reports.queue.moderator'), sortable: true },
    { key: 'received', label: t('reports.queue.received'), numeric: true, sortable: true },
    ...(['inquiry', 'problem', 'case', 'auto', 'escalation', 'no_reply'] as const).map((r) => ({
        key: `closed_${r}`,
        label: t(`board.day.reason.${r}`),
        numeric: true,
        sortable: true,
    })),
    { key: 'first_reply_avg_seconds', label: t('reports.queue.first_reply'), numeric: true, sortable: true },
    { key: 'handle_avg_seconds', label: t('reports.queue.handle'), numeric: true, sortable: true },
    { key: 'sla_pct', label: t('reports.queue.sla'), numeric: true, sortable: true },
    { key: 'rating_avg', label: t('reports.queue.rating'), numeric: true, sortable: true },
    { key: 'qa_avg', label: t('reports.queue.qa'), numeric: true, sortable: true },
    { key: 'cases_resolved', label: t('reports.queue.cases_resolved'), numeric: true, sortable: true },
    ...KINDS.map((k) => ({ key: `points_${k}`, label: t(`queue_points.kinds.${k}`), numeric: true, sortable: true })),
    { key: 'total', label: t('reports.queue.total'), numeric: true, sortable: true },
]);

const k = computed(() => props.report.kpis);
const breadcrumbs = computed(() => [{ title: t('reports.queue.title'), href: '/reports/queue' }]);
</script>

<template>
    <Head :title="t('reports.queue.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-7xl space-y-4 p-3 md:p-6">
            <PageHeader :title="t('reports.queue.title')" :description="t('reports.queue.description')">
                <a
                    :href="exportHref"
                    class="inline-flex h-8 items-center gap-1.5 rounded-md border border-border bg-card px-3 text-xs font-medium hover:bg-muted/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                    <Download class="size-3.5" aria-hidden="true" />
                    {{ t('reports.queue.export') }}
                </a>
            </PageHeader>

            <div class="rounded-lg bg-card p-3 shadow-card">
                <DateRangePicker :model-value="range" @update:model-value="visit" />
                <p class="mt-2 text-2xs text-muted-foreground">{{ t('reports.queue.scope') }}</p>
            </div>

            <div class="grid grid-cols-2 gap-3 md:grid-cols-4" :aria-busy="loading">
                <StatCard :label="t('reports.queue.kpi.tickets')" :value="n(k.tickets)" :hint="t('reports.queue.kpi.windows', { n: n(k.windows) })" />
                <StatCard :label="t('reports.queue.kpi.handled')" :value="n(k.handled)" />
                <StatCard
                    :label="t('reports.queue.kpi.first_reply')"
                    :value="secs(k.first_reply_avg_seconds)"
                    :hint="t('reports.queue.kpi.handle', { time: secs(k.handle_avg_seconds) })"
                />
                <StatCard :label="t('reports.queue.kpi.sla')" :value="pct(k.sla_pct)" />
                <StatCard
                    :label="t('reports.queue.kpi.rating')"
                    :value="avg(k.rating_avg)"
                    :hint="t('reports.queue.kpi.ratings', { n: n(k.rating_count) })"
                />
                <StatCard :label="t('reports.queue.kpi.cases_opened')" :value="n(k.cases_opened)" />
                <StatCard
                    :label="t('reports.queue.kpi.cases_resolved')"
                    :value="n(k.cases_resolved)"
                    :hint="t('reports.queue.kpi.in_time', { n: n(k.cases_resolved_in_time) })"
                />
                <StatCard
                    :label="t('reports.queue.kpi.cases_overdue')"
                    :value="n(k.cases_overdue)"
                    :tone="k.cases_overdue > 0 ? 'negative' : 'default'"
                />
            </div>

            <EmptyState v-if="report.rows.length === 0" :icon="ListOrdered" :title="t('reports.queue.empty')" :body="t('reports.queue.empty_body')" />

            <section v-else class="space-y-2">
                <h2 class="text-sm font-medium">{{ t('reports.queue.per_moderator') }}</h2>
                <DataTable
                    table-id="report-queue"
                    :sort="sorts.sort || null"
                    @update:sort="setSort({ sort: $event })"
                    :columns="columns"
                    :rows="rows"
                    :empty="t('reports.queue.empty')"
                    :caption="t('reports.queue.per_moderator')"
                >
                    <template #cell-user="{ row }">
                        <span class="inline-flex items-center gap-2 whitespace-nowrap font-medium">
                            <span class="size-2.5 rounded-full" :style="{ backgroundColor: row.user.color ?? '#94a3b8' }" aria-hidden="true" />
                            {{ row.user.name }}
                        </span>
                    </template>
                    <template v-for="r in ['inquiry', 'problem', 'case', 'auto', 'escalation', 'no_reply']" :key="r" #[`cell-closed_${r}`]="{ row }">
                        <span class="tabular-nums">{{ n(row.closed[r] ?? 0) }}</span>
                    </template>
                    <template #cell-received="{ value }"
                        ><span class="tabular-nums">{{ n(value as number) }}</span></template
                    >
                    <template #cell-first_reply_avg_seconds="{ value }"
                        ><span class="tabular-nums">{{ secs(value as number | null) }}</span></template
                    >
                    <template #cell-handle_avg_seconds="{ value }"
                        ><span class="tabular-nums">{{ secs(value as number | null) }}</span></template
                    >
                    <template #cell-sla_pct="{ value }"
                        ><span class="tabular-nums">{{ pct(value as number | null) }}</span></template
                    >
                    <template #cell-rating_avg="{ row }">
                        <span class="tabular-nums">{{ avg(row.rating_avg) }}</span>
                        <span v-if="row.rating_count" class="ms-1 text-2xs text-muted-foreground">({{ n(row.rating_count) }})</span>
                    </template>
                    <template #cell-qa_avg="{ row }">
                        <span class="tabular-nums">{{ avg(row.qa_avg) }}</span>
                        <span v-if="row.qa_count" class="ms-1 text-2xs text-muted-foreground">({{ n(row.qa_count) }})</span>
                    </template>
                    <template #cell-cases_resolved="{ value }"
                        ><span class="tabular-nums">{{ n(value as number) }}</span></template
                    >
                    <template v-for="kind in KINDS" :key="kind" #[`cell-points_${kind}`]="{ row }">
                        <span class="tabular-nums" :class="(row.points[kind] ?? 0) < 0 ? 'text-destructive' : ''">{{
                            n(row.points[kind] ?? 0)
                        }}</span>
                    </template>
                    <template #cell-total="{ value }"
                        ><span class="font-bold tabular-nums">{{ n(value as number) }}</span></template
                    >
                </DataTable>
            </section>
        </div>
    </AppLayout>
</template>
