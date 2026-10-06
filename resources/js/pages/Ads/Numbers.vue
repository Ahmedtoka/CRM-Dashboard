<script setup lang="ts">
/** «الأرقام» (D11, U 2.1, quick win 9): 5 hero tiles + «باقي الأرقام», daily chart and table, accounts, buyers, chat table. */
import AdsFilterBar from '@/components/ads/AdsFilterBar.vue';
import BuyerCard from '@/components/ads/BuyerCard.vue';
import ComboChart from '@/components/ads/ComboChart.vue';
import DataHealthBanner from '@/components/ads/DataHealthBanner.vue';
import RevenueSummaryCard from '@/components/ads/RevenueSummaryCard.vue';
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import StatCard from '@/components/crm/StatCard.vue';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatAdsMoney, formatDayShort, formatPct, formatQty, formatRoas } from '@/lib/ads';
import { buildHref, carryQuery, readQuery } from '@/lib/adsFilters';
import { formatCount } from '@/lib/format';
import type { AdsNumbersProps, AdsOption } from '@/types/ads';
import { Head, Link } from '@inertiajs/vue3';
import { computed, onMounted } from 'vue';

const props = defineProps<AdsNumbersProps>();
const { t, locale } = useI18n();
const money = (v: number | null | undefined, currency: string = props.currency) => formatAdsMoney(v ?? null, locale.value, currency);
const tot = computed(() => props.overview.totals);
const shared = computed(() => carryQuery(readQuery(typeof window === 'undefined' ? '' : window.location.search)));
/** Links keep the shared filters (range, accounts, buyer, platform). */
const href = (path: string, extra: Record<string, string> = {}) => buildHref(path, { ...shared.value, ...extra });
/** The page's `buyers` prop is the buyer cards; the filter bar's buyer options come from them. */
const buyerOptions = computed<AdsOption[]>(() => props.buyers.filter((b) => b.buyer_id !== null).map((b) => ({ id: b.buyer_id as number, name: b.name })));

const explorer = (extra: Record<string, string>) => href('/ads/explorer', { status: 'all', ...extra });
const hero = computed(() => [
    { key: 'spend', label: t('ads.control.numbers.hero_spend'), value: money(tot.value.spend_tax), href: explorer({ sort: '-spend' }), link: t('ads.control.today.see_all') },
    { key: 'real_orders', label: t('ads.control.numbers.hero_real_orders'), value: formatCount(tot.value.real_orders, locale.value), href: explorer({ sort: '-spend' }), link: t('ads.control.today.see_all') },
    { key: 'real_roas', label: t('ads.control.numbers.hero_real_roas'), value: formatRoas(tot.value.real_roas, locale.value), href: explorer({ health: 'winning', sort: '-roas' }), link: t('ads.control.today.see_all') },
    { key: 'meta_roas', label: t('ads.control.numbers.hero_meta_roas'), value: formatRoas(tot.value.roas, locale.value), href: explorer({ sort: '-roas' }), link: t('ads.control.today.see_all') },
    { key: 'losers', label: t('ads.control.numbers.hero_losers'), value: formatPct(tot.value.losers_spend_share, locale.value, 0), href: explorer({ health: 'losing', sort: '-spend' }), link: t('ads.control.today.who') },
]);
const more = computed(() => [
    { key: 'purchase_value', label: t('ads.kpi.purchase_value'), value: money(tot.value.purchase_value), href: explorer({ sort: '-purchases' }) },
    { key: 'purchases', label: t('ads.kpi.meta_orders'), value: formatQty(tot.value.purchases, locale.value), href: explorer({ sort: '-purchases' }) },
    { key: 'cpa', label: t('ads.kpi.cpa'), value: money(tot.value.cpa), href: explorer({ sort: '-purchases' }) },
    { key: 'ctr', label: t('ads.kpi.ctr'), value: formatPct(tot.value.ctr, locale.value), href: explorer({ sort: '-ctr' }) },
    { key: 'impressions', label: t('ads.kpi.impressions'), value: formatCount(tot.value.impressions, locale.value), href: explorer({ sort: '-impressions' }) },
    { key: 'conversations', label: t('ads.control.numbers.conversations'), value: formatCount(tot.value.conversations, locale.value), href: explorer({ sort: '-conversations' }) },
]);

