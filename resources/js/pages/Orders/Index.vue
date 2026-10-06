<script setup lang="ts">
import AdDrawer from '@/components/ads/AdDrawer.vue';
import DateRangePicker from '@/components/crm/DateRangePicker.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import FilterBar from '@/components/crm/FilterBar.vue';
import OrdersAdsTab from '@/components/crm/orders/OrdersAdsTab.vue';
import OrdersAnalyticsTab from '@/components/crm/orders/OrdersAnalyticsTab.vue';
import OrdersListTable from '@/components/crm/orders/OrdersListTable.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import Pagination from '@/components/crm/Pagination.vue';
import SkeletonList from '@/components/crm/SkeletonList.vue';
import { Button } from '@/components/ui/button';
import { useAdDrawer } from '@/composables/useAdDrawer';
import { useI18n } from '@/composables/useI18n';
import { useStaleOrderRefresh } from '@/composables/useStaleOrderRefresh';
import { useUrlFilters } from '@/composables/useUrlFilters';
import { useVisitLoading } from '@/composables/useVisitLoading';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMinutes } from '@/lib/format';
import type { SharedData } from '@/types';
import type { OrderRow, Paginated, ReportRange } from '@/types/admin';
import type { OrdersAnalytics, OrdersByAdRow, OrdersTab } from '@/types/orders';
import { Head, router, usePage } from '@inertiajs/vue3';
import { SearchX } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const FINANCIAL_STATUSES = ['paid', 'pending', 'partially_paid', 'refunded', 'partially_refunded', 'voided'];
const FULFILLMENT_STATUSES = ['fulfilled', 'partial', 'unfulfilled', 'restocked'];
const STATUSES = ['submitting', 'awaiting_payment', 'confirmed', 'cancelled', 'failed'];
const AD_PLATFORMS = ['meta', 'tiktok', 'google'] as const;
const TABS: OrdersTab[] = ['list', 'analytics', 'ads'];

// The server still shares `filters`; the page reads the same values from the URL (useUrlFilters).
defineOptions({ inheritAttrs: false });

const props = withDefaults(
    defineProps<{
        tab?: OrdersTab;
        orders: Paginated<OrderRow> | null;
        analytics?: OrdersAnalytics | null;
        adsBreakdown?: OrdersByAdRow[] | null;
        /** The range the server used (this Cairo month when the URL has none). */
        range: ReportRange;
        canOpenAds?: boolean;
        governorates?: { value: string; label: string }[];
        team?: { id: number; name: string }[];
    }>(),
    { tab: 'list', analytics: null, adsBreakdown: null, canOpenAds: false, governorates: () => [], team: () => [] },
);

const { t, locale } = useI18n();
const page = usePage<SharedData>();
const { loading, track } = useVisitLoading();
const drawer = useAdDrawer();

// The filters live in the URL (shareable, back/forward), and the server reads the same keys.
const { filters, set, clear, activeKeys, query } = useUrlFilters(
    {
        tab: 'list',
        q: '',
        status: null as string | null,
        governorate: null as string | null,
        ad_platform: null as string | null,
        financial_status: null as string | null,
        source: null as string | null,
        type: null as string | null,
        created_by: null as string | null,
        platform: null as string | null,
        fulfillment_status: null as string | null,
        /** Cairo calendar dates (Y-m-d); none = this month (server default). */
        from: null as string | null,
        to: null as string | null,
        mismatch: false,
        stuck: false,
        /** Minutes since the order was made (the «النهارده» payment link); no control, a chip only. */
        older_than: null as string | null,
        /** «النهارده» links: without cancelled and failed (the reports' set). */
        real: false,
        /** Whitelisted on the server (OrderController::SORTS): `-total`, `created_at`, ... */
        sort: '',
    },
    { replaceKeys: ['q'], keep: ['ad'] },
);

// Any filter change reloads the open tab from the server.
watch(query, (q) => {
    router.get(
        '/orders',
        q,
        track({ only: ['tab', 'orders', 'analytics', 'adsBreakdown', 'range', 'filters'], preserveState: true, preserveScroll: true, replace: true }),
    );
});

const activeTab = computed<OrdersTab>(() => (TABS.includes(filters.value.tab as OrdersTab) ? (filters.value.tab as OrdersTab) : 'list'));

// Rows the page patches in place when an OrderUpdated broadcast arrives.
const rows = ref<OrderRow[]>(props.orders?.data ?? []);
watch(
    () => props.orders?.data,
    (data) => (rows.value = data ?? []),
);
useStaleOrderRefresh(rows);

