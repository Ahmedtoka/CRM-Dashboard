<script setup lang="ts">
import { buttonVariants } from '@/components/ui/button';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { formatCount, formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Message, ProductVariant } from '@/types/crm';
import { isAxiosError } from 'axios';
import { useMediaQuery } from '@vueuse/core';
import { LoaderCircle, Search, SendHorizontal, Shirt } from 'lucide-vue-next';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

/**
 * «ابعت منتج» (2026-10-17): search the synced catalog, pick a colour/size, and send it to the
 * customer as the bot's product card (POST /inbox/conversations/{id}/product). An out-of-stock
 * variant asks once before it goes. The redesign (p1) shows it as a sheet of 4:5 cards with the stock
 * on each: a card is picked first and the wide button names what goes out.
 */
const props = defineProps<{ conversationId: number }>();
const emit = defineEmits<{ sent: [message: Message]; close: [] }>();

const api = useApi();
const { t, locale, dir } = useI18n();
const phone = useMediaQuery('(max-width: 759px)');
/** Under this many left the card says «فاضل ٧ بس» in amber. */
const LOW_STOCK = 10;
const selected = ref<ProductVariant | null>(null);

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

onMounted(() => void search(''));

/** The sheet's own first focus goes to the search box, not the close button. */
function focusSearch(event: Event): void {
    event.preventDefault();
    input.value?.focus();
}

