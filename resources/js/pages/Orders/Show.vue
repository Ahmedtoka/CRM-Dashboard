<script setup lang="ts">
import AdDrawer from '@/components/ads/AdDrawer.vue';
import OrderConfirmationCard from '@/components/crm/confirmations/OrderConfirmationCard.vue';
import OrderReturnCases from '@/components/crm/orders/OrderReturnCases.vue';
import ShippingTimeline from '@/components/crm/orders/ShippingTimeline.vue';
import DetailPanel from '@/components/crm/orders/DetailPanel.vue';
import OrderNote from '@/components/crm/orders/OrderNote.vue';
import OrderSourceChip from '@/components/crm/orders/OrderSourceChip.vue';
import OrderSyncLine from '@/components/crm/orders/OrderSyncLine.vue';
import OrderSummary from '@/components/crm/OrderSummary.vue';
import OrderTimeline from '@/components/crm/OrderTimeline.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import PlatformBadge from '@/components/crm/PlatformBadge.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { Button } from '@/components/ui/button';
import { useAdDrawer } from '@/composables/useAdDrawer';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import AppLayout from '@/layouts/AppLayout.vue';
import { cairoDayKey, cairoToday, formatDateTime } from '@/lib/format';
import { drawerRange } from '@/lib/ordersHub';
import {
    orderFamilies,
    orderLabel,
    orderName,
    orderStatusTone,
    shipmentTone,
    statusLabel as shopifyLabel,
    stripBidiControls,
} from '@/lib/orderStatus';
import type { SharedData } from '@/types';
import type { OrderRow } from '@/types/admin';
import type { Order, SupportCase } from '@/types/crm';
import type { OrderConfirmationBlock } from '@/types/orderConfirmations';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ExternalLink,
    MapPin,
    MessageCircle,
    MessagesSquare,
    Package,
    RotateCcw,
    StickyNote,
    Store,
    TriangleAlert,
    Truck,
    User,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        order: OrderRow;
        canManage: boolean;
        /** WhatsApp order confirmation (spec 2026-10-16); null when none was made. */
        confirmation?: OrderConfirmationBlock | null;
        /** Supervisors and admins: the source chip opens the ad drawer. */
        canOpenAds?: boolean;
        /** Round 2026-10-24: this order's return/exchange cases with their lifecycle. */
        returnCases?: SupportCase[];
    }>(),
    { confirmation: null, canOpenAds: false, returnCases: () => [] },
);

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
/** Carrier links come from Shopify: only http(s) ones become links. */
const safeUrl = (url: string | null | undefined): string | null => (url && /^https?:\/\//i.test(url) ? url : null);
const trackingUrl = computed(() => safeUrl(order.value.fulfillments?.find((f) => safeUrl(f.tracking_url))?.tracking_url));

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
const btn =
    'inline-flex h-9 items-center gap-1.5 rounded-md border border-line-strong bg-card px-3 text-13 font-semibold hover:bg-surface-2 disabled:opacity-50';

const drawer = useAdDrawer();
/** The ad drawer opens on the order's own month (its figures, not this month's), up to today. */
const adRange = computed(() => {
    const day = cairoDayKey(order.value.placed_at ?? order.value.created_at);
    if (!day) return drawerRange({ from: null, to: null });
    const [y, m] = day.split('-').map(Number);
    const last = new Date(Date.UTC(y, m, 0)).toISOString().slice(0, 10);
    const today = cairoToday();
    return drawerRange({ from: `${day.slice(0, 8)}01`, to: last < today ? last : today });
});
/** The line under the title: who, how she pays, when (the chips beside the title carry the state). */
const subline = computed(() =>
    [
        order.value.customer?.name ?? order.value.shipping?.name,
        t(`order.${order.value.type}`),
        formatDateTime(order.value.placed_at ?? order.value.created_at, locale.value),
    ]
        .filter(Boolean)
        .join(' · '),
);

/** Shopify fulfillments (tracking company, number, the carrier's step), newest first (OrderResource sends them oldest first). */
const fulfillments = computed(() => [...(order.value.fulfillments ?? [])].reverse());
const fulfillmentTone = (status: string | null) =>
    status === 'delivered' ? 'ok' : status === 'failure' || status === 'not_delivered' ? 'danger' : status ? 'info' : 'neutral';
</script>

<template>
    <Head :title="orderName(order)" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full min-w-0 max-w-7xl space-y-4 p-3 md:p-6">
            <PageHeader :title="number" :description="subline" :breadcrumbs="crumbs">
                <template #extra>
                    <!-- One chip per family (payment, fulfilment, shipment step), never twice. -->
                    <span class="flex flex-wrap items-center gap-1.5" data-order-chips>
                        <StatusChip v-if="crmStatusChip" :label="t(`orders.statuses.${order.status}`)" :tone="orderStatusTone[order.status]" />
                        <StatusChip
                            v-if="families.payment"
                            :label="statusLabel('orders.payment_status', families.payment)"
                            :tone="paymentTone(families.payment)"
                        />
                        <StatusChip
                            v-if="families.fulfillment"
                            :label="statusLabel('orders.fulfillment_status', families.fulfillment)"
                            tone="neutral"
                        />
                        <StatusChip v-if="families.step" :label="statusLabel('shipment.status', families.step)" :tone="shipmentTone(families.step)" />
                    </span>
                </template>
                <button type="button" :class="btn" @click="copyStatus">
                    <Package class="size-4" aria-hidden="true" />{{ t('order.copy_status') }}
                </button>
                <a v-if="trackingUrl" :href="trackingUrl" target="_blank" rel="noopener noreferrer" :class="btn">
                    <Truck class="size-4" aria-hidden="true" />{{ t('order.track') }}
                </a>
                <Button
                    v-if="canRetry"
                    type="button"
                    variant="outline"
                    class="text-destructive"
                    :loading="busy === 'retry'"
                    :disabled="!!busy"
                    @click="act('retry')"
                >
                    <RotateCcw aria-hidden="true" />{{ t('orders.retry') }}
                </Button>
                <Button
                    v-if="canMarkPaid"
                    type="button"
                    variant="outline"
                    :loading="busy === 'mark-paid'"
                    :disabled="!!busy"
                    @click="act('mark-paid')"
                >
                    {{ t('orders.mark_paid') }}
                </Button>
                <button v-if="canCancel && !confirmingCancel" type="button" :class="[btn, 'text-danger']" @click="confirmingCancel = true">
                    {{ t('orders.cancel') }}
                </button>
            </PageHeader>

            <div
                v-if="canCancel && confirmingCancel"
                class="flex flex-wrap items-center gap-3 rounded-lg border border-danger/30 bg-danger-soft px-4 py-3 text-13 text-danger"
                role="alert"
                data-cancel-confirm
            >
                <span class="font-semibold">{{ t('orders.cancel_question') }}</span>
                <label class="flex items-center gap-1.5 text-foreground"
                    ><input v-model="restock" type="checkbox" class="size-4" />{{ t('order.restock') }}</label
                >
                <span class="flex-1" />
                <Button type="button" variant="destructive" size="sm" :loading="busy === 'cancel'" :disabled="!!busy" @click="act('cancel')">
                    {{ t('orders.cancel_confirm') }}
                </Button>
                <Button type="button" variant="ghost" size="sm" class="text-foreground" @click="confirmingCancel = false">{{ t('ui.no') }}</Button>
            </div>

            <!-- Where it came from: chat or store, the platform, and the ad it is credited to (or «مباشر»). -->
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-13 text-fg-2" data-order-source-line>
                <span class="inline-flex items-center gap-1.5">
                    <component :is="order.source === 'store' ? Store : MessageCircle" class="size-4 text-fg-3" aria-hidden="true" />{{
                        t(`orders.source.${order.source ?? 'chat'}`)
                    }}
                </span>
                <PlatformBadge v-if="order.platform" :platform="order.platform" show-label />
                <span v-if="order.ad_source !== undefined" class="inline-flex min-w-0 items-center gap-1.5" data-order-ad>
                    <span class="text-fg-3">{{ t('ordersHub.columns.source') }}:</span>
                    <OrderSourceChip :source="order.ad_source" :can-open-ads="canOpenAds" @open="drawer.open" />
                </span>
                <span>{{ t('order.created_by', { name: order.created_by?.name ?? t('orders.bot') }) }}</span>
            </div>

            <OrderSyncLine :order="order" @refreshed="onRefreshed" />

            <p
                v-if="order.mismatch"
                class="flex items-center gap-2 rounded-lg border border-danger/30 bg-danger-soft px-4 py-3 text-13 text-danger"
                role="status"
            >
                <TriangleAlert class="size-4 shrink-0" aria-hidden="true" />
                {{ order.mismatch_reason ? t(`order.mismatch.reasons.${order.mismatch_reason}`) : t('order.mismatch.title') }}
            </p>

            <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_340px]">
                <div class="min-w-0 space-y-4">
                    <!-- On a phone (one column) the note sits above the items; on desktop it heads the side column. -->
                    <DetailPanel v-if="order.note" :title="t('orders.note_card.title')" :icon="StickyNote" tone="note" class="lg:hidden">
                        <OrderNote :note="order.note" :lines="6" class="text-sm" />
                    </DetailPanel>
                    <OrderSummary :order="order" />

                    <DetailPanel v-if="order.delivery?.timeline?.length" :title="t('shipping_timeline.title')" :icon="Truck" data-order-shipping>
                        <ShippingTimeline :shipping="order.delivery" />
                    </DetailPanel>

                    <OrderReturnCases :cases="returnCases" @updated="router.reload({ only: ['order', 'returnCases'] })" />

                    <DetailPanel v-if="fulfillments.length" :title="t('order.fulfillments.title')" :icon="Truck" flush data-order-fulfillments>
                        <ul class="divide-y divide-line">
                            <li v-for="f in fulfillments" :key="f.id" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3 text-13">
                                <StatusChip
                                    :label="
                                        f.shipment_status
                                            ? statusLabel('order.fulfillments.shipment', f.shipment_status)
                                            : t('order.fulfillments.no_update')
                                    "
                                    :tone="fulfillmentTone(f.shipment_status)"
                                />
                                <span class="flex min-w-0 flex-1 flex-wrap items-baseline gap-x-2">
                                    <span v-if="f.tracking_company" class="font-semibold" dir="auto">{{ f.tracking_company }}</span>
                                    <bdi v-if="f.tracking_number" class="tabular-nums text-fg-2" dir="ltr">{{ f.tracking_number }}</bdi>
                                </span>
                                <span class="whitespace-nowrap text-xs tabular-nums text-fg-3">{{
                                    formatDateTime(f.updated_at ?? f.created_at, locale)
                                }}</span>
                                <a
                                    v-if="safeUrl(f.tracking_url)"
                                    :href="safeUrl(f.tracking_url) ?? undefined"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex items-center gap-1 font-semibold text-brand-ink hover:underline"
                                >
                                    <ExternalLink class="size-3.5" aria-hidden="true" />{{ t('order.track') }}
                                </a>
                            </li>
                        </ul>
                    </DetailPanel>

                    <DetailPanel :title="t('orders.timeline.title')">
                        <OrderTimeline :entries="order.timeline ?? []" />
                    </DetailPanel>
                </div>

                <aside class="min-w-0 space-y-4 text-13">
                    <DetailPanel v-if="order.note" :title="t('orders.note_card.title')" :icon="StickyNote" tone="note" class="hidden lg:block">
                        <OrderNote :note="order.note" :lines="6" class="text-sm" />
                    </DetailPanel>

                    <OrderConfirmationCard v-if="confirmation" :confirmation="confirmation" />

                    <DetailPanel :title="t('orders.columns.customer')" :icon="User">
                        <div class="space-y-1.5">
                            <Link
                                v-if="order.customer"
                                :href="`/customers/${order.customer.id}`"
                                class="block font-semibold text-brand-ink hover:underline"
                                dir="auto"
                                >{{ order.customer.name }}</Link
                            >
                            <p v-if="order.customer?.phone" class="text-start tabular-nums text-fg-2" dir="ltr">{{ order.customer.phone }}</p>
                            <Link
                                v-if="order.conversation_id"
                                :href="`/inbox?c=${order.conversation_id}`"
                                class="inline-flex items-center gap-1.5 font-semibold text-brand-ink hover:underline"
                            >
                                <MessagesSquare class="size-4" aria-hidden="true" />{{ t('ui.open_conversation') }}
                            </Link>
                        </div>
                    </DetailPanel>

                    <DetailPanel v-if="order.shipping" :title="t('orders.shipping_to')" :icon="MapPin">
                        <div class="space-y-1">
                            <p class="font-semibold" dir="auto">{{ order.shipping.name }}</p>
                            <p v-if="order.shipping.phone" dir="ltr" class="text-start tabular-nums text-fg-2">{{ order.shipping.phone }}</p>
                            <p class="text-fg-2" dir="auto">{{ [order.shipping.city, order.shipping.address].filter(Boolean).join(' — ') }}</p>
                        </div>
                    </DetailPanel>

                    <DetailPanel :title="t('orders.shopify')" :icon="Store">
                        <dl class="space-y-1.5">
                            <div v-if="order.shopify_order_id" class="flex justify-between gap-3">
                                <dt class="text-fg-2">{{ t('orders.shopify_order') }}</dt>
                                <dd class="tabular-nums" dir="ltr">{{ order.shopify_order_id }}</dd>
                            </div>
                            <div v-if="order.shopify_draft_order_id" class="flex justify-between gap-3">
                                <dt class="text-fg-2">{{ t('orders.shopify_draft') }}</dt>
                                <dd class="tabular-nums" dir="ltr">{{ order.shopify_draft_order_id }}</dd>
                            </div>
                            <div v-if="order.paid_at" class="flex justify-between gap-3">
                                <dt class="text-fg-2">{{ t('orders.paid_at') }}</dt>
                                <dd class="tabular-nums">{{ formatDateTime(order.paid_at, locale) }}</dd>
                            </div>
                        </dl>
                        <div class="mt-2.5 flex flex-wrap gap-x-4 gap-y-1.5">
                            <a
                                v-if="order.shopify_admin_url"
                                :href="order.shopify_admin_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-1.5 font-semibold text-brand-ink hover:underline"
                            >
                                <ExternalLink class="size-4" aria-hidden="true" />{{ t('orders.open_shopify') }}
                            </a>
                            <p v-else-if="order.shopify_order_id" class="text-xs text-fg-2">{{ t('orders.shop_missing') }}</p>
                            <a
                                v-if="order.invoice_url"
                                :href="order.invoice_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-1.5 break-all font-semibold text-brand-ink hover:underline"
                            >
                                <ExternalLink class="size-4 shrink-0" aria-hidden="true" />{{ t('orders.invoice') }}
                            </a>
                        </div>
                    </DetailPanel>
                </aside>
            </div>
        </div>

        <AdDrawer v-if="canOpenAds" :ad-id="drawer.adId.value" :filters="adRange" @close="drawer.close" />
    </AppLayout>
</template>
