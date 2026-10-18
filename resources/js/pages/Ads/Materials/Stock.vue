<script setup lang="ts">
import SectionTabs from '@/components/crm/SectionTabs.vue';
import { sectionTabs } from '@/lib/sectionTabs';
/** Ads Hub — مخزون الإعلانات: materials against their product's inventory, with the manual availability override (spec §8.7). */
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
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
import { ADS_FIELD } from '@/lib/adsUi';
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
/** DataTable keys rows by `id`; a stock row is one material. */
const tableRows = computed(() => page.value.data.map((r) => ({ ...r, id: r.material_id })));
const columns = computed<Column[]>(() => [
    { key: 'image', label: t('ads.materials.col.image') },
    { key: 'title', label: t('ads.materials.col.title'), primary: true },
    { key: 'product', label: t('ads.materials.col.product') },
    { key: 'variants', label: t('ads.materials.stock_page.variants') },
    { key: 'price', label: t('ads.materials.stock_page.price'), numeric: true },
    { key: 'quantity', label: t('ads.materials.stock_page.quantity'), numeric: true },
    { key: 'collections', label: t('ads.materials.col.collections') },
    { key: 'availability', label: t('ads.materials.stock_page.availability') },
]);

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

const fieldClass = ADS_FIELD;
const breadcrumbs = computed(() => [
    { title: t('nav.ads'), href: '/ads/materials' },
    { title: t('nav.ads_stock'), href: '/ads/stock' },
]);
</script>

