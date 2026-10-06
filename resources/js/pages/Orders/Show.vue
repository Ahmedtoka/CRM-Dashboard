<script setup lang="ts">
import OrderNote from '@/components/crm/orders/OrderNote.vue';
import OrderSyncLine from '@/components/crm/orders/OrderSyncLine.vue';
import OrderSummary from '@/components/crm/OrderSummary.vue';
import OrderTimeline from '@/components/crm/OrderTimeline.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import PlatformBadge from '@/components/crm/PlatformBadge.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDateTime } from '@/lib/format';
import { orderFamilies, orderLabel, orderName, stripBidiControls, orderStatusTone, shipmentTone, statusLabel as shopifyLabel } from '@/lib/orderStatus';
import type { SharedData } from '@/types';
import type { OrderRow } from '@/types/admin';
import type { Order } from '@/types/crm';
import { Button } from '@/components/ui/button';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ExternalLink,
    MessageCircle,
    MessagesSquare,
    Package,
    RotateCcw,
    StickyNote,
    Store,
    TriangleAlert,
    Truck,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps<{ order: OrderRow; canManage: boolean }>();

const { t, locale } = useI18n();
const api = useApi();
const toast = useToast();
const busy = ref<string | null>(null);
const confirmingCancel = ref(false);
const restock = ref(true);

// Local copy so «تحديث من شوبيفاي» shows the fresh order at once; a reload replaces it again.
const order = ref<OrderRow>(props.order);
watch(
    () => props.order,
    (value) => (order.value = value),
);
function onRefreshed(fresh: Order): void {
    order.value = { ...order.value, ...fresh };
}

// orderLabel isolates «#S2000», so it stays left-to-right inside the Arabic title and breadcrumb.
const number = computed(() => orderLabel(order.value, t).text);
const families = computed(() => orderFamilies(order.value));
const paymentTone = (value: string | null) =>
    value === 'paid' ? 'positive' : value === 'pending' || value === 'partially_paid' || value === 'authorized' ? 'warning' : 'neutral';
// The CRM's own status gets a chip only when it says something the three families do not.
const crmStatusChip = computed(() => ['submitting', 'failed', 'cancelled'].includes(order.value.status));

const page = usePage<SharedData>();

const isFulfilled = computed(() => ['fulfilled', 'partial'].includes(order.value.fulfillment_status ?? ''));
const canCancel = computed(
    () =>
        props.canManage &&
        !isFulfilled.value &&
        order.value.status !== 'cancelled' &&
        order.value.status !== 'failed' &&
        order.value.display?.shipment_step !== 'delivered',
);
const canMarkPaid = computed(() => props.canManage && order.value.status === 'awaiting_payment');
// Mirrors OrderPolicy::retry — supervisors/admins or the order's creator.
const canRetry = computed(() => order.value.status === 'failed' && (props.canManage || order.value.created_by?.id === page.props.auth.user.id));
const trackingUrl = computed(() => order.value.fulfillments?.find((f) => f.tracking_url)?.tracking_url ?? null);

const statusLabel = (prefix: string, value: string | null | undefined) => shopifyLabel(t, prefix, value);

async function act(action: 'cancel' | 'mark-paid' | 'retry'): Promise<void> {
    busy.value = action;
    try {
        const { data } = await api.post<{ data: OrderRow }>(
            `/orders/${order.value.id}/${action}`,
            action === 'cancel' ? { restock: restock.value } : undefined,
        );
        if (action === 'retry') {
            toast.push(
                t(data.data.status === 'failed' ? 'orders.retry_still_failed' : 'orders.retried_done'),
                data.data.status === 'failed' ? 'error' : 'success',
            );
        } else {
            toast.push(t({ cancel: 'orders.cancelled_done', 'mark-paid': 'orders.paid_done' }[action]));
        }
        confirmingCancel.value = false;
        router.reload({ only: ['order'] });
    } catch (error) {
        toast.push(apiErrorMessage(error, t('common.error')), 'error');
    } finally {
        busy.value = null;
    }
}

async function copyStatus(): Promise<void> {
    const payment = statusLabel('orders.payment_status', order.value.display?.payment);
    const shipment = order.value.display?.shipment_step ? t(`shipment.status.${order.value.display.shipment_step}`) : '';
    const tracking = trackingUrl.value ? ` ${trackingUrl.value}` : '';
    // Customer-facing: the plain name (never «مسودة», no bidi controls), and nothing invisible left in it.
    const text = stripBidiControls(t('order.status_message', { number: orderName(order.value), payment, shipment, tracking }));

    // This page has no reply composer to insert into, so this is clipboard-only — the toast
    // must not claim the text went anywhere but the clipboard (see OrderCard.vue's copyStatus).
    try {
        await navigator.clipboard.writeText(text);
        toast.push(t('order.copy_status_clipboard_done'));
    } catch {
        // Clipboard access can be blocked; there's nothing more useful to do here.
    }
}

