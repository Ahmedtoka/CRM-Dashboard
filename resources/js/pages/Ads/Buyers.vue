<script setup lang="ts">
/** Ads Hub — الميديا باير: one scorecard per buyer + a sortable comparison table (spec §8.2). */
import AdsRangeBar from '@/components/ads/AdsRangeBar.vue';
import BuyerCard from '@/components/ads/BuyerCard.vue';
import MoneyCell from '@/components/ads/MoneyCell.vue';
import DataTable from '@/components/crm/DataTable.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { flowArrow, formatAdsMoney, formatPct, formatQty, formatRoas, rangeQueryString } from '@/lib/ads';
import { formatCount } from '@/lib/format';
import type { SharedData } from '@/types';
import type { AdsBuyersProps, BuyerCardData } from '@/types/ads';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Users } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<AdsBuyersProps>();

const { t, locale } = useI18n();
const page = usePage<SharedData>();
const money = (v: number | null) => formatAdsMoney(v, locale.value);
const n = (v: number) => formatCount(v, locale.value);

const hrefOf = (c: BuyerCardData) => (c.buyer_id === null ? null : `/ads/buyers/${c.buyer_id}${rangeQueryString(props.filters)}`);

type SortKey = 'spend' | 'roas' | 'purchases' | 'real_orders' | 'real_revenue' | 'conversations' | 'cpa' | 'ctr' | 'budget_used_pct';
const sortKey = ref<SortKey>('spend');
const sortDesc = ref(true);
const SORTABLE: SortKey[] = ['spend', 'budget_used_pct', 'roas', 'purchases', 'real_orders', 'real_revenue', 'conversations', 'cpa', 'ctr'];

function toggleSort(key: SortKey): void {
    if (sortKey.value === key) sortDesc.value = !sortDesc.value;
    else {
        sortKey.value = key;
        sortDesc.value = key !== 'cpa';
    }
}

type Row = BuyerCardData & { id: string };
const rows = computed<Row[]>(() => {
    const dir = sortDesc.value ? -1 : 1;
    // Nulls always sink to the bottom whatever the direction.
    return props.cards
        .map((c) => ({ ...c, id: c.buyer_id === null ? 'unassigned' : String(c.buyer_id) }))
        .sort((a, b) => {
            const x = a[sortKey.value];
            const y = b[sortKey.value];
            if (x === null && y === null) return 0;
            if (x === null) return 1;
            if (y === null) return -1;
            return (x - y) * dir;
        });
});

const columns = computed(() => [
    { key: 'name', label: t('ads.table.buyer'), primary: true },
    { key: 'spend', label: t('ads.kpi.spend_tax'), align: 'end' as const },
    { key: 'budget_used_pct', label: t('ads.buyers.budget'), align: 'end' as const, hideOnMobile: true },
    { key: 'roas', label: t('ads.kpi.roas'), align: 'end' as const },
    { key: 'purchases', label: t('ads.kpi.meta_orders'), align: 'end' as const },
    { key: 'real_orders', label: t('ads.kpi.real_orders'), align: 'end' as const },
    { key: 'real_revenue', label: t('ads.table.real_revenue'), align: 'end' as const, hideOnMobile: true },
    { key: 'conversations', label: t('ads.kpi.conversations'), align: 'end' as const, hideOnMobile: true },
    { key: 'cpa', label: t('ads.kpi.cpa'), align: 'end' as const },
    { key: 'ctr', label: t('ads.kpi.ctr'), align: 'end' as const, hideOnMobile: true },
]);

function open(row: Row): void {
    const href = hrefOf(row);
    if (href) router.visit(href);
}

const isBuyer = computed(() => page.props.ads?.isBuyer === true);
const breadcrumbs = computed(() => [
    { title: t('nav.ads'), href: '/ads' },
    { title: t('nav.ads_buyers'), href: '/ads/buyers' },
]);
</script>

