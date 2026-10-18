<script setup lang="ts">
import SectionTabs from '@/components/crm/SectionTabs.vue';
import { sectionTabs } from '@/lib/sectionTabs';
/** Ads Hub — مكتبة مواد الإعلانات: what the content team uploaded, its status, stock and (for spend viewers) performance (spec §8.7). */
import AdLinkPicker from '@/components/ads/AdLinkPicker.vue';
import CaptionsDialog from '@/components/ads/CaptionsDialog.vue';
import LaunchEditor from '@/components/ads/launch/LaunchEditor.vue';
import LaunchStateChip from '@/components/ads/launch/LaunchStateChip.vue';
import MaterialLightbox from '@/components/ads/MaterialLightbox.vue';
import MaterialStatusChip from '@/components/ads/MaterialStatusChip.vue';
import MoneyCell from '@/components/ads/MoneyCell.vue';
import PublicationsList from '@/components/ads/PublicationsList.vue';
import PublishDialog from '@/components/ads/PublishDialog.vue';
import ViewSwitch from '@/components/ads/ui/ViewSwitch.vue';
import WinnerBadge from '@/components/ads/WinnerBadge.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import FormDialog from '@/components/crm/FormDialog.vue';
import IconAction from '@/components/crm/IconAction.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { buttonVariants } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatAdsMoney, formatRoas, roasTone, safeUrl } from '@/lib/ads';
import { ADS_FIELD } from '@/lib/adsUi';
import { cleanQuery, links, MATERIAL_STATUSES, pageList, queryString, reviewQueueLink, statusCounts, useMaterialPermissions } from '@/lib/adsMaterials';
import { formatClock, formatCount, formatDate } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { AdsMaterialsIndexProps, MaterialRow, MaterialStatus, PublishCaption } from '@/types/ads';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useMediaQuery, useStorage } from '@vueuse/core';
import {
    ArrowUp,
    ChevronLeft,
    ChevronRight,
    CircleCheckBig,
    CirclePause,
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
    ListChecks,
    Package,
    PackageCheck,
    PackageX,
    Pencil,
    Play,
    Plus,
    Rocket,
    Search,
    SearchCheck,
    Send,
    Sparkles,
    Square,
    Table2,
    Trash2,
    X,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, reactive, ref, watch, type Component } from 'vue';

const props = defineProps<AdsMaterialsIndexProps>();

const { t, locale } = useI18n();
const toast = useToast();
const perms = useMaterialPermissions();
/** The buyer's drafts to review live on /ads/launches (final review C1). */
const reviewQueue = computed(() => reviewQueueLink(usePage().props.adsCounters as { buyer_review?: number } | null | undefined));
const n = (v: number) => formatCount(v, locale.value);

const STATUSES: MaterialStatus[] = MATERIAL_STATUSES;
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
    /** Set when the label is not `ads.materials.kpi.<key>` (the status tiles). */
    label?: string;
}
/** Status tiles carry the filter's status names (جديدة / في المراجعة / شغالة / واقفة / خلصت), in its order. */
const STATUS_TILE: Record<MaterialStatus, Pick<Kpi, 'icon' | 'border' | 'tint'>> = {
    new: { icon: Hourglass, border: 'border-t-warning', tint: 'text-warn' },
    in_review: { icon: SearchCheck, border: 'border-t-info', tint: 'text-info' },
    live: { icon: CirclePlay, border: 'border-t-success', tint: 'text-ok' },
    paused: { icon: CirclePause, border: 'border-t-muted-foreground', tint: 'text-fg-3' },
    retired: { icon: CircleCheckBig, border: 'border-t-info', tint: 'text-info' },
};
const kpis = computed<Kpi[]>(() => [
    { key: 'total', value: props.stats.total, icon: Layers, border: 'border-t-primary', tint: 'text-brand-ink' },
    ...statusCounts(props.stats).map((c) => ({
        key: `status-${c.status}`,
        label: t(`ads.materials.status.${c.status}`),
        value: c.value,
        ...STATUS_TILE[c.status],
    })),
    {
        key: 'reels',
        value: props.stats.reels,
        icon: Clapperboard,
        border: 'border-t-fuchsia-500',
        tint: 'text-brand-ink',
    },
    {
        key: 'posts',
        value: props.stats.posts,
        icon: LayoutGrid,
        border: 'border-t-violet-500',
        tint: 'text-brand-ink',
        hint: t('ads.materials.kpi.carousels_hint', { n: n(props.stats.carousels) }),
    },
    {
        key: 'in_stock',
        value: props.stats.in_stock,
        icon: PackageCheck,
        border: 'border-t-cyan-500',
        tint: 'text-ok',
    },
    {
        key: 'out_of_stock',
        value: props.stats.out_of_stock,
        icon: PackageX,
        border: 'border-t-destructive',
        tint: 'text-danger',
        hint: t('ads.materials.kpi.need_stop_hint', { n: n(props.stats.need_stop) }),
    },
]);

