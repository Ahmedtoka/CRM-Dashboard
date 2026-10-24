<script setup lang="ts">
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import PeriodControls from '@/components/crm/reports/PeriodControls.vue';
import { teamTabs } from '@/components/crm/reports/teamTabs';
import SectionTabs from '@/components/crm/SectionTabs.vue';
import SkeletonList from '@/components/crm/SkeletonList.vue';
import Ledger, { type LedgerFigure } from '@/components/today/Ledger.vue';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { useUrlFilters } from '@/composables/useUrlFilters';
import { useVisitLoading } from '@/composables/useVisitLoading';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCount, formatMoney, formatSeconds } from '@/lib/format';
import { sortRows } from '@/lib/sort';
import type { ReportRange } from '@/types/admin';
import type { PointsKind, QueueReport, QueueReportRow, ServiceCostReport } from '@/types/queue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Download, ListOrdered } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * Reports › «الطابور» (queue Part 2): the queue's report for a range of business days — the day's
 * tiles and one row per moderator (closes, times, ratings, QA, cases, points per kind). Test and
 * load-test chats are never in it. Sorted client-side; the CSV carries the same rows.
 */
const props = defineProps<{ range: ReportRange; report: QueueReport; service_cost: ServiceCostReport }>();

const { t, locale } = useI18n();
const page = usePage();
const tabs = computed(() => teamTabs(t, page.url));
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

// The day's tiles as two ledgers (mockup V.team › الطابور): the windows and times, then the cases and ratings.
const queueFigures = computed<LedgerFigure[]>(() => [
    {
        key: 'tickets',
        label: t('reports.queue.kpi.tickets'),
        value: n(k.value.tickets),
        note: t('reports.queue.kpi.windows', { n: n(k.value.windows) }),
    },
    { key: 'handled', label: t('reports.queue.kpi.handled'), value: n(k.value.handled) },
    {
        key: 'first_reply',
        label: t('reports.queue.kpi.first_reply'),
        value: secs(k.value.first_reply_avg_seconds),
        note: t('reports.queue.kpi.handle', { time: secs(k.value.handle_avg_seconds) }),
    },
    { key: 'sla', label: t('reports.queue.kpi.sla'), value: pct(k.value.sla_pct) },
]);
const caseFigures = computed<LedgerFigure[]>(() => [
    {
        key: 'rating',
        label: t('reports.queue.kpi.rating'),
        value: avg(k.value.rating_avg),
        note: t('reports.queue.kpi.ratings', { n: n(k.value.rating_count) }),
    },
    { key: 'cases_opened', label: t('reports.queue.kpi.cases_opened'), value: n(k.value.cases_opened) },
    {
        key: 'cases_resolved',
        label: t('reports.queue.kpi.cases_resolved'),
        value: n(k.value.cases_resolved),
        note: t('reports.queue.kpi.in_time', { n: n(k.value.cases_resolved_in_time) }),
    },
    {
        key: 'cases_overdue',
        label: t('reports.queue.kpi.cases_overdue'),
        value: n(k.value.cases_overdue),
        tone: k.value.cases_overdue > 0 ? 'down' : 'neutral',
    },
]);
// «تكلفة الخدمة» (round 2026-10-24): what a handled conversation and an order from a chat cost, team + bot.
const money = (v: number | null | undefined) => (v === null || v === undefined ? '—' : formatMoney(v, locale.value));
const usd = (v: number) => `$${v.toLocaleString(locale.value === 'ar' ? 'ar-EG' : 'en', { minimumFractionDigits: 2, maximumFractionDigits: 4 })}`;
const costFigures = computed<LedgerFigure[]>(() => {
    const c = props.service_cost;
    return [
        {
            key: 'per_conversation',
            label: t('reports.queue.cost.per_conversation'),
            value: money(c.cost_per_conversation_egp),
            note: t('reports.queue.cost.per_conversation_note', {
                team: money(c.team_cost_per_conversation_egp),
                bot: money(c.bot_cost_per_conversation_egp),
            }),
        },
        {
            key: 'per_order',
            label: t('reports.queue.cost.per_order'),
            value: money(c.cost_per_order_egp),
            note: t('reports.queue.cost.per_order_note', { n: n(c.orders_from_chats) }),
        },
        {
            key: 'team',
            label: t('reports.queue.cost.team'),
            value: money(c.team_cost_egp),
            note: t('reports.queue.cost.team_note', { monthly: money(c.team_monthly_cost_egp), days: n(c.days), n: n(c.handled_conversations) }),
        },
        {
            key: 'bot',
            label: t('reports.queue.cost.bot'),
            value: c.bot_cost_egp === null ? usd(c.bot_cost_usd) : money(c.bot_cost_egp),
            note: t('reports.queue.cost.bot_note', {
                usd: usd(c.bot_cost_usd),
                rate: c.usd_egp_rate === null ? '—' : n(c.usd_egp_rate),
                n: n(c.bot_conversations),
            }),
        },
        { key: 'total', label: t('reports.queue.cost.total'), value: money(c.total_egp) },
    ];
});
const breadcrumbs = computed(() => [{ title: t('reports.queue.title'), href: '/reports/queue' }]);
</script>

