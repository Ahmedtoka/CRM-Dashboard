<script setup lang="ts">
import AdSourceChip from '@/components/crm/AdSourceChip.vue';
import OrderNote from '@/components/crm/orders/OrderNote.vue';
import OrderStatusChip from '@/components/crm/orders/OrderStatusChip.vue';
import OrderSyncLine from '@/components/crm/orders/OrderSyncLine.vue';
import ShipmentTimeline from '@/components/crm/ShipmentTimeline.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import { notOnShopifyText, orderLabel, orderStatusText } from '@/lib/orderStatus';
import { formatDateTime, formatMoney } from '@/lib/format';
import type { SharedData } from '@/types';
import type { Order } from '@/types/crm';
import { Button } from '@/components/ui/button';
import { usePage } from '@inertiajs/vue3';
import { ExternalLink, LoaderCircle, MessageCircle, Package, RotateCcw, SquarePen, Store, TriangleAlert, Truck, XCircle } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        order: Order;
        showEdit?: boolean;
        /** Control room S3: in the inbox the status goes into the reply (no clipboard), labelled as an insert. */
        insertMode?: boolean;
    }>(),
    { showEdit: false, insertMode: false },
);
const emit = defineEmits<{ editOrder: [order: Order]; copyStatus: [text: string] }>();

const { t, locale } = useI18n();
const api = useApi();
const toast = useToast();
const page = usePage<SharedData>();

// Local copy so a retry/cancel result shows immediately (the OrderUpdated broadcast also updates the parent).
const current = ref<Order>(props.order);
watch(
    () => props.order,
    (value) => (current.value = value),
);

const retrying = ref(false);
const cancelling = ref(false);
const confirmingCancel = ref(false);
const restock = ref(true);

const itemsCount = computed(() => (current.value.items ?? []).reduce((sum, item) => sum + item.qty, 0));
const label = computed(() => orderLabel(current.value, t));
const number = computed(() => label.value.text);
const thumbnails = computed(() =>
    Array.from(new Set((current.value.items ?? []).map((i) => i.image_url).filter((u): u is string => !!u))).slice(0, 4),
);
const isSubmitting = computed(() => current.value.status === 'submitting');
const isFulfilled = computed(() => ['fulfilled', 'partial'].includes(current.value.fulfillment_status ?? ''));
const trackingUrl = computed(() => current.value.fulfillments?.find((f) => f.tracking_url)?.tracking_url ?? null);

const me = computed(() => page.props.auth.user);
const isSupervisorPlus = computed(() => me.value.role === 'admin' || me.value.role === 'supervisor');
// Mirrors OrderPolicy::retry — supervisors/admins or the order's creator.
const canRetry = computed(() => current.value.status === 'failed' && (isSupervisorPlus.value || current.value.created_by?.id === me.value.id));
// Mirrors OrderPolicy::cancel — supervisor+, and only while nothing shipped yet.
const canCancel = computed(
    () => isSupervisorPlus.value && !isFulfilled.value && !['cancelled', 'failed'].includes(current.value.status) && current.value.shipment?.status !== 'delivered',
);

async function retry(): Promise<void> {
    retrying.value = true;
    try {
        const { data } = await api.post<{ data: Order }>(`/orders/${current.value.id}/retry`);
        current.value = { ...current.value, ...data.data };
        const failed = data.data.status === 'failed';
        toast.push(t(failed ? 'order.retry_failed' : 'order.retried', { number: number.value }), failed ? 'error' : 'success');
    } catch (error) {
        toast.push(apiErrorMessage(error, t('common.error')), 'error');
    } finally {
        retrying.value = false;
    }
}

async function cancel(): Promise<void> {
    cancelling.value = true;
    try {
        const { data } = await api.post<{ data: Order }>(`/orders/${current.value.id}/cancel`, { restock: restock.value });
        current.value = { ...current.value, ...data.data };
        confirmingCancel.value = false;
        toast.push(t('orders.cancelled_done'));
    } catch (error) {
        toast.push(apiErrorMessage(error, t('common.error')), 'error');
    } finally {
        cancelling.value = false;
    }
}

function onRefreshed(fresh: Order): void {
    current.value = { ...current.value, ...fresh };
}

async function copyStatus(): Promise<void> {
    // Customer-facing: the plain name (never «مسودة»), no bidi controls (lib/orderStatus).
    const text = orderStatusText(current.value, t);

    // In the inbox (insertMode) the parent puts `text` straight into the reply and focuses it: no
    // clipboard. Elsewhere (the customer page) the clipboard write is a best-effort convenience.
    if (!props.insertMode) {
        try {
            await navigator.clipboard.writeText(text);
            toast.push(t('order.copy_status_clipboard_done'));
        } catch {
            // Nothing more useful to do; the parent still gets the text via the emit below.
        }
    }
    emit('copyStatus', text);
}
</script>

