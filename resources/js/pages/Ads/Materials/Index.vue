<script setup lang="ts">
/** Ads Hub — مكتبة مواد الإعلانات: what the content team uploaded, its status, stock and (for spend viewers) performance (spec §8.7). */
import AdLinkPicker from '@/components/ads/AdLinkPicker.vue';
import MaterialLightbox from '@/components/ads/MaterialLightbox.vue';
import MaterialStatusChip from '@/components/ads/MaterialStatusChip.vue';
import MoneyCell from '@/components/ads/MoneyCell.vue';
import WinnerBadge from '@/components/ads/WinnerBadge.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import FormDialog from '@/components/crm/FormDialog.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { buttonVariants } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatRoas, roasTone, safeUrl } from '@/lib/ads';
import { cleanQuery, links, pageList, queryString, useMaterialPermissions } from '@/lib/adsMaterials';
import { formatClock, formatCount, formatDate } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { AdsMaterialsIndexProps, MaterialRow, MaterialStatus } from '@/types/ads';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowUp,
    ChevronLeft,
    ChevronRight,
    CircleCheckBig,
    CirclePlay,
    Clapperboard,
    Download,
    Globe,
    HardDrive,
    Hourglass,
    ImageOff,
    Info,
    Instagram,
    Layers,
    LayoutGrid,
    Link2,
    Package,
    PackageCheck,
    PackageX,
    Pencil,
    Play,
    Plus,
    RotateCcw,
    Search,
    Square,
    Trash2,
    X,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, reactive, ref, watch, type Component } from 'vue';

const props = defineProps<AdsMaterialsIndexProps>();

const { t, locale } = useI18n();
const toast = useToast();
const perms = useMaterialPermissions();
const n = (v: number) => formatCount(v, locale.value);

const STATUSES: MaterialStatus[] = ['not_started', 'activated', 'done'];
const STOCKS = ['in', 'out', 'none'] as const;
const TYPES = ['reel', 'carousel', 'post', 'story', 'image', 'video'] as const;

/* ---- filters ---- */
const f = reactive({
    q: props.filters.q ?? '',
    status: props.filters.status ?? '',
    stock: props.filters.stock ?? '',
    collection: props.filters.collection ?? '',
    type: props.filters.type ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});
watch(
    () => props.filters,
    (s) => {
        // While a search is still being typed (timer pending) keep it; otherwise take the applied one.
        if (timer === undefined && (s.q ?? '') !== f.q.trim()) {
            f.q = s.q ?? '';
            sentQ = f.q;
        }
        Object.assign(f, {
            status: s.status ?? '',
            stock: s.stock ?? '',
            collection: s.collection ?? '',
            type: s.type ?? '',
            from: s.from ?? '',
            to: s.to ?? '',
        });
    },
);

let timer: number | undefined;
const applied = () => ({ q: f.q.trim(), status: f.status, stock: f.stock, collection: f.collection, type: f.type, from: f.from, to: f.to });

