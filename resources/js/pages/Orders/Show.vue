<script setup lang="ts">
import OrderSummary from '@/components/crm/OrderSummary.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import PlatformBadge from '@/components/crm/PlatformBadge.vue';
import ShipmentTimeline from '@/components/crm/ShipmentTimeline.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDateTime } from '@/lib/format';
import { orderStatusTone, paymentState } from '@/lib/orderStatus';
import type { SharedData } from '@/types';
import type { OrderRow } from '@/types/admin';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ExternalLink, LoaderCircle, MessagesSquare, RotateCcw } from 'lucide-vue-next';
import { computed, ref } from 'vue';

// `shopifyShop` is not shared by the backend yet; the admin link appears once it is.
const props = defineProps<{ order: OrderRow; canManage: boolean; shopifyShop?: string | null }>();

const { t, locale } = useI18n();
const api = useApi();
const toast = useToast();
const busy = ref<string | null>(null);

const number = computed(() => props.order.order_number || `#${props.order.id}`);
const shopifyUrl = computed(() =>
    props.shopifyShop && props.order.shopify_order_id ? `https://admin.shopify.com/store/${props.shopifyShop}/orders/${props.order.shopify_order_id}` : null,
);

const page = usePage<SharedData>();

const canCancel = computed(() => props.order.status !== 'cancelled' && props.order.status !== 'failed' && props.order.shipment?.status !== 'delivered');
const canMarkPaid = computed(() => props.canManage && props.order.status === 'awaiting_payment');
const canShip = computed(() => props.canManage && props.order.status === 'confirmed' && !props.order.shipment);
// Mirrors OrderPolicy::retry — supervisors/admins or the order's creator.
const canRetry = computed(() => props.order.status === 'failed' && (props.canManage || props.order.created_by?.id === page.props.auth.user.id));

async function act(action: 'cancel' | 'mark-paid' | 'ship' | 'retry'): Promise<void> {
    if (action === 'cancel' && !window.confirm(t('orders.cancel_confirm'))) return;
    busy.value = action;
    try {
        const { data } = await api.post<{ data: OrderRow }>(`/orders/${props.order.id}/${action}`);
        if (action === 'retry') {
            toast.push(t(data.data.status === 'failed' ? 'orders.retry_still_failed' : 'orders.retried_done'), data.data.status === 'failed' ? 'error' : 'success');
        } else {
            toast.push(t({ cancel: 'orders.cancelled_done', 'mark-paid': 'orders.paid_done', ship: 'orders.shipped_done' }[action]));
        }
        router.reload({ only: ['order'] });
    } catch (error) {
        toast.push(apiErrorMessage(error, t('common.error')), 'error');
    } finally {
        busy.value = null;
    }
}

const breadcrumbs = computed(() => [
    { title: t('orders.title'), href: '/orders' },
    { title: number.value, href: `/orders/${props.order.id}` },
]);
const btn = 'inline-flex h-8 items-center gap-1.5 rounded-md border bg-background px-3 text-xs font-medium hover:bg-muted disabled:opacity-50';
</script>

