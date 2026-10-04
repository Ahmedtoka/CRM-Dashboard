<script setup lang="ts">
/** Ads Hub — نظرة عامة: platform spend, KPIs, daily trend and the daily table (spec §8.1). */
import AdsRangeBar from '@/components/ads/AdsRangeBar.vue';
import ComboChart, { type ComboSeries } from '@/components/ads/ComboChart.vue';
import MoneyCell from '@/components/ads/MoneyCell.vue';
import PlatformChip from '@/components/ads/PlatformChip.vue';
import RevenueSummaryCard from '@/components/ads/RevenueSummaryCard.vue';
import DataTable from '@/components/crm/DataTable.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import StatCard from '@/components/crm/StatCard.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { adAccountActive, adAccountStatusLabel, flowArrow, formatAdsMoney, formatCompact, formatDayLong, formatDayShort, formatPct, formatQty, formatRoas, roasTone } from '@/lib/ads';
import { formatCount, formatDateTime } from '@/lib/format';
import type { SharedData } from '@/types';
import type { AdsDailyRow, AdsOverviewProps, AdsTopAccountRow } from '@/types/ads';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { AlertTriangle, Megaphone } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<AdsOverviewProps>();
type Row = AdsDailyRow & { id: string };

const { t, locale } = useI18n();
const page = usePage<SharedData>();
const money = (v: number | null) => formatAdsMoney(v, locale.value, props.currency);
const n = (v: number) => formatCount(v, locale.value);
const tot = computed(() => props.overview.totals);
const hasData = computed(() => tot.value.spend > 0 || tot.value.impressions > 0 || tot.value.real_orders > 0);
const taxPct = computed(() => formatPct(props.overview.tax_rate, locale.value, 0));

const kpis = computed(() => {
    const x = tot.value;
    const ordered = x.conversations > 0 ? x.conversations_ordered / x.conversations : null;
    return [
        { key: 'purchase_value', label: t('ads.kpi.purchase_value'), value: money(x.purchase_value), border: 'border-t-chart-2' },
        { key: 'roas', label: t('ads.kpi.roas'), value: formatRoas(x.roas, locale.value), border: 'border-t-chart-4', hint: t('ads.kpi.roas_hint') },
        { key: 'meta_orders', label: t('ads.kpi.meta_orders'), value: formatQty(x.purchases, locale.value), border: 'border-t-chart-3' },
        {
            key: 'real_orders',
            label: t('ads.kpi.real_orders'),
            value: n(x.real_orders),
            hint: t('ads.kpi.real_revenue', { amount: money(x.real_revenue), roas: formatRoas(x.real_roas, locale.value) }),
            border: 'border-t-success',
        },
        { key: 'cpa', label: t('ads.kpi.cpa'), value: money(x.cpa), border: 'border-t-chart-5' },
        { key: 'ctr', label: t('ads.kpi.ctr'), value: formatPct(x.ctr, locale.value), border: 'border-t-chart-3' },
        { key: 'impressions', label: t('ads.kpi.impressions'), value: n(x.impressions), border: 'border-t-chart-1' },
        { key: 'reach', label: t('ads.kpi.reach'), value: n(x.reach), border: 'border-t-chart-1' },
        {
            key: 'conversations',
            label: t('ads.kpi.conversations'),
            value: `${n(x.conversations)} ${flowArrow(locale.value)} ${n(x.conversations_ordered)}`,
            hint: t('ads.buyers.conversion', { pct: formatPct(ordered, locale.value, 1) }),
            border: 'border-t-info',
        },
    ];
});

const daily = computed(() => props.overview.daily);
const series = computed<{ bars: ComboSeries[]; lines: ComboSeries[] }>(() => ({
    bars: [
        { key: 'spend', label: t('ads.chart.spend_tax'), values: daily.value.map((d) => d.spend_tax), color: 'hsl(var(--chart-1))', format: money },
    ],
    lines: [
        {
            key: 'value',
            label: t('ads.kpi.purchase_value'),
            values: daily.value.map((d) => d.purchase_value),
            color: 'hsl(var(--chart-2))',
            format: money,
        },
        {
            key: 'roas',
            label: t('ads.kpi.roas'),
            values: daily.value.map((d) => d.roas),
            color: 'hsl(var(--chart-4))',
            format: (v) => formatRoas(v, locale.value),
            axis: 'right',
            dashed: true,
        },
    ],
}));