/** The search text of the last visit, so a programmatic change (clear) does not fire a second, debounced one. */
let sentQ = props.filters.q ?? '';
function go(page: number | null = null): void {
    window.clearTimeout(timer);
    timer = undefined;
    sentQ = f.q.trim();
    router.get('/ads/materials', cleanQuery({ ...applied(), page: page && page > 1 ? page : null }), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

watch(
    () => f.q,
    (q) => {
        window.clearTimeout(timer);
        timer = undefined;
        if (q.trim() === sentQ) return;
        timer = window.setTimeout(() => go(), 300);
    },
);
onBeforeUnmount(() => window.clearTimeout(timer));

const hasFilters = computed(() => Object.values(applied()).some((v) => v !== ''));
function clearFilters(): void {
    Object.assign(f, { q: '', status: '', stock: '', collection: '', type: '', from: '', to: '' });
    go();
}

const exportUrl = computed(() => `/ads/materials/export${queryString(applied())}`);

/* ---- stats ---- */
interface Kpi {
    key: string;
    value: number;
    icon: Component;
    border: string;
    tint: string;
    hint?: string;
}
const kpis = computed<Kpi[]>(() => [
    { key: 'total', value: props.stats.total, icon: Layers, border: 'border-t-primary', tint: 'bg-primary/10 text-primary' },
    {
        key: 'activated',
        value: props.stats.activated,
        icon: CirclePlay,
        border: 'border-t-success',
        tint: 'bg-success/15 text-emerald-700 dark:text-emerald-300',
    },
    {
        key: 'not_started',
        value: props.stats.not_started,
        icon: Hourglass,
        border: 'border-t-warning',
        tint: 'bg-warning/20 text-amber-800 dark:text-amber-200',
    },
    { key: 'done', value: props.stats.done, icon: CircleCheckBig, border: 'border-t-info', tint: 'bg-info/10 text-blue-700 dark:text-blue-200' },
    {
        key: 'reels',
        value: props.stats.reels,
        icon: Clapperboard,
        border: 'border-t-fuchsia-500',
        tint: 'bg-fuchsia-500/10 text-fuchsia-700 dark:text-fuchsia-300',
    },
    {
        key: 'posts',
        value: props.stats.posts,
        icon: LayoutGrid,
        border: 'border-t-violet-500',
        tint: 'bg-violet-500/10 text-violet-700 dark:text-violet-300',
        hint: t('ads.materials.kpi.carousels_hint', { n: n(props.stats.carousels) }),
    },
    {
        key: 'in_stock',
        value: props.stats.in_stock,
        icon: PackageCheck,
        border: 'border-t-cyan-500',
        tint: 'bg-cyan-500/10 text-cyan-700 dark:text-cyan-300',
    },
    {
        key: 'out_of_stock',
        value: props.stats.out_of_stock,
        icon: PackageX,
        border: 'border-t-destructive',
        tint: 'bg-destructive/10 text-destructive',
        hint: t('ads.materials.kpi.need_stop_hint', { n: n(props.stats.need_stop) }),
    },
]);

/* ---- rows ---- */
const page = computed(() => props.materials);
const pages = computed(() => pageList(page.value.current_page, page.value.last_page));

const created = (iso: string | null) => ({ day: formatDate(iso, locale.value), time: formatClock(iso, locale.value) });

const stockChip = (m: MaterialRow) =>
    m.stock === 'in'
        ? { label: t('ads.materials.stock.in'), cls: 'bg-cyan-500/15 text-cyan-800 dark:bg-cyan-500/25 dark:text-cyan-100' }
        : m.stock === 'out'
          ? { label: t('ads.materials.stock.out'), cls: 'bg-muted text-muted-foreground' }
          : { label: t('ads.materials.stock.none'), cls: 'bg-muted text-muted-foreground' };

const typeLabel = (type: string) => (TYPES.includes(type as (typeof TYPES)[number]) ? t(`ads.materials.type.${type}`) : type);

/* ---- status ---- */
const busyId = ref<number | null>(null);
function setStatus(m: MaterialRow, status: MaterialStatus): void {
    busyId.value = m.id;
    router.post(
        `/ads/materials/${m.id}/status`,
        { status },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => toast.push(t(`ads.materials.status_saved.${status}`)),
            onError: (errors) => toast.push(String(Object.values(errors)[0] ?? t('common.error')), 'error'),
            onFinish: () => (busyId.value = null),
        },
    );
}

/* ---- gallery ---- */
const gallery = ref<MaterialRow | null>(null);
const galleryOpen = computed({ get: () => gallery.value !== null, set: (v) => !v && (gallery.value = null) });

/* ---- linking ads ---- */
const linking = ref<MaterialRow | null>(null);
const linkOpen = computed({ get: () => linking.value !== null, set: (v) => !v && (linking.value = null) });

/* ---- delete ---- */
const deleting = ref<MaterialRow | null>(null);
const deleteOpen = computed({ get: () => deleting.value !== null, set: (v) => !v && (deleting.value = null) });
const deleteBusy = ref(false);
function confirmDelete(): void {
    const m = deleting.value;
    if (!m) return;
    deleteBusy.value = true;
    router.delete(`/ads/materials/${m.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            deleting.value = null;
            toast.push(t('ads.materials.deleted'));
        },
        onError: (errors) => toast.push(String(Object.values(errors)[0] ?? t('common.error')), 'error'),
        onFinish: () => (deleteBusy.value = false),
    });
}

const showPerf = computed(() => props.canSeeSpend);
const fieldClass = 'h-9 w-full rounded-md border border-input bg-background px-2 text-xs';
const iconBtn = 'inline-flex size-8 items-center justify-center rounded-md border transition-colors disabled:opacity-50';

const breadcrumbs = computed(() => [
    { title: t('nav.ads'), href: '/ads' },
    { title: t('nav.ads_materials'), href: '/ads/materials' },
]);
</script>

<template>
    <Head :title="t('ads.materials.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-[1400px] space-y-4 p-3 md:p-6">
            <PageHeader :title="t('ads.materials.title')" :description="t('ads.materials.description')">
                <a :href="exportUrl" :class="cn(buttonVariants({ variant: 'outline', size: 'sm' }), 'gap-1.5')">
                    <Download aria-hidden="true" />{{ t('ads.materials.export') }}
                </a>
                <Link
                    v-if="perms.canAuthor.value"
                    href="/ads/materials/create"
                    :class="cn(buttonVariants({ variant: 'default', size: 'sm' }), 'gap-1.5')"
                >
                    <Plus aria-hidden="true" />{{ t('ads.materials.new') }}
                </Link>
            </PageHeader>

            <!-- KPIs (whole library) -->
            <ul class="grid grid-cols-2 gap-3 sm:grid-cols-4 xl:grid-cols-8">
                <li v-for="k in kpis" :key="k.key" class="rounded-lg border-t-4 bg-card px-3 py-3 shadow-card" :class="k.border">
                    <div class="flex items-start justify-between gap-2">
                        <p class="text-2xs font-medium text-muted-foreground">{{ t(`ads.materials.kpi.${k.key}`) }}</p>
                        <span class="flex size-7 shrink-0 items-center justify-center rounded-md" :class="k.tint">
                            <component :is="k.icon" class="size-3.5" aria-hidden="true" />
                        </span>
                    </div>
                    <p class="mt-1 text-xl font-bold tabular-nums">{{ n(k.value) }}</p>
                    <p v-if="k.hint" class="text-2xs text-muted-foreground">{{ k.hint }}</p>
                </li>
            </ul>

            <!-- Filters -->
            <form
                class="grid grid-cols-2 gap-2 rounded-lg bg-card p-3 shadow-card md:grid-cols-4 xl:grid-cols-8"
                role="search"
                @submit.prevent="go()"
            >
                <div class="relative col-span-2">
                    <Search
                        class="pointer-events-none absolute start-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <label class="sr-only" for="mat-q">{{ t('ads.materials.filters.search') }}</label>
                    <input id="mat-q" v-model="f.q" type="search" :placeholder="t('ads.materials.filters.search')" :class="cn(fieldClass, 'ps-8')" />
                </div>
                <div>
                    <label class="sr-only" for="mat-status">{{ t('ads.materials.filters.status') }}</label>
                    <select id="mat-status" v-model="f.status" :class="fieldClass" @change="go()">
                        <option value="">{{ t('ads.materials.filters.all_status') }}</option>
                        <option v-for="s in STATUSES" :key="s" :value="s">{{ t(`ads.materials.status.${s}`) }}</option>
                    </select>
                </div>
                <div>
                    <label class="sr-only" for="mat-stock">{{ t('ads.materials.filters.stock') }}</label>
                    <select id="mat-stock" v-model="f.stock" :class="fieldClass" @change="go()">
                        <option value="">{{ t('ads.materials.filters.all_stock') }}</option>
                        <option v-for="s in STOCKS" :key="s" :value="s">{{ t(`ads.materials.stock.${s}`) }}</option>
                    </select>
                </div>
                <div>
                    <label class="sr-only" for="mat-collection">{{ t('ads.materials.filters.collection') }}</label>
                    <select id="mat-collection" v-model="f.collection" :class="fieldClass" @change="go()">
                        <option value="">{{ t('ads.materials.filters.all_collections') }}</option>
                        <option v-for="c in collections" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
                    </select>
                </div>
                <div>
                    <label class="sr-only" for="mat-type">{{ t('ads.materials.filters.type') }}</label>
                    <select id="mat-type" v-model="f.type" :class="fieldClass" @change="go()">
                        <option value="">{{ t('ads.materials.filters.all_types') }}</option>
                        <option v-for="ty in TYPES" :key="ty" :value="ty">{{ t(`ads.materials.type.${ty}`) }}</option>
                    </select>
                </div>
                <div class="flex items-center gap-1">
                    <label class="shrink-0 text-2xs text-muted-foreground" for="mat-from">{{ t('ads.materials.filters.from') }}</label>
                    <input id="mat-from" v-model="f.from" type="date" dir="ltr" :class="fieldClass" @change="go()" />
                </div>
                <div class="flex items-center gap-1">
                    <label class="shrink-0 text-2xs text-muted-foreground" for="mat-to">{{ t('ads.materials.filters.to') }}</label>
                    <input id="mat-to" v-model="f.to" type="date" dir="ltr" :class="fieldClass" @change="go()" />
                </div>
                <div v-if="hasFilters" class="col-span-2 flex items-center md:col-span-4 xl:col-span-8">
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                        @click="clearFilters"
                    >
                        <X class="size-3.5" aria-hidden="true" />{{ t('ads.materials.filters.clear') }}
                    </button>
                </div>
            </form>

            <!-- Table -->
            <div class="scrollbar-thin overflow-x-auto rounded-lg bg-card shadow-card">
                <EmptyState
                    v-if="!page.data.length"
                    :icon="ImageOff"
                    :title="hasFilters ? t('ads.materials.empty_filtered') : t('ads.materials.empty')"
                    :body="perms.canAuthor.value && !hasFilters ? t('ads.materials.empty_body') : undefined"
                />
                <table v-else class="w-full min-w-[1200px] text-xs">
                    <caption class="sr-only">
                        {{
                            t('ads.materials.title')
                        }}
                    </caption>
                    <thead class="border-b border-border/60 text-2xs font-semibold text-muted-foreground">
                        <tr>
                            <th scope="col" class="px-3 py-2 text-start">{{ t('ads.materials.col.created') }}</th>
                            <th scope="col" class="px-2 py-2 text-start">{{ t('ads.materials.col.image') }}</th>
                            <th scope="col" class="px-2 py-2 text-start">{{ t('ads.materials.col.title') }}</th>
                            <th scope="col" class="px-2 py-2 text-start">{{ t('ads.materials.col.product') }}</th>
                            <th scope="col" class="px-2 py-2 text-start">{{ t('ads.materials.col.collections') }}</th>
                            <th scope="col" class="px-2 py-2 text-start">{{ t('ads.materials.col.links') }}</th>
                            <th scope="col" class="px-2 py-2 text-start">{{ t('ads.materials.col.types') }}</th>
                            <th scope="col" class="px-2 py-2 text-start">{{ t('ads.materials.col.status') }}</th>
                            <th scope="col" class="px-2 py-2 text-start">{{ t('ads.materials.col.stock') }}</th>
                            <th scope="col" class="px-2 py-2 text-start">{{ t('ads.materials.col.dates') }}</th>
                            <th v-if="showPerf" scope="col" class="px-2 py-2 text-end">{{ t('ads.materials.col.performance') }}</th>
                            <th scope="col" class="px-3 py-2 text-end">{{ t('ads.materials.col.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="m in page.data" :key="m.id" class="border-t border-border/60 align-top first:border-t-0 hover:bg-muted/40">
                            <td class="whitespace-nowrap px-3 py-2.5">
                                <p class="font-medium">{{ created(m.created_at).day }}</p>
                                <p class="text-2xs text-muted-foreground">{{ created(m.created_at).time }}</p>
                            </td>
                            <td class="px-2 py-2.5">
                                <button
                                    type="button"
                                    class="relative block size-14 overflow-hidden rounded-md bg-muted disabled:cursor-default"
                                    :disabled="!m.files?.length"
                                    :aria-label="t('ads.materials.gallery.open_for', { title: m.title })"
                                    @click="gallery = m"
                                >
                                    <img v-if="m.thumb_url" :src="m.thumb_url" alt="" loading="lazy" class="size-full object-cover" />
                                    <span v-else class="flex size-full items-center justify-center">
                                        <Play v-if="m.files_count" class="size-4 text-muted-foreground" aria-hidden="true" />
                                        <ImageOff v-else class="size-4 text-muted-foreground" aria-hidden="true" />
                                    </span>
                                    <span
                                        v-if="m.files_count > 1"
                                        class="absolute bottom-0.5 end-0.5 rounded bg-black/70 px-1 text-[10px] font-semibold tabular-nums text-white"
                                        >{{ n(m.files_count) }}</span
                                    >
                                </button>
                            </td>
                            <td class="max-w-56 px-2 py-2.5">
                                <p class="line-clamp-2 font-bold text-foreground" dir="auto">{{ m.title }}</p>
                                <p v-if="m.creator" class="mt-0.5 truncate text-2xs text-muted-foreground">
                                    {{ t('ads.materials.by', { name: m.creator.name }) }}
                                </p>
                                <p v-if="m.buyer" class="truncate text-2xs text-muted-foreground">
                                    {{ t('ads.materials.buyer_is', { name: m.buyer.name }) }}
                                </p>
                            </td>
                            <td class="max-w-48 px-2 py-2.5">
                                <template v-if="m.product">
                                    <p class="line-clamp-2 font-medium" dir="auto">{{ m.product.title }}</p>
                                    <Popover>
                                        <PopoverTrigger as-child>
                                            <button type="button" class="mt-0.5 inline-flex items-center gap-1 text-2xs text-primary hover:underline">
                                                <Info class="size-3" aria-hidden="true" />{{ t('ads.materials.product.details') }}
                                            </button>
                                        </PopoverTrigger>
                                        <PopoverContent class="w-72 space-y-2 text-xs">
                                            <div class="flex items-center gap-2">
                                                <img
                                                    v-if="safeUrl(m.product.image_url)"
                                                    :src="safeUrl(m.product.image_url) ?? undefined"
                                                    alt=""
                                                    class="size-12 rounded object-cover"
                                                    loading="lazy"
                                                />
                                                <span v-else class="flex size-12 items-center justify-center rounded bg-muted">
                                                    <Package class="size-4 text-muted-foreground" aria-hidden="true" />
                                                </span>
                                                <p class="min-w-0 flex-1 font-semibold" dir="auto">{{ m.product.title }}</p>
                                            </div>
                                            <p>
                                                {{ t('ads.materials.product.inventory', { n: n(m.product.inventory) }) }}
                                            </p>
                                            <table v-if="m.product.variants?.length" class="w-full text-2xs">
                                                <caption class="sr-only">
                                                    {{
                                                        t('ads.materials.product.variants')
                                                    }}
                                                </caption>
                                                <thead class="text-muted-foreground">
                                                    <tr>
                                                        <th scope="col" class="py-1 text-start font-medium">
                                                            {{ t('ads.materials.product.variants') }}
                                                        </th>
                                                        <th scope="col" class="py-1 text-end font-medium">
                                                            {{ t('ads.materials.stock_page.quantity') }}
                                                        </th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr v-for="v in m.product.variants" :key="v.id" class="border-t border-border/60">
                                                        <td class="py-1" dir="auto">{{ v.title ?? '—' }}</td>
                                                        <td
                                                            class="py-1 text-end tabular-nums"
                                                            :class="v.inventory > 0 ? '' : 'font-semibold text-destructive'"
                                                        >
                                                            {{ n(v.inventory) }}
                                                        </td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                            <p v-if="m.need_stop" class="font-medium text-destructive">{{ t('ads.materials.need_stop_hint') }}</p>
                                            <a
                                                v-for="(url, i) in links(m.website_links).map(safeUrl).filter(Boolean)"
                                                :key="i"
                                                :href="url ?? undefined"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="flex items-center gap-1 text-primary hover:underline"
                                            >
                                                <Globe class="size-3.5" aria-hidden="true" />{{ t('ads.materials.links.website') }}
                                            </a>
                                        </PopoverContent>
                                    </Popover>
                                </template>
                                <span v-else class="text-muted-foreground">{{ t('ads.materials.product.none') }}</span>
                            </td>
                            <td class="max-w-44 px-2 py-2.5">
                                <div class="flex flex-wrap gap-1">
                                    <span
                                        v-for="c in m.collections"
                                        :key="c.id"
                                        class="inline-flex h-5 items-center rounded-full bg-primary/10 px-2 text-2xs font-medium text-primary"
                                        dir="auto"
                                        >{{ c.name }}</span
                                    >
                                    <span v-if="!m.collections.length" class="text-muted-foreground">—</span>
                                </div>
                            </td>
                            <td class="px-2 py-2.5">
                                <div class="flex flex-wrap gap-1">
                                    <a
                                        v-for="(url, i) in links(m.drive_links).map(safeUrl).filter(Boolean)"
                                        :key="`d${i}`"
                                        :href="url ?? undefined"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        :class="cn(iconBtn, 'border-border text-muted-foreground hover:bg-muted hover:text-primary')"
                                        :aria-label="t('ads.materials.links.drive_n', { n: i + 1 })"
                                        :title="t('ads.materials.links.drive_n', { n: i + 1 })"
                                    >
                                        <HardDrive class="size-4" aria-hidden="true" />
                                    </a>
                                    <a
                                        v-for="(url, i) in links(m.ig_links).map(safeUrl).filter(Boolean)"
                                        :key="`i${i}`"
                                        :href="url ?? undefined"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        :class="cn(iconBtn, 'border-border text-muted-foreground hover:bg-muted hover:text-primary')"
                                        :aria-label="t('ads.materials.links.ig_n', { n: i + 1 })"
                                        :title="t('ads.materials.links.ig_n', { n: i + 1 })"
                                    >
                                        <Instagram class="size-4" aria-hidden="true" />
                                    </a>
                                    <span v-if="!links(m.drive_links).length && !links(m.ig_links).length" class="text-muted-foreground">—</span>
                                </div>
                            </td>
                            <td class="px-2 py-2.5">
                                <div class="flex flex-wrap gap-1">
                                    <StatusChip v-for="ty in m.types" :key="ty" :label="typeLabel(ty)" tone="info" />
                                </div>
                            </td>
                            <td class="px-2 py-2.5"><MaterialStatusChip :status="m.status" /></td>
                            <td class="px-2 py-2.5">
                                <div class="flex flex-col items-start gap-1">
                                    <span
                                        class="inline-flex h-5 items-center whitespace-nowrap rounded-full px-2 text-2xs font-medium"
                                        :class="stockChip(m).cls"
                                        >{{ stockChip(m).label }}</span
                                    >
                                    <StatusChip v-if="m.need_stop" :label="t('ads.materials.need_stop')" tone="negative" dot />
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-2 py-2.5 text-2xs">
                                <p
                                    v-if="m.activated_at"
                                    class="flex items-center gap-1 text-emerald-700 dark:text-emerald-300"
                                    :title="t('ads.materials.activated_on')"
                                >
                                    <ArrowUp class="size-3" aria-hidden="true" />{{ formatDate(m.activated_at, locale) }}
                                </p>
                                <p v-if="m.done_at" class="flex items-center gap-1 text-muted-foreground" :title="t('ads.materials.done_on')">
                                    <Square class="size-3" aria-hidden="true" />{{ formatDate(m.done_at, locale) }}
                                </p>
                                <span v-if="!m.activated_at && !m.done_at" class="text-muted-foreground">—</span>
                            </td>
                            <td v-if="showPerf" class="px-2 py-2.5 text-end">
                                <div v-if="m.performance && m.ads.length" class="flex flex-col items-end gap-1">
                                    <MoneyCell :amount="m.performance.spend" :with-tax="m.performance.spend_tax" />
                                    <StatusChip :label="formatRoas(m.performance.roas, locale)" :tone="roasTone(m.performance.roas)" />
                                    <WinnerBadge v-if="m.performance.winner_tier" :tier="m.performance.winner_tier" />
                                    <span class="text-2xs text-muted-foreground">{{ t('ads.materials.ads_n', { n: n(m.ads.length) }) }}</span>
                                </div>
                                <span v-else class="text-2xs text-muted-foreground">{{ t('ads.materials.no_ads') }}</span>
                            </td>
                            <td class="px-3 py-2.5">
                                <div class="flex flex-wrap justify-end gap-1">
                                    <template v-if="perms.canOperate.value">
                                        <button
                                            v-if="m.status !== 'activated'"
                                            type="button"
                                            :class="
                                                cn(
                                                    iconBtn,
                                                    'border-success/40 bg-success/10 text-emerald-700 hover:bg-success/20 dark:text-emerald-300',
                                                )
                                            "
                                            :disabled="busyId === m.id"
                                            :aria-label="t('ads.materials.actions.activate')"
                                            :title="t('ads.materials.actions.activate')"
                                            @click="setStatus(m, 'activated')"
                                        >
                                            <Play class="size-4" aria-hidden="true" />
                                        </button>
                                        <button
                                            v-else
                                            type="button"
                                            :class="cn(iconBtn, 'border-foreground/20 bg-foreground text-background hover:bg-foreground/85')"
                                            :disabled="busyId === m.id"
                                            :aria-label="t('ads.materials.actions.done')"
                                            :title="t('ads.materials.actions.done')"
                                            @click="setStatus(m, 'done')"
                                        >
                                            <Square class="size-4" aria-hidden="true" />
                                        </button>
                                    </template>
                                    <button
                                        v-if="m.status !== 'not_started' && (perms.canOperate.value || perms.isContent.value)"
                                        type="button"
                                        :class="cn(iconBtn, 'border-border text-muted-foreground hover:bg-muted hover:text-foreground')"
                                        :disabled="busyId === m.id"
                                        :aria-label="t('ads.materials.actions.reset')"
                                        :title="t('ads.materials.actions.reset')"
                                        @click="setStatus(m, 'not_started')"
                                    >
                                        <RotateCcw class="size-4" aria-hidden="true" />
                                    </button>
                                    <button
                                        v-if="perms.canOperate.value"
                                        type="button"
                                        :class="cn(iconBtn, 'border-primary/30 text-primary hover:bg-primary/10')"
                                        :aria-label="t('ads.materials.actions.link')"
                                        :title="t('ads.materials.actions.link')"
                                        @click="linking = m"
                                    >
                                        <Link2 class="size-4" aria-hidden="true" />
                                    </button>
                                    <Link
                                        v-if="perms.canAuthor.value"
                                        :href="`/ads/materials/${m.id}/edit`"
                                        :class="cn(iconBtn, 'border-warning/50 bg-warning/15 text-amber-800 hover:bg-warning/25 dark:text-amber-200')"
                                        :aria-label="t('ads.materials.actions.edit')"
                                        :title="t('ads.materials.actions.edit')"
                                    >
                                        <Pencil class="size-4" aria-hidden="true" />
                                    </Link>
                                    <button
                                        v-if="perms.canDelete(m)"
                                        type="button"
                                        :class="cn(iconBtn, 'border-destructive/30 text-destructive hover:bg-destructive/10')"
                                        :aria-label="t('ads.materials.actions.delete')"
                                        :title="t('ads.materials.actions.delete')"
                                        @click="deleting = m"
                                    >
                                        <Trash2 class="size-4" aria-hidden="true" />
                                    </button>
                                </div>
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

        <MaterialLightbox v-model:open="galleryOpen" :title="gallery?.title ?? ''" :files="gallery?.files ?? []" />

        <Dialog v-model:open="linkOpen">
            <DialogContent class="max-h-[90svh] overflow-y-auto sm:max-w-lg">
                <DialogTitle class="text-base">{{ t('ads.materials.link.title') }}</DialogTitle>
                <DialogDescription class="text-xs" dir="auto">{{ linking?.title }}</DialogDescription>
                <AdLinkPicker v-if="linking" :material-id="linking.id" :ads="linking.ads" @saved="linking = null" />
            </DialogContent>
        </Dialog>

        <FormDialog
            v-model:open="deleteOpen"
            :title="t('ads.materials.delete_title')"
            :description="deleting ? t('ads.materials.delete_body', { title: deleting.title }) : undefined"
            :submit-label="t('ads.materials.actions.delete')"
            :busy="deleteBusy"
            destructive
            @submit="confirmDelete"
        />
    </AppLayout>
</template>
