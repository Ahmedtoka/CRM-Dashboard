<script setup lang="ts">
/** Ads Hub — one media buyer: KPIs, daily trend, accounts held, campaigns, top creatives (spec §8.2). */
import AdDrawer from '@/components/ads/AdDrawer.vue';
import AdsFilterBar from '@/components/ads/AdsFilterBar.vue';
import ComboChart, { type ComboSeries } from '@/components/ads/ComboChart.vue';
import CreativeThumb from '@/components/ads/CreativeThumb.vue';
import MoneyCell from '@/components/ads/MoneyCell.vue';
import PlatformChip from '@/components/ads/PlatformChip.vue';
import RevenueSummaryCard from '@/components/ads/RevenueSummaryCard.vue';
import DataTable from '@/components/crm/DataTable.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import DataHealthBanner from '@/components/ads/DataHealthBanner.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { useAdDrawer } from '@/composables/useAdDrawer';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { flowArrow, formatAdsMoney, formatCompact, formatDayLong, formatDayShort, formatPct, formatQty, formatRoas, roasTone } from '@/lib/ads';
import { formatCount } from '@/lib/format';
import { buildHref, carryQuery, readQuery } from '@/lib/adsFilters';
import type { AdsBuyerShowProps, BuyerAssignment, BuyerCampaignRow } from '@/types/ads';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<AdsBuyerShowProps>();

const { t, locale } = useI18n();
const money = (v: number | null) => formatAdsMoney(v, locale.value, props.currency);
const n = (v: number) => formatCount(v, locale.value);
const d = computed(() => props.detail);

const kpis = computed(() => {
    const x = d.value;
    const conv = x.conversations > 0 ? x.conversations_ordered / x.conversations : null;
    return [
        { key: 'value', label: t('ads.kpi.purchase_value'), value: money(x.purchase_value), border: 'border-t-chart-2' },
        {
            key: 'roas',
            label: t('ads.kpi.roas'),
            value: formatRoas(x.roas, locale.value),
            hint: x.target_roas !== null ? t('ads.buyers.target', { roas: formatRoas(x.target_roas, locale.value) }) : undefined,
            tone: x.target_roas !== null && x.roas !== null ? (x.roas >= x.target_roas ? 'positive' : 'negative') : 'default',
            border: 'border-t-chart-4',
        },
        { key: 'orders', label: t('ads.kpi.meta_orders'), value: formatQty(x.purchases, locale.value), border: 'border-t-chart-3' },
        {
            key: 'real',
            label: t('ads.kpi.real_orders'),
            value: n(x.real_orders),
            hint: t('ads.kpi.real_revenue', { amount: money(x.real_revenue), roas: formatRoas(x.real_roas, locale.value) }),
            tip: t('ads.kpi.real_revenue_tip'),
            border: 'border-t-success',
        },
        { key: 'cpa', label: t('ads.kpi.cpa'), value: money(x.cpa), border: 'border-t-chart-5' },
        { key: 'ctr', label: t('ads.kpi.ctr'), value: formatPct(x.ctr, locale.value), border: 'border-t-chart-3' },
        {
            key: 'conv',
            label: t('ads.kpi.conversations'),
            value: `${n(x.conversations)} ${flowArrow(locale.value)} ${n(x.conversations_ordered)}`,
            hint: t('ads.buyers.conversion', { pct: formatPct(conv, locale.value, 1) }),
            border: 'border-t-info',
        },
        {
            key: 'budget',
            label: t('ads.buyers.budget'),
            value: x.budget === null ? '—' : money(x.budget),
            hint:
                x.budget_used_pct === null
                    ? t('ads.buyers.no_budget')
                    : t('ads.buyers.used', { pct: formatPct(x.budget_used_pct / 100, locale.value, 0) }),
            tone: (x.budget_used_pct ?? 0) > 100 ? 'warning' : 'default',
            border: 'border-t-primary',
        },
    ] as { key: string; label: string; value: string; hint?: string; tip?: string; tone?: 'default' | 'positive' | 'warning' | 'negative'; border: string }[];
});

const TONE = { default: 'text-foreground', positive: 'text-ok', warning: 'text-warn', negative: 'text-danger' } as const;

const series = computed<{ bars: ComboSeries[]; lines: ComboSeries[] }>(() => ({
    bars: [
        { key: 'spend', label: t('ads.chart.spend_tax'), values: d.value.daily.map((r) => r.spend_tax), color: 'hsl(var(--chart-1))', format: money },
    ],
    lines: [
        {
            key: 'value',
            label: t('ads.kpi.purchase_value'),
            values: d.value.daily.map((r) => r.purchase_value),
            color: 'hsl(var(--chart-2))',
            format: money,
        },
        {
            key: 'roas',
            label: t('ads.kpi.roas'),
            values: d.value.daily.map((r) => r.roas),
            color: 'hsl(var(--chart-4))',
            format: (v) => formatRoas(v, locale.value),
            axis: 'right',
            dashed: true,
        },
    ],
}));

