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
import { useVisitLoading } from '@/composables/useVisitLoading';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCount, formatSeconds } from '@/lib/format';
import { OUTCOME_GROUPS } from '@/lib/outcomes';
import type { ReportRange } from '@/types/admin';
import type { OutcomeKey } from '@/types/crm';
import type { OutcomesFigures, OutcomesGroup, OutcomesPeriod, OutcomesReport, OutcomesRow } from '@/types/outcomes';
import { Head, router, usePage } from '@inertiajs/vue3';
import { Download, MessagesSquare } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * Reports › الفريق › «نتائج المحادثات» (2026-10-24): what happened in the chats that ended in a
 * range of days — the totals, the split by outcome, and a row per period and per moderator.
 * The bars use one colour (the order is the legend), the table carries the exact counts; the CSV
 * holds the same numbers.
 */
const props = defineProps<{
    range: ReportRange;
    group: OutcomesGroup;
    userId: number | null;
    moderators: { id: number; name: string; color: string | null }[];
    report: OutcomesReport;
}>();

const { t, locale } = useI18n();
const page = usePage();
const tabs = computed(() => teamTabs(t, page.url));
const { loading, track } = useVisitLoading();
const n = (v: number | null | undefined) => (v === null || v === undefined ? '—' : formatCount(v, locale.value));
const pct = (v: number | null) => (v === null ? '—' : `${formatCount(v, locale.value)}%`);
const secs = (v: number | null) => (v === null ? '—' : formatSeconds(v, locale.value));

function visit(next: { from?: string; to?: string; group?: OutcomesGroup; user?: number | null }): void {
    const query: Record<string, string | number> = {
        from: next.from ?? props.range.from,
        to: next.to ?? props.range.to,
        group: next.group ?? props.group,
    };
    const user = next.user === undefined ? props.userId : next.user;
    if (user !== null) query.user = user;
    router.get('/reports/outcomes', query, track({ preserveScroll: true }));
}

const exportHref = computed(() => {
    const query = new URLSearchParams({ from: props.range.from, to: props.range.to, group: props.group });
    if (props.userId !== null) query.set('user', String(props.userId));

    return `/reports/outcomes/export?${query.toString()}`;
});

const k = computed(() => props.report.kpis);
const GROUPS: OutcomesGroup[] = ['day', 'week', 'month'];

const figures = computed<LedgerFigure[]>(() => [
    {
        key: 'total',
        label: t('reports.outcomes.kpi.total'),
        value: n(k.value.total),
        note: t('reports.outcomes.kpi.counted', { n: n(k.value.counted) }),
    },
    {
        key: 'purchases',
        label: t('reports.outcomes.kpi.purchases'),
        value: n(k.value.purchases),
        note: k.value.purchase_pct === null ? null : t('reports.outcomes.kpi.purchase_pct', { n: n(k.value.purchase_pct) }),
        tone: 'up',
    },
    {
        key: 'no_reply',
        label: t('reports.outcomes.kpi.no_reply'),
        value: pct(k.value.no_reply_pct),
        note: t('reports.outcomes.kpi.no_reply_pct', { n: n(k.value.no_reply) }),
    },
    { key: 'returns', label: t('reports.outcomes.kpi.returns'), value: n(k.value.returns) },
    {
        key: 'complaints',
        label: t('reports.outcomes.kpi.complaints'),
        value: n(k.value.complaints),
        note: t('reports.outcomes.kpi.complaints_pending', { n: n(k.value.complaints_pending) }),
        tone: k.value.complaints_pending > 0 ? 'down' : 'neutral',
    },
    { key: 'avg_close', label: t('reports.outcomes.kpi.avg_close'), value: secs(k.value.avg_close_seconds) },
]);
const followed = computed(() =>
    k.value.suggested === 0
        ? t('reports.outcomes.kpi.no_suggestions')
        : `${t('reports.outcomes.kpi.followed')}: ${pct(k.value.followed_pct)} · ${t('reports.outcomes.kpi.followed_of', { n: n(k.value.suggested) })}`,
);

/** The split by outcome, in the sheet's order, with the share of the counted chats. Spam and "not recorded" sit last, outside the shares. */
const split = computed(() => {
    const total = Math.max(1, k.value.counted);
    const keys: OutcomeKey[] = [...OUTCOME_GROUPS.flatMap((g) => g.outcomes).filter((o) => o !== 'spam'), 'spam', 'unknown'];

    return keys
        .map((key) => ({
            key,
            count: props.report.by_outcome[key] ?? 0,
            share: key === 'spam' || key === 'unknown' ? null : (props.report.by_outcome[key] ?? 0) / total,
        }))
        .filter((row) => row.count > 0);
});
const maxCount = computed(() => Math.max(1, ...split.value.map((r) => r.count)));

