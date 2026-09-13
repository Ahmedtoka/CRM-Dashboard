<script setup lang="ts">
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import Pagination from '@/components/crm/Pagination.vue';
import PlatformBadge from '@/components/crm/PlatformBadge.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDateTime, formatMoney } from '@/lib/format';
import { orderStatusTone, paymentState, shipmentTone } from '@/lib/orderStatus';
import type { SharedData } from '@/types';
import type { OrderRow, Paginated } from '@/types/admin';
import { Head, router, usePage } from '@inertiajs/vue3';
import { Search } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

interface Filters {
    status: string | null;
    type: string | null;
    platform: string | null;
    q: string | null;
    created_by: string | number | null;
    /** Cairo calendar dates (Y-m-d); the server converts them to UTC bounds. */
    from: string | null;
    to: string | null;
}

const props = withDefaults(defineProps<{ orders: Paginated<OrderRow>; filters: Filters; team?: { id: number; name: string }[] }>(), {
    team: () => [],
});

const { t, locale } = useI18n();
const page = usePage<SharedData>();
const loading = ref(false);

function apply(patch: Partial<Filters>): void {
    const next = { ...props.filters, ...patch };
    const query = Object.fromEntries(Object.entries(next).filter(([, v]) => v !== null && v !== ''));
    router.get('/orders', query, { preserveState: true, preserveScroll: true, replace: true, onStart: () => (loading.value = true), onFinish: () => (loading.value = false) });
}

const search = ref(props.filters.q ?? '');
let timer: number | undefined;
watch(search, (value) => {
    window.clearTimeout(timer);
    timer = window.setTimeout(() => apply({ q: value.trim() || null }), 350);
});
onBeforeUnmount(() => window.clearTimeout(timer));

const selectValue = (event: Event) => (event.target as HTMLSelectElement).value || null;
const inputValue = (event: Event) => (event.target as HTMLInputElement).value || null;

const columns = computed<Column[]>(() => [
    { key: 'order_number', label: t('orders.columns.number') },
    { key: 'customer', label: t('orders.columns.customer') },
    { key: 'platform', label: t('orders.columns.platform') },
    { key: 'created_by', label: t('orders.columns.created_by') },
    { key: 'type', label: t('orders.columns.type') },
    { key: 'total', label: t('orders.columns.total'), align: 'end' },
    { key: 'payment', label: t('orders.columns.payment') },
    { key: 'shipment', label: t('orders.columns.shipment') },
    { key: 'created_at', label: t('orders.columns.date') },
]);

const breadcrumbs = computed(() => [{ title: t('orders.title'), href: '/orders' }]);
const selectClass = 'h-8 rounded-md border border-input bg-background px-2 text-xs';
</script>

<template>
    <Head :title="t('orders.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-4 p-4">
            <PageHeader :title="t('orders.title')" />

            <div class="flex flex-wrap items-center gap-2">
                <div class="relative min-w-[14rem] flex-1 sm:max-w-xs">
                    <Search class="pointer-events-none absolute start-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
                    <input v-model="search" type="search" :placeholder="t('orders.search')" :aria-label="t('orders.search')" class="h-8 w-full rounded-md border border-input bg-background pe-2 ps-8 text-sm" />
                </div>
                <select :value="filters.status ?? ''" :class="selectClass" :aria-label="t('orders.status_all')" @change="apply({ status: selectValue($event) })">
                    <option value="">{{ t('orders.status_all') }}</option>
                    <option v-for="s in ['awaiting_payment', 'confirmed', 'cancelled', 'failed']" :key="s" :value="s">{{ t(`orders.statuses.${s}`) }}</option>
                </select>
                <select :value="filters.type ?? ''" :class="selectClass" :aria-label="t('orders.type_all')" @change="apply({ type: selectValue($event) })">
                    <option value="">{{ t('orders.type_all') }}</option>
                    <option value="cod">{{ t('order.cod') }}</option>
                    <option value="payment_link">{{ t('order.payment_link') }}</option>
                </select>
                <select :value="filters.platform ?? ''" :class="selectClass" :aria-label="t('ui.all_platforms')" @change="apply({ platform: selectValue($event) })">
                    <option value="">{{ t('ui.all_platforms') }}</option>
                    <option v-for="p in page.props.platforms" :key="p.value" :value="p.value">{{ p.label }}</option>
                </select>
                <select :value="filters.created_by ?? ''" :class="selectClass" :aria-label="t('orders.created_by_all')" @change="apply({ created_by: selectValue($event) })">
                    <option value="">{{ t('orders.created_by_all') }}</option>
                    <option v-for="member in team" :key="member.id" :value="String(member.id)">{{ member.name }}</option>
                </select>
                <label class="flex items-center gap-1 text-xs text-muted-foreground">
                    {{ t('orders.date_from') }}
                    <input type="date" :value="filters.from ?? ''" :max="filters.to ?? undefined" :class="selectClass" @change="apply({ from: inputValue($event) })" />
                </label>
                <label class="flex items-center gap-1 text-xs text-muted-foreground">
                    {{ t('orders.date_to') }}
                    <input type="date" :value="filters.to ?? ''" :min="filters.from ?? undefined" :class="selectClass" @change="apply({ to: inputValue($event) })" />
                </label>
            </div>

            <div>
                <DataTable :columns="columns" :rows="orders.data" clickable :loading="loading" :empty="t('orders.empty')" :caption="t('orders.title')" @row-click="router.visit(`/orders/${$event.id}`)">
                    <template #cell-order_number="{ row }">
                        <span class="font-medium" dir="ltr">{{ row.order_number || `#${row.id}` }}</span>
                        <StatusChip class="ms-1.5" :label="t(`orders.statuses.${row.status}`)" :tone="orderStatusTone[row.status]" />
                    </template>
                    <template #cell-customer="{ row }">
                        <span class="block max-w-[12rem] truncate">{{ row.customer?.name ?? '—' }}</span>
                        <span v-if="row.customer?.phone" class="block text-2xs text-muted-foreground" dir="ltr">{{ row.customer.phone }}</span>
                    </template>
                    <template #cell-platform="{ row }"><PlatformBadge :platform="row.platform" /></template>
                    <template #cell-created_by="{ row }">{{ row.created_by?.name ?? t('orders.bot') }}</template>
                    <template #cell-type="{ row }"><span class="whitespace-nowrap">{{ t(`order.${row.type}`) }}</span></template>
                    <template #cell-total="{ row }"><span class="whitespace-nowrap font-medium tabular-nums">{{ formatMoney(row.total, locale) }}</span></template>
                    <template #cell-payment="{ row }">
                        <StatusChip :label="t(`orders.payment.${paymentState(row).key}`)" :tone="paymentState(row).tone" />
                    </template>
                    <template #cell-shipment="{ row }">
                        <StatusChip
                            :label="row.shipment?.status ? t(`shipment.status.${row.shipment.status}`) : t('orders.no_shipment')"
                            :tone="shipmentTone(row.shipment?.status)"
                        />
                    </template>
                    <template #cell-created_at="{ row }"><span class="whitespace-nowrap tabular-nums text-muted-foreground">{{ formatDateTime(row.created_at, locale) }}</span></template>
                </DataTable>
                <Pagination :page="orders" />
            </div>
        </div>
    </AppLayout>
</template>
