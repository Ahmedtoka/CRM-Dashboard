<script setup lang="ts">
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
import OrderSourceChip from '@/components/crm/orders/OrderSourceChip.vue';
import OrderStatusChip from '@/components/crm/orders/OrderStatusChip.vue';
import { useI18n } from '@/composables/useI18n';
import { formatDateTime, formatMoney } from '@/lib/format';
import { notOnShopifyText, orderLabel } from '@/lib/orderStatus';
import type { OrderRow } from '@/types/admin';
import { router } from '@inertiajs/vue3';
import { AlertTriangle } from 'lucide-vue-next';
import { computed } from 'vue';

/** One order row for /orders and /orders/ads/{ad} (fresh-orders F5). */
const props = withDefaults(
    defineProps<{ rows: OrderRow[]; tableId: string; loading?: boolean; canOpenAds?: boolean; sort?: string | null; showSource?: boolean }>(),
    { loading: false, canOpenAds: false, sort: null, showSource: true },
);
const emit = defineEmits<{ 'open-ad': [id: number]; 'update:sort': [sort: string] }>();
const { t, locale } = useI18n();

const MAX_THUMBS = 3;

const columns = computed<Column[]>(() => [
    { key: 'order', label: t('orders.list.order'), primary: true },
    { key: 'customer', label: t('ordersHub.columns.customer') },
    { key: 'governorate', label: t('ordersHub.columns.governorate') },
    { key: 'district', label: t('ordersHub.columns.district'), hideOnMobile: true },
    { key: 'total', label: t('ordersHub.columns.total'), numeric: true, sortable: true },
    { key: 'status', label: t('ordersHub.columns.status') },
    { key: 'products', label: t('ordersHub.columns.products'), hideOnMobile: true },
    ...(props.showSource ? [{ key: 'source', label: t('ordersHub.columns.source') }] : []),
    // The order date the filters use: placed in the store, else made in the CRM (server sort key `date`).
    { key: 'date', label: t('ordersHub.columns.date'), hideOnMobile: true, sortable: true },
]);
</script>

<template>
    <DataTable
        :table-id="tableId"
        :columns="columns"
        :rows="rows"
        clickable
        :loading="loading"
        :empty="t('orders.empty')"
        :caption="t('orders.title')"
        :sort="sort"
        @update:sort="emit('update:sort', $event)"
        @row-click="router.visit(`/orders/${$event.id}`)"
    >
        <template v-if="$slots.empty" #empty><slot name="empty" /></template>
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
                <span
                    v-if="row.mismatch"
                    class="inline-flex size-4 shrink-0 items-center justify-center rounded-full bg-warning/15"
                    :title="t('order.mismatch.title')"
                >
                    <AlertTriangle class="size-3" :aria-label="t('order.mismatch.title')" />
                </span>
            </span>
        </template>
        <template #cell-customer="{ row }">
            <span class="block max-w-[12rem] truncate" dir="auto">{{ row.customer?.name ?? row.shipping?.name ?? '—' }}</span>
            <span v-if="row.customer?.phone || row.shipping?.phone" class="block text-2xs text-muted-foreground" dir="ltr">{{
                row.customer?.phone ?? row.shipping?.phone
            }}</span>
        </template>
        <template #cell-governorate="{ row }"
            ><span dir="auto">{{ row.governorate ?? '—' }}</span></template
        >
        <template #cell-district="{ row }"
            ><span class="block max-w-[10rem] truncate" dir="auto">{{ row.district ?? '—' }}</span></template
        >
        <template #cell-total="{ row }"
            ><span class="whitespace-nowrap font-bold tabular-nums">{{ formatMoney(row.total, locale) }}</span></template
        >
        <template #cell-status="{ row }"><OrderStatusChip :order="row" /></template>
        <template #cell-products="{ row }">
            <span class="flex items-center gap-1" data-products>
                <template v-for="item in (row.items ?? []).slice(0, MAX_THUMBS)" :key="item.id">
                    <img
                        v-if="item.image_url"
                        :src="item.image_url"
                        :alt="item.title"
                        :title="`${item.title} × ${item.qty}`"
                        class="size-8 shrink-0 rounded object-cover"
                        loading="lazy"
                    />
                    <span
                        v-else
                        class="inline-flex h-8 max-w-[6rem] items-center truncate rounded bg-muted px-1.5 text-2xs"
                        :title="item.title"
                        dir="auto"
                        >{{ item.title }}</span
                    >
                </template>
                <span v-if="(row.items?.length ?? 0) > MAX_THUMBS" class="text-2xs text-muted-foreground">{{
                    t('ordersHub.more_products', { n: (row.items?.length ?? 0) - MAX_THUMBS })
                }}</span>
            </span>
        </template>
        <template #cell-date="{ row }">
            <span class="whitespace-nowrap tabular-nums text-muted-foreground">{{ formatDateTime(row.placed_at ?? row.created_at, locale) }}</span>
        </template>
        <template #cell-source="{ row }">
            <OrderSourceChip :source="row.ad_source" :can-open-ads="canOpenAds" @open="emit('open-ad', $event)" />
        </template>
    </DataTable>
</template>