/* ---- rows ---- */
const page = computed(() => props.materials);
const pages = computed(() => pageList(page.value.current_page, page.value.last_page));

const created = (iso: string | null) => ({ day: formatDate(iso, locale.value), time: formatClock(iso, locale.value) });

const stockChip = (m: MaterialRow) =>
    m.stock === 'in'
        ? { label: t('ads.materials.stock.in'), tone: 'ok' as const }
        : m.stock === 'out'
          ? { label: t('ads.materials.stock.out'), tone: 'danger' as const }
          : { label: t('ads.materials.stock.none'), tone: 'neutral' as const };
const STOCK_TONE = { ok: 'bg-ok-soft text-ok', danger: 'bg-danger-soft text-danger', neutral: 'bg-sunken text-fg-2' } as const;

const typeLabel = (type: string) => (TYPES.includes(type as (typeof TYPES)[number]) ? t(`ads.materials.type.${type}`) : type);

/* ---- gallery ---- */
const gallery = ref<MaterialRow | null>(null);
const galleryOpen = computed({ get: () => gallery.value !== null, set: (v) => !v && (gallery.value = null) });

/* ---- linking ads ---- */
const linking = ref<MaterialRow | null>(null);
const linkOpen = computed({ get: () => linking.value !== null, set: (v) => !v && (linking.value = null) });

/* ---- publish as paused ads ---- */
const publishing = ref<MaterialRow | null>(null);
const publishCaptions = ref<PublishCaption[] | null>(null);
const publishFileIds = ref<number[] | null>(null);
const publishOpen = computed({
    get: () => publishing.value !== null,
    set: (v) => {
        if (!v) {
            publishing.value = null;
            publishCaptions.value = null;
            publishFileIds.value = null;
        }
    },
});

/* ---- AI captions ---- */
const captioning = ref<MaterialRow | null>(null);
const captionsOpen = computed({ get: () => captioning.value !== null, set: (v) => !v && (captioning.value = null) });
function createFromCaptions(captions: PublishCaption[], fileId: number): void {
    publishCaptions.value = captions;
    publishFileIds.value = [fileId];
    publishing.value = captioning.value;
    captioning.value = null;
}
const publications = ref<MaterialRow | null>(null);
const publicationsOpen = computed({ get: () => publications.value !== null, set: (v) => !v && (publications.value = null) });

/* ---- launches (S1) ---- */
const preparing = ref<MaterialRow | null>(null);
const prepareOpen = computed({ get: () => preparing.value !== null, set: (v) => !v && (preparing.value = null) });
function launchSaved(): void {
    router.reload({ only: ['materials', 'stats'] });
}

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

/* ---- view: cards (the mockup's library) or the full table; a per-viewer choice, phones always get cards ---- */
const view = useStorage<'cards' | 'table'>('ads.materials.view', 'cards');
const phone = useMediaQuery('(max-width: 767px)');
const shownView = computed(() => (phone.value ? 'cards' : view.value));
const viewOptions = computed(() => [
    { key: 'cards', label: t('ads.control.view.cards'), icon: LayoutGrid },
    { key: 'table', label: t('ads.control.view.table'), icon: Table2 },
]);
const fieldClass = ADS_FIELD;
const iconBtn = 'inline-flex size-8 items-center justify-center rounded-md border transition-colors disabled:opacity-50';