type Row = (OutcomesRow | OutcomesPeriod) & { id: string | number };
type UserRow = OutcomesRow & { id: string | number };
const columns = (first: { key: string; label: string }): Column[] => [
    { key: first.key, label: first.label, primary: true },
    { key: 'total', label: t('reports.outcomes.total'), numeric: true },
    { key: 'purchases', label: t('reports.outcomes.purchases'), numeric: true },
    { key: 'no_reply_pct', label: t('reports.outcomes.no_reply_pct'), numeric: true },
    { key: 'returns', label: t('reports.outcomes.returns'), numeric: true },
    { key: 'complaints', label: t('reports.outcomes.complaints'), numeric: true },
    { key: 'complaints_pending', label: t('reports.outcomes.complaints_pending'), numeric: true },
    { key: 'avg_close_seconds', label: t('reports.outcomes.avg_close'), numeric: true, hideOnMobile: true },
    { key: 'followed_pct', label: t('reports.outcomes.followed_pct'), numeric: true, hideOnMobile: true },
];
const userColumns = computed(() => columns({ key: 'user', label: t('reports.outcomes.moderator') }));
const periodColumns = computed(() => columns({ key: 'key', label: t('reports.outcomes.period') }));
const userRows = computed<UserRow[]>(() => props.report.rows.map((r) => ({ ...r, id: r.user?.id ?? 'auto' })));
const periodRows = computed<Row[]>(() => props.report.periods.map((p) => ({ ...p, id: p.key })));

function periodLabel(row: OutcomesPeriod): string {
    return props.report.group === 'week' ? t('reports.outcomes.week_of', { date: row.from }) : row.key;
}

const cell = (value: number | null, suffix = false) => (value === null ? '—' : suffix ? pct(value) : n(value));
type Count = 'total' | 'purchases' | 'returns' | 'complaints' | 'complaints_pending';
const COUNTS: Count[] = ['total', 'purchases', 'returns', 'complaints', 'complaints_pending'];
const count = (row: OutcomesFigures, key: Count) => row[key];
const breadcrumbs = computed(() => [{ title: t('reports.outcomes.title'), href: '/reports/outcomes' }]);
const control =
    'h-9 rounded-md border border-line-strong bg-card px-2 text-13 text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring';
</script>

