<script setup lang="ts">
/** Ads Hub — الكرييتف الشغال: ads that ran in the range, Arena-style table with totals and the preview modal (spec §8.3). */
import AdsRangeBar from '@/components/ads/AdsRangeBar.vue';
import CreativePreviewModal from '@/components/ads/CreativePreviewModal.vue';
import CreativeThumb from '@/components/ads/CreativeThumb.vue';
import MoneyCell from '@/components/ads/MoneyCell.vue';
import PlatformChip from '@/components/ads/PlatformChip.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { type AdsQueryValue, adTypeKey, formatPct, formatQty, formatRoas, isAdActive, roasTone, safeUrl, visitAds } from '@/lib/ads';
import { formatCount } from '@/lib/format';
import type { AdsCreativesProps, CreativePerPage, CreativeRow, CreativeSort, CreativeStatusFilter } from '@/types/ads';
import { Head } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, ExternalLink, ImageOff, Search } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps<AdsCreativesProps>();

const { t, locale } = useI18n();
const n = (v: number) => formatCount(v, locale.value);

const SORTS: CreativeSort[] = ['spend', 'roas', 'ctr', 'impressions', 'clicks', 'purchases', 'date'];
const PER_PAGE: CreativePerPage[] = [10, 25, 50, 100];
const STATUSES: CreativeStatusFilter[] = ['all', 'active', 'inactive'];

/** The page's own params as applied by the server. */
const keep = computed<Record<string, AdsQueryValue>>(() => ({
    status: props.filters.status === 'all' ? null : props.filters.status,
    account: props.filters.account,
    sort: props.filters.sort === 'spend' ? null : props.filters.sort,
    per_page: props.filters.per_page === 25 ? null : props.filters.per_page,
    q: props.filters.q,
}));

function go(changes: Record<string, AdsQueryValue>, keepPage = false): void {
    const f = props.filters;
    visitAds({ from: f.from, to: f.to, platform: f.platform, buyer: f.buyer, ...keep.value, page: keepPage ? f.page : null, ...changes });
}

const search = ref(props.filters.q ?? '');
watch(
    () => props.filters.q,
    (q) => (search.value = q ?? ''),
);

const meta = computed(() => props.result.meta);
const rangeFrom = computed(() => (meta.value.total === 0 ? 0 : (meta.value.current_page - 1) * meta.value.per_page + 1));
const rangeTo = computed(() => Math.min(meta.value.total, meta.value.current_page * meta.value.per_page));
const totals = computed(() => props.result.totals);

/** Account chips (the server narrows the list to the picked account, so «all accounts» always stays first). */
const accountChips = computed(() => props.result.accounts);

const selected = ref<CreativeRow | null>(null);
const modalOpen = ref(false);
function openAd(ad: CreativeRow): void {
    selected.value = ad;
    modalOpen.value = true;
}
function onRowKey(e: KeyboardEvent, ad: CreativeRow): void {
    if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        openAd(ad);
    }
}

const chip = (on: boolean) =>
    on ? 'border-primary bg-primary text-primary-foreground' : 'border-border bg-background text-muted-foreground hover:text-foreground';
const selectClass = 'h-9 rounded-md border border-input bg-background px-2 text-xs';

const breadcrumbs = computed(() => [
    { title: t('nav.ads'), href: '/ads' },
    { title: t('nav.ads_creatives'), href: '/ads/creatives' },
]);
</script>