<template>
    <Head :title="t('ads.materials.stock_page.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full min-w-0 max-w-[1400px] space-y-5 p-4 md:p-6">
            <PageHeader :title="t('ads.materials.stock_page.title')" :description="t('ads.materials.stock_page.description')">
                <a :href="exportUrl" :class="cn(buttonVariants({ variant: 'outline', size: 'sm' }), 'gap-1.5')">
                    <Download aria-hidden="true" />{{ t('ads.materials.stock_page.export') }}
                </a>
            </PageHeader>
            <SectionTabs :tabs="sectionTabs.library(t)" :label="t('section_tabs.library.label')" />

            <form class="flex flex-wrap items-end gap-3" @submit.prevent="go()">
                <div class="space-y-1">
                    <label class="block text-xs font-medium text-fg-2" for="stock-min">{{
                        t('ads.materials.stock_page.min_qty')
                    }}</label>
                    <input id="stock-min" v-model="minQty" type="number" min="0" inputmode="numeric" dir="ltr" :class="[fieldClass, 'w-32']" />
                </div>
                <div class="space-y-1">
                    <label class="block text-xs font-medium text-fg-2" for="stock-availability">{{
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
                    class="inline-flex h-9 items-center gap-1 rounded-md px-2.5 text-[13px] font-medium text-fg-2 hover:bg-sunken hover:text-foreground"
                    @click="reset"
                >
                    <RotateCcw class="size-3.5" aria-hidden="true" />{{ t('ads.materials.stock_page.reset') }}
                </button>
            </form>

            <DataTable
                table-id="ads-stock"
                :columns="columns"
                :rows="tableRows"
                :caption="t('ads.materials.stock_page.title')"
            >
                <template #empty>
                    <EmptyState :icon="Package" :title="t('ads.materials.stock_page.empty')" :body="t('ads.materials.stock_page.empty_body')" />
                </template>
                <template #cell-image="{ row: r }">
                    <span class="block h-[52px] w-[42px] overflow-hidden rounded-md bg-sunken">
                        <img v-if="r.thumb_url" :src="r.thumb_url" alt="" loading="lazy" class="size-full object-cover" />
                        <span v-else class="flex size-full items-center justify-center"><ImageOff class="size-4 text-fg-3" aria-hidden="true" /></span>
                    </span>
                </template>
                <template #cell-title="{ row: r }">
                    <p class="line-clamp-2 max-w-56 whitespace-normal font-semibold" dir="auto">{{ r.title }}</p>
                </template>
                <template #cell-product="{ row: r }">
                    <p class="line-clamp-2 max-w-56 whitespace-normal text-fg-2" dir="auto">{{ r.product.title }}</p>
                </template>
                <template #cell-variants="{ row: r }">
                    <Popover v-if="r.variants.length">
                        <PopoverTrigger as-child>
                            <button type="button" class="font-medium text-brand-ink underline-offset-2 hover:underline">
                                {{ t('ads.materials.stock_page.variants_n', { n: n(r.variants.length) }) }}
                            </button>
                        </PopoverTrigger>
                        <PopoverContent class="w-80 p-2">
                            <table class="w-full text-2xs">
                                <thead class="text-fg-3">
                                    <tr>
                                        <th scope="col" class="px-1.5 py-1 text-start">{{ t('ads.materials.stock_page.variant') }}</th>
                                        <th scope="col" class="px-1.5 py-1 text-end">{{ t('ads.materials.stock_page.price') }}</th>
                                        <th scope="col" class="px-1.5 py-1 text-end">{{ t('ads.materials.stock_page.quantity') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="v in r.variants" :key="v.id" class="border-t border-line">
                                        <td class="px-1.5 py-1" dir="auto">{{ v.title ?? v.sku ?? '—' }}</td>
                                        <td class="whitespace-nowrap px-1.5 py-1 text-end tabular-nums">
                                            {{ formatAdsMoney(v.price, locale) }}
                                        </td>
                                        <td class="px-1.5 py-1 text-end tabular-nums" :class="v.quantity > 0 ? '' : 'font-semibold text-danger'">
                                            {{ n(v.quantity) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </PopoverContent>
                    </Popover>
                    <span v-else class="text-muted-foreground">—</span>
                </template>
                <template #cell-price="{ row: r }"><span class="whitespace-nowrap">{{ price(r) }}</span></template>
                <template #cell-quantity="{ row: r }">
                    <span class="font-semibold tabular-nums" :class="r.quantity <= 0 ? 'text-danger' : r.quantity < 10 ? 'text-warn' : ''">{{ n(r.quantity) }}</span>
                </template>
                <template #cell-collections="{ row: r }">
                    <div class="flex max-w-44 flex-wrap gap-1">
                        <span
                            v-for="c in r.collections"
                            :key="c.id"
                            class="inline-flex h-6 items-center rounded-full bg-brand-soft px-2 text-xs font-medium text-brand-ink"
                            dir="auto"
                            >{{ c.name }}</span
                        >
                        <span v-if="!r.collections.length" class="text-muted-foreground">—</span>
                    </div>
                </template>
                <template #cell-availability="{ row: r }">
                    <template v-if="perms.canAuthor.value">
                        <label class="sr-only" :for="`avail-${r.material_id}`">{{ t('ads.materials.stock_page.availability_for', { title: r.title }) }}</label>
                        <select
                            :id="`avail-${r.material_id}`"
                            :key="`avail-${r.material_id}-${selectsKey}`"
                            :value="selectValue(r)"
                            :disabled="busyId === r.material_id"
                            class="h-9 rounded-md border px-2 text-[13px] font-medium focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            :class="
                                r.override === null
                                    ? 'border-line-strong bg-card text-foreground'
                                    : r.availability
                                      ? 'border-transparent bg-ok-soft text-ok'
                                      : 'border-transparent bg-danger-soft text-danger'
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
                        class="ms-1.5 inline-flex h-6 items-center rounded-full bg-brand-soft px-2 text-xs font-medium text-brand-ink"
                        :title="t('ads.materials.stock_page.manual_hint')"
                        >{{ t('ads.materials.stock_page.manual') }}</span
                    >
                </template>
            </DataTable>

            <nav
                v-if="page.total > 0"
                class="flex flex-wrap items-center justify-between gap-2 text-[13px] text-fg-2"
                :aria-label="t('ui.pagination')"
            >
                <span class="tabular-nums">{{ t('ads.materials.showing', { from: page.from ?? 0, to: page.to ?? 0, total: page.total }) }}</span>
                <div v-if="page.last_page > 1" class="flex flex-wrap items-center gap-1">
                    <button
                        type="button"
                        class="inline-flex size-8 items-center justify-center rounded-md border border-line-strong bg-card hover:bg-surface-2 disabled:opacity-50"
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
                                    ? 'border-brand bg-brand text-brand-on'
                                    : 'border-line-strong bg-card hover:bg-surface-2'
                            "
                            :aria-current="p === page.current_page ? 'page' : undefined"
                            @click="go(p)"
                        >
                            {{ n(p) }}
                        </button>
                    </template>
                    <button
                        type="button"
                        class="inline-flex size-8 items-center justify-center rounded-md border border-line-strong bg-card hover:bg-surface-2 disabled:opacity-50"
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