<template>
    <Head :title="t('ads.buyers.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-7xl space-y-4 p-3 md:p-6">
            <PageHeader :title="t('ads.buyers.title')" :description="t('ads.buyers.hint')">
                <AdsRangeBar :filters="filters" />
            </PageHeader>

            <EmptyState
                v-if="!cards.length"
                :icon="Users"
                :title="t('ads.buyers.empty')"
                :body="isBuyer ? t('ads.buyers.empty_buyer') : t('ads.empty.body')"
                class="rounded-lg bg-card shadow-card"
            >
                <template v-if="page.props.ads?.canManage" #action>
                    <Link
                        href="/ads/setup/buyers"
                        class="inline-flex h-9 items-center rounded-md bg-primary px-3 text-xs font-medium text-primary-foreground"
                        >{{ t('ads.buyers.setup_cta') }}</Link
                    >
                </template>
            </EmptyState>

            <template v-else>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <BuyerCard v-for="c in cards" :key="c.buyer_id ?? 'unassigned'" :card="c" :href="hrefOf(c)" />
                </div>

                <section class="space-y-2">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-sm font-bold">{{ t('ads.buyers.compare') }}</h2>
                        <div class="flex flex-wrap items-center gap-1" role="group" :aria-label="t('ads.filters.sort')">
                            <span class="text-2xs text-muted-foreground">{{ t('ads.filters.sort') }}:</span>
                            <button
                                v-for="k in SORTABLE"
                                :key="k"
                                type="button"
                                :aria-pressed="sortKey === k"
                                class="inline-flex h-7 items-center gap-1 rounded-full border px-2 text-2xs transition-colors"
                                :class="
                                    sortKey === k
                                        ? 'border-primary bg-primary text-primary-foreground'
                                        : 'bg-background text-muted-foreground hover:text-foreground'
                                "
                                @click="toggleSort(k)"
                            >
                                {{ columns.find((c) => c.key === k)?.label }}
                                <template v-if="sortKey === k">
                                    <ArrowDown v-if="sortDesc" class="size-3" aria-hidden="true" />
                                    <ArrowUp v-else class="size-3" aria-hidden="true" />
                                    <span class="sr-only">{{ sortDesc ? t('ads.filters.desc') : t('ads.filters.asc') }}</span>
                                </template>
                            </button>
                        </div>
                    </div>
                    <DataTable :columns="columns" :rows="rows" clickable :caption="t('ads.buyers.compare')" @row-click="open">
                        <template #cell-name="{ row }">
                            <span class="inline-flex items-center gap-2 font-semibold">
                                <span
                                    class="size-2 rounded-full"
                                    :style="{ backgroundColor: (row as Row).color ?? 'hsl(var(--muted-foreground))' }"
                                    aria-hidden="true"
                                />
                                {{ (row as Row).name }}
                            </span>
                        </template>
                        <template #cell-spend="{ row }"><MoneyCell :amount="(row as Row).spend" :with-tax="(row as Row).spend_tax" /></template>
                        <template #cell-budget_used_pct="{ row }">
                            <span class="tabular-nums" :class="((row as Row).budget_used_pct ?? 0) > 100 ? 'font-semibold text-destructive' : ''">
                                {{
                                    (row as Row).budget_used_pct === null ? '—' : formatPct(((row as Row).budget_used_pct as number) / 100, locale, 0)
                                }}
                            </span>
                        </template>
                        <template #cell-roas="{ row }">
                            <span
                                class="font-semibold tabular-nums"
                                :class="
                                    (row as Row).target_roas === null || (row as Row).roas === null
                                        ? ''
                                        : ((row as Row).roas as number) >= ((row as Row).target_roas as number)
                                          ? 'text-emerald-700 dark:text-emerald-300'
                                          : 'text-destructive'
                                "
                            >
                                {{ formatRoas((row as Row).roas, locale) }}
                            </span>
                            <span v-if="(row as Row).target_roas !== null" class="block text-2xs text-muted-foreground">{{
                                t('ads.buyers.target', { roas: formatRoas((row as Row).target_roas, locale) })
                            }}</span>
                        </template>
                        <template #cell-purchases="{ row }"
                            ><span class="tabular-nums">{{ formatQty((row as Row).purchases, locale) }}</span></template
                        >
                        <template #cell-real_revenue="{ row }"
                            ><span class="tabular-nums">{{ money((row as Row).real_revenue) }}</span></template
                        >
                        <template #cell-conversations="{ row }">
                            <span class="tabular-nums"
                                >{{ n((row as Row).conversations) }} {{ flowArrow(locale) }} {{ n((row as Row).conversations_ordered) }}</span
                            >
                        </template>
                        <template #cell-cpa="{ row }"
                            ><span class="tabular-nums">{{ money((row as Row).cpa) }}</span></template
                        >
                        <template #cell-ctr="{ row }"
                            ><span class="tabular-nums">{{ formatPct((row as Row).ctr, locale) }}</span></template
                        >
                    </DataTable>
                </section>
            </template>
        </div>
    </AppLayout>
</template>
