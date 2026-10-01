<script setup lang="ts">
import RelativeTime from '@/components/crm/RelativeTime.vue';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useNow } from '@/composables/useNow';
import { useToast } from '@/composables/useToast';
import { isSyncStale, notOnShopifyText } from '@/lib/orderStatus';
import type { Order } from '@/types/crm';
import { CloudOff, LoaderCircle, RefreshCw } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = withDefaults(defineProps<{ order: Order; compact?: boolean }>(), { compact: false });
const emit = defineEmits<{ refreshed: [order: Order] }>();

const { t } = useI18n();
const api = useApi();
const toast = useToast();
const now = useNow();

const onShopify = computed(() => props.order.on_shopify ?? !!props.order.shopify_order_id);
const stale = computed(() => isSyncStale(props.order, now.value));
const running = ref(false);

async function refresh(): Promise<void> {
    if (running.value) return;
    running.value = true;
    try {
        const { data } = await api.post<{ data: Order }>(`/orders/${props.order.id}/refresh`);
        emit('refreshed', data.data);
    } catch (error) {
        toast.push(apiErrorMessage(error, t('orders.sync.refresh_failed')), 'error');
    } finally {
        running.value = false;
    }
}
</script>

<template>
    <div class="flex min-w-0 flex-wrap items-center gap-x-4 gap-y-1.5 text-muted-foreground" :class="compact ? 'text-2xs' : 'text-xs'">
        <template v-if="onShopify">
            <span class="inline-flex items-center gap-1">
                {{ t('orders.sync.shopify_updated') }}:
                <RelativeTime :iso="order.shopify_updated_at" class="font-medium text-foreground" />
            </span>
            <span class="inline-flex items-center gap-1" :title="stale ? t('orders.sync.stale') : undefined">
                {{ t('orders.sync.last_synced') }}:
                <RelativeTime
                    v-if="order.last_synced_at"
                    :iso="order.last_synced_at"
                    :class="stale ? 'font-semibold text-amber-700 dark:text-amber-300' : 'font-medium text-foreground'"
                />
                <span v-else class="font-semibold text-amber-700 dark:text-amber-300">{{ t('orders.sync.never') }}</span>
            </span>
            <button
                type="button"
                class="inline-flex items-center gap-1 rounded-md border border-input bg-background font-medium text-foreground hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-wait disabled:opacity-60"
                :class="compact ? 'h-6 px-1.5' : 'h-7 px-2'"
                :disabled="running"
                :aria-busy="running"
                :aria-label="t('orders.sync.refresh')"
                :title="running ? t('orders.sync.refreshing') : t('orders.sync.refresh')"
                @click="refresh"
            >
                <LoaderCircle v-if="running" class="size-3.5 animate-spin" aria-hidden="true" />
                <RefreshCw v-else class="size-3.5" aria-hidden="true" />
                {{ compact ? t('orders.sync.refresh_short') : t('orders.sync.refresh') }}
            </button>
        </template>
        <template v-else>
            <span class="inline-flex items-center gap-1 font-medium text-foreground">
                <CloudOff class="size-3.5 text-muted-foreground" aria-hidden="true" />{{ notOnShopifyText(order, t) }}
            </span>
            <!-- Why Shopify refused it: red once the order failed, muted while it may still go through. -->
            <span
                v-if="order.last_error"
                class="min-w-0 basis-full break-words"
                :class="order.status === 'failed' ? 'text-destructive' : ''"
                dir="auto"
                >{{ order.last_error }}</span
            >
        </template>
    </div>
</template>