<template>
    <article class="rounded-lg bg-card p-3 text-xs shadow-card">
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
            <span v-if="label.draft" class="font-medium text-muted-foreground" :title="notOnShopifyText(current, t)" dir="auto">{{ number }}</span>
            <span v-else class="font-semibold" dir="ltr">{{ number }}</span>
            <span class="inline-flex text-muted-foreground" :title="t(`orders.source.${current.source ?? 'chat'}`)"><component :is="current.source === 'store' ? Store : MessageCircle" class="size-3.5" aria-hidden="true" /><span class="sr-only">{{ t(`orders.source.${current.source ?? 'chat'}`) }}</span></span>
            <OrderStatusChip :order="current" />
            <span class="ms-auto font-bold tabular-nums">{{ formatMoney(current.total, locale) }}</span>
        </div>
        <AdSourceChip v-if="current.ad_source" :source="current.ad_source" class="mt-1" />

        <p class="mt-1 text-2xs text-muted-foreground">
            {{ t(`order.${current.type}`) }}
            <template v-if="itemsCount"> · {{ t('order.items_count', { n: itemsCount }) }}</template>
            <template v-if="current.created_by"> · {{ t('order.created_by', { name: current.created_by.name }) }}</template>
            <template v-if="current.created_at"> · <span class="tabular-nums">{{ formatDateTime(current.created_at, locale) }}</span></template>
        </p>

        <div v-if="thumbnails.length" class="mt-1.5 flex gap-1">
            <img v-for="src in thumbnails" :key="src" :src="src" alt="" class="size-8 rounded object-cover" />
        </div>

        <p v-if="isSubmitting" class="mt-1.5 flex items-center gap-1.5">
            <LoaderCircle class="size-3.5 animate-spin text-muted-foreground" aria-hidden="true" />
            <StatusChip :label="t('order.sending')" tone="warning" />
        </p>

        <div v-if="current.note" class="mt-1.5 rounded-md bg-elevated px-2 py-1.5">
            <OrderNote :note="current.note" :lines="2" />
        </div>

        <p v-if="current.mismatch" class="mt-1.5 flex items-start gap-1.5 rounded-md bg-destructive/10 px-2 py-1 text-foreground">
            <TriangleAlert class="mt-px size-3.5 shrink-0 text-destructive" aria-hidden="true" />
            <span>{{ current.mismatch_reason ? t(`order.mismatch.reasons.${current.mismatch_reason}`) : t('order.mismatch.title') }}</span>
        </p>

        <OrderSyncLine v-if="!isSubmitting" :order="current" compact class="mt-1.5" @refreshed="onRefreshed" />

        <a
            v-if="current.invoice_url && current.status === 'awaiting_payment'"
            :href="current.invoice_url"
            target="_blank"
            rel="noopener noreferrer"
            class="mt-1.5 inline-flex items-center gap-1 text-primary hover:underline"
        >
            <ExternalLink class="size-3" aria-hidden="true" />{{ t('order.invoice') }}
        </a>

        <ShipmentTimeline v-if="current.shipment" :shipment="current.shipment" class="mt-2 border-t border-border pt-2" />

        <div class="mt-2 flex flex-wrap items-center gap-1.5 border-t border-border pt-2">
            <a
                v-if="current.shopify_admin_url"
                :href="current.shopify_admin_url"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex h-7 items-center gap-1 rounded-md px-2 text-2xs font-medium text-muted-foreground hover:bg-muted hover:text-foreground"
            >
                <ExternalLink class="size-3.5" aria-hidden="true" />{{ t('orders.open_shopify') }}
            </a>
            <a
                v-if="trackingUrl"
                :href="trackingUrl"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex h-7 items-center gap-1 rounded-md px-2 text-2xs font-medium text-muted-foreground hover:bg-muted hover:text-foreground"
            >
                <Truck class="size-3.5" aria-hidden="true" />{{ t('order.track') }}
            </a>
            <button type="button" class="inline-flex h-7 items-center gap-1 rounded-md px-2 text-2xs font-medium text-muted-foreground hover:bg-muted hover:text-foreground" @click="copyStatus">
                <Package class="size-3.5" aria-hidden="true" />{{ insertMode ? t('order.insert_status') : t('order.copy_status') }}
            </button>
            <Button
                v-if="canRetry"
                type="button"
                variant="ghost"
                size="sm"
                class="h-7 gap-1 px-2 text-2xs font-medium text-destructive [&_svg]:size-3.5"
                :loading="retrying"
                @click="retry"
            >
                <RotateCcw aria-hidden="true" />
                {{ t('order.retry') }}
            </Button>
            <button
                v-if="current.status === 'failed' && showEdit"
                type="button"
                class="inline-flex h-7 items-center gap-1 rounded-md px-2 text-2xs font-medium text-muted-foreground hover:bg-muted hover:text-foreground"
                @click="emit('editOrder', current)"
            >
                <SquarePen class="size-3.5" aria-hidden="true" />{{ t('order.edit_order') }}
            </button>
            <button
                v-if="canCancel && !confirmingCancel"
                type="button"
                class="inline-flex h-7 items-center gap-1 rounded-md px-2 text-2xs font-medium text-destructive hover:bg-muted"
                @click="confirmingCancel = true"
            >
                <XCircle class="size-3.5" aria-hidden="true" />{{ t('orders.cancel') }}
            </button>
        </div>

        <div v-if="confirmingCancel" class="mt-2 space-y-1.5 rounded-md border border-destructive/30 bg-destructive/10 p-2">
            <label class="flex items-center gap-1.5 text-2xs text-foreground">
                <input v-model="restock" type="checkbox" />
                {{ t('order.restock') }}
            </label>
            <div class="flex gap-1.5">
                <Button type="button" variant="destructive" size="sm" class="h-7 gap-1 px-2 text-2xs font-medium" :loading="cancelling" @click="cancel">
                    {{ t('orders.cancel_confirm') }}
                </Button>
                <button type="button" class="inline-flex h-7 items-center rounded-md border border-border bg-card px-2 text-2xs font-medium hover:bg-muted" @click="confirmingCancel = false">
                    {{ t('ui.no') }}
                </button>
            </div>
        </div>
    </article>
</template>