type AssignmentRow = BuyerAssignment & { id: string };
const assignments = computed<AssignmentRow[]>(() => d.value.assignments.map((a, i) => ({ ...a, id: `${a.account_id}-${a.starts_on}-${i}` })));
const assignmentColumns = computed(() => [
    { key: 'account', label: t('ads.table.account'), primary: true },
    { key: 'platform', label: t('ads.table.platform') },
    { key: 'starts_on', label: t('ads.buyers.starts_on') },
    { key: 'ends_on', label: t('ads.buyers.ends_on') },
]);

type CampaignRow = Omit<BuyerCampaignRow, 'id'> & { id: string };
const campaigns = computed<CampaignRow[]>(() => d.value.campaigns.map((c, i) => ({ ...c, id: c.id === null ? `none-${i}` : String(c.id) })));
const campaignColumns = computed(() => [
    { key: 'name', label: t('ads.table.campaign'), primary: true },
    { key: 'account', label: t('ads.table.account'), hideOnMobile: true },
    { key: 'spend', label: t('ads.kpi.spend_tax'), align: 'end' as const },
    { key: 'purchase_value', label: t('ads.kpi.purchase_value'), align: 'end' as const, hideOnMobile: true },
    { key: 'roas', label: t('ads.kpi.roas'), align: 'end' as const },
    { key: 'purchases', label: t('ads.kpi.meta_orders'), align: 'end' as const },
    { key: 'cpa', label: t('ads.kpi.cpa'), align: 'end' as const, hideOnMobile: true },
    { key: 'ctr', label: t('ads.kpi.ctr'), align: 'end' as const, hideOnMobile: true },
    { key: 'real_orders', label: t('ads.kpi.real_orders'), align: 'end' as const },
]);

const drawer = useAdDrawer();
/** Quick win 4: a campaign row opens that campaign in the explorer tree (this buyer, every status). */
const campaignHref = (row: CampaignRow) =>
    buildHref('/ads/explorer', {
        ...carryQuery(readQuery(typeof window === 'undefined' ? '' : window.location.search)),
        view: 'tree',
        status: 'all',
        buyer: String(props.buyer.id),
        ...(row.id.startsWith('none-') ? {} : { open: `c:${row.id}` }),
    });

const breadcrumbs = computed(() => [
    { title: t('nav.ads'), href: '/ads/decisions' },
    { title: t('nav.ads_numbers'), href: '/ads/numbers?section=buyers' },
    { title: props.buyer.name, href: `/ads/buyers/${props.buyer.id}` },
]);
const crumbs = computed(() => [{ label: t('nav.ads_numbers'), href: '/ads/numbers?section=buyers' }, { label: props.buyer.name }]);
</script>