<template>
    <Head :title="t('reports.outcomes.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-7xl space-y-4 p-3 md:p-6">
            <PageHeader :title="t('reports.hub.title')" :description="t('reports.hub.description')">
                <PeriodControls :range="range" :show-platform="false" @change="visit({ from: $event.from, to: $event.to })" />
                <select
                    :value="group"
                    :class="control"
                    :aria-label="t('reports.outcomes.group.label')"
                    data-outcomes-group
                    @change="visit({ group: ($event.target as HTMLSelectElement).value as OutcomesGroup })"
                >
                    <option v-for="g in GROUPS" :key="g" :value="g">{{ t(`reports.outcomes.group.${g}`) }}</option>
                </select>
                <select
                    :value="userId ?? ''"
                    :class="control"
                    :aria-label="t('reports.outcomes.moderator_filter')"
                    data-outcomes-user
                    @change="visit({ user: ($event.target as HTMLSelectElement).value ? Number(($event.target as HTMLSelectElement).value) : null })"
                >
                    <option value="">{{ t('reports.outcomes.all_moderators') }}</option>
                    <option v-for="m in moderators" :key="m.id" :value="m.id">{{ m.name }}</option>
                </select>
                <Button as="a" :href="exportHref" variant="secondary" :aria-label="t('reports.outcomes.export')" data-export>
                    <Download aria-hidden="true" />
                    {{ t('reports.hub.csv') }}
                </Button>
            </PageHeader>
            <SectionTabs :tabs="tabs" :label="t('section_tabs.team.label')" />

            <p class="text-13 text-fg-2">{{ t('reports.outcomes.scope') }}</p>

            <SkeletonList v-if="loading" variant="tiles" :count="6" />
            <template v-else>
                <div v-if="report.kpis.total === 0" class="rounded-lg border border-line bg-card">
                    <EmptyState :icon="MessagesSquare" :title="t('reports.outcomes.empty')" :body="t('reports.outcomes.empty_body')" />
                </div>

                <template v-else>
                    <Ledger :figures="figures" :label="t('reports.outcomes.title')" />
                    <p class="text-13 text-fg-2" data-outcomes-followed>{{ followed }}</p>

                    <section
                        class="space-y-2 rounded-lg border border-line bg-card p-4"
                        :aria-label="t('reports.outcomes.by_outcome')"
                        data-outcomes-split
                    >
                        <h2 class="text-base font-bold">{{ t('reports.outcomes.by_outcome') }}</h2>
                        <ul class="space-y-1.5">
                            <li
                                v-for="row in split"
                                :key="row.key"
                                class="grid grid-cols-[minmax(0,9rem)_minmax(0,1fr)_4.5rem] items-center gap-3 text-13 sm:grid-cols-[13rem_minmax(0,1fr)_6rem]"
                                :data-split="row.key"
                            >
                                <span class="truncate">{{ t(`outcomes.${row.key}`) }}</span>
                                <span class="h-2.5 rounded-full bg-sunken" aria-hidden="true"
                                    ><span
                                        class="bg-chart block h-2.5 rounded-full"
                                        :style="{ width: `${Math.max(2, Math.round((row.count / maxCount) * 100))}%` }"
                                /></span>
                                <span class="text-end tabular-nums"
                                    >{{ n(row.count)
                                    }}<span v-if="row.share !== null" class="ms-1 text-xs text-fg-3">{{
                                        pct(Math.round(row.share * 100))
                                    }}</span></span
                                >
                            </li>
                        </ul>
                    </section>

                    <section v-if="userRows.length" class="space-y-2" :aria-label="t('reports.outcomes.per_moderator')">
                        <h2 class="text-base font-bold">{{ t('reports.outcomes.per_moderator') }}</h2>
                        <DataTable
                            table-id="report-outcomes-users"
                            :columns="userColumns"
                            :rows="userRows"
                            :caption="t('reports.outcomes.per_moderator')"
                        >
                            <template #cell-user="{ row }">
                                <span class="inline-flex items-center gap-2 whitespace-nowrap font-semibold">
                                    <span
                                        class="size-2.5 shrink-0 rounded-full"
                                        :style="{ backgroundColor: row.user?.color ?? 'hsl(var(--fg-3))' }"
                                        aria-hidden="true"
                                    />
                                    {{ row.user?.name ?? t('reports.outcomes.automatic') }}
                                </span>
                            </template>
                            <template v-for="c in COUNTS" :key="c" #[`cell-${c}`]="{ row }">
                                <span
                                    class="tabular-nums"
                                    :class="c === 'complaints_pending' && count(row as Row, c) > 0 ? 'font-semibold text-danger' : ''"
                                    >{{ cell(count(row as Row, c)) }}</span
                                >
                            </template>
                            <template #cell-no_reply_pct="{ row }"
                                ><span class="tabular-nums">{{ cell(row.no_reply_pct, true) }}</span></template
                            >
                            <template #cell-avg_close_seconds="{ row }"
                                ><span class="tabular-nums">{{ secs(row.avg_close_seconds) }}</span></template
                            >
                            <template #cell-followed_pct="{ row }"
                                ><span class="tabular-nums">{{ cell(row.followed_pct, true) }}</span></template
                            >
                        </DataTable>
                    </section>

                    <section class="space-y-2" :aria-label="t('reports.outcomes.by_period')">
                        <h2 class="text-base font-bold">{{ t('reports.outcomes.by_period') }}</h2>
                        <DataTable
                            table-id="report-outcomes-periods"
                            :columns="periodColumns"
                            :rows="periodRows"
                            :caption="t('reports.outcomes.by_period')"
                        >
                            <template #cell-key="{ row }"
                                ><span class="whitespace-nowrap font-semibold tabular-nums" dir="ltr">{{
                                    periodLabel(row as OutcomesPeriod)
                                }}</span></template
                            >
                            <template v-for="c in COUNTS" :key="c" #[`cell-${c}`]="{ row }">
                                <span class="tabular-nums">{{ cell(count(row as Row, c)) }}</span>
                            </template>
                            <template #cell-no_reply_pct="{ row }"
                                ><span class="tabular-nums">{{ cell(row.no_reply_pct, true) }}</span></template
                            >
                            <template #cell-avg_close_seconds="{ row }"
                                ><span class="tabular-nums">{{ secs(row.avg_close_seconds) }}</span></template
                            >
                            <template #cell-followed_pct="{ row }"
                                ><span class="tabular-nums">{{ cell(row.followed_pct, true) }}</span></template
                            >
                        </DataTable>
                    </section>
                </template>
            </template>
        </div>
    </AppLayout>
</template>