<template>
    <Head :title="number" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-4 p-4">
            <PageHeader :title="number">
                <button v-if="canRetry" type="button" :class="[btn, 'text-red-700']" :disabled="!!busy" @click="act('retry')">
                    <LoaderCircle v-if="busy === 'retry'" class="size-3.5 animate-spin" aria-hidden="true" />
                    <RotateCcw v-else class="size-3.5" aria-hidden="true" />{{ t('orders.retry') }}
                </button>
                <button v-if="canMarkPaid" type="button" :class="btn" :disabled="!!busy" @click="act('mark-paid')">
                    <LoaderCircle v-if="busy === 'mark-paid'" class="size-3.5 animate-spin" aria-hidden="true" />{{ t('orders.mark_paid') }}
                </button>
                <button v-if="canShip" type="button" :class="btn" :disabled="!!busy" @click="act('ship')">
                    <LoaderCircle v-if="busy === 'ship'" class="size-3.5 animate-spin" aria-hidden="true" />{{ t('orders.ship') }}
                </button>
                <button v-if="canCancel" type="button" :class="[btn, 'text-red-700']" :disabled="!!busy" @click="act('cancel')">
                    <LoaderCircle v-if="busy === 'cancel'" class="size-3.5 animate-spin" aria-hidden="true" />{{ t('orders.cancel') }}
                </button>
            </PageHeader>

            <div class="flex flex-wrap items-center gap-1.5 text-xs">
                <StatusChip :label="t(`orders.statuses.${order.status}`)" :tone="orderStatusTone[order.status]" />
                <StatusChip :label="t(`orders.payment.${paymentState(order).key}`)" :tone="paymentState(order).tone" />
                <PlatformBadge :platform="order.platform" show-label />
                <span class="text-muted-foreground">{{ t(`order.${order.type}`) }} · <span class="tabular-nums">{{ formatDateTime(order.created_at, locale) }}</span></span>
            </div>

            <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_320px]">
                <div class="space-y-4">
                    <OrderSummary :order="order" />
                    <section v-if="order.shipment" class="rounded-lg border bg-card p-3">
                        <h2 class="mb-2 text-xs font-medium">{{ t('shipment.title') }}</h2>
                        <ShipmentTimeline :shipment="order.shipment" :limit="20" />
                    </section>
                </div>

                <aside class="space-y-4 text-xs">
                    <section class="space-y-1.5 rounded-lg border bg-card p-3">
                        <h2 class="font-medium">{{ t('orders.columns.customer') }}</h2>
                        <Link v-if="order.customer" :href="`/customers/${order.customer.id}`" class="block font-medium text-primary hover:underline">{{ order.customer.name }}</Link>
                        <p class="text-muted-foreground">{{ t('order.created_by', { name: order.created_by?.name ?? t('orders.bot') }) }}</p>
                        <Link v-if="order.conversation_id" :href="`/inbox?c=${order.conversation_id}`" class="inline-flex items-center gap-1 text-primary hover:underline">
                            <MessagesSquare class="size-3.5" aria-hidden="true" />{{ t('ui.open_conversation') }}
                        </Link>
                    </section>

                    <section v-if="order.shipping" class="space-y-1 rounded-lg border bg-card p-3">
                        <h2 class="font-medium">{{ t('orders.shipping_to') }}</h2>
                        <p>{{ order.shipping.name }}</p>
                        <p v-if="order.shipping.phone" dir="ltr" class="text-start">{{ order.shipping.phone }}</p>
                        <p class="text-muted-foreground">{{ [order.shipping.city, order.shipping.address].filter(Boolean).join(' — ') }}</p>
                        <p v-if="order.note" class="border-t pt-1"><span class="text-muted-foreground">{{ t('orders.note') }}:</span> {{ order.note }}</p>
                    </section>

                    <section class="space-y-1.5 rounded-lg border bg-card p-3">
                        <h2 class="font-medium">{{ t('orders.shopify') }}</h2>
                        <p v-if="order.shopify_order_id"><span class="text-muted-foreground">{{ t('orders.shopify_order') }}:</span> <span dir="ltr">{{ order.shopify_order_id }}</span></p>
                        <p v-if="order.shopify_draft_order_id"><span class="text-muted-foreground">{{ t('orders.shopify_draft') }}:</span> <span dir="ltr">{{ order.shopify_draft_order_id }}</span></p>
                        <a v-if="shopifyUrl" :href="shopifyUrl" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 text-primary hover:underline">
                            <ExternalLink class="size-3.5" aria-hidden="true" />{{ t('orders.open_shopify') }}
                        </a>
                        <p v-else-if="order.shopify_order_id" class="text-2xs text-muted-foreground">{{ t('orders.shop_missing') }}</p>
                        <p v-if="order.paid_at"><span class="text-muted-foreground">{{ t('orders.paid_at') }}:</span> {{ formatDateTime(order.paid_at, locale) }}</p>
                        <a v-if="order.invoice_url" :href="order.invoice_url" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 break-all text-primary hover:underline">
                            <ExternalLink class="size-3.5 shrink-0" aria-hidden="true" />{{ t('orders.invoice') }}
                        </a>
                    </section>
                </aside>
            </div>
        </div>
    </AppLayout>
</template>