<template>
    <Head :title="buyer.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full min-w-0 max-w-[1400px] space-y-5 p-4 md:p-6">
            <PageHeader :title="buyer.name" :description="t('ads.buyers.show_hint')" :breadcrumbs="crumbs" :freshness="freshness" />
            <DataHealthBanner :data-health="data_health" :numbers-under-review="numbers_under_review" :clamped-to-history="clamped_to_history" />
            <AdsFilterBar
                :path="`/ads/buyers/${buyer.id}`"
                :filters="filters"
                :account-options="[]"
                :buyers="[]"
                :platforms="platforms"
                :show="{ status: false, list: false, presets: false }"
            />

            <!-- The buyer's ledger: one panel, hairline dividers, the figure large and its context under it. -->
            <section class="grid grid-cols-2 gap-px overflow-hidden rounded-lg border border-line bg-line md:grid-cols-3 xl:grid-cols-5" data-test="buyer-ledger">
                <div class="min-w-0 bg-card px-4 py-3.5">
                    <p class="truncate text-[12.5px] font-medium text-fg-2">{{ t('ads.kpi.spend_tax') }}</p>
                    <MoneyCell :amount="d.spend" :with-tax="d.spend_tax" size="lg" align="start" class="mt-0.5" :currency="currency" />
                </div>
                <div v-for="k in kpis" :key="k.key" class="min-w-0 bg-card px-4 py-3.5" :title="k.tip" :data-kpi="k.key">
                    <p class="truncate text-[12.5px] font-medium text-fg-2">{{ k.label }}</p>
                    <p class="truncate text-xl font-bold leading-snug tabular-nums" :class="TONE[k.tone ?? 'default']">{{ k.value }}</p>
                    <p v-if="k.hint" class="truncate text-xs text-fg-3">{{ k.hint }}</p>
                </div>
            </section>

            <RevenueSummaryCard :summary="summary" />

            <ComboChart
                :title="t('ads.chart.daily_trend')"
                :labels="d.daily.map((r) => r.date)"
                :label-format="(l) => formatDayShort(l, locale)"
                :bars="series.bars"
                :lines="series.lines"
                :left-format="(v) => formatCompact(v, locale)"
                :right-format="(v) => formatRoas(v, locale)"
            />

            <section class="space-y-2.5">
                <h2 class="text-base font-bold">{{ t('ads.buyers.top_creatives') }}</h2>
                <p v-if="!d.top_ads.length" class="rounded-lg border border-line bg-card p-6 text-center text-[13px] text-fg-3">
                    {{ t('ads.empty.range') }}
                </p>
                <ul v-else class="grid grid-cols-2 gap-3.5 sm:grid-cols-3 lg:grid-cols-5">
                    <li v-for="ad in d.top_ads" :key="ad.id">
                        <button
                            type="button"
                            class="flex w-full flex-col overflow-hidden rounded-lg border border-line bg-card text-start transition-colors duration-150 hover:border-line-strong focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            @click="drawer.open(ad.id)"
                        >
                            <CreativeThumb :ad="ad" size="fill" square class="!aspect-[4/5]" />
                            <span class="flex flex-col gap-1.5 px-3 pb-2.5 pt-2">
                                <span class="line-clamp-2 text-[13px] font-semibold" dir="auto">{{ ad.name }}</span>
                                <span class="flex items-center justify-between gap-2 text-xs">
                                    <span class="tabular-nums text-fg-2">{{ money(ad.spend_tax) }}</span>
                                    <StatusChip :label="formatRoas(ad.roas, locale)" :tone="roasTone(ad.roas)" size="sm" />
                                </span>
                            </span>
                        </button>
                    </li>
                </ul>
            </section>

            <div class="grid gap-5 2xl:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
                <section class="min-w-0 space-y-2.5">
                    <h2 class="text-base font-bold">{{ t('ads.buyers.accounts') }}</h2>
                    <DataTable
                        table-id="ads-buyer-assignments"
                        :columns="assignmentColumns"
                        :rows="assignments"
                        :caption="t('ads.buyers.accounts')"
                        :empty="t('ads.buyers.no_accounts')"
                    >
                        <template #cell-account="{ row }"
                            ><span class="font-medium" dir="auto">{{ (row as AssignmentRow).account }}</span></template
                        >
                        <template #cell-platform="{ row }"><PlatformChip :platform="(row as AssignmentRow).platform" size="xs" /></template>
                        <template #cell-starts_on="{ row }"
                            ><span class="whitespace-nowrap">{{ formatDayLong((row as AssignmentRow).starts_on, locale) }}</span></template
                        >
                        <template #cell-ends_on="{ row }">
                            <span class="whitespace-nowrap">{{
                                (row as AssignmentRow).ends_on ? formatDayLong((row as AssignmentRow).ends_on, locale) : t('ads.buyers.open_ended')
                            }}</span>
                        </template>
                    </DataTable>
                </section>

                <section class="min-w-0 space-y-2.5">
                    <h2 class="text-base font-bold">{{ t('ads.buyers.campaigns') }}</h2>
                    <DataTable table-id="ads-buyer-campaigns" :columns="campaignColumns" :rows="campaigns" :caption="t('ads.buyers.campaigns')" :empty="t('ads.empty.range')">
                        <template #cell-name="{ row }">
                            <span class="flex flex-col">
                                <Link :href="campaignHref(row as CampaignRow)" class="font-medium hover:underline" dir="auto">{{
                                    (row as CampaignRow).name ?? t('ads.buyers.no_campaign')
                                }}</Link>
                                <span v-if="(row as CampaignRow).platform" class="mt-0.5"
                                    ><PlatformChip :platform="(row as CampaignRow).platform" size="xs"
                                /></span>
                            </span>
                        </template>
                        <template #cell-account="{ row }"
                            ><span class="whitespace-nowrap" dir="auto">{{ (row as CampaignRow).account ?? '—' }}</span></template
                        >
                        <template #cell-spend="{ row }"
                            ><MoneyCell :amount="(row as CampaignRow).spend" :with-tax="(row as CampaignRow).spend_tax" :currency="currency"
                        /></template>
                        <template #cell-purchase_value="{ row }"
                            ><span class="tabular-nums">{{ money((row as CampaignRow).purchase_value) }}</span></template
                        >
                        <template #cell-roas="{ row }"
                            ><StatusChip :label="formatRoas((row as CampaignRow).roas, locale)" :tone="roasTone((row as CampaignRow).roas)"
                        /></template>
                        <template #cell-purchases="{ row }"
                            ><span class="tabular-nums">{{ formatQty((row as CampaignRow).purchases, locale) }}</span></template
                        >
                        <template #cell-cpa="{ row }"
                            ><span class="tabular-nums">{{ money((row as CampaignRow).cpa) }}</span></template
                        >
                        <template #cell-ctr="{ row }"
                            ><span class="tabular-nums">{{ formatPct((row as CampaignRow).ctr, locale) }}</span></template
                        >
                    </DataTable>
                </section>
            </div>
        </div>

        <AdDrawer :ad-id="drawer.adId.value" :filters="filters" :currency="currency" :data-at="freshness" :reload-only="['detail']" @close="drawer.close" />
    </AppLayout>
</template>
