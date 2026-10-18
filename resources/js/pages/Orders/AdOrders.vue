<script setup lang="ts">
import AdDrawer from '@/components/ads/AdDrawer.vue';
import DetailPanel from '@/components/crm/orders/DetailPanel.vue';
import OrdersListTable from '@/components/crm/orders/OrdersListTable.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import Pagination from '@/components/crm/Pagination.vue';
import Ledger, { type LedgerFigure } from '@/components/today/Ledger.vue';
import { Button } from '@/components/ui/button';
import { useAdDrawer } from '@/composables/useAdDrawer';
import { useI18n } from '@/composables/useI18n';
import { useStaleOrderRefresh } from '@/composables/useStaleOrderRefresh';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCount, formatMoney } from '@/lib/format';
import { drawerRange } from '@/lib/ordersHub';
import type { OrderRow, Paginated } from '@/types/admin';
import type { AdOrdersAd, OrdersProductRow, OrdersRange, OrdersTotals } from '@/types/orders';
import { Head } from '@inertiajs/vue3';
import { ExternalLink, Megaphone } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

/** `/orders/ads/{ad}` (fresh-orders F5): one ad's orders under the /orders filters. */
const props = withDefaults(
    defineProps<{
        ad: AdOrdersAd;
        summary: OrdersTotals;
        products: OrdersProductRow[];
        orders: Paginated<OrderRow>;
        range: OrdersRange;
        canOpenAds?: boolean;
    }>(),
    { canOpenAds: false },
);

const { t, locale } = useI18n();
const drawer = useAdDrawer();
const n = (v: number) => formatCount(v, locale.value);
const money = (v: number) => formatMoney(v, locale.value);

const rows = ref<OrderRow[]>(props.orders.data);
watch(
    () => props.orders.data,
    (data) => (rows.value = data),
);
useStaleOrderRefresh(rows);

/** Back to /orders on the ads tab with the same filters. */
const backHref = computed(() => {
    const p = new URLSearchParams(typeof window === 'undefined' ? '' : window.location.search);
    p.delete('page');
    p.delete('ad');
    p.set('tab', 'ads');
    return `/orders?${p.toString()}`;
});
const title = computed(() => props.ad.name ?? t('ordersHub.ad_page.title'));
const crumbs = computed(() => [{ label: t('ordersHub.ad_page.back'), href: backHref.value }, { label: title.value }]);
const breadcrumbs = computed(() => [
    { title: t('orders.title'), href: backHref.value },
    { title: title.value, href: `/orders/ads/${props.ad.id}` },
]);
const drawerFilters = computed(() => drawerRange(props.range));
/** The ad's totals in one ledger (DESIGN §3 Ledger). */
const figures = computed<LedgerFigure[]>(() => [
    { key: 'orders', label: t('ordersHub.analytics.orders'), value: n(props.summary.orders) },
    {
        key: 'revenue',
        label: t('ordersHub.analytics.revenue'),
        value: n(Math.round(props.summary.revenue)),
        unit: t('common.currency'),
        note: t('ordersHub.analytics.real_hint'),
    },
    { key: 'customers', label: t('ordersHub.analytics.customers'), value: n(props.summary.customers) },
    { key: 'units', label: t('ordersHub.ad_page.units'), value: n(props.summary.units) },
]);
const subtitle = computed(() => [props.ad.campaign, props.ad.ad_set].filter(Boolean).join(' · '));
</script>

<template>
    <Head :title="title" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full min-w-0 max-w-7xl space-y-4 p-3 md:p-6">
            <PageHeader :title="title" :description="subtitle || undefined" :breadcrumbs="crumbs">
                <Button v-if="canOpenAds" variant="outline" size="sm" data-open-ad @click="drawer.open(ad.id)">
                    <Megaphone aria-hidden="true" />{{ t('ordersHub.open_ad') }}
                </Button>
                <Button
                    v-if="canOpenAds && ad.manager_url"
                    as="a"
                    variant="outline"
                    size="sm"
                    :href="ad.manager_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    data-open-meta
                >
                    <ExternalLink aria-hidden="true" />{{ t('ordersHub.open_meta') }}
                </Button>
            </PageHeader>

            <Ledger :figures="figures" :label="t('ordersHub.ad_page.summary')" />

            <DetailPanel v-if="products.length" :title="t('ordersHub.ad_page.products')">
                <ul class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3" data-ad-products>
                    <li v-for="p in products" :key="p.title" class="flex min-w-0 items-center gap-2.5 rounded-lg bg-surface-2 p-2">
                        <img
                            v-if="p.image_url"
                            :src="p.image_url"
                            :alt="p.title"
                            class="h-[60px] w-12 shrink-0 rounded-md object-cover"
                            loading="lazy"
                        />
                        <span v-else class="h-[60px] w-12 shrink-0 rounded-md bg-sunken" aria-hidden="true" />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-13 font-semibold" dir="auto">{{ p.title }}</span>
                            <span class="block text-xs tabular-nums text-fg-2"
                                >{{ t('ordersHub.analytics.units', { n: n(p.units) }) }} · {{ money(p.revenue) }}</span
                            >
                        </span>
                    </li>
                </ul>
            </DetailPanel>

            <section class="min-w-0" :aria-label="t('ordersHub.ad_page.orders')">
                <h2 class="mb-2 text-base font-bold">{{ t('ordersHub.ad_page.orders') }}</h2>
                <OrdersListTable table-id="ad-orders" :rows="rows" :can-open-ads="canOpenAds" :show-source="false" />
                <Pagination :page="orders" />
            </section>
        </div>

        <AdDrawer v-if="canOpenAds" :ad-id="drawer.adId.value" :filters="drawerFilters" @close="drawer.close" />
    </AppLayout>
</template>