<template>
    <Head :title="t('ads.creatives.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-[1400px] space-y-4 p-3 md:p-6">
            <PageHeader :title="t('ads.creatives.title')" :description="t('ads.creatives.hint')">
                <AdsRangeBar :filters="filters" :platforms="platforms" :buyers="buyers" :keep="keep" />
            </PageHeader>

            <!-- Filters -->
            <div class="space-y-2 rounded-lg bg-card p-3 shadow-card">
                <div class="flex flex-wrap items-center gap-2">
                    <div class="flex flex-wrap items-center gap-1" role="group" :aria-label="t('ads.filters.status')">
                        <button
                            v-for="s in STATUSES"
                            :key="s"
                            type="button"
                            :aria-pressed="filters.status === s"
                            class="inline-flex h-8 items-center gap-1 rounded-full border px-3 text-xs font-medium transition-colors"
                            :class="chip(filters.status === s)"
                            @click="go({ status: s === 'all' ? null : s })"
                        >
                            {{ t(`ads.creatives.status_${s}`) }}
                            <span class="tabular-nums opacity-80">({{ n(result.counts[s]) }})</span>
                        </button>
                    </div>

                    <div class="ms-auto flex flex-wrap items-center gap-2">
                        <form class="relative" role="search" @submit.prevent="go({ q: search.trim() || null })">
                            <Search
                                class="pointer-events-none absolute start-2 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <label class="sr-only" for="creative-search">{{ t('ads.filters.search') }}</label>
                            <input
                                id="creative-search"
                                v-model="search"
                                type="search"
                                :placeholder="t('ads.filters.search')"
                                class="h-9 w-52 rounded-md border border-input bg-background pe-2 ps-7 text-xs"
                                @search="search === '' && filters.q && go({ q: null })"
                            />
                        </form>
                        <label class="sr-only" for="creative-sort">{{ t('ads.filters.sort') }}</label>
                        <select
                            id="creative-sort"
                            :value="filters.sort"
                            :class="selectClass"
                            @change="go({ sort: ($event.target as HTMLSelectElement).value })"
                        >
                            <option v-for="s in SORTS" :key="s" :value="s">{{ t('ads.filters.sort') }}: {{ t(`ads.sort.${s}`) }}</option>
                        </select>
                        <label class="sr-only" for="creative-per-page">{{ t('ads.filters.per_page') }}</label>
                        <select
                            id="creative-per-page"
                            :value="filters.per_page"
                            :class="selectClass"
                            @change="go({ per_page: Number(($event.target as HTMLSelectElement).value) })"
                        >
                            <option v-for="p in PER_PAGE" :key="p" :value="p">
                                {{ p === 100 ? t('ads.filters.per_page_all') : t('ads.filters.per_page_n', { n: p }) }}
                            </option>
                        </select>
                    </div>
                </div>

                <div
                    v-if="accountChips.length || filters.account"
                    class="flex flex-wrap items-center gap-1"
                    role="group"
                    :aria-label="t('ads.filters.account')"
                >
                    <button
                        type="button"
                        :aria-pressed="filters.account === null"
                        class="inline-flex h-7 items-center rounded-full border px-2.5 text-2xs font-medium transition-colors"
                        :class="chip(filters.account === null)"
                        @click="go({ account: null })"
                    >
                        {{ t('ads.filters.all_accounts') }}
                    </button>
                    <button
                        v-for="a in accountChips"
                        :key="a.id"
                        type="button"
                        :aria-pressed="filters.account === a.id"
                        class="inline-flex h-7 max-w-64 items-center gap-1 rounded-full border px-2.5 text-2xs font-medium transition-colors"
                        :class="chip(filters.account === a.id)"
                        @click="go({ account: filters.account === a.id ? null : a.id })"
                    >
                        <span class="truncate" dir="auto">{{ a.name }}</span>
                        <span class="tabular-nums opacity-80">({{ n(a.count) }})</span>
                    </button>
                </div>
            </div>

            <!-- Table -->
            <div class="scrollbar-thin overflow-x-auto rounded-lg bg-card shadow-card">
                <EmptyState v-if="!result.data.length" :icon="ImageOff" :title="t('ads.creatives.empty')" :body="t('ads.empty.body')" />
                <table v-else class="w-full min-w-[1100px] text-xs">
                    <caption class="sr-only">
                        {{
                            t('ads.creatives.title')
                        }}
                    </caption>
                    <thead class="border-b border-border/60 text-2xs font-semibold text-muted-foreground">
                        <tr>
                            <th scope="col" class="px-3 py-2 text-start">{{ t('ads.table.creative') }}</th>
                            <th scope="col" class="px-2 py-2 text-start">{{ t('ads.table.type') }}</th>
                            <th scope="col" class="px-2 py-2 text-start">{{ t('ads.table.platform') }}</th>
                            <th scope="col" class="px-2 py-2 text-start">{{ t('ads.table.account') }}</th>
                            <th scope="col" class="px-2 py-2 text-end">{{ t('ads.kpi.impressions') }}</th>
                            <th scope="col" class="px-2 py-2 text-end">{{ t('ads.kpi.clicks') }}</th>
                            <th scope="col" class="px-2 py-2 text-end">{{ t('ads.kpi.ctr') }}</th>
                            <th scope="col" class="px-2 py-2 text-end">{{ t('ads.table.conv') }}</th>
                            <th scope="col" class="px-2 py-2 text-end">{{ t('ads.kpi.spend_tax') }}</th>
                            <th scope="col" class="px-2 py-2 text-end">{{ t('ads.kpi.roas') }}</th>
                            <th scope="col" class="px-2 py-2 text-center">{{ t('ads.table.status') }}</th>
                            <th scope="col" class="px-2 py-2">
                                <span class="sr-only">{{ t('ads.table.open') }}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="ad in result.data"
                            :key="ad.id"
                            class="cursor-pointer border-t border-border/60 first:border-t-0 hover:bg-muted focus-visible:bg-muted"
                            tabindex="0"
                            @click="openAd(ad)"
                            @keydown="onRowKey($event, ad)"
                        >
                            <td class="px-3 py-2">
                                <div class="flex items-start gap-3">
                                    <CreativeThumb :ad="ad" :size="120" />
                                    <div class="min-w-0 max-w-72 space-y-0.5 pt-0.5">
                                        <p class="line-clamp-2 font-bold text-foreground" dir="auto">{{ ad.name }}</p>
                                        <p v-if="ad.headline" class="line-clamp-1 text-muted-foreground" dir="auto">«{{ ad.headline }}»</p>
                                        <p v-if="ad.adset" class="truncate text-2xs text-muted-foreground">
                                            {{ t('ads.table.adset') }}: <span dir="auto">{{ ad.adset }}</span>
                                        </p>
                                        <p v-if="ad.campaign" class="truncate text-2xs text-muted-foreground">
                                            {{ t('ads.table.campaign_short') }}: <span dir="auto">{{ ad.campaign }}</span>
                                        </p>
                                        <p v-if="ad.buyer" class="truncate text-2xs text-muted-foreground">
                                            {{ t('ads.table.buyer') }}: {{ ad.buyer }}
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-2 py-2"><StatusChip :label="t(`ads.type.${adTypeKey(ad.type)}`)" tone="info" /></td>
                            <td class="px-2 py-2"><PlatformChip :platform="ad.platform" size="xs" /></td>
                            <td class="max-w-40 truncate px-2 py-2" dir="auto">{{ ad.account }}</td>
                            <td class="px-2 py-2 text-end tabular-nums">{{ n(ad.impressions) }}</td>
                            <td class="px-2 py-2 text-end tabular-nums">{{ n(ad.clicks) }}</td>
                            <td class="px-2 py-2 text-end tabular-nums">{{ formatPct(ad.ctr, locale) }}</td>
                            <td class="px-2 py-2 text-end tabular-nums">{{ formatQty(ad.purchases, locale) }}</td>
                            <td class="px-2 py-2 text-end"><MoneyCell :amount="ad.spend" :with-tax="ad.spend_tax" :currency="currency" /></td>
                            <td class="px-2 py-2 text-end"><StatusChip :label="formatRoas(ad.roas, locale)" :tone="roasTone(ad.roas)" /></td>
                            <td class="px-2 py-2 text-center">
                                <StatusChip
                                    :label="isAdActive(ad) ? t('ads.status.active') : t('ads.status.inactive')"
                                    :tone="isAdActive(ad) ? 'positive' : 'neutral'"
                                    dot
                                />
                            </td>
                            <td class="px-2 py-2 text-center">
                                <a
                                    v-if="safeUrl(ad.permalink_url)"
                                    :href="safeUrl(ad.permalink_url) ?? undefined"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex size-8 items-center justify-center rounded-md text-muted-foreground hover:bg-background hover:text-primary"
                                    :aria-label="t('ads.preview.original_post')"
                                    :title="t('ads.preview.original_post')"
                                    @click.stop
                                >
                                    <ExternalLink class="size-4" aria-hidden="true" />
                                </a>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t-2 border-border bg-muted/40 font-semibold">
                        <tr>
                            <th scope="row" class="px-3 py-2 text-start">{{ t('ads.table.totals_n', { n: meta.total }) }}</th>
                            <td colspan="3" />
                            <td class="px-2 py-2 text-end tabular-nums">{{ n(totals.impressions) }}</td>
                            <td class="px-2 py-2 text-end tabular-nums">{{ n(totals.clicks) }}</td>
                            <td class="px-2 py-2 text-end tabular-nums">{{ formatPct(totals.ctr, locale) }}</td>
                            <td class="px-2 py-2 text-end tabular-nums">{{ formatQty(totals.purchases, locale) }}</td>
                            <td class="px-2 py-2 text-end"><MoneyCell :amount="totals.spend" :with-tax="totals.spend_tax" :currency="currency" /></td>
                            <td class="px-2 py-2 text-end"><StatusChip :label="formatRoas(totals.roas, locale)" :tone="roasTone(totals.roas)" /></td>
                            <td colspan="2" />
                        </tr>
                    </tfoot>
                </table>
            </div>

            <nav
                v-if="meta.total > 0"
                class="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground"
                :aria-label="t('ui.pagination')"
            >
                <span class="tabular-nums">{{ t('ui.page_summary', { from: rangeFrom, to: rangeTo, total: meta.total }) }}</span>
                <div v-if="meta.last_page > 1" class="flex items-center gap-1.5">
                    <button
                        type="button"
                        class="inline-flex h-8 items-center gap-1 rounded-md border border-border bg-background px-2.5 text-xs hover:bg-muted disabled:opacity-50"
                        :disabled="meta.current_page <= 1"
                        @click="go({ page: meta.current_page - 1 })"
                    >
                        <ChevronLeft class="rtl-flip size-3.5" aria-hidden="true" />{{ t('ui.prev') }}
                    </button>
                    <span class="tabular-nums">{{ t('ads.creatives.page_of', { page: meta.current_page, last: meta.last_page }) }}</span>
                    <button
                        type="button"
                        class="inline-flex h-8 items-center gap-1 rounded-md border border-border bg-background px-2.5 text-xs hover:bg-muted disabled:opacity-50"
                        :disabled="meta.current_page >= meta.last_page"
                        @click="go({ page: meta.current_page + 1 })"
                    >
                        {{ t('ui.next') }}<ChevronRight class="rtl-flip size-3.5" aria-hidden="true" />
                    </button>
                </div>
            </nav>
        </div>

        <CreativePreviewModal v-model:open="modalOpen" :ad="selected" :filters="filters" :currency="currency" />
    </AppLayout>
</template>
