<script setup lang="ts">
import ShipmentTimeline from '@/components/crm/ShipmentTimeline.vue';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import { formatDateTime, formatMoney } from '@/lib/format';
import type { SharedData } from '@/types';
import type { Order } from '@/types/crm';
import { usePage } from '@inertiajs/vue3';
import { ExternalLink, LoaderCircle, RotateCcw } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps<{ order: Order }>();

const { t, locale } = useI18n();
const api = useApi();
const toast = useToast();
const page = usePage<SharedData>();

// Local copy so a retry result shows immediately (the OrderUpdated broadcast also updates the parent).
const current = ref<Order>(props.order);
watch(
    () => props.order,
    (value) => (current.value = value),
);

const retrying = ref(false);

const tones: Record<Order['status'], string> = {
    awaiting_payment: 'bg-amber-50 text-amber-800',
    confirmed: 'bg-emerald-50 text-emerald-700',
    cancelled: 'bg-slate-100 text-slate-600',
    failed: 'bg-red-50 text-red-700',
};

const itemsCount = computed(() => (current.value.items ?? []).reduce((sum, item) => sum + item.qty, 0));
const number = computed(() => current.value.order_number || `#${current.value.id}`);

// Mirrors OrderPolicy::retry — supervisors/admins or the order's creator.
const canRetry = computed(() => {
    const me = page.props.auth.user;
    return current.value.status === 'failed' && (me.role === 'admin' || me.role === 'supervisor' || current.value.created_by?.id === me.id);
});

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
</script>

<template>
    <article class="rounded-lg border bg-background p-3 text-xs">
        <div class="flex items-center gap-2">
            <span class="font-semibold" dir="ltr">{{ number }}</span>
            <span class="rounded px-1.5 py-0.5 text-2xs font-medium" :class="tones[current.status] ?? tones.cancelled">{{ t(`order.status.${current.status}`) }}</span>
            <span class="ms-auto font-semibold tabular-nums">{{ formatMoney(current.total, locale) }}</span>
        </div>
        <p class="mt-1 text-2xs text-muted-foreground">
            {{ t(`order.${current.type}`) }}
            <template v-if="itemsCount"> · {{ t('order.items_count', { n: itemsCount }) }}</template>
            <template v-if="current.created_by"> · {{ t('order.created_by', { name: current.created_by.name }) }}</template>
            <template v-if="current.created_at"> · <span class="tabular-nums">{{ formatDateTime(current.created_at, locale) }}</span></template>
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
        <button
            v-if="canRetry"
            type="button"
            class="mt-1.5 inline-flex h-7 items-center gap-1 rounded-md border bg-background px-2 text-xs font-medium text-red-700 hover:bg-muted disabled:opacity-50"
            :disabled="retrying"
            @click="retry"
        >
            <LoaderCircle v-if="retrying" class="size-3.5 animate-spin" aria-hidden="true" />
            <RotateCcw v-else class="size-3.5" aria-hidden="true" />
            {{ t('order.retry') }}
        </button>
        <ShipmentTimeline v-if="current.shipment" :shipment="current.shipment" class="mt-2 border-t pt-2" />
    </article>
</template>