<template>
    <Head :title="t('reports.queue.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-7xl space-y-4 p-3 md:p-6">
            <PageHeader :title="t('reports.hub.title')" :description="t('reports.hub.description')">
                <PeriodControls :range="range" :show-platform="false" @change="visit" />
                <Button as="a" :href="exportHref" variant="secondary" :aria-label="t('reports.queue.export')" data-export>
                    <Download aria-hidden="true" />
                    {{ t('reports.hub.csv') }}
                </Button>
            </PageHeader>
            <SectionTabs :tabs="tabs" :label="t('section_tabs.team.label')" />

            <p class="text-13 text-fg-2">{{ t('reports.queue.scope') }}</p>

            <SkeletonList v-if="loading" variant="tiles" :count="8" />
            <div v-else class="space-y-3">
                <Ledger :figures="queueFigures" :label="t('reports.hub.queue_figures')" />
                <Ledger :figures="caseFigures" :label="t('reports.hub.cases_figures')" />
            </div>

            <section class="space-y-2" :aria-label="t('reports.queue.cost.title')" data-service-cost>
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5">
                    <h2 class="text-base font-bold">{{ t('reports.queue.cost.title') }}</h2>
                    <p class="text-13 text-fg-2">{{ t('reports.queue.cost.scope') }}</p>
                </div>
                <p v-if="service_cost.missing.length" class="rounded-md border border-line bg-sunken px-3 py-2 text-13" data-service-cost-missing>
                    {{ t('reports.queue.cost.missing') }}
                    <Link href="/settings/queue" class="font-semibold text-brand-ink underline">{{ t('reports.queue.cost.settings_link') }}</Link>
                </p>
                <SkeletonList v-if="loading" variant="tiles" :count="5" />
                <Ledger v-else :figures="costFigures" :label="t('reports.queue.cost.title')" />
                <p class="text-xs leading-5 text-fg-3">{{ t('reports.queue.cost.formula') }}</p>
            </section>

            <div v-if="report.rows.length === 0" class="rounded-lg border border-line bg-card">
                <EmptyState :icon="ListOrdered" :title="t('reports.queue.empty')" :body="t('reports.queue.empty_body')" />
            </div>

            <section v-else class="space-y-2" :aria-label="t('reports.queue.per_moderator')">
                <h2 class="text-base font-bold">{{ t('reports.queue.per_moderator') }}</h2>
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
                        <span class="inline-flex items-center gap-2 whitespace-nowrap font-semibold">
                            <span
                                class="size-2.5 shrink-0 rounded-full"
                                :style="{ backgroundColor: row.user.color ?? 'hsl(var(--fg-3))' }"
                                aria-hidden="true"
                            />
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
                        <span v-if="row.rating_count" class="ms-1 text-xs tabular-nums text-fg-3">({{ n(row.rating_count) }})</span>
                    </template>
                    <template #cell-qa_avg="{ row }">
                        <span class="tabular-nums">{{ avg(row.qa_avg) }}</span>
                        <span v-if="row.qa_count" class="ms-1 text-xs tabular-nums text-fg-3">({{ n(row.qa_count) }})</span>
                    </template>
                    <template #cell-cases_resolved="{ value }"
                        ><span class="tabular-nums">{{ n(value as number) }}</span></template
                    >
                    <template v-for="kind in KINDS" :key="kind" #[`cell-points_${kind}`]="{ row }">
                        <span class="tabular-nums" :class="(row.points[kind] ?? 0) < 0 ? 'text-danger' : ''">{{ n(row.points[kind] ?? 0) }}</span>
                    </template>
                    <template #cell-total="{ value }"
                        ><span class="font-bold tabular-nums">{{ n(value as number) }}</span></template
                    >
                </DataTable>
            </section>
        </div>
    </AppLayout>
</template>
