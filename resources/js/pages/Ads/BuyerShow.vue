<script setup lang="ts">
/** Ads Hub — one media buyer: KPIs, daily trend, accounts held, campaigns, top creatives (spec §8.2). */
import AdsRangeBar from '@/components/ads/AdsRangeBar.vue';
import ComboChart, { type ComboSeries } from '@/components/ads/ComboChart.vue';
import CreativePreviewModal from '@/components/ads/CreativePreviewModal.vue';
import CreativeThumb from '@/components/ads/CreativeThumb.vue';
import MoneyCell from '@/components/ads/MoneyCell.vue';
import PlatformChip from '@/components/ads/PlatformChip.vue';
import RevenueSummaryCard from '@/components/ads/RevenueSummaryCard.vue';
import DataTable from '@/components/crm/DataTable.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import StatCard from '@/components/crm/StatCard.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { flowArrow, formatAdsMoney, formatCompact, formatDayLong, formatDayShort, formatPct, formatQty, formatRoas, roasTone } from '@/lib/ads';
import { formatCount } from '@/lib/format';
import type { AdsBuyerShowProps, BuyerAssignment, BuyerCampaignRow, CreativeRow } from '@/types/ads';
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

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

const selected = ref<CreativeRow | null>(null);
const modalOpen = ref(false);
function openAd(ad: CreativeRow): void {
    selected.value = ad;
    modalOpen.value = true;
}

const breadcrumbs = computed(() => [
    { title: t('nav.ads'), href: '/ads' },
    { title: t('nav.ads_buyers'), href: '/ads/buyers' },
    { title: props.buyer.name, href: `/ads/buyers/${props.buyer.id}` },
]);
</script>

<template>
    <Head :title="buyer.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-7xl space-y-4 p-3 md:p-6">
            <PageHeader :title="buyer.name" :description="t('ads.buyers.show_hint')">
                <AdsRangeBar :filters="filters" :platforms="platforms" :show-buyer="false" />
            </PageHeader>

            <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
                <div class="rounded-lg border-t-4 bg-card px-4 py-3 shadow-card" :style="{ borderTopColor: buyer.color ?? 'hsl(var(--primary))' }">
                    <p class="text-2xs font-medium text-muted-foreground">{{ t('ads.kpi.spend_tax') }}</p>
                    <MoneyCell :amount="d.spend" :with-tax="d.spend_tax" size="lg" align="start" class="mt-0.5" :currency="currency" />
                </div>
                <StatCard
                    v-for="k in kpis"
                    :key="k.key"
                    :label="k.label"
                    :value="k.value"
                    :hint="k.hint"
                    :title="k.tip"
                    :tone="k.tone"
                    class="border-t-4"
                    :class="k.border"
                />
            </div>

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

            <section class="space-y-2">
                <h2 class="text-sm font-bold">{{ t('ads.buyers.top_creatives') }}</h2>
                <p v-if="!d.top_ads.length" class="rounded-lg bg-card p-6 text-center text-xs text-muted-foreground shadow-card">
                    {{ t('ads.empty.range') }}
                </p>
                <ul v-else class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    <li v-for="ad in d.top_ads" :key="ad.id">
                        <button
                            type="button"
                            class="flex w-full flex-col gap-2 rounded-lg bg-card p-2 text-start shadow-card hover:shadow-md"
                            @click="openAd(ad)"
                        >
                            <CreativeThumb :ad="ad" size="fill" />
                            <span class="line-clamp-2 text-xs font-semibold" dir="auto">{{ ad.name }}</span>
                            <span class="flex items-center justify-between gap-2 text-2xs">
                                <span class="tabular-nums text-muted-foreground">{{ money(ad.spend_tax) }}</span>
                                <StatusChip :label="formatRoas(ad.roas, locale)" :tone="roasTone(ad.roas)" />
                            </span>
                        </button>
                    </li>
                </ul>
            </section>

            <div class="grid gap-4 2xl:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
                <section class="space-y-2">
                    <h2 class="text-sm font-bold">{{ t('ads.buyers.accounts') }}</h2>
                    <DataTable
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

                <section class="space-y-2">
                    <h2 class="text-sm font-bold">{{ t('ads.buyers.campaigns') }}</h2>
                    <DataTable :columns="campaignColumns" :rows="campaigns" :caption="t('ads.buyers.campaigns')" :empty="t('ads.empty.range')">
                        <template #cell-name="{ row }">
                            <span class="flex flex-col">
                                <span class="font-medium" dir="auto">{{ (row as CampaignRow).name ?? t('ads.buyers.no_campaign') }}</span>
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

        <CreativePreviewModal v-model:open="modalOpen" :ad="selected" :filters="{ ...filters, buyer: buyer.id }" :currency="currency" />
    </AppLayout>
</template>
