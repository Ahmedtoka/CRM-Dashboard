<script setup lang="ts">
/** Ads Hub — مخزون الإعلانات: materials against their product's inventory, with the manual availability override (spec §8.7). */
import EmptyState from '@/components/crm/EmptyState.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { buttonVariants } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatAdsMoney } from '@/lib/ads';
import { cleanQuery, pageList, queryString, useMaterialPermissions } from '@/lib/adsMaterials';
import { formatCount } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { AdStockRow, AdsStockProps } from '@/types/ads';
import { Head, router } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, Download, ImageOff, Package, RotateCcw } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps<AdsStockProps>();

const { t, locale } = useI18n();
const toast = useToast();
const perms = useMaterialPermissions();
const n = (v: number) => formatCount(v, locale.value);

/* ---- filters ---- */
const minQty = ref(props.filters.min_qty === null ? '' : String(props.filters.min_qty));
const availability = ref(props.filters.availability);
watch(
    () => props.filters,
    (f) => {
        availability.value = f.availability;
        if (minQty.value.trim() !== (f.min_qty === null ? '' : String(f.min_qty))) minQty.value = f.min_qty === null ? '' : String(f.min_qty);
    },
);

let timer: number | undefined;
/** The min quantity of the last visit, so a programmatic change (reset) does not fire a second, debounced one. */
let sentMin = minQty.value.trim();
function go(page: number | null = null): void {
    window.clearTimeout(timer);
    sentMin = minQty.value.trim();
    router.get(
        '/ads/stock',
        cleanQuery({
            min_qty: minQty.value.trim(),
            availability: availability.value === 'all' ? null : availability.value,
            page: page && page > 1 ? page : null,
        }),
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

watch(minQty, (v) => {
    window.clearTimeout(timer);
    if (v.trim() === sentMin) return;
    timer = window.setTimeout(() => go(), 300);
});
onBeforeUnmount(() => window.clearTimeout(timer));

/** Plain link to the CSV with the filters as applied by the server. */
const exportUrl = computed(
    () =>
        `/ads/stock/export${queryString({ min_qty: props.filters.min_qty, availability: props.filters.availability === 'all' ? null : props.filters.availability })}`,
);

const filtered = computed(() => minQty.value.trim() !== '' || availability.value !== 'all');
function reset(): void {
    minQty.value = '';
    availability.value = 'all';
    go();
}

/* ---- rows ---- */
const page = computed(() => props.rows);
const pages = computed(() => pageList(page.value.current_page, page.value.last_page));

function price(r: AdStockRow): string {
    const { min, max } = r.price;
    if (min === null || max === null) return '—';
    if (min === max) return formatAdsMoney(min, locale.value);

    return `${formatAdsMoney(min, locale.value)} – ${formatAdsMoney(max, locale.value)}`;
}

/* ---- availability override ---- */
const busyId = ref<number | null>(null);
const selectsKey = ref(0);
/** «follow inventory» when nothing is pinned, else the pinned value. */
const selectValue = (r: AdStockRow) => (r.override === null ? 'auto' : r.override ? 'yes' : 'no');
const effective = (r: AdStockRow) => (r.availability ? t('ads.materials.stock_page.yes') : t('ads.materials.stock_page.no'));
function setAvailability(r: AdStockRow, value: string): void {
    const available = value === 'auto' ? null : value === 'yes';
    busyId.value = r.material_id;
    router.post(
        `/ads/stock/${r.material_id}/availability`,
        { available },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => toast.push(t('ads.materials.stock_page.saved')),
            onError: (errors) => toast.push(String(Object.values(errors)[0] ?? t('common.error')), 'error'),
            onFinish: () => {
                busyId.value = null;
                // Re-mount the selects so one left on «follow inventory» shows the resulting yes / no.
                selectsKey.value++;
            },
        },
    );
}

const fieldClass = 'h-9 rounded-md border border-input bg-background px-2 text-xs';
const breadcrumbs = computed(() => [
    { title: t('nav.ads'), href: '/ads' },
    { title: t('nav.ads_stock'), href: '/ads/stock' },
]);
</script>

<template>
    <Head :title="t('ads.materials.stock_page.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-6xl space-y-4 p-3 md:p-6">
            <PageHeader :title="t('ads.materials.stock_page.title')" :description="t('ads.materials.stock_page.description')">
                <a :href="exportUrl" :class="cn(buttonVariants({ variant: 'outline', size: 'sm' }), 'gap-1.5')">
                    <Download aria-hidden="true" />{{ t('ads.materials.stock_page.export') }}
                </a>
            </PageHeader>

            <form class="flex flex-wrap items-end gap-3 rounded-lg bg-card p-3 shadow-card" @submit.prevent="go()">
                <div class="space-y-1">
                    <label class="block text-2xs font-medium text-muted-foreground" for="stock-min">{{
                        t('ads.materials.stock_page.min_qty')
                    }}</label>
                    <input id="stock-min" v-model="minQty" type="number" min="0" inputmode="numeric" dir="ltr" :class="[fieldClass, 'w-32']" />
                </div>
                <div class="space-y-1">
                    <label class="block text-2xs font-medium text-muted-foreground" for="stock-availability">{{
                        t('ads.materials.stock_page.availability')
                    }}</label>
                    <select id="stock-availability" v-model="availability" :class="[fieldClass, 'w-40']" @change="go()">
                        <option value="all">{{ t('ads.materials.stock_page.all') }}</option>
                        <option value="in">{{ t('ads.materials.stock_page.available') }}</option>
                        <option value="out">{{ t('ads.materials.stock_page.unavailable') }}</option>
                    </select>
                </div>
                <button
                    v-if="filtered"
                    type="button"
                    class="inline-flex h-9 items-center gap-1 rounded-md border border-border bg-background px-3 text-xs hover:bg-muted"
                    @click="reset"
                >
                    <RotateCcw class="size-3.5" aria-hidden="true" />{{ t('ads.materials.stock_page.reset') }}
                </button>
            </form>

            <div class="scrollbar-thin relative overflow-x-auto rounded-lg bg-card shadow-card [contain:inline-size]">
                <EmptyState
                    v-if="!page.data.length"
                    :icon="Package"
                    :title="t('ads.materials.stock_page.empty')"
                    :body="t('ads.materials.stock_page.empty_body')"
                />
                <table v-else class="w-full min-w-[960px] text-xs">
                    <caption class="sr-only">
                        {{
                            t('ads.materials.stock_page.title')
                        }}
                    </caption>
                    <thead class="border-b border-border/60 text-2xs font-semibold text-muted-foreground">
                        <tr>
                            <th scope="col" class="px-3 py-2 text-start">{{ t('ads.materials.col.image') }}</th>
                            <th scope="col" class="px-2 py-2 text-start">{{ t('ads.materials.col.title') }}</th>
                            <th scope="col" class="px-2 py-2 text-start">{{ t('ads.materials.col.product') }}</th>
                            <th scope="col" class="px-2 py-2 text-start">{{ t('ads.materials.stock_page.variants') }}</th>
                            <th scope="col" class="px-2 py-2 text-end">{{ t('ads.materials.stock_page.price') }}</th>
                            <th scope="col" class="px-2 py-2 text-end">{{ t('ads.materials.stock_page.quantity') }}</th>
                            <th scope="col" class="px-2 py-2 text-start">{{ t('ads.materials.col.collections') }}</th>
                            <th scope="col" class="px-3 py-2 text-start">{{ t('ads.materials.stock_page.availability') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in page.data" :key="r.material_id" class="border-t border-border/60 first:border-t-0 hover:bg-muted/40">
                            <td class="px-3 py-2">
                                <span class="block size-12 overflow-hidden rounded-md bg-muted">
                                    <img v-if="r.thumb_url" :src="r.thumb_url" alt="" loading="lazy" class="size-full object-cover" />
                                    <span v-else class="flex size-full items-center justify-center"
                                        ><ImageOff class="size-4 text-muted-foreground" aria-hidden="true"
                                    /></span>
                                </span>
                            </td>
                            <td class="max-w-56 px-2 py-2">
                                <p class="line-clamp-2 font-bold" dir="auto">{{ r.title }}</p>
                            </td>
                            <td class="max-w-56 px-2 py-2">
                                <p class="line-clamp-2 font-medium text-primary" dir="auto">{{ r.product.title }}</p>
                            </td>
                            <td class="px-2 py-2">
                                <Popover v-if="r.variants.length">
                                    <PopoverTrigger as-child>
                                        <button type="button" class="text-primary hover:underline">
                                            {{ t('ads.materials.stock_page.variants_n', { n: n(r.variants.length) }) }}
                                        </button>
                                    </PopoverTrigger>
                                    <PopoverContent class="w-80 p-2">
                                        <table class="w-full text-2xs">
                                            <thead class="text-muted-foreground">
                                                <tr>
                                                    <th scope="col" class="px-1.5 py-1 text-start">{{ t('ads.materials.stock_page.variant') }}</th>
                                                    <th scope="col" class="px-1.5 py-1 text-end">{{ t('ads.materials.stock_page.price') }}</th>
                                                    <th scope="col" class="px-1.5 py-1 text-end">{{ t('ads.materials.stock_page.quantity') }}</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr v-for="v in r.variants" :key="v.id" class="border-t border-border/60">
                                                    <td class="px-1.5 py-1" dir="auto">{{ v.title ?? v.sku ?? '—' }}</td>
                                                    <td class="whitespace-nowrap px-1.5 py-1 text-end tabular-nums">
                                                        {{ formatAdsMoney(v.price, locale) }}
                                                    </td>
                                                    <td class="px-1.5 py-1 text-end tabular-nums" :class="v.quantity > 0 ? '' : 'text-destructive'">
                                                        {{ n(v.quantity) }}
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </PopoverContent>
                                </Popover>
                                <span v-else class="text-muted-foreground">—</span>
                            </td>
                            <td class="whitespace-nowrap px-2 py-2 text-end tabular-nums">{{ price(r) }}</td>
                            <td class="px-2 py-2 text-end font-semibold tabular-nums" :class="r.quantity > 0 ? '' : 'text-destructive'">
                                {{ n(r.quantity) }}
                            </td>
                            <td class="max-w-44 px-2 py-2">
                                <div class="flex flex-wrap gap-1">
                                    <span
                                        v-for="c in r.collections"
                                        :key="c.id"
                                        class="inline-flex h-5 items-center rounded-full bg-primary/10 px-2 text-2xs font-medium text-primary"
                                        dir="auto"
                                        >{{ c.name }}</span
                                    >
                                    <span v-if="!r.collections.length" class="text-muted-foreground">—</span>
                                </div>
                            </td>
                            <td class="px-3 py-2">
                                <template v-if="perms.canAuthor.value">
                                    <label class="sr-only" :for="`avail-${r.material_id}`">{{
                                        t('ads.materials.stock_page.availability_for', { title: r.title })
                                    }}</label>
                                    <select
                                        :id="`avail-${r.material_id}`"
                                        :key="`avail-${r.material_id}-${selectsKey}`"
                                        :value="selectValue(r)"
                                        :disabled="busyId === r.material_id"
                                        class="h-8 rounded-md border px-2 text-xs font-medium"
                                        :class="
                                            r.override === null
                                                ? 'border-input bg-background text-foreground'
                                                : r.availability
                                                  ? 'border-success/40 bg-success/10 text-emerald-800 dark:text-emerald-200'
                                                  : 'border-destructive/30 bg-destructive/10 text-destructive'
                                        "
                                        @change="setAvailability(r, ($event.target as HTMLSelectElement).value)"
                                    >
                                        <option value="yes">{{ t('ads.materials.stock_page.yes') }}</option>
                                        <option value="no">{{ t('ads.materials.stock_page.no') }}</option>
                                        <option value="auto">{{ t('ads.materials.stock_page.auto_now', { state: effective(r) }) }}</option>
                                    </select>
                                </template>
                                <StatusChip
                                    v-else
                                    :label="r.availability ? t('ads.materials.stock_page.yes') : t('ads.materials.stock_page.no')"
                                    :tone="r.availability ? 'positive' : 'negative'"
                                    dot
                                />
                                <span
                                    v-if="r.override !== null"
                                    class="ms-1.5 inline-flex h-5 items-center rounded-full bg-violet-500/15 px-2 text-2xs font-medium text-violet-800 dark:bg-violet-500/25 dark:text-violet-100"
                                    :title="t('ads.materials.stock_page.manual_hint')"
                                    >{{ t('ads.materials.stock_page.manual') }}</span
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <nav
                v-if="page.total > 0"
                class="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground"
                :aria-label="t('ui.pagination')"
            >
                <span class="tabular-nums">{{ t('ads.materials.showing', { from: page.from ?? 0, to: page.to ?? 0, total: page.total }) }}</span>
                <div v-if="page.last_page > 1" class="flex flex-wrap items-center gap-1">
                    <button
                        type="button"
                        class="inline-flex size-8 items-center justify-center rounded-md border border-border bg-background hover:bg-muted disabled:opacity-50"
                        :disabled="page.current_page <= 1"
                        :aria-label="t('ui.prev')"
                        @click="go(page.current_page - 1)"
                    >
                        <ChevronLeft class="rtl-flip size-3.5" aria-hidden="true" />
                    </button>
                    <template v-for="(p, i) in pages" :key="i">
                        <span v-if="p === null" class="px-1">…</span>
                        <button
                            v-else
                            type="button"
                            class="inline-flex h-8 min-w-8 items-center justify-center rounded-md border px-2 tabular-nums"
                            :class="
                                p === page.current_page
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : 'border-border bg-background hover:bg-muted'
                            "
                            :aria-current="p === page.current_page ? 'page' : undefined"
                            @click="go(p)"
                        >
                            {{ n(p) }}
                        </button>
                    </template>
                    <button
                        type="button"
                        class="inline-flex size-8 items-center justify-center rounded-md border border-border bg-background hover:bg-muted disabled:opacity-50"
                        :disabled="page.current_page >= page.last_page"
                        :aria-label="t('ui.next')"
                        @click="go(page.current_page + 1)"
                    >
                        <ChevronRight class="rtl-flip size-3.5" aria-hidden="true" />
                    </button>
                </div>
            </nav>
        </div>
    </AppLayout>
</template>
