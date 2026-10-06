<script setup lang="ts">
import AdSourceChip from '@/components/crm/AdSourceChip.vue';
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
import DateRangePicker from '@/components/crm/DateRangePicker.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import FilterBar from '@/components/crm/FilterBar.vue';
import OrderStatusChip from '@/components/crm/orders/OrderStatusChip.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import Pagination from '@/components/crm/Pagination.vue';
import RelativeTime from '@/components/crm/RelativeTime.vue';
import { useI18n } from '@/composables/useI18n';
import { useNow } from '@/composables/useNow';
import { useStaleOrderRefresh } from '@/composables/useStaleOrderRefresh';
import { useUrlFilters } from '@/composables/useUrlFilters';
import { useVisitLoading } from '@/composables/useVisitLoading';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDateTime, formatMoney } from '@/lib/format';
import { isSyncStale, notOnShopifyText, orderLabel } from '@/lib/orderStatus';
import type { SharedData } from '@/types';
import type { OrderRow, Paginated } from '@/types/admin';
import { Head, router, usePage } from '@inertiajs/vue3';
import { AlertTriangle, MessageCircle, SearchX, StickyNote, Store } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { computed, ref, watch } from 'vue';

const FINANCIAL_STATUSES = ['paid', 'pending', 'partially_paid', 'refunded', 'partially_refunded', 'voided'];
const FULFILLMENT_STATUSES = ['fulfilled', 'partial', 'unfulfilled', 'restocked'];
const SHIPMENT_STEPS = ['created', 'picked_up', 'in_transit', 'out_for_delivery', 'delivered', 'failed_attempt', 'returned', 'cancelled'];
const STATUSES = ['submitting', 'awaiting_payment', 'confirmed', 'cancelled', 'failed'];

// The server still shares `filters`; the page reads the same values from the URL (useUrlFilters),
// so that prop is left undeclared and kept off the root.
defineOptions({ inheritAttrs: false });

const props = withDefaults(defineProps<{ orders: Paginated<OrderRow>; team?: { id: number; name: string }[] }>(), {
    team: () => [],
});

const { t, locale } = useI18n();
const page = usePage<SharedData>();
const now = useNow();
const { loading, track } = useVisitLoading();

// The filters live in the URL (shareable, back/forward), and the server reads the same keys.
const { filters, set, clear, activeKeys, query } = useUrlFilters(
    {
        q: '',
        status: null as string | null,
        financial_status: null as string | null,
        source: null as string | null,
        type: null as string | null,
        created_by: null as string | null,
        platform: null as string | null,
        fulfillment_status: null as string | null,
        shipment_step: null as string | null,
        /** Cairo calendar dates (Y-m-d); the server converts them to UTC bounds. */
        from: null as string | null,
        to: null as string | null,
        mismatch: false,
        stuck: false,
        /** Whitelisted on the server (OrderController::SORTS): `-total`, `created_at`, ... */
        sort: '',
    },
    { replaceKeys: ['q'] },
);

// Any filter change reloads page 1 of the list from the server.
watch(query, (q) => {
    router.get('/orders', q, track({ only: ['orders', 'filters'], preserveState: true, preserveScroll: true, replace: true }));
});

// Rows the page patches in place when an OrderUpdated broadcast arrives.
const rows = ref<OrderRow[]>(props.orders.data);
watch(
    () => props.orders.data,
    (data) => (rows.value = data),
);
useStaleOrderRefresh(rows);

// The «فلاتر» badge counts what the popover holds; a date range counts once.
const MORE_KEYS = ['source', 'type', 'created_by', 'platform', 'fulfillment_status', 'shipment_step', 'mismatch', 'stuck'];
const moreCount = computed(
    () => activeKeys.value.filter((k) => MORE_KEYS.includes(k as string)).length + (filters.value.from || filters.value.to ? 1 : 0),
);

const chips = computed(() => {
    const f = filters.value;
    const out: { key: string; label: string }[] = [];
    if (f.status) out.push({ key: 'status', label: t(`orders.statuses.${f.status}`) });
    if (f.financial_status) out.push({ key: 'financial_status', label: t(`orders.payment_status.${f.financial_status}`) });
    if (f.source) out.push({ key: 'source', label: t(`orders.source.${f.source}`) });
    if (f.type) out.push({ key: 'type', label: t(`order.${f.type}`) });
    if (f.created_by)
        out.push({ key: 'created_by', label: props.team.find((m) => String(m.id) === f.created_by)?.name ?? t('orders.list.filter_team') });
    if (f.platform) out.push({ key: 'platform', label: page.props.platforms.find((p) => p.value === f.platform)?.label ?? f.platform });
    if (f.fulfillment_status) out.push({ key: 'fulfillment_status', label: t(`orders.fulfillment_status.${f.fulfillment_status}`) });
    if (f.shipment_step) out.push({ key: 'shipment_step', label: t(`shipment.status.${f.shipment_step}`) });
    if (f.from || f.to) out.push({ key: 'date', label: t('orders.list.date_chip', { from: f.from ?? '…', to: f.to ?? '…' }) });
    if (f.mismatch) out.push({ key: 'mismatch', label: t('orders.mismatch_only') });
    if (f.stuck) out.push({ key: 'stuck', label: t('orders.stuck_only') });
    return out;
});

