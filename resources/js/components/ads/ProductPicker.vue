<script setup lang="ts">
/** Pick the store product a material is for: debounced search on /ads/products/search (300 ms). */
import { useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { safeUrl } from '@/lib/ads';
import { formatCount } from '@/lib/format';
import type { MaterialProduct } from '@/types/ads';
import { LoaderCircle, Package, Search, X } from 'lucide-vue-next';
import { onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';

const props = defineProps<{ modelValue: MaterialProduct | null; inputId?: string }>();
const emit = defineEmits<{ 'update:modelValue': [product: MaterialProduct | null] }>();

const api = useApi();
const { t, locale } = useI18n();

const query = ref('');
const results = ref<MaterialProduct[]>([]);
const loading = ref(false);
const open = ref(false);
/** Option highlighted with the arrow keys (-1 = none); exposed through aria-activedescendant. */
const active = ref(-1);
const root = ref<HTMLElement | null>(null);
const listId = `product-list-${useId()}`;
const optionId = (i: number) => `${listId}-${i}`;
let timer: number | undefined;
let seq = 0;

async function search(term: string): Promise<void> {
    const current = ++seq;
    loading.value = true;
    try {
        const { data } = await api.get<MaterialProduct[]>('/ads/products/search', { params: term ? { q: term } : {}, silent: true });
        if (current === seq) {
            results.value = data;
            active.value = data.length ? 0 : -1;
        }
    } catch {
        if (current === seq) results.value = [];
    } finally {
        if (current === seq) loading.value = false;
    }
}

watch(query, (value) => {
    window.clearTimeout(timer);
    if (value.trim() === '') {
        results.value = [];
        open.value = false;

        return;
    }
    open.value = true;
    timer = window.setTimeout(() => void search(value.trim()), 300);
});

function close(): void {
    open.value = false;
    active.value = -1;
}

/** Click / tap anywhere outside the picker closes the list. */
function onDocPointer(e: PointerEvent): void {
    if (open.value && root.value && !root.value.contains(e.target as Node)) close();
}
/** Tabbing away closes it too. */
function onFocusOut(e: FocusEvent): void {
    if (!root.value?.contains(e.relatedTarget as Node | null)) close();
}
onMounted(() => document.addEventListener('pointerdown', onDocPointer));
onBeforeUnmount(() => {
    window.clearTimeout(timer);
    document.removeEventListener('pointerdown', onDocPointer);
});

function onKeydown(e: KeyboardEvent): void {
    if (e.key === 'Escape') {
        if (open.value) e.preventDefault();
        close();

        return;
    }
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        if (!open.value && query.value.trim() !== '') open.value = true;
        const n = results.value.length;
        if (!n) return;
        active.value = e.key === 'ArrowDown' ? (active.value + 1) % n : (active.value - 1 + n) % n;
        document.getElementById(optionId(active.value))?.scrollIntoView({ block: 'nearest' });

        return;
    }
    if (e.key === 'Enter' && open.value && active.value >= 0 && results.value[active.value]) {
        e.preventDefault();
        pick(results.value[active.value]);
    }
}

function pick(p: MaterialProduct): void {
    emit('update:modelValue', p);
    query.value = '';
    close();
}

const stock = (n: number) => formatCount(n, locale.value);
const img = (p: MaterialProduct) => safeUrl(p.image_url);
</script>

<template>
    <div class="space-y-2">
        <div v-if="props.modelValue" class="flex items-center gap-3 rounded-lg border border-border bg-muted/30 p-2">
            <img v-if="img(props.modelValue)" :src="img(props.modelValue) ?? undefined" alt="" class="size-10 rounded object-cover" loading="lazy" />
            <span v-else class="flex size-10 items-center justify-center rounded bg-muted"
                ><Package class="size-4 text-muted-foreground" aria-hidden="true"
            /></span>
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium" dir="auto">{{ props.modelValue.title }}</p>
                <p class="text-2xs text-muted-foreground">{{ t('ads.materials.product.inventory', { n: stock(props.modelValue.inventory) }) }}</p>
            </div>
            <button
                type="button"
                class="inline-flex size-8 items-center justify-center rounded-md text-muted-foreground hover:bg-muted hover:text-destructive"
                :aria-label="t('ads.materials.product.clear')"
                :title="t('ads.materials.product.clear')"
                @click="emit('update:modelValue', null)"
            >
                <X class="size-4" aria-hidden="true" />
            </button>
        </div>

        <div ref="root" class="relative" @focusout="onFocusOut">
            <Search class="pointer-events-none absolute start-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
            <input
                :id="inputId"
                v-model="query"
                type="search"
                autocomplete="off"
                :placeholder="props.modelValue ? t('ads.materials.product.change') : t('ads.materials.product.search')"
                class="flex h-9 w-full rounded-md border border-input bg-card pe-8 ps-8 text-sm placeholder:text-muted-foreground focus-visible:border-primary focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-primary"
                role="combobox"
                aria-autocomplete="list"
                :aria-expanded="open"
                :aria-controls="listId"
                :aria-activedescendant="open && active >= 0 ? optionId(active) : undefined"
                @keydown="onKeydown"
            />
            <LoaderCircle
                v-if="loading"
                class="absolute end-2.5 top-1/2 size-3.5 -translate-y-1/2 animate-spin text-muted-foreground"
                aria-hidden="true"
            />

            <ul
                v-show="open"
                :id="listId"
                class="absolute inset-x-0 top-full z-20 mt-1 max-h-72 overflow-y-auto rounded-md border border-border bg-popover p-1 shadow-lg"
                role="listbox"
                :aria-label="t('ads.materials.form.product')"
            >
                <li v-if="!loading && !results.length" role="presentation" class="px-3 py-2 text-xs text-muted-foreground">
                    {{ t('ads.materials.product.none_found') }}
                </li>
                <li
                    v-for="(p, i) in results"
                    :id="optionId(i)"
                    :key="p.id"
                    role="option"
                    :aria-selected="i === active"
                    class="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-start"
                    :class="i === active ? 'bg-muted' : 'hover:bg-muted'"
                    @mousedown.prevent
                    @mouseenter="active = i"
                    @click="pick(p)"
                >
                    <img v-if="img(p)" :src="img(p) ?? undefined" alt="" class="size-8 rounded object-cover" loading="lazy" />
                    <span v-else class="flex size-8 items-center justify-center rounded bg-muted"
                        ><Package class="size-3.5 text-muted-foreground" aria-hidden="true"
                    /></span>
                    <span class="min-w-0 flex-1 truncate text-xs" dir="auto">{{ p.title }}</span>
                    <span
                        class="shrink-0 text-2xs tabular-nums"
                        :class="p.inventory > 0 ? 'text-emerald-700 dark:text-emerald-300' : 'text-destructive'"
                    >
                        {{ t('ads.materials.product.inventory', { n: stock(p.inventory) }) }}
                    </span>
                </li>
            </ul>
        </div>
    </div>
</template>