// The «فلاتر» badge counts what the popover holds.
const MORE_KEYS = ['financial_status', 'source', 'type', 'created_by', 'platform', 'fulfillment_status', 'mismatch', 'stuck'];
const moreCount = computed(() => activeKeys.value.filter((k) => MORE_KEYS.includes(k as string)).length);

const governorateLabel = (v: string) => props.governorates.find((g) => g.value === v)?.label ?? v;
const adPlatformLabel = (v: string) =>
    v === 'direct' ? t('ordersHub.filters.direct') : v === 'meta' ? 'Meta' : v === 'tiktok' ? 'TikTok' : 'Google';

const chips = computed(() => {
    const f = filters.value;
    const out: { key: string; label: string }[] = [];
    if (f.status) out.push({ key: 'status', label: t(`orders.statuses.${f.status}`) });
    if (f.governorate) out.push({ key: 'governorate', label: governorateLabel(f.governorate) });
    if (f.ad_platform) out.push({ key: 'ad_platform', label: adPlatformLabel(f.ad_platform) });
    if (f.financial_status) out.push({ key: 'financial_status', label: t(`orders.payment_status.${f.financial_status}`) });
    if (f.source) out.push({ key: 'source', label: t(`orders.source.${f.source}`) });
    if (f.type) out.push({ key: 'type', label: t(`order.${f.type}`) });
    if (f.created_by)
        out.push({ key: 'created_by', label: props.team.find((m) => String(m.id) === f.created_by)?.name ?? t('orders.list.filter_team') });
    if (f.platform) out.push({ key: 'platform', label: page.props.platforms.find((p) => p.value === f.platform)?.label ?? f.platform });
    if (f.fulfillment_status) out.push({ key: 'fulfillment_status', label: t(`orders.fulfillment_status.${f.fulfillment_status}`) });
    if (f.mismatch) out.push({ key: 'mismatch', label: t('orders.mismatch_only') });
    if (f.stuck) out.push({ key: 'stuck', label: t('orders.stuck_only') });
    if (f.real) out.push({ key: 'real', label: t('orders.real_chip') });
    if (f.older_than)
        out.push({ key: 'older_than', label: t('orders.older_than_chip', { time: formatMinutes(Number(f.older_than), locale.value) }) });
    return out;
});

function removeChip(key: string): void {
    if (key === 'real' || key === 'mismatch' || key === 'stuck') set({ [key]: false });
    else set({ [key]: null } as Partial<typeof filters.value>);
}

/** Clearing keeps the open tab. */
function clearAll(): void {
    const tab = filters.value.tab;
    clear();
    if (tab !== 'list') set({ tab });
}

const range = computed<ReportRange>(() => ({ from: filters.value.from ?? props.range.from, to: filters.value.to ?? props.range.to }));
const selectValue = (event: Event) => (event.target as HTMLSelectElement).value || null;

// One-click saved views: each preset is a plain filtered address, so the chip, the URL and the list agree.
const PRESETS = [
    { key: 'awaiting_payment', query: 'status=awaiting_payment', active: () => filters.value.status === 'awaiting_payment' },
    { key: 'mismatch', query: 'mismatch=1', active: () => filters.value.mismatch },
    { key: 'stuck', query: 'stuck=1', active: () => filters.value.stuck },
] as const;
const presets = computed(() =>
    PRESETS.map((p) => ({ key: p.key, label: t(`orders.presets.${p.key}`), href: `/orders?${p.query}`, active: p.active() })),
);

const summary = computed(() => {
    const count = activeTab.value === 'list' ? [t('ui.results', { n: props.orders?.meta?.total ?? rows.value.length })] : [];
    return [...count, ...chips.value.map((c) => c.label)].join(' · ');
});
const filtered = computed(() => chips.value.length > 0 || filters.value.q !== '');

/** The filters (no tab, page or sort) carried to /orders/ads/{ad}, so the ad page shows the same slice. */
const adQuery = computed(() => {
    const p = new URLSearchParams(query.value as Record<string, string>);
    for (const k of ['tab', 'sort', 'page']) p.delete(k);
    p.set('from', range.value.from);
    p.set('to', range.value.to);
    return p.toString();
});
const drawerFilters = computed(() => ({ from: range.value.from, to: range.value.to, platform: null, buyer: null }));