// Newest day first in the table (the chart reads oldest → newest), the range totals as the last row.
const TOTAL = 'total';
const rows = computed<Row[]>(() => {
    if (!daily.value.length) return [];
    const x = tot.value;
    const total: Row = {
        id: TOTAL,
        date: TOTAL,
        spend: x.spend,
        spend_tax: x.spend_tax,
        purchase_value: x.purchase_value,
        roas: x.roas,
        purchases: x.purchases,
        impressions: x.impressions,
        clicks: x.clicks,
        ctr: x.ctr,
        cpm: x.cpm,
        cpc: x.cpc,
        reach: x.reach,
        real_orders: x.real_orders,
        real_revenue: x.real_revenue,
    };
    return [...[...daily.value].reverse().map((d) => ({ ...d, id: d.date })), total];
});
const columns = computed(() => [
    { key: 'date', label: t('ads.table.date'), primary: true },
    { key: 'spend', label: t('ads.table.spend'), align: 'end' as const },
    { key: 'spend_tax', label: t('ads.table.spend_tax'), align: 'end' as const, hideOnMobile: true },
    { key: 'purchase_value', label: t('ads.kpi.purchase_value'), align: 'end' as const },
    { key: 'roas', label: t('ads.kpi.roas'), align: 'end' as const },
    { key: 'purchases', label: t('ads.table.purchases'), align: 'end' as const },
    { key: 'impressions', label: t('ads.kpi.impressions'), align: 'end' as const, hideOnMobile: true },
    { key: 'clicks', label: t('ads.kpi.clicks'), align: 'end' as const, hideOnMobile: true },
    { key: 'ctr', label: t('ads.kpi.ctr'), align: 'end' as const },
    { key: 'cpm', label: t('ads.table.cpm'), align: 'end' as const, hideOnMobile: true },
    { key: 'cpc', label: t('ads.table.cpc'), align: 'end' as const, hideOnMobile: true },
    { key: 'reach', label: t('ads.kpi.reach'), align: 'end' as const, hideOnMobile: true },
    { key: 'real_orders', label: t('ads.kpi.real_orders'), align: 'end' as const },
]);

const roasChip = (v: number | null) => roasTone(v);

/* ---- top ad accounts in the range (spec §1.2) ---- */
const accountColumns = computed(() => [
    { key: 'name', label: t('ads.table.account'), primary: true },
    { key: 'buyer', label: t('ads.table.buyer') },
    { key: 'spend', label: t('ads.kpi.spend_tax'), align: 'end' as const },
    { key: 'purchase_value', label: t('ads.kpi.purchase_value'), align: 'end' as const },
    { key: 'purchases', label: t('ads.table.purchases'), align: 'end' as const, hideOnMobile: true },
    { key: 'roas', label: t('ads.kpi.roas'), align: 'end' as const },
    { key: 'status', label: t('ads.table.status'), hideOnMobile: true },
    { key: 'last_synced_at', label: t('ads.accounts.last_sync'), hideOnMobile: true },
]);
const acc = (row: unknown) => row as AdsTopAccountRow;
const canManage = computed(() => page.props.ads?.canManage === true);
const breadcrumbs = computed(() => [{ title: t('nav.ads'), href: '/ads' }]);
</script>