function removeChip(key: string): void {
    if (key === 'date') set({ from: null, to: null });
    else if (key === 'mismatch' || key === 'stuck') set({ [key]: false });
    else set({ [key]: null } as Partial<typeof filters.value>);
}

const range = computed(() => ({ from: filters.value.from ?? '', to: filters.value.to ?? '' }));
const selectValue = (event: Event) => (event.target as HTMLSelectElement).value || null;

const columns = computed<Column[]>(() => [
    { key: 'order', label: t('orders.list.order'), primary: true },
    { key: 'customer', label: t('orders.columns.customer') },
    { key: 'ad', label: t('orders.columns.ad'), hideOnMobile: true },
    { key: 'total', label: t('orders.columns.total'), numeric: true, sortable: true },
    { key: 'status', label: t('orders.list.status') },
    { key: 'shopify_updated_at', label: t('orders.list.updated') },
    { key: 'last_synced_at', label: t('orders.list.synced') },
    { key: 'created_at', label: t('orders.columns.date'), hideOnMobile: true, sortable: true },
]);

// One-click saved views: each preset is a plain filtered address, so the chip, the URL and the list agree.
const PRESETS = [
    { key: 'awaiting_payment', query: 'status=awaiting_payment', active: () => filters.value.status === 'awaiting_payment' },
    { key: 'mismatch', query: 'mismatch=1', active: () => filters.value.mismatch },
    { key: 'stuck', query: 'stuck=1', active: () => filters.value.stuck },
] as const;
const presets = computed(() =>
    PRESETS.map((p) => ({ key: p.key, label: t(`orders.presets.${p.key}`), href: `/orders?${p.query}`, active: p.active() })),
);

// The bar says what the list is showing: the result count, then every active filter.
const summary = computed(() => [t('ui.results', { n: props.orders.meta?.total ?? rows.value.length }), ...chips.value.map((c) => c.label)].join(' · '));
const filtered = computed(() => chips.value.length > 0 || filters.value.q !== '');

const breadcrumbs = computed(() => [{ title: t('orders.title'), href: '/orders' }]);
const selectClass = 'h-9 w-full rounded-md border border-input bg-background px-2 text-xs sm:w-auto';
const fieldLabel = 'mb-1 block text-2xs font-medium text-muted-foreground';
</script>