const breadcrumbs = computed(() => [{ title: t('orders.title'), href: '/orders' }]);
const selectClass = 'h-9 w-full rounded-md border border-input bg-background px-2 text-xs sm:w-auto';
const fieldLabel = 'mb-1 block text-2xs font-medium text-muted-foreground';
</script>

<template>
    <Head :title="t('orders.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full min-w-0 max-w-7xl space-y-4 p-3 md:p-6">
            <PageHeader :title="t('orders.title')" />

            <div class="flex flex-wrap gap-1.5" role="tablist" :aria-label="t('orders.title')">
                <button
                    v-for="tab in TABS"
                    :key="tab"
                    type="button"
                    role="tab"
                    :aria-selected="activeTab === tab"
                    class="inline-flex h-9 items-center rounded-full px-4 text-xs font-medium"
                    :class="
                        activeTab === tab ? 'bg-primary text-primary-foreground' : 'bg-card text-muted-foreground shadow-card hover:text-foreground'
                    "
                    :data-tab="tab"
                    @click="set({ tab })"
                >
                    {{ t(`ordersHub.tabs.${tab}`) }}
                </button>
            </div>

            <div class="space-y-3 rounded-lg bg-card p-3 shadow-card">
                <div class="min-w-0">
                    <span :class="fieldLabel">{{ t('ordersHub.filters.range') }}</span>
                    <DateRangePicker :model-value="range" month @update:model-value="set({ from: $event.from || null, to: $event.to || null })" />
                </div>
                <FilterBar
                    :search="filters.q"
                    :search-placeholder="t('orders.search')"
                    :chips="chips"
                    :more-count="moreCount"
                    :presets="presets"
                    :summary="summary"
                    @update:search="set({ q: $event })"
                    @remove="removeChip"
                    @clear="clearAll()"
                >
                    <template #inline>
                        <select
                            :value="filters.status ?? ''"
                            :class="selectClass"
                            :aria-label="t('orders.status_all')"
                            @change="set({ status: selectValue($event) })"
                        >
                            <option value="">{{ t('orders.status_all') }}</option>
                            <option v-for="s in STATUSES" :key="s" :value="s">{{ t(`orders.statuses.${s}`) }}</option>
                        </select>
                        <select
                            :value="filters.governorate ?? ''"
                            :class="selectClass"
                            :aria-label="t('ordersHub.filters.governorate')"
                            data-filter="governorate"
                            @change="set({ governorate: selectValue($event) })"
                        >
                            <option value="">{{ t('ordersHub.filters.governorate_all') }}</option>
                            <option v-for="g in governorates" :key="g.value" :value="g.value">{{ g.label }}</option>
                        </select>
                        <select
                            :value="filters.ad_platform ?? ''"
                            :class="selectClass"
                            :aria-label="t('ordersHub.filters.ad_platform')"
                            data-filter="ad_platform"
                            @change="set({ ad_platform: selectValue($event) })"
                        >
                            <option value="">{{ t('ordersHub.filters.ad_platform_all') }}</option>
                            <option v-for="p in AD_PLATFORMS" :key="p" :value="p">{{ adPlatformLabel(p) }}</option>
                            <option value="direct">{{ t('ordersHub.filters.direct') }}</option>
                        </select>
                    </template>
                    <template #more>
                        <div class="grid grid-cols-2 gap-3">
                            <label class="min-w-0">
                                <span :class="fieldLabel">{{ t('orders.financial_all') }}</span>
                                <select
                                    :value="filters.financial_status ?? ''"
                                    :class="[selectClass, 'sm:w-full']"
                                    @change="set({ financial_status: selectValue($event) })"
                                >
                                    <option value="">{{ t('orders.financial_all') }}</option>
                                    <option v-for="s in FINANCIAL_STATUSES" :key="s" :value="s">{{ t(`orders.payment_status.${s}`) }}</option>
                                </select>
                            </label>
                            <label class="min-w-0">
                                <span :class="fieldLabel">{{ t('orders.list.filter_source') }}</span>
                                <select
                                    :value="filters.source ?? ''"
                                    :class="[selectClass, 'sm:w-full']"
                                    @change="set({ source: selectValue($event) })"
                                >
                                    <option value="">{{ t('orders.source_all') }}</option>
                                    <option value="chat">{{ t('orders.source.chat') }}</option>
                                    <option value="store">{{ t('orders.source.store') }}</option>
                                </select>
                            </label>
                            <label class="min-w-0">
                                <span :class="fieldLabel">{{ t('orders.list.filter_type') }}</span>
                                <select :value="filters.type ?? ''" :class="[selectClass, 'sm:w-full']" @change="set({ type: selectValue($event) })">
                                    <option value="">{{ t('orders.type_all') }}</option>
                                    <option value="cod">{{ t('order.cod') }}</option>
                                    <option value="payment_link">{{ t('order.payment_link') }}</option>
                                </select>
                            </label>
                            <label class="min-w-0">
                                <span :class="fieldLabel">{{ t('orders.list.filter_team') }}</span>
                                <select
                                    :value="filters.created_by ?? ''"
                                    :class="[selectClass, 'sm:w-full']"
                                    @change="set({ created_by: selectValue($event) })"
                                >
                                    <option value="">{{ t('orders.created_by_all') }}</option>
                                    <option v-for="member in team" :key="member.id" :value="String(member.id)">{{ member.name }}</option>
                                </select>
                            </label>
                            <label class="min-w-0">
                                <span :class="fieldLabel">{{ t('orders.list.filter_platform') }}</span>
                                <select
                                    :value="filters.platform ?? ''"
                                    :class="[selectClass, 'sm:w-full']"
                                    @change="set({ platform: selectValue($event) })"
                                >
                                    <option value="">{{ t('ui.all_platforms') }}</option>
                                    <option v-for="p in page.props.platforms" :key="p.value" :value="p.value">{{ p.label }}</option>
                                </select>
                            </label>
                            <label class="min-w-0">
                                <span :class="fieldLabel">{{ t('orders.list.filter_fulfillment') }}</span>
                                <select
                                    :value="filters.fulfillment_status ?? ''"
                                    :class="[selectClass, 'sm:w-full']"
                                    @change="set({ fulfillment_status: selectValue($event) })"
                                >
                                    <option value="">{{ t('orders.fulfillment_all') }}</option>
                                    <option v-for="s in FULFILLMENT_STATUSES" :key="s" :value="s">{{ t(`orders.fulfillment_status.${s}`) }}</option>
                                </select>
                            </label>
                        </div>
                        <div>
                            <span :class="fieldLabel">{{ t('orders.list.filter_flags') }}</span>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="flag in ['mismatch', 'stuck'] as const"
                                    :key="flag"
                                    type="button"
                                    class="h-8 rounded-full border px-3 text-xs font-medium"
                                    :class="
                                        filters[flag]
                                            ? 'border-warning bg-warning/15 text-foreground'
                                            : 'border-input bg-background text-muted-foreground hover:text-foreground'
                                    "
                                    :aria-pressed="filters[flag]"
                                    @click="set({ [flag]: !filters[flag] })"
                                >
                                    {{ t(flag === 'mismatch' ? 'orders.mismatch_only' : 'orders.stuck_only') }}
                                </button>
                            </div>
                        </div>
                    </template>
                </FilterBar>
            </div>

            <div v-if="activeTab === 'list'" role="tabpanel" class="min-w-0">
                <OrdersListTable
                    table-id="orders"
                    :rows="rows"
                    :loading="loading"
                    :can-open-ads="canOpenAds"
                    :sort="filters.sort || null"
                    @update:sort="set({ sort: $event })"
                    @open-ad="drawer.open"
                >
                    <template v-if="filtered" #empty>
                        <EmptyState :icon="SearchX" :title="t('orders.empty')">
                            <template #action>
                                <Button variant="outline" size="sm" @click="clearAll()">{{ t('ui.clear_filters') }}</Button>
                            </template>
                        </EmptyState>
                    </template>
                </OrdersListTable>
                <Pagination v-if="orders" :page="orders" />
            </div>
            <div v-else-if="activeTab === 'analytics'" role="tabpanel" class="min-w-0">
                <SkeletonList v-if="loading || !analytics" variant="tiles" />
                <OrdersAnalyticsTab v-else :data="analytics" />
            </div>
            <div v-else role="tabpanel" class="min-w-0">
                <SkeletonList v-if="loading || !adsBreakdown" variant="cards" />
                <OrdersAdsTab v-else :rows="adsBreakdown" :can-open-ads="canOpenAds" :query="adQuery" @open-ad="drawer.open" />
            </div>
        </div>

        <AdDrawer v-if="canOpenAds" :ad-id="drawer.adId.value" :filters="drawerFilters" @close="drawer.close" />
    </AppLayout>
</template>