<template>
    <Head :title="t('ads.overview.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-7xl space-y-4 p-3 md:p-6">
            <PageHeader :title="t('ads.overview.title')" :description="t('ads.overview.hint', { tax: taxPct })">
                <AdsRangeBar :filters="filters" :platforms="platforms" :buyers="buyers" />
            </PageHeader>

            <div
                v-if="sync.errors.length"
                role="alert"
                class="flex items-start gap-2 rounded-md border border-destructive/30 bg-destructive/10 px-3 py-2 text-xs text-destructive"
            >
                <AlertTriangle class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                <div class="min-w-0 flex-1 space-y-0.5">
                    <p class="font-semibold">{{ t('ads.sync.errors_title') }}</p>
                    <p v-for="(e, i) in sync.errors" :key="i" class="break-words" dir="auto">
                        <span class="font-medium">{{ e.account }}:</span> {{ e.error }}
                    </p>
                </div>
                <Link v-if="canManage" href="/ads/accounts" class="shrink-0 font-semibold underline underline-offset-2">{{
                    t('ads.sync.open_accounts')
                }}</Link>
            </div>
            <p class="text-2xs text-muted-foreground">
                {{ sync.last_synced_at ? t('ads.sync.last', { time: formatDateTime(sync.last_synced_at, locale) }) : t('ads.sync.never') }}
            </p>

            <EmptyState
                v-if="!hasData"
                :icon="Megaphone"
                :title="sync.last_synced_at ? t('ads.empty.range') : t('ads.empty.title')"
                :body="sync.last_synced_at ? undefined : t('ads.empty.body')"
                class="rounded-lg bg-card shadow-card"
            >
                <template v-if="canManage && !sync.last_synced_at" #action>
                    <Link
                        href="/ads/accounts"
                        class="inline-flex h-9 items-center rounded-md bg-primary px-3 text-xs font-medium text-primary-foreground"
                        >{{ t('ads.empty.cta') }}</Link
                    >
                </template>
            </EmptyState>

            <template v-else>
                <div v-if="overview.platforms.length" class="flex flex-wrap items-center gap-2" :aria-label="t('ads.overview.by_platform')">
                    <PlatformChip v-for="p in overview.platforms" :key="p.platform" :platform="p.platform">
                        <span class="tabular-nums text-muted-foreground">{{ money(p.spend_tax) }}</span>
                        <span class="tabular-nums text-muted-foreground">· {{ formatRoas(p.roas, locale) }}</span>
                    </PlatformChip>
                </div>

                <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
                    <div class="rounded-lg border-t-4 border-t-primary bg-card px-4 py-3 shadow-card">
                        <p class="text-2xs font-medium text-muted-foreground">{{ t('ads.kpi.spend_tax') }}</p>
                        <MoneyCell :amount="tot.spend" :with-tax="tot.spend_tax" :currency="currency" size="lg" align="start" class="mt-0.5" />
                    </div>
                    <StatCard v-for="k in kpis" :key="k.key" :label="k.label" :value="k.value" :hint="k.hint" class="border-t-4" :class="k.border" />
                </div>

                <RevenueSummaryCard :summary="summary" />

                <ComboChart
                    :title="t('ads.chart.daily_trend')"
                    :labels="daily.map((d) => d.date)"
                    :label-format="(l) => formatDayShort(l, locale)"
                    :bars="series.bars"
                    :lines="series.lines"
                    :left-format="(v) => formatCompact(v, locale)"
                    :right-format="(v) => formatRoas(v, locale)"
                />

                <section class="space-y-2" aria-labelledby="top-accounts-title">
                    <div>
                        <h2 id="top-accounts-title" class="text-sm font-semibold">{{ t('ads.overview.top_accounts') }}</h2>
                        <p class="text-2xs text-muted-foreground">{{ t('ads.overview.top_accounts_hint') }}</p>
                    </div>
                    <DataTable :columns="accountColumns" :rows="top_accounts" :caption="t('ads.overview.top_accounts')" :empty="t('ads.empty.range')">
                        <template #cell-name="{ row }">
                            <div class="flex min-w-40 items-center gap-2">
                                <PlatformChip :platform="acc(row).platform" size="xs" />
                                <span class="font-medium" dir="auto">{{ acc(row).name }}</span>
                            </div>
                        </template>
                        <template #cell-buyer="{ row }">
                            <span v-if="acc(row).buyer" dir="auto">{{ acc(row).buyer }}</span>
                            <span v-else class="text-muted-foreground">{{ t('ads.accounts.unassigned') }}</span>
                        </template>
                        <template #cell-spend="{ row }">
                            <MoneyCell :amount="acc(row).spend" :with-tax="acc(row).spend_tax" :currency="currency" />
                        </template>
                        <template #cell-purchase_value="{ row }"
                            ><span class="tabular-nums">{{ money(acc(row).purchase_value) }}</span></template
                        >
                        <template #cell-purchases="{ row }"
                            ><span class="tabular-nums">{{ formatQty(acc(row).purchases, locale) }}</span></template
                        >
                        <template #cell-roas="{ row }">
                            <StatusChip :label="formatRoas(acc(row).roas, locale)" :tone="roasChip(acc(row).roas)" />
                        </template>
                        <template #cell-status="{ row }">
                            <StatusChip
                                :label="adAccountStatusLabel(acc(row).status, t)"
                                :tone="adAccountActive(acc(row).status) ? 'positive' : 'neutral'"
                            />
                        </template>
                        <template #cell-last_synced_at="{ row }">
                            <span class="whitespace-nowrap text-2xs text-muted-foreground">{{
                                acc(row).last_synced_at ? formatDateTime(acc(row).last_synced_at as string, locale) : t('ads.accounts.never_synced')
                            }}</span>
                        </template>
                    </DataTable>
                </section>

                <DataTable :columns="columns" :rows="rows" :caption="t('ads.overview.daily_table')" :empty="t('ads.empty.range')">
                    <template #cell-date="{ row }">
                        <span v-if="row.id === TOTAL" class="font-bold">{{ t('ads.table.totals') }}</span>
                        <span v-else class="whitespace-nowrap font-medium">{{ formatDayLong((row as Row).date, locale) }}</span>
                    </template>
                    <template #cell-spend="{ row }"
                        ><span class="tabular-nums">{{ money((row as Row).spend) }}</span></template
                    >
                    <template #cell-spend_tax="{ row }"
                        ><span class="tabular-nums">{{ money((row as Row).spend_tax) }}</span></template
                    >
                    <template #cell-purchase_value="{ row }"
                        ><span class="tabular-nums">{{ money((row as Row).purchase_value) }}</span></template
                    >
                    <template #cell-roas="{ row }">
                        <StatusChip :label="formatRoas((row as Row).roas, locale)" :tone="roasChip((row as Row).roas)" />
                    </template>
                    <template #cell-purchases="{ row }"
                        ><span class="tabular-nums">{{ formatQty((row as Row).purchases, locale) }}</span></template
                    >
                    <template #cell-ctr="{ row }"
                        ><span class="tabular-nums">{{ formatPct((row as Row).ctr, locale) }}</span></template
                    >
                    <template #cell-cpm="{ row }"
                        ><span class="tabular-nums">{{ money((row as Row).cpm) }}</span></template
                    >
                    <template #cell-cpc="{ row }"
                        ><span class="tabular-nums">{{ formatAdsMoney((row as Row).cpc, locale, currency, 2) }}</span></template
                    >
                </DataTable>
            </template>
        </div>
    </AppLayout>
</template>