<template>
    <Head :title="t('orders.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full min-w-0 max-w-7xl space-y-4 p-3 md:p-6">
            <PageHeader :title="t('orders.title')" />

            <div class="rounded-lg bg-card p-3 shadow-card">
                <FilterBar
                    :search="filters.q"
                    :search-placeholder="t('orders.search')"
                    :chips="chips"
                    :more-count="moreCount"
                    :presets="presets"
                    :summary="summary"
                    @update:search="set({ q: $event })"
                    @remove="removeChip"
                    @clear="clear()"
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
                            :value="filters.financial_status ?? ''"
                            :class="selectClass"
                            :aria-label="t('orders.financial_all')"
                            @change="set({ financial_status: selectValue($event) })"
                        >
                            <option value="">{{ t('orders.financial_all') }}</option>
                            <option v-for="s in FINANCIAL_STATUSES" :key="s" :value="s">{{ t(`orders.payment_status.${s}`) }}</option>
                        </select>
                    </template>
                    <template #more>
                        <div class="grid grid-cols-2 gap-3">
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
                            <label class="min-w-0">
                                <span :class="fieldLabel">{{ t('orders.list.filter_shipment') }}</span>
                                <select
                                    :value="filters.shipment_step ?? ''"
                                    :class="[selectClass, 'sm:w-full']"
                                    @change="set({ shipment_step: selectValue($event) })"
                                >
                                    <option value="">{{ t('orders.shipment_step_all') }}</option>
                                    <option v-for="s in SHIPMENT_STEPS" :key="s" :value="s">{{ t(`shipment.status.${s}`) }}</option>
                                </select>
                            </label>
                        </div>
                        <div>
                            <span :class="fieldLabel">{{ t('orders.list.filter_date') }}</span>
                            <DateRangePicker :model-value="range" @update:model-value="set({ from: $event.from || null, to: $event.to || null })" />
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

            <div>
                <DataTable
                    table-id="orders"
                    :columns="columns"
                    :rows="rows"
                    clickable
                    :loading="loading"
                    :empty="t('orders.empty')"
                    :caption="t('orders.title')"
                    :sort="filters.sort || null"
                    @update:sort="set({ sort: $event })"
                    @row-click="router.visit(`/orders/${$event.id}`)"
                >
                    <template v-if="filtered" #empty>
                        <EmptyState :icon="SearchX" :title="t('orders.empty')">
                            <template #action>
                                <Button variant="outline" size="sm" @click="clear()">{{ t('ui.clear_filters') }}</Button>
                            </template>
                        </EmptyState>
                    </template>
                    <template #cell-order="{ row }">
                        <span class="inline-flex max-w-full items-center gap-1.5 align-middle">
                            <span
                                v-if="orderLabel(row, t).draft"
                                class="truncate font-medium text-muted-foreground"
                                :title="notOnShopifyText(row, t)"
                                dir="auto"
                                >{{ orderLabel(row, t).text }}</span
                            >
                            <span v-else class="font-semibold" dir="ltr">{{ orderLabel(row, t).text }}</span>
                            <span class="inline-flex shrink-0 text-muted-foreground" :title="t(`orders.source.${row.source ?? 'chat'}`)">
                                <component :is="row.source === 'store' ? Store : MessageCircle" class="size-3.5" aria-hidden="true" />
                                <span class="sr-only">{{ t(`orders.source.${row.source ?? 'chat'}`) }}</span>
                            </span>
                            <!-- Desktop: the note is one hover away. Phone: the note is its own line below. -->
                            <span
                                v-if="row.note"
                                role="img"
                                class="hidden shrink-0 text-amber-600 dark:text-amber-400 md:inline-flex"
                                :title="row.note"
                                :aria-label="t('orders.list.has_note', { note: row.note })"
                            >
                                <StickyNote class="size-3.5" aria-hidden="true" />
                            </span>
                            <span
                                v-if="row.mismatch"
                                class="inline-flex size-4 shrink-0 items-center justify-center rounded-full bg-warning/15"
                                :title="t('order.mismatch.title')"
                            >
                                <AlertTriangle class="size-3" :aria-label="t('order.mismatch.title')" />
                            </span>
                        </span>
                        <span v-if="row.note" class="mt-0.5 flex min-w-0 items-center gap-1 text-2xs font-normal text-muted-foreground md:hidden">
                            <StickyNote class="size-3 shrink-0 text-amber-600 dark:text-amber-400" aria-hidden="true" />
                            <span class="truncate" dir="auto">{{ row.note }}</span>
                        </span>
                    </template>
                    <template #cell-customer="{ row }">
                        <span class="block max-w-[12rem] truncate">{{ row.customer?.name ?? '—' }}</span>
                        <span v-if="row.customer?.phone" class="block text-2xs text-muted-foreground" dir="ltr">{{ row.customer.phone }}</span>
                    </template>
                    <template #cell-ad="{ row }"><AdSourceChip :source="row.ad_source ?? null" /></template>
                    <template #cell-total="{ row }"
                        ><span class="whitespace-nowrap font-bold tabular-nums">{{ formatMoney(row.total, locale) }}</span></template
                    >
                    <template #cell-status="{ row }"><OrderStatusChip :order="row" /></template>
                    <template #cell-shopify_updated_at="{ row }">
                        <RelativeTime v-if="row.on_shopify" :iso="row.shopify_updated_at" class="whitespace-nowrap" />
                        <span v-else class="text-muted-foreground">—</span>
                    </template>
                    <template #cell-last_synced_at="{ row }">
                        <template v-if="row.on_shopify">
                            <RelativeTime
                                v-if="row.last_synced_at"
                                :iso="row.last_synced_at"
                                class="whitespace-nowrap"
                                :class="isSyncStale(row, now) ? 'font-semibold text-amber-700 dark:text-amber-300' : 'text-muted-foreground'"
                            />
                            <span v-else class="whitespace-nowrap font-semibold text-amber-700 dark:text-amber-300">{{
                                t('orders.sync.never')
                            }}</span>
                        </template>
                        <span v-else class="whitespace-nowrap text-muted-foreground">{{ notOnShopifyText(row, t) }}</span>
                    </template>
                    <template #cell-created_at="{ row }"
                        ><span class="whitespace-nowrap tabular-nums text-muted-foreground">{{
                            formatDateTime(row.created_at, locale)
                        }}</span></template
                    >
                </DataTable>
                <Pagination :page="orders" />
            </div>
        </div>
    </AppLayout>
</template>
