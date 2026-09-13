<script setup lang="ts">
import { useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { formatMoney } from '@/lib/format';
import type { ProductVariant } from '@/types/crm';
import { LoaderCircle, Plus, Search } from 'lucide-vue-next';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

const emit = defineEmits<{ add: [variant: ProductVariant] }>();

const api = useApi();
const { t, locale } = useI18n();

const query = ref('');
const results = ref<ProductVariant[]>([]);
const loading = ref(false);
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

// Local catalog search, debounced 250 ms.
watch(query, (value) => {
    window.clearTimeout(timer);
    timer = window.setTimeout(() => void search(value.trim()), 250);
});

onMounted(() => void search(''));
onBeforeUnmount(() => window.clearTimeout(timer));

function addFirst(): void {
    if (results.value[0]) emit('add', results.value[0]);
}
</script>

<template>
    <div>
        <div class="relative">
            <Search class="pointer-events-none absolute start-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
            <input
                v-model="query"
                type="search"
                :placeholder="t('order.search')"
                :aria-label="t('order.search')"
                class="h-8 w-full rounded-md border border-input bg-background pe-8 ps-8 text-sm"
                @keydown.enter.prevent="addFirst"
            />
            <LoaderCircle v-if="loading" class="absolute end-2.5 top-1/2 size-4 -translate-y-1/2 animate-spin text-muted-foreground" aria-hidden="true" />
        </div>
        <ul class="scrollbar-thin mt-2 max-h-44 overflow-y-auto rounded-md border">
            <li v-if="!results.length && !loading" class="px-3 py-2 text-xs text-muted-foreground">{{ t('order.no_products') }}</li>
            <li v-for="variant in results" :key="variant.id" class="border-b last:border-b-0">
                <button type="button" class="flex w-full items-center gap-2 px-2.5 py-1.5 text-start hover:bg-muted" @click="emit('add', variant)">
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-xs font-medium">{{ variant.product_title }}</span>
                        <span class="block truncate text-2xs text-muted-foreground">
                            {{ variant.title }}<template v-if="variant.sku"> · <span dir="ltr">{{ variant.sku }}</span></template>
                            · <span :class="{ 'text-red-600': variant.stock <= 0 }">{{ t('order.stock', { n: variant.stock }) }}</span>
                        </span>
                    </span>
                    <span class="shrink-0 text-xs font-medium tabular-nums">{{ formatMoney(variant.price, locale) }}</span>
                    <Plus class="size-3.5 shrink-0 text-primary" aria-hidden="true" />
                </button>
            </li>
        </ul>
    </div>
</template>