const breadcrumbs = computed(() => [
    { title: t('nav.ads'), href: '/ads/materials' },
    { title: t('nav.ads_materials'), href: '/ads/materials' },
]);
</script>

<template>
    <Head :title="t('ads.materials.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full min-w-0 max-w-[1400px] space-y-5 p-4 md:p-6">
            <PageHeader :title="t('ads.materials.title')" :description="t('ads.materials.description')">
                <Link
                    v-if="reviewQueue"
                    :href="reviewQueue.href"
                    :class="cn(buttonVariants({ variant: 'default', size: 'sm' }), 'gap-1.5')"
                    data-test="review-queue-link"
                >
                    {{ t('ads.materials.review_queue', { n: n(reviewQueue.count) }) }}
                </Link>
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
            <SectionTabs :tabs="sectionTabs.library(t)" :label="t('section_tabs.library.label')" />

            <!-- KPIs (whole library): one ledger panel, hairline dividers -->
            <ul class="grid grid-cols-2 gap-px overflow-hidden rounded-lg border border-line bg-line sm:grid-cols-5" data-test="material-kpis">
                <li v-for="k in kpis" :key="k.key" class="min-w-0 bg-card px-3.5 py-3">
                    <p class="flex items-center gap-1.5 text-[12.5px] font-medium text-fg-2">
                        <component :is="k.icon" class="size-3.5 shrink-0" :class="k.tint" aria-hidden="true" />
                        <span class="truncate">{{ k.label ?? t(`ads.materials.kpi.${k.key}`) }}</span>
                    </p>
                    <p class="mt-0.5 text-xl font-bold leading-snug tabular-nums">{{ n(k.value) }}</p>
                    <p v-if="k.hint" class="truncate text-xs text-fg-3">{{ k.hint }}</p>
                </li>
            </ul>

            <!-- Filters (DESIGN §3 FilterBar): search + the selects inline, the dates after them -->
            <form class="flex flex-wrap items-center gap-2" role="search" @submit.prevent="go()">
                <div class="relative w-full sm:w-64">
                    <Search class="pointer-events-none absolute start-2.5 top-1/2 size-4 -translate-y-1/2 text-fg-3" aria-hidden="true" />
                    <label class="sr-only" for="mat-q">{{ t('ads.materials.filters.search') }}</label>
                    <input id="mat-q" v-model="f.q" type="search" :placeholder="t('ads.materials.filters.search')" :class="cn(fieldClass, 'w-full ps-8')" />
                </div>
                <label class="sr-only" for="mat-status">{{ t('ads.materials.filters.status') }}</label>
                <select id="mat-status" v-model="f.status" :class="cn(fieldClass, f.status && 'border-brand bg-brand-soft text-brand-ink')" @change="go()">
                    <option value="">{{ t('ads.materials.filters.all_status') }}</option>
                    <option v-for="s in STATUSES" :key="s" :value="s">{{ t(`ads.materials.status.${s}`) }}</option>
                </select>
                <label class="sr-only" for="mat-stock">{{ t('ads.materials.filters.stock') }}</label>
                <select id="mat-stock" v-model="f.stock" :class="cn(fieldClass, f.stock && 'border-brand bg-brand-soft text-brand-ink')" @change="go()">
                    <option value="">{{ t('ads.materials.filters.all_stock') }}</option>
                    <option v-for="s in STOCKS" :key="s" :value="s">{{ t(`ads.materials.stock.${s}`) }}</option>
                </select>
                <label class="sr-only" for="mat-collection">{{ t('ads.materials.filters.collection') }}</label>
                <select id="mat-collection" v-model="f.collection" :class="cn(fieldClass, f.collection && 'border-brand bg-brand-soft text-brand-ink')" @change="go()">
                    <option value="">{{ t('ads.materials.filters.all_collections') }}</option>
                    <option v-for="c in collections" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
                </select>
                <label class="sr-only" for="mat-type">{{ t('ads.materials.filters.type') }}</label>
                <select id="mat-type" v-model="f.type" :class="cn(fieldClass, f.type && 'border-brand bg-brand-soft text-brand-ink')" @change="go()">
                    <option value="">{{ t('ads.materials.filters.all_types') }}</option>
                    <option v-for="ty in TYPES" :key="ty" :value="ty">{{ t(`ads.materials.type.${ty}`) }}</option>
                </select>
                <span class="flex items-center gap-1.5">
                    <label class="shrink-0 text-xs text-fg-2" for="mat-from">{{ t('ads.materials.filters.from') }}</label>
                    <input id="mat-from" v-model="f.from" type="date" dir="ltr" :class="fieldClass" @change="go()" />
                </span>
                <span class="flex items-center gap-1.5">
                    <label class="shrink-0 text-xs text-fg-2" for="mat-to">{{ t('ads.materials.filters.to') }}</label>
                    <input id="mat-to" v-model="f.to" type="date" dir="ltr" :class="fieldClass" @change="go()" />
                </span>
                <button
                    v-if="hasFilters"
                    type="button"
                    class="inline-flex h-9 items-center gap-1 rounded-md px-2 text-[13px] font-medium text-fg-2 hover:bg-sunken hover:text-foreground"
                    @click="clearFilters"
                >
                    <X class="size-3.5" aria-hidden="true" />{{ t('ads.materials.filters.clear') }}
                </button>
                <span class="ms-auto max-md:hidden">
                    <ViewSwitch :options="viewOptions" :current="view" :label="t('ads.control.view.label')" @select="(v) => (view = v as 'cards' | 'table')" />
                </span>
            </form>

            <EmptyState
                v-if="!page.data.length"
                :icon="ImageOff"
                :title="hasFilters ? t('ads.materials.empty_filtered') : t('ads.materials.empty')"
                :body="perms.canAuthor.value && !hasFilters ? t('ads.materials.empty_body') : undefined"
            />

            <!-- Cards (DESIGN mgrid): the picture at 4:5 like the clothes, the state and the actions under it -->
            <ul v-else-if="shownView === 'cards'" class="grid grid-cols-2 gap-3.5 sm:grid-cols-[repeat(auto-fill,minmax(220px,1fr))]" data-test="material-cards">
                <li v-for="m in page.data" :key="m.id" class="flex min-w-0 flex-col overflow-hidden rounded-lg border border-line bg-card">
                    <button
                        type="button"
                        class="relative block aspect-[4/5] w-full overflow-hidden bg-sunken focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring disabled:cursor-default"
                        :disabled="!m.files?.length"
                        :aria-label="t('ads.materials.gallery.open_for', { title: m.title })"
                        @click="gallery = m"
                    >
                        <img v-if="m.thumb_url" :src="m.thumb_url" alt="" loading="lazy" class="size-full object-cover" />
                        <span v-else class="flex size-full items-center justify-center">
                            <Play v-if="m.files_count" class="size-6 text-fg-3" aria-hidden="true" />
                            <ImageOff v-else class="size-6 text-fg-3" aria-hidden="true" />
                        </span>
                        <span class="absolute start-2 top-2 flex flex-wrap gap-1">
                            <span v-for="ty in m.types.slice(0, 2)" :key="ty" class="rounded-full bg-card/90 px-2 py-0.5 text-[11.5px] font-semibold text-foreground">{{ typeLabel(ty) }}</span>
                        </span>
                        <span v-if="m.files_count > 1" class="absolute bottom-2 end-2 rounded-full bg-black/70 px-2 py-0.5 text-[11.5px] font-semibold tabular-nums text-white">{{
                            t('ads.materials.files_n', { n: n(m.files_count) })
                        }}</span>
                    </button>
                    <div class="flex flex-1 flex-col gap-1.5 px-3 pb-2 pt-2.5">
                        <p class="line-clamp-2 text-[13.5px] font-semibold text-foreground" dir="auto">{{ m.title }}</p>
                        <p class="truncate text-xs text-fg-2" dir="auto">{{ m.product?.title ?? t('ads.materials.product.none') }}</p>
                        <div class="flex flex-wrap items-center gap-1">
                            <MaterialStatusChip :status="m.status" />
                            <StatusChip :label="stockChip(m).label" :tone="stockChip(m).tone" size="sm" />
                            <StatusChip v-if="m.need_stop" :label="t('ads.materials.need_stop')" tone="danger" size="sm" dot />
                        </div>
                        <ul v-if="m.launches?.length" class="space-y-0.5">
                            <li v-for="l in m.launches.slice(0, 2)" :key="l.id">
                                <Link :href="`/ads/launches?material=${m.id}&box=all&launch=${l.id}`" class="inline-flex max-w-full items-center gap-1 text-xs hover:underline">
                                    <LaunchStateChip :state="l.state" /><span class="truncate text-fg-3">{{ l.adset }}</span>
                                </Link>
                            </li>
                        </ul>
                        <p v-if="showPerf && m.performance && m.ads.length" class="flex items-center justify-between gap-2 text-xs tabular-nums text-fg-2">
                            <span>{{ formatAdsMoney(m.performance.spend_tax, locale) }}</span>
                            <StatusChip :label="formatRoas(m.performance.roas, locale)" :tone="roasTone(m.performance.roas)" size="sm" />
                        </p>
                        <p class="mt-auto flex items-center justify-between gap-2 pt-1 text-xs text-fg-3">
                            <span v-if="m.creator" class="truncate">{{ m.creator.name }}</span>
                            <span class="shrink-0 tabular-nums">{{ created(m.created_at).day }}</span>
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center justify-end gap-0.5 border-t border-line px-1.5 py-1">
                        <IconAction v-if="m.files?.some((f) => f.mime?.startsWith('video/'))" :icon="Sparkles" variant="primary" :label="t('ads.captions.button')" @click="captioning = m" />
                        <IconAction v-if="perms.canPrepare.value" :icon="Rocket" variant="primary" :label="t('ads.launch.prepare')" :disabled="!m.files?.length || !m.product" @click="preparing = m" />
                        <IconAction v-if="perms.canDirectPublish.value" :icon="Send" variant="primary" :label="t('ads.publish.button')" :disabled="!m.files?.length" @click="publishing = m" />
                        <IconAction v-if="perms.canOperate.value" :icon="ListChecks" :label="t('ads.publish.publications')" @click="publications = m" />
                        <IconAction v-if="perms.canOperate.value" :icon="Link2" variant="primary" :label="t('ads.materials.actions.link')" @click="linking = m" />
                        <IconAction v-if="perms.canAuthor.value" :icon="Pencil" :href="`/ads/materials/${m.id}/edit`" :label="t('ads.materials.actions.edit')" />
                        <IconAction v-if="perms.canDelete(m)" :icon="Trash2" variant="destructive" :label="t('ads.materials.actions.delete')" @click="deleting = m" />
                    </div>
                </li>
            </ul>

            <!-- Table -->
            <div v-else class="scrollbar-thin table-scroll-box relative rounded-lg border border-line bg-card [contain:inline-size]">
                <table class="w-full min-w-[1200px] text-[13px]">
                    <caption class="sr-only">
                        {{
                            t('ads.materials.title')
                        }}
                    </caption>
                    <thead class="crm-sticky-head border-b border-line bg-surface-2 text-[13px] font-semibold text-fg-2">
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
                        <tr v-for="m in page.data" :key="m.id" class="border-t border-line align-top first:border-t-0 hover:bg-surface-2">
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
                                        class="inline-flex h-6 items-center rounded-full bg-brand-soft px-2 text-xs font-medium text-brand-ink"
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
                            <td class="px-2 py-2.5">
                                <MaterialStatusChip :status="m.status" />
                                <ul v-if="m.launches?.length" class="mt-1 space-y-0.5">
                                    <li v-for="l in m.launches" :key="l.id">
                                        <Link
                                            :href="`/ads/launches?material=${m.id}&box=all&launch=${l.id}`"
                                            class="inline-flex items-center gap-1 text-2xs hover:underline"
                                        >
                                            <LaunchStateChip :state="l.state" /><span class="truncate text-muted-foreground">{{ l.adset }}</span>
                                        </Link>
                                    </li>
                                </ul>
                            </td>
                            <td class="px-2 py-2.5">
                                <div class="flex flex-col items-start gap-1">
                                    <span
                                        class="inline-flex h-5 items-center whitespace-nowrap rounded-full px-2 text-2xs font-medium"
                                        :class="STOCK_TONE[stockChip(m).tone]"
                                        >{{ stockChip(m).label }}</span
                                    >
                                    <StatusChip v-if="m.need_stop" :label="t('ads.materials.need_stop')" tone="negative" dot />
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-2 py-2.5 text-2xs">
                                <p
                                    v-if="m.activated_at"
                                    class="flex items-center gap-1 text-ok"
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
                                    <!-- No dead end (U 1.1): the linked ads open in the explorer. -->
                                    <Link
                                        :href="`/ads/explorer?status=all&q=${encodeURIComponent(m.ads[0].name)}`"
                                        class="text-2xs text-primary hover:underline"
                                        :title="t('ads.control.today.see_all')"
                                    >
                                        {{ t('ads.materials.ads_n', { n: n(m.ads.length) }) }}
                                    </Link>
                                </div>
                                <span v-else class="text-2xs text-muted-foreground">{{ t('ads.materials.no_ads') }}</span>
                            </td>
                            <td class="px-3 py-2.5">
                                <div class="flex flex-wrap justify-end gap-0.5">
                                    <IconAction v-if="m.files?.some((f) => f.mime?.startsWith('video/'))" :icon="Sparkles" variant="primary" :label="t('ads.captions.button')" @click="captioning = m" />
                                    <IconAction v-if="perms.canPrepare.value" :icon="Rocket" variant="primary" :label="t('ads.launch.prepare')" :disabled="!m.files?.length || !m.product" @click="preparing = m" />
                                    <IconAction v-if="perms.canDirectPublish.value" :icon="Send" variant="primary" :label="t('ads.publish.button')" :disabled="!m.files?.length" @click="publishing = m" />
                                    <IconAction v-if="perms.canOperate.value" :icon="ListChecks" :label="t('ads.publish.publications')" @click="publications = m" />
                                    <IconAction v-if="perms.canOperate.value" :icon="Link2" variant="primary" :label="t('ads.materials.actions.link')" @click="linking = m" />
                                    <IconAction v-if="perms.canAuthor.value" :icon="Pencil" :href="`/ads/materials/${m.id}/edit`" :label="t('ads.materials.actions.edit')" />
                                    <IconAction v-if="perms.canDelete(m)" :icon="Trash2" variant="destructive" :label="t('ads.materials.actions.delete')" @click="deleting = m" />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

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

        <MaterialLightbox v-model:open="galleryOpen" :title="gallery?.title ?? ''" :files="gallery?.files ?? []" />

        <Dialog v-model:open="linkOpen">
            <DialogContent class="max-h-[90svh] overflow-y-auto sm:max-w-lg">
                <DialogTitle class="text-base">{{ t('ads.materials.link.title') }}</DialogTitle>
                <DialogDescription class="text-xs" dir="auto">{{ linking?.title }}</DialogDescription>
                <AdLinkPicker v-if="linking" :material-id="linking.id" :ads="linking.ads" @saved="linking = null" />
            </DialogContent>
        </Dialog>

        <CaptionsDialog
            v-if="captioning"
            v-model:open="captionsOpen"
            :material="captioning"
            :can-publish="perms.canDirectPublish.value"
            @create="createFromCaptions"
        />
        <PublishDialog
            v-if="publishing"
            v-model:open="publishOpen"
            :material="publishing"
            :captions="publishCaptions"
            :file-ids="publishFileIds"
            @published="publications = publishing"
        />

        <Dialog v-model:open="publicationsOpen">
            <DialogContent class="max-h-[90svh] overflow-y-auto sm:max-w-2xl">
                <DialogTitle class="text-base">{{ t('ads.publish.publications') }}</DialogTitle>
                <DialogDescription class="text-xs" dir="auto">{{ publications?.title }}</DialogDescription>
                <PublicationsList v-if="publications" :material-id="publications.id" />
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
        <LaunchEditor v-if="preparing" v-model:open="prepareOpen" :material="preparing" mode="content" @saved="launchSaved" />
    </AppLayout>
</template>
