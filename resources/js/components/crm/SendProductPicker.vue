<script setup lang="ts">
import { buttonVariants } from '@/components/ui/button';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Message, ProductVariant } from '@/types/crm';
import { isAxiosError } from 'axios';
import { LoaderCircle, Search, SendHorizontal, X } from 'lucide-vue-next';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

/**
 * «ابعت منتج» (2026-10-17): search the synced catalog, pick a colour/size, and send it to the
 * customer as the bot's product card (POST /inbox/conversations/{id}/product). An out-of-stock
 * variant asks once before it goes.
 */
const props = defineProps<{ conversationId: number }>();
const emit = defineEmits<{ sent: [message: Message]; close: [] }>();

const api = useApi();
const { t, locale } = useI18n();

const query = ref('');
const input = ref<HTMLInputElement | null>(null);
const results = ref<ProductVariant[]>([]);
const loading = ref(false);
const sending = ref<number | null>(null);
const confirming = ref<ProductVariant | null>(null);
const error = ref<string | null>(null);
let timer: number | undefined;
let seq = 0;

async function search(term: string): Promise<void> {
    const current = ++seq;
    loading.value = true;
    try {
        const { data } = await api.get<{ data: ProductVariant[] }>('/products/search', { params: term ? { q: term } : {} });
        if (current === seq) results.value = data.data;
    } catch {
        if (current === seq) results.value = [];
    } finally {
        if (current === seq) loading.value = false;
    }
}

watch(query, (value) => {
    window.clearTimeout(timer);
    timer = window.setTimeout(() => void search(value.trim()), 250);
});

onMounted(() => {
    void search('');
    input.value?.focus();
});
onBeforeUnmount(() => window.clearTimeout(timer));

// Out of stock and Shopify does not keep selling it at zero (`inventory_policy: deny`).
function isOutOfStock(variant: ProductVariant): boolean {
    return variant.stock <= 0 && variant.inventory_policy !== 'continue';
}

function pick(variant: ProductVariant): void {
    error.value = null;
    if (isOutOfStock(variant)) {
        confirming.value = variant;
        return;
    }
    void send(variant, false);
}

async function send(variant: ProductVariant, confirmOutOfStock: boolean): Promise<void> {
    if (sending.value !== null) return;
    sending.value = variant.id;
    error.value = null;
    try {
        const { data } = await api.post<{ data: Message }>(`/inbox/conversations/${props.conversationId}/product`, {
            variant_id: variant.id,
            confirm_out_of_stock: confirmOutOfStock,
        });
        confirming.value = null;
        emit('sent', data.data);
        emit('close');
    } catch (e) {
        // The stock changed since the list loaded: ask, the same as an out-of-stock pick.
        if (isAxiosError(e) && e.response?.status === 409) {
            confirming.value = variant;
        } else {
            error.value = apiErrorMessage(e, t('send_product.failed'));
        }
    } finally {
        sending.value = null;
    }
}
</script>

<template>
    <div
        class="mb-2 rounded-xl border bg-popover p-2 text-popover-foreground shadow-md"
        role="dialog"
        :aria-label="t('send_product.title')"
        data-testid="send-product-picker"
        @keydown.esc.prevent="emit('close')"
    >
        <div class="mb-2 flex items-center gap-2">
            <div class="relative flex-1">
                <Search class="pointer-events-none absolute start-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
                <input
                    ref="input"
                    v-model="query"
                    type="search"
                    :placeholder="t('send_product.search')"
                    :aria-label="t('send_product.search')"
                    class="h-8 w-full rounded-md border border-input bg-background pe-8 ps-8 text-sm"
                />
                <LoaderCircle v-if="loading" class="absolute end-2.5 top-1/2 size-4 -translate-y-1/2 animate-spin text-muted-foreground" aria-hidden="true" />
            </div>
            <button
                type="button"
                :title="t('common.close')"
                :aria-label="t('common.close')"
                :class="cn(buttonVariants({ variant: 'ghost', size: 'icon' }), 'size-8')"
                @click="emit('close')"
            >
                <X class="size-4" aria-hidden="true" />
            </button>
        </div>

        <div
            v-if="confirming"
            role="alertdialog"
            class="mb-2 flex flex-wrap items-center gap-2 rounded-md bg-warning/15 px-3 py-2 text-xs"
            data-testid="send-product-confirm"
        >
            <span class="min-w-0 flex-1">{{ t('send_product.out_of_stock_confirm', { name: confirming.product_title ?? '' }) }}</span>
            <button type="button" :class="cn(buttonVariants({ size: 'sm' }), 'h-7')" :disabled="sending !== null" @click="send(confirming, true)">
                {{ t('send_product.send_anyway') }}
            </button>
            <button type="button" :class="cn(buttonVariants({ variant: 'ghost', size: 'sm' }), 'h-7')" @click="confirming = null">{{ t('common.cancel') }}</button>
        </div>
        <p v-if="error" role="alert" class="mb-2 px-1 text-2xs text-destructive">{{ error }}</p>

        <ul class="scrollbar-thin max-h-56 overflow-y-auto rounded-md border">
            <li v-if="loading && !results.length" class="space-y-1 p-2" aria-hidden="true">
                <div v-for="n in 3" :key="n" class="h-9 animate-pulse rounded bg-muted" />
            </li>
            <li v-else-if="!results.length" class="px-3 py-2 text-xs text-muted-foreground">{{ t('send_product.empty') }}</li>
            <li v-for="variant in results" :key="variant.id" class="border-b last:border-b-0">
                <button
                    type="button"
                    class="flex w-full items-center gap-2 px-2.5 py-1.5 text-start hover:bg-muted disabled:opacity-50"
                    :disabled="sending !== null"
                    :data-testid="`send-product-${variant.id}`"
                    @click="pick(variant)"
                >
                    <img v-if="variant.image_url" :src="variant.image_url" alt="" class="size-9 shrink-0 rounded object-cover" />
                    <span v-else class="size-9 shrink-0 rounded bg-muted" aria-hidden="true" />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-xs font-medium">{{ variant.product_title }}</span>
                        <span class="block truncate text-2xs text-muted-foreground">
                            {{ variant.title }}<template v-if="variant.sku"> · <span dir="ltr">{{ variant.sku }}</span></template>
                        </span>
                    </span>
                    <span
                        v-if="isOutOfStock(variant)"
                        class="shrink-0 rounded bg-destructive/10 px-1.5 py-px text-2xs font-medium text-destructive"
                        data-testid="out-of-stock-badge"
                        >{{ t('send_product.out_of_stock') }}</span
                    >
                    <span class="shrink-0 text-xs font-medium tabular-nums">{{ formatMoney(variant.price, locale) }}</span>
                    <LoaderCircle v-if="sending === variant.id" class="size-3.5 shrink-0 animate-spin text-primary" aria-hidden="true" />
                    <SendHorizontal v-else class="size-3.5 shrink-0 text-primary rtl:-scale-x-100" aria-hidden="true" />
                </button>
            </li>
        </ul>
    </div>
</template>
