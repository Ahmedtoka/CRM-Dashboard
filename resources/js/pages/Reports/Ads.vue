<script setup lang="ts">
/** «تقرير الإعلانات» (owner, 2026-09-25): campaign → conversations → orders → spend. */
import DataTable from '@/components/crm/DataTable.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import ReportFilters from '@/components/crm/ReportFilters.vue';
import StatCard from '@/components/crm/StatCard.vue';
import { useI18n } from '@/composables/useI18n';
import { useReportFilters } from '@/composables/useReportFilters';
import { formatNumber } from '@/i18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCount } from '@/lib/format';
import type { ReportRange } from '@/types/admin';
import type { PlatformValue } from '@/types/crm';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

interface AdsRow {
    campaign: string;
    ads: string[];
    conversations: number;
    customers: number;
    orders: number;
    revenue: number;
    spend: number | null;
    cost_per_conversation: number | null;
    cost_per_order: number | null;
    roas: number | null;
}

interface AdsReport {
    rows: AdsRow[];
    totals: { conversations: number; customers: number; orders: number; revenue: number; spend: number | null; roas: number | null; cost_per_order: number | null };
    currency: string | null;
    spend_available: boolean;
}

const props = defineProps<{ range: ReportRange; platform: PlatformValue | null; report: AdsReport }>();

const { t, locale } = useI18n();
const { visit } = useReportFilters();
const n = (v: number) => formatCount(v, locale.value);
const money = (v: number | null) => (v === null ? '—' : formatNumber(locale.value, v, { maximumFractionDigits: 0 }) + (props.report.currency ? ` ${props.report.currency}` : ''));
const ratio = (v: number | null) => (v === null ? '—' : formatNumber(locale.value, v, { maximumFractionDigits: 2 }) + '×');

const cards = computed(() => [
    { label: t('reports.ads.conversations'), value: n(props.report.totals.conversations) },
    { label: t('reports.ads.customers'), value: n(props.report.totals.customers) },
    { label: t('reports.ads.orders'), value: n(props.report.totals.orders) },
    { label: t('reports.ads.revenue'), value: money(props.report.totals.revenue) },
    { label: t('reports.ads.spend'), value: money(props.report.totals.spend) },
    { label: t('reports.ads.cost_per_order'), value: money(props.report.totals.cost_per_order) },
    { label: t('reports.ads.roas'), value: ratio(props.report.totals.roas) },
]);

const rows = computed(() => props.report.rows.map((r) => ({ ...r, id: r.campaign, name: r.campaign === 'unnamed' ? t('reports.ads.unnamed') : r.campaign })));
const columns = computed(() => [
    { key: 'name', label: t('reports.ads.campaign') },
    { key: 'conversations', label: t('reports.ads.conversations'), align: 'end' as const },
    { key: 'customers', label: t('reports.ads.customers'), align: 'end' as const },
    { key: 'orders', label: t('reports.ads.orders'), align: 'end' as const },
    { key: 'revenue', label: t('reports.ads.revenue'), align: 'end' as const },
    { key: 'spend', label: t('reports.ads.spend'), align: 'end' as const },
    { key: 'cost_per_conversation', label: t('reports.ads.cost_per_conversation'), align: 'end' as const },
    { key: 'cost_per_order', label: t('reports.ads.cost_per_order'), align: 'end' as const },
    { key: 'roas', label: t('reports.ads.roas'), align: 'end' as const },
]);

const breadcrumbs = computed(() => [{ title: t('reports.ads_title'), href: '/reports/ads' }]);
</script>

<template>
    <Head :title="t('reports.ads_title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-7xl space-y-4 p-3 md:p-6">
            <PageHeader :title="t('reports.ads_title')" :description="t('reports.ads.hint')">
                <ReportFilters :range="range" :platform="platform" @change="visit" />
            </PageHeader>

            <div class="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-7">
                <StatCard v-for="card in cards" :key="card.label" :label="card.label" :value="card.value" />
            </div>

            <p v-if="!report.spend_available" class="rounded-md border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-800 dark:text-amber-200">
                {{ t('reports.ads.no_spend') }}
            </p>

            <DataTable :columns="columns" :rows="rows" :empty="t('reports.no_data')" :caption="t('reports.ads_title')">
                <template #cell-name="{ row }">
                    <span class="flex flex-col">
                        <span class="font-medium" dir="auto">{{ row.name }}</span>
                        <span v-if="row.ads.length" class="text-2xs text-muted-foreground" dir="auto">{{ row.ads.join(' · ') }}</span>
                    </span>
                </template>
                <template #cell-conversations="{ value }"><span class="tabular-nums">{{ n(value as number) }}</span></template>
                <template #cell-customers="{ value }"><span class="tabular-nums">{{ n(value as number) }}</span></template>
                <template #cell-orders="{ value }"><span class="tabular-nums">{{ n(value as number) }}</span></template>
                <template #cell-revenue="{ value }"><span class="tabular-nums">{{ money(value as number) }}</span></template>
                <template #cell-spend="{ value }"><span class="tabular-nums">{{ money(value as number | null) }}</span></template>
                <template #cell-cost_per_conversation="{ value }"><span class="tabular-nums">{{ money(value as number | null) }}</span></template>
                <template #cell-cost_per_order="{ value }"><span class="tabular-nums">{{ money(value as number | null) }}</span></template>
                <template #cell-roas="{ value }"><span class="tabular-nums">{{ ratio(value as number | null) }}</span></template>
            </DataTable>

            <p class="text-2xs text-muted-foreground">{{ t('reports.ads.attribution_note') }}</p>
        </div>
    </AppLayout>
</template>