const days = computed(() => props.overview.daily);
const bars = computed(() => [{ key: 'spend', label: t('ads.control.numbers.spend'), values: days.value.map((d) => d.spend_tax), color: 'hsl(var(--chart-1))', format: (v: number) => money(v) }]);
const lines = computed(() => [{ key: 'rev', label: t('ads.control.numbers.revenue'), values: days.value.map((d) => d.real_revenue), color: 'hsl(var(--chart-2))', format: (v: number) => money(v) }]);
const dailyRows = computed(() => days.value.map((d) => ({ ...d, id: d.date })));
const dailyCols = computed<Column[]>(() => [
    { key: 'date', label: t('ads.table.date'), primary: true },
    { key: 'spend_tax', label: t('ads.control.numbers.spend'), numeric: true },
    { key: 'real_orders', label: t('ads.control.numbers.orders'), numeric: true },
    { key: 'real_revenue', label: t('ads.control.numbers.revenue'), numeric: true },
    { key: 'roas', label: t('ads.control.today.meta_roas'), numeric: true },
]);
const accountCols = computed<Column[]>(() => [
    { key: 'name', label: t('ads.control.numbers.accounts'), primary: true },
    { key: 'buyer', label: t('ads.control.filter.buyer') },
    { key: 'spend_tax', label: t('ads.control.numbers.spend'), numeric: true },
    { key: 'roas', label: t('ads.control.today.meta_roas'), numeric: true },
]);
const chatCurrency = computed(() => props.chat_campaigns?.currency || props.currency);
const chatRows = computed(() => (props.chat_campaigns?.rows ?? []).map((r, i) => ({ ...r, id: i })));
const chatCols = computed<Column[]>(() => [
    { key: 'campaign', label: t('ads.control.numbers.campaign'), primary: true },
    { key: 'conversations', label: t('ads.control.numbers.conversations'), numeric: true },
    { key: 'orders', label: t('ads.control.numbers.orders'), numeric: true },
    { key: 'revenue', label: t('ads.control.numbers.revenue'), numeric: true },
    { key: 'spend', label: t('ads.control.numbers.spend'), numeric: true },
    { key: 'cost_per_order', label: t('ads.control.numbers.cost_per_order'), numeric: true },
    { key: 'roas', label: t('ads.control.numbers.roas'), numeric: true },
]);
const crumbs = computed(() => [{ label: t('nav.ads'), href: '/ads' }, { label: t('ads.control.numbers.title') }]);
const appCrumbs = computed(() => crumbs.value.map((c) => ({ title: c.label, href: c.href ?? '/ads/numbers' })));

onMounted(() => {
    if (props.filters.section) document.getElementById(props.filters.section)?.scrollIntoView?.({ block: 'start' });
});
</script>