const breadcrumbs = computed(() => [
    { title: t('orders.title'), href: '/orders' },
    { title: number.value, href: `/orders/${order.value.id}` },
]);
const crumbs = computed(() => breadcrumbs.value.map((b) => ({ label: b.title, href: b.href })));
const btn = 'inline-flex h-8 items-center gap-1.5 rounded-md border bg-background px-3 text-xs font-medium hover:bg-muted disabled:opacity-50';
</script>

<template>
    <Head :title="orderName(order)" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full min-w-0 max-w-7xl space-y-4 p-3 md:p-6">
            <PageHeader :title="number" :breadcrumbs="crumbs">
                <button type="button" :class="btn" @click="copyStatus">
                    <Package class="size-3.5" aria-hidden="true" />{{ t('order.copy_status') }}
                </button>
                <a v-if="trackingUrl" :href="trackingUrl" target="_blank" rel="noopener noreferrer" :class="btn">
                    <Truck class="size-3.5" aria-hidden="true" />{{ t('order.track') }}
                </a>
                <Button
                    v-if="canRetry"
                    type="button"
                    variant="outline"
                    size="sm"
                    class="text-destructive"
                    :loading="busy === 'retry'"
                    :disabled="!!busy"
                    @click="act('retry')"
                >
                    <RotateCcw aria-hidden="true" />{{ t('orders.retry') }}
                </Button>
                <Button v-if="canMarkPaid" type="button" variant="outline" size="sm" :loading="busy === 'mark-paid'" :disabled="!!busy" @click="act('mark-paid')">
                    {{ t('orders.mark_paid') }}
                </Button>
                <button v-if="canCancel && !confirmingCancel" type="button" :class="[btn, 'text-destructive']" @click="confirmingCancel = true">
                    {{ t('orders.cancel') }}
                </button>
            </PageHeader>

            <div
                v-if="canCancel && confirmingCancel"
                class="flex flex-wrap items-center gap-3 rounded-lg border border-destructive/30 bg-destructive/10 p-2.5 text-xs text-destructive"
            >
                <label class="flex items-center gap-1.5"><input v-model="restock" type="checkbox" />{{ t('order.restock') }}</label>
                <Button type="button" variant="destructive" size="sm" class="h-7 px-2" :loading="busy === 'cancel'" :disabled="!!busy" @click="act('cancel')">
                    {{ t('orders.cancel_confirm') }}
                </Button>
                <button
                    type="button"
                    class="inline-flex h-7 items-center rounded-md border bg-background px-2 font-medium hover:bg-muted"
                    @click="confirmingCancel = false"
                >
                    {{ t('ui.no') }}
                </button>
            </div>

            <div class="flex flex-wrap items-center gap-1.5 text-xs">
                <span class="inline-flex text-muted-foreground" :title="t(`orders.source.${order.source ?? 'chat'}`)"
                    ><component :is="order.source === 'store' ? Store : MessageCircle" class="size-3.5" aria-hidden="true" /><span class="sr-only">{{
                        t(`orders.source.${order.source ?? 'chat'}`)
                    }}</span></span
                >
                <!-- One chip per family (payment, fulfilment, shipment step), never twice. -->
                <StatusChip v-if="crmStatusChip" :label="t(`orders.statuses.${order.status}`)" :tone="orderStatusTone[order.status]" />
                <StatusChip
                    v-if="families.payment"
                    :label="statusLabel('orders.payment_status', families.payment)"
                    :tone="paymentTone(families.payment)"
                />
                <StatusChip v-if="families.fulfillment" :label="statusLabel('orders.fulfillment_status', families.fulfillment)" tone="neutral" />
                <StatusChip v-if="families.step" :label="statusLabel('shipment.status', families.step)" :tone="shipmentTone(families.step)" />
                <PlatformBadge :platform="order.platform" show-label />
                <span class="text-muted-foreground"
                    >{{ t(`order.${order.type}`) }} · <span class="tabular-nums">{{ formatDateTime(order.created_at, locale) }}</span></span
                >
            </div>

            <OrderSyncLine :order="order" @refreshed="onRefreshed" />

            <p
                v-if="order.mismatch"
                class="flex items-center gap-1.5 rounded-lg border border-destructive/30 bg-destructive/10 p-2.5 text-xs text-destructive"
            >
                <TriangleAlert class="size-3.5 shrink-0" aria-hidden="true" />
                {{ order.mismatch_reason ? t(`order.mismatch.reasons.${order.mismatch_reason}`) : t('order.mismatch.title') }}
            </p>

            <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_320px]">
                <div class="min-w-0 space-y-4">
                    <section v-if="order.note" class="rounded-lg bg-card p-3 text-xs shadow-card lg:hidden" :aria-label="t('orders.note_card.title')">
                        <h2 class="mb-1.5 flex items-center gap-1.5 font-medium">
                            <StickyNote class="size-3.5 text-amber-600 dark:text-amber-400" aria-hidden="true" />{{ t('orders.note_card.title') }}
                        </h2>
                        <OrderNote :note="order.note" :lines="6" class="text-sm" />
                    </section>
                    <OrderSummary :order="order" />
                    <section class="rounded-lg bg-card p-3 shadow-card">
                        <h2 class="mb-2 text-xs font-medium">{{ t('orders.timeline.title') }}</h2>
                        <OrderTimeline :entries="order.timeline ?? []" />
                    </section>
                </div>

                <aside class="space-y-4 text-xs">
                    <!-- The note heads the side column; on a phone (one column) it sits above the items instead. -->
                    <section v-if="order.note" class="hidden rounded-lg bg-card p-3 shadow-card lg:block" :aria-label="t('orders.note_card.title')">
                        <h2 class="mb-1.5 flex items-center gap-1.5 font-medium">
                            <StickyNote class="size-3.5 text-amber-600 dark:text-amber-400" aria-hidden="true" />{{ t('orders.note_card.title') }}
                        </h2>
                        <OrderNote :note="order.note" :lines="6" class="text-sm" />
                    </section>

                    <section class="space-y-1.5 rounded-lg bg-card p-3 shadow-card">
                        <h2 class="font-medium">{{ t('orders.columns.customer') }}</h2>
                        <Link
                            v-if="order.customer"
                            :href="`/customers/${order.customer.id}`"
                            class="block font-medium text-primary hover:underline"
                            >{{ order.customer.name }}</Link
                        >
                        <p class="text-muted-foreground">{{ t('order.created_by', { name: order.created_by?.name ?? t('orders.bot') }) }}</p>
                        <Link
                            v-if="order.conversation_id"
                            :href="`/inbox?c=${order.conversation_id}`"
                            class="inline-flex items-center gap-1 text-primary hover:underline"
                        >
                            <MessagesSquare class="size-3.5" aria-hidden="true" />{{ t('ui.open_conversation') }}
                        </Link>
                    </section>

                    <section v-if="order.shipping" class="space-y-1 rounded-lg bg-card p-3 shadow-card">
                        <h2 class="font-medium">{{ t('orders.shipping_to') }}</h2>
                        <p>{{ order.shipping.name }}</p>
                        <p v-if="order.shipping.phone" dir="ltr" class="text-start">{{ order.shipping.phone }}</p>
                        <p class="text-muted-foreground">{{ [order.shipping.city, order.shipping.address].filter(Boolean).join(' — ') }}</p>
                    </section>

                    <section class="space-y-1.5 rounded-lg bg-card p-3 shadow-card">
                        <h2 class="font-medium">{{ t('orders.shopify') }}</h2>
                        <p v-if="order.shopify_order_id">
                            <span class="text-muted-foreground">{{ t('orders.shopify_order') }}:</span>
                            <span dir="ltr">{{ order.shopify_order_id }}</span>
                        </p>
                        <p v-if="order.shopify_draft_order_id">
                            <span class="text-muted-foreground">{{ t('orders.shopify_draft') }}:</span>
                            <span dir="ltr">{{ order.shopify_draft_order_id }}</span>
                        </p>
                        <a
                            v-if="order.shopify_admin_url"
                            :href="order.shopify_admin_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center gap-1 text-primary hover:underline"
                        >
                            <ExternalLink class="size-3.5" aria-hidden="true" />{{ t('orders.open_shopify') }}
                        </a>
                        <p v-else-if="order.shopify_order_id" class="text-2xs text-muted-foreground">{{ t('orders.shop_missing') }}</p>
                        <p v-if="order.paid_at">
                            <span class="text-muted-foreground">{{ t('orders.paid_at') }}:</span> {{ formatDateTime(order.paid_at, locale) }}
                        </p>
                        <a
                            v-if="order.invoice_url"
                            :href="order.invoice_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center gap-1 break-all text-primary hover:underline"
                        >
                            <ExternalLink class="size-3.5 shrink-0" aria-hidden="true" />{{ t('orders.invoice') }}
                        </a>
                    </section>
                </aside>
            </div>
        </div>
    </AppLayout>
</template>
