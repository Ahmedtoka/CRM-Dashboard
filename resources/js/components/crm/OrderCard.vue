<script setup lang="ts">
import ShipmentTimeline from '@/components/crm/ShipmentTimeline.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import { orderStatusTone } from '@/lib/orderStatus';
import { formatDateTime, formatMoney } from '@/lib/format';
import type { SharedData } from '@/types';
import type { Order } from '@/types/crm';
import { usePage } from '@inertiajs/vue3';
import { ExternalLink, LoaderCircle, Package, RotateCcw, SquarePen, Truck, XCircle } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = withDefaults(defineProps<{ order: Order; showEdit?: boolean }>(), { showEdit: false });
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
const number = computed(() => current.value.order_number || `#${current.value.id}`);
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

/** Falls back to the raw Shopify value (an open-ended vocabulary) when there's no translation for it. */
function statusLabel(prefix: string, value: string | null | undefined): string {
    if (!value) return '';
    const key = `${prefix}.${value}`;
    const label = t(key);
    return label === key ? value : label;
}

const paymentLabel = (value: string | null | undefined) => statusLabel('orders.payment_status', value);
const fulfillmentLabel = (value: string | null | undefined) => statusLabel('orders.fulfillment_status', value);

async function copyStatus(): Promise<void> {
    const payment = paymentLabel(current.value.display?.payment);
    const shipment = current.value.display?.shipment_step ? t(`shipment.status.${current.value.display.shipment_step}`) : '';
    const tracking = trackingUrl.value ? ` ${trackingUrl.value}` : '';
    const text = t('order.status_message', { number: number.value, payment, shipment, tracking });

    // Clipboard write is a best-effort convenience only (it can be blocked by permissions or
    // an insecure context) and never claims to have reached the reply composer — the parent
    // (Inbox.vue, via CustomerPanel) is the one that actually inserts `text` there and toasts
    // that outcome once it happens. A caller with no composer (e.g. the customer profile page)
    // gets this neutral "copied" toast instead.
    try {
        await navigator.clipboard.writeText(text);
        toast.push(t('order.copy_status_clipboard_done'));
    } catch {
        // Nothing more useful to do; the parent still gets the text via the emit below.
    }
    emit('copyStatus', text);
}
</script>

<template>
    <article class="rounded-lg bg-card p-3 text-xs shadow-card">
        <div class="flex items-center gap-2">
            <span class="font-semibold" dir="ltr">{{ number }}</span>
            <span :title="t(`orders.source.${current.source ?? 'chat'}`)">{{ current.source === 'store' ? '🛍️' : '🗨️' }}</span>
            <StatusChip :label="t(`order.status.${current.status}`)" :tone="orderStatusTone[current.status] ?? 'neutral'" />
            <span class="ms-auto font-bold tabular-nums">{{ formatMoney(current.total, locale) }}</span>
        </div>

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

        <div v-if="current.shopify_order_id || current.shopify_draft_order_id" class="mt-1.5 flex flex-wrap items-center gap-1.5">
            <StatusChip v-if="current.display?.payment" :label="paymentLabel(current.display.payment)" tone="info" />
            <StatusChip v-if="current.display?.fulfillment" :label="fulfillmentLabel(current.display.fulfillment)" tone="neutral" />
        </div>

        <p v-if="current.mismatch" class="mt-1.5 rounded-md bg-destructive/10 px-2 py-1 text-foreground">
            ⚠️ {{ current.mismatch_reason ? t(`order.mismatch.reasons.${current.mismatch_reason}`) : t('order.mismatch.title') }}
        </p>

        <p v-if="current.status === 'failed' && current.last_error" class="mt-1.5 rounded-md bg-destructive/10 px-2 py-1 text-foreground" dir="auto">
            {{ current.last_error }}
        </p>

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
                <Package class="size-3.5" aria-hidden="true" />{{ t('order.copy_status') }}
            </button>
            <button
                v-if="canRetry"
                type="button"
                class="inline-flex h-7 items-center gap-1 rounded-md px-2 text-2xs font-medium text-destructive hover:bg-muted disabled:opacity-50"
                :disabled="retrying"
                @click="retry"
            >
                <LoaderCircle v-if="retrying" class="size-3.5 animate-spin" aria-hidden="true" />
                <RotateCcw v-else class="size-3.5" aria-hidden="true" />
                {{ t('order.retry') }}
            </button>
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
                <button
                    type="button"
                    class="inline-flex h-7 items-center gap-1 rounded-md bg-destructive px-2 text-2xs font-medium text-destructive-foreground hover:bg-destructive/90 disabled:opacity-50"
                    :disabled="cancelling"
                    @click="cancel"
                >
                    <LoaderCircle v-if="cancelling" class="size-3.5 animate-spin" aria-hidden="true" />{{ t('orders.cancel_confirm') }}
                </button>
                <button type="button" class="inline-flex h-7 items-center rounded-md border border-border bg-card px-2 text-2xs font-medium hover:bg-muted" @click="confirmingCancel = false">
                    {{ t('ui.no') }}
                </button>
            </div>
        </div>
    </article>
</template>