<template>
    <Head :title="t('ads.control.numbers.title')" />
    <AppLayout :breadcrumbs="appCrumbs">
        <div class="mx-auto w-full min-w-0 max-w-[1400px] space-y-5 p-3 md:p-6">
            <PageHeader :title="t('ads.control.numbers.title')" :description="t('ads.control.numbers.description')" :breadcrumbs="crumbs" :freshness="freshness" />
            <DataHealthBanner :data-health="data_health" :numbers-under-review="numbers_under_review" :clamped-to-history="clamped_to_history" />
            <ul v-if="sync_errors.length" class="space-y-1 rounded-md bg-destructive/10 p-3 text-xs text-destructive" role="alert">
                <li v-for="e in sync_errors" :key="e.account"><span dir="auto">{{ e.account }}</span>: {{ e.error }}</li>
            </ul>
            <AdsFilterBar
                path="/ads/numbers"
                :filters="filters"
                :account-options="account_options"
                :buyers="buyerOptions"
                :platforms="platforms"
                :show="{ status: false, list: false, presets: false }"
            />

            <section class="grid grid-cols-2 gap-3 md:grid-cols-5" :aria-label="t('ads.control.numbers.title')">
                <StatCard v-for="h in hero" :key="h.key" data-test="hero" :label="h.label" :value="h.value">
                    <Link :data-test="h.key === 'losers' ? 'losers-link' : `hero-link-${h.key}`" :href="h.href" class="text-2xs text-primary hover:underline">{{ h.link }}</Link>
                </StatCard>
            </section>
            <details data-test="more" class="rounded-lg bg-card p-3 shadow-card">
                <summary class="cursor-pointer text-xs font-medium">{{ t('ads.control.numbers.more') }}</summary>
                <div class="mt-3 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                    <Link v-for="m in more" :key="m.key" :href="m.href" class="block rounded-lg hover:ring-1 hover:ring-primary">
                        <StatCard :label="m.label" :value="m.value" />
                    </Link>
                </div>
            </details>

            <RevenueSummaryCard :summary="summary" />
            <ComboChart
                v-if="days.length"
                :title="t('ads.control.numbers.daily')"
                :labels="days.map((d) => d.date)"
                :label-format="(l: string) => formatDayShort(l, locale)"
                :bars="bars"
                :lines="lines"
            />
            <DataTable table-id="ads-numbers-daily" :columns="dailyCols" :rows="dailyRows" :caption="t('ads.control.numbers.daily')">
                <template #cell-date="{ row }">
                    <Link :href="href('/ads/explorer', { from: row.date, to: row.date, status: 'all' })" class="hover:underline">{{ formatDayShort(row.date, locale) }}</Link>
                </template>
                <template #cell-spend_tax="{ row }">{{ money(row.spend_tax) }}</template>
                <template #cell-real_revenue="{ row }">{{ money(row.real_revenue) }}</template>
                <template #cell-roas="{ row }">{{ formatRoas(row.roas, locale) }}</template>
            </DataTable>

            <section id="accounts" class="scroll-mt-20 space-y-2">
                <h2 class="text-sm font-semibold">{{ t('ads.control.numbers.accounts') }}</h2>
                <DataTable table-id="ads-numbers-accounts" :columns="accountCols" :rows="top_accounts" :caption="t('ads.control.numbers.accounts')">
                    <template #cell-name="{ row }">
                        <Link :data-test="`account-link-${row.id}`" :href="href('/ads/explorer', { accounts: String(row.id), status: 'all' })" class="hover:underline" dir="auto">
                            {{ row.name }}
                        </Link>
                    </template>
                    <template #cell-buyer="{ row }"><bdi>{{ row.buyer ?? '—' }}</bdi></template>
                    <template #cell-spend_tax="{ row }">{{ money(row.spend_tax, row.currency) }}</template>
                    <template #cell-roas="{ row }">{{ formatRoas(row.roas, locale) }}</template>
                </DataTable>
            </section>

            <section id="buyers" class="scroll-mt-20 space-y-2">
                <h2 class="text-sm font-semibold">{{ t('ads.control.numbers.buyers') }}</h2>
                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    <BuyerCard v-for="c in buyers" :key="c.buyer_id ?? 'none'" :card="c" :currency="currency" :href="c.buyer_id === null ? null : href(`/ads/buyers/${c.buyer_id}`)" />
                </div>
            </section>

            <section v-if="chat_campaigns" id="chat" class="scroll-mt-20 space-y-2">
                <h2 class="text-sm font-semibold">{{ t('ads.control.numbers.chat') }}</h2>
                <DataTable table-id="ads-numbers-chat" :columns="chatCols" :rows="chatRows" :empty="t('ads.control.numbers.no_chat')" :caption="t('ads.control.numbers.chat')">
                    <template #cell-campaign="{ row }">
                        <Link :href="href('/ads/explorer', { view: 'tree', status: 'all' })" class="hover:underline" dir="auto">{{ row.campaign }}</Link>
                    </template>
                    <template #cell-revenue="{ row }">{{ money(row.revenue) }}</template>
                    <template #cell-spend="{ row }">{{ money(row.spend, chatCurrency) }}</template>
                    <template #cell-cost_per_order="{ row }">{{ money(row.cost_per_order, chatCurrency) }}</template>
                    <template #cell-roas="{ row }">{{ formatRoas(row.roas, locale) }}</template>
                </DataTable>
            </section>
        </div>
    </AppLayout>
</template>