function choose(variant: ProductVariant): void {
    error.value = null;
    confirming.value = null;
    selected.value = variant;
}
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
    <!-- «ابعت منتج» as a sheet (DESIGN §3 ProductPicker): the side of the end on a desk, a bottom sheet on a phone. A card
         picks the product, the one wide button says what goes out; a double click sends at once (the old one-step way). -->
    <Sheet :open="true" @update:open="(open) => !open && emit('close')">
        <SheetContent
            :side="phone ? 'bottom' : dir === 'rtl' ? 'left' : 'right'"
            class="flex flex-col gap-0 p-0 sm:max-w-none"
            :class="phone ? 'max-h-[88dvh] rounded-t-xl pb-[env(safe-area-inset-bottom)]' : 'w-[min(560px,100%)]'"
            data-testid="send-product-picker"
            @open-auto-focus="focusSearch"
        >
            <SheetHeader class="border-b border-line px-5 pb-3 pt-4 text-start">
                <SheetTitle class="pe-8 text-lg font-bold">{{ t('send_product.title') }}</SheetTitle>
                <SheetDescription class="text-[13px] text-fg-2">{{ t('send_product.hint') }}</SheetDescription>
            </SheetHeader>

            <div class="scrollbar-thin min-h-0 flex-1 space-y-3 overflow-y-auto px-5 py-4">
                <div class="relative">
                    <Search class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-fg-3" aria-hidden="true" />
                    <input
                        ref="input"
                        v-model="query"
                        type="search"
                        :placeholder="t('send_product.search')"
                        :aria-label="t('send_product.search')"
                        class="h-10 w-full rounded-md border border-line-strong bg-card pe-9 ps-9 text-sm outline-none focus:border-brand focus:ring-[3px] focus:ring-brand-soft"
                    />
                    <LoaderCircle v-if="loading" class="absolute end-3 top-1/2 size-4 -translate-y-1/2 animate-spin text-fg-3" aria-hidden="true" />
                </div>

                <div
                    v-if="confirming"
                    role="alertdialog"
                    class="flex flex-wrap items-center gap-2 rounded-md bg-warn-soft px-3 py-2 text-[13px] text-foreground"
                    data-testid="send-product-confirm"
                >
                    <span class="min-w-0 flex-1">{{ t('send_product.out_of_stock_confirm', { name: confirming.product_title ?? '' }) }}</span>
                    <button type="button" :class="cn(buttonVariants({ size: 'sm' }), 'h-8')" :disabled="sending !== null" @click="send(confirming, true)">
                        {{ t('send_product.send_anyway') }}
                    </button>
                    <button type="button" :class="cn(buttonVariants({ variant: 'ghost', size: 'sm' }), 'h-8')" @click="confirming = null">{{ t('common.cancel') }}</button>
                </div>
                <p v-if="error" role="alert" class="text-xs font-medium text-danger">{{ error }}</p>

                <div v-if="loading && !results.length" class="grid grid-cols-2 gap-3 sm:grid-cols-3" aria-hidden="true">
                    <div v-for="n in 6" :key="n" class="aspect-[4/5] animate-pulse rounded-lg bg-sunken" />
                </div>
                <p v-else-if="!results.length" class="py-6 text-center text-[13px] text-fg-2">{{ t('send_product.empty') }}</p>
                <ul v-else class="grid grid-cols-2 gap-3 sm:grid-cols-3" role="list">
                    <li v-for="variant in results" :key="variant.id">
                        <button
                            type="button"
                            class="group flex w-full flex-col overflow-hidden rounded-lg border bg-card text-start transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50"
                            :class="selected?.id === variant.id ? 'border-brand ring-2 ring-brand' : 'border-line hover:border-line-strong'"
                            :aria-pressed="selected?.id === variant.id"
                            :disabled="sending !== null"
                            :data-testid="`send-product-${variant.id}`"
                            @click="choose(variant)"
                            @dblclick="pick(variant)"
                        >
                            <span class="relative block aspect-[4/5] w-full bg-sunken" :class="isOutOfStock(variant) ? 'opacity-60' : ''">
                                <img v-if="variant.image_url" :src="variant.image_url" alt="" class="size-full object-cover" loading="lazy" />
                                <Shirt v-else class="absolute left-1/2 top-1/2 size-8 -translate-x-1/2 -translate-y-1/2 text-fg-3" aria-hidden="true" />
                                <LoaderCircle
                                    v-if="sending === variant.id"
                                    class="absolute end-2 top-2 size-4 animate-spin text-brand"
                                    aria-hidden="true"
                                />
                            </span>
                            <span class="flex min-w-0 flex-col gap-0.5 px-2.5 py-2">
                                <span class="truncate text-[13px] font-semibold">{{ variant.product_title }}</span>
                                <span class="truncate text-xs text-fg-2">
                                    {{ formatMoney(variant.price, locale) }}<template v-if="variant.title"> · {{ variant.title }}</template>
                                </span>
                                <span
                                    v-if="isOutOfStock(variant)"
                                    class="text-xs font-semibold text-danger"
                                    data-testid="out-of-stock-badge"
                                    >{{ t('send_product.out_of_stock') }}</span
                                >
                                <span v-else-if="variant.stock > 0 && variant.stock < LOW_STOCK" class="text-xs font-semibold text-warn">{{
                                    t('send_product.low_stock', { n: formatCount(variant.stock, locale) })
                                }}</span>
                                <span v-else-if="variant.stock > 0" class="text-xs text-fg-3">{{ t('send_product.in_stock', { n: formatCount(variant.stock, locale) }) }}</span>
                                <span v-if="variant.sku" class="truncate text-[11px] text-fg-3" dir="ltr">{{ variant.sku }}</span>
                            </span>
                        </button>
                    </li>
                </ul>
            </div>

            <div class="flex shrink-0 items-center gap-2 border-t border-line px-5 py-3">
                <button
                    type="button"
                    :class="cn(buttonVariants({ size: 'lg' }), 'h-11 min-w-0 flex-1 gap-2')"
                    :disabled="selected === null || sending !== null"
                    data-testid="send-product-send"
                    @click="selected && pick(selected)"
                >
                    <LoaderCircle v-if="sending !== null" class="size-4 animate-spin" aria-hidden="true" />
                    <SendHorizontal v-else class="size-4 rtl-flip" aria-hidden="true" />
                    <span class="truncate">{{ selected ? t('send_product.send_named', { name: selected.product_title ?? '' }) : t('send_product.choose') }}</span>
                </button>
                <button type="button" :class="cn(buttonVariants({ variant: 'ghost', size: 'lg' }), 'h-11')" @click="emit('close')">
                    {{ t('common.cancel') }}
                </button>
            </div>
        </SheetContent>
    </Sheet>
</template>
