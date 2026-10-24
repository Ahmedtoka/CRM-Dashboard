<script setup lang="ts">
/** «الإعلانات» (spec 4.1): one explorer, views جدول / كروت / شجرة; everything in the URL. */
import AdCard from '@/components/ads/AdCard.vue';
import AdDrawer from '@/components/ads/AdDrawer.vue';
import AdRow from '@/components/ads/AdRow.vue';
import AdsFilterBar from '@/components/ads/AdsFilterBar.vue';
import CampaignTreeView from '@/components/ads/CampaignTreeView.vue';
import DataHealthBanner from '@/components/ads/DataHealthBanner.vue';
import DataTable from '@/components/crm/DataTable.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import SkeletonList from '@/components/crm/SkeletonList.vue';
import { useAdDrawer } from '@/composables/useAdDrawer';
import { useI18n } from '@/composables/useI18n';
import { syncInertiaUrl } from '@/composables/useUrlFilters';
import { usePathVisitLoading } from '@/composables/usePathVisitLoading';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatAdsMoney, formatRoas } from '@/lib/ads';
import { AD_PARTS, adColumns, columnSort, serverSort, type AdPart } from '@/lib/adsColumns';
import { buildHref, EXPLORER_DEFAULTS, readQuery, withParam } from '@/lib/adsFilters';
import { formatCount } from '@/lib/format';
import type { AdRowData, AdsExplorerProps, AdsView, WinnerTierFilter } from '@/types/ads';
import { Head, Link, router } from '@inertiajs/vue3';
import { useEventListener, useMediaQuery } from '@vueuse/core';
import ViewSwitch from '@/components/ads/ui/ViewSwitch.vue';
import { ADS_FIELD } from '@/lib/adsUi';
import { ChevronLeft, ChevronRight, LayoutGrid, Megaphone, Network, Table2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<AdsExplorerProps>();
const { t, locale } = useI18n();
const phone = useMediaQuery('(max-width: 767px)');
const drawer = useAdDrawer();

/** Phones get cards for the table view (U 3.2); the tree stays desktop-only. */
const view = computed<AdsView>(() => (props.filters.view === 'table' && phone.value ? 'cards' : props.filters.view));
const columns = computed(() => adColumns(t));
const rows = computed<AdRowData[]>(() => props.result?.data ?? []);
const money = (v: number | null) => formatAdsMoney(v, locale.value, props.currency);
const search = () => (typeof window === 'undefined' ? '' : window.location.search);

/* Loading: the list dims while a filter visit to this page runs. */
const loading = usePathVisitLoading('/ads/explorer');

const go = (q: Record<string, string>) => router.get('/ads/explorer', q, { preserveState: true, preserveScroll: true, replace: true });
const setParam = (key: string, value: string | null) => go(withParam(readQuery(search()), key, value, EXPLORER_DEFAULTS));
const viewHref = (v: AdsView) => buildHref('/ads/explorer', withParam(readQuery(search()), 'view', v, EXPLORER_DEFAULTS));
const pageHref = (n: number) => buildHref('/ads/explorer', withParam(readQuery(search()), 'page', String(n), EXPLORER_DEFAULTS));

/** DataTable sorts by column key; the result column sorts by conversations on the server. */
const tableSort = computed(() => columnSort(props.filters.sort));
const onSort = (s: string) => setParam('sort', serverSort(s));
const SORTS = ['spend', 'roas', 'ctr', 'impressions', 'clicks', 'purchases', 'conversations', 'date'];

/* Old Winners chips (cards view): scored ads per tier, each a link to that tier. */
const TIER_HEALTH: Record<WinnerTierFilter, string | null> = { top: 'top', winner: 'winning', promising: 'promising', neutral: 'neutral', loser: 'losing', all: null };
const tierChips = computed(() =>
    props.tier_counts
        ? (['top', 'winner', 'promising', 'neutral', 'loser', 'all'] as WinnerTierFilter[]).map((k) => ({
              key: k,
              label: k === 'top' ? t('ads.control.explorer.tier_top') : k === 'all' ? t('ads.control.explorer.tier_all') : t(`ads.tier.${k}`),
              count: props.tier_counts?.[k] ?? 0,
              href: buildHref('/ads/explorer', withParam(readQuery(search()), 'health', TIER_HEALTH[k], EXPLORER_DEFAULTS)),
              active: (props.filters.health ?? null) === TIER_HEALTH[k],
          }))
        : [],
);

/* Tree: open nodes in the URL (replace); Back / Forward re-read them. */
const readOpen = () => (readQuery(search()).open ?? '').split(',').filter(Boolean);
const open = ref<string[]>(readOpen());
useEventListener(typeof window === 'undefined' ? undefined : window, 'popstate', () => (open.value = readOpen()));
function setOpen(keys: string[]): void {
    open.value = keys;
    const url = new URL(window.location.href);
    if (keys.length) url.searchParams.set('open', keys.join(','));
    else url.searchParams.delete('open');
    syncInertiaUrl(url, 'replace');
}

const crumbs = computed(() => [{ label: t('nav.ads'), href: '/today/ads' }, { label: t('ads.control.explorer.title') }]);
const appCrumbs = computed(() => crumbs.value.map((c) => ({ title: c.label, href: c.href ?? '/ads/explorer' })));
const isPart = (k: string): k is AdPart => (AD_PARTS as readonly string[]).includes(k);
const selectClass = ADS_FIELD;
const viewOptions = computed(() => [
    { key: 'table', label: t('ads.control.view.table'), icon: Table2, href: viewHref('table') },
    { key: 'cards', label: t('ads.control.view.cards'), icon: LayoutGrid, href: viewHref('cards') },
    { key: 'tree', label: t('ads.control.view.tree'), icon: Network, href: viewHref('tree') },
]);
</script>

<template>
    <Head :title="t('ads.control.explorer.title')" />
    <AppLayout :breadcrumbs="appCrumbs">
        <div class="mx-auto w-full min-w-0 max-w-[1400px] space-y-4 p-4 md:p-6">
            <PageHeader :title="t('ads.control.explorer.title')" :description="t('ads.control.explorer.description')" :breadcrumbs="crumbs" :freshness="freshness">
                <ViewSwitch :options="viewOptions" :current="view" :label="t('ads.control.view.label')" />
            </PageHeader>

            <DataHealthBanner :data-health="data_health" :numbers-under-review="numbers_under_review" :clamped-to-history="clamped_to_history" />

            <AdsFilterBar path="/ads/explorer" :filters="filters" :account-options="account_options" :buyers="buyers" :platforms="platforms" :defaults="EXPLORER_DEFAULTS" />

            <div v-if="view !== 'tree'" class="flex flex-wrap items-center justify-between gap-2 text-[13px]">
                <label class="inline-flex items-center gap-2">
                    <span class="text-fg-2">{{ t('ads.control.sort.label') }}</span>
                    <select :value="filters.sort" :class="selectClass" @change="setParam('sort', ($event.target as HTMLSelectElement).value)">
                        <option v-for="s in SORTS" :key="s" :value="`-${s}`">{{ t(`ads.control.sort.${s}`) }}</option>
                        <option v-if="!filters.sort.startsWith('-')" :value="filters.sort">{{ t(`ads.control.sort.${filters.sort}`) }}</option>
                    </select>
                </label>
                <span v-if="result" class="tabular-nums text-fg-3">{{ t('ads.control.explorer.shown', { n: formatCount(rows.length, locale), total: formatCount(result.meta.total, locale) }) }}</span>
                <label class="inline-flex items-center gap-2">
                    <span class="text-fg-2">{{ t('ads.control.explorer.per_page') }}</span>
                    <select :value="filters.per_page" :class="selectClass" @change="setParam('per_page', ($event.target as HTMLSelectElement).value === '25' ? null : ($event.target as HTMLSelectElement).value)">
                        <option v-for="n in [25, 50, 100]" :key="n" :value="n">{{ formatCount(n, locale) }}</option>
                    </select>
                </label>
            </div>

            <template v-if="view === 'tree'">
                <p class="rounded-lg bg-sunken px-4 py-2.5 text-[13px] text-fg-2 md:hidden">{{ t('ads.control.view.tree_desktop_only') }}</p>
                <div class="hidden md:block">
                    <EmptyState v-if="!tree?.length" :icon="Megaphone" :title="t('ads.control.explorer.empty')" />
                    <CampaignTreeView v-else :nodes="tree" :currency="currency" :open="open" :data-at="freshness" @update:open="setOpen" @open-ad="drawer.open" />
                </div>
            </template>

            <template v-else-if="view === 'cards'">
                <div v-if="tierChips.length" class="scrollbar-none -mx-1 flex gap-1.5 overflow-x-auto px-1" role="group" :aria-label="t('ads.control.explorer.tiers')">
                    <Link
                        v-for="c in tierChips"
                        :key="c.key"
                        :href="c.href"
                        preserve-scroll
                        class="inline-flex h-8 shrink-0 items-center gap-1.5 rounded-full border px-3 text-[13px] font-medium"
                        :class="c.active ? 'border-foreground bg-foreground text-background' : 'border-line-strong bg-card text-fg-2 hover:text-foreground'"
                        :aria-current="c.active ? 'true' : undefined"
                    >
                        {{ c.label }} <span class="tabular-nums">{{ formatCount(c.count, locale) }}</span>
                    </Link>
                </div>
                <SkeletonList v-if="loading && !rows.length" variant="cards" :count="4" />
                <EmptyState v-else-if="!rows.length" :icon="Megaphone" :title="t('ads.control.explorer.empty')" />
                <div v-else class="grid gap-3.5 sm:grid-cols-[repeat(auto-fill,minmax(200px,1fr))]" :class="loading ? 'opacity-60 transition-opacity' : ''">
                    <AdCard v-for="r in rows" :key="r.id" :row="r" :currency="currency" :data-at="freshness" @open="drawer.open" />
                </div>
            </template>

            <DataTable
                table-id="ads-explorer"
                v-else
                :columns="columns"
                :rows="rows"
                :sort="tableSort"
                :loading="loading"
                :empty="t('ads.control.explorer.empty')"
                :empty-icon="Megaphone"
                :caption="t('ads.control.explorer.title')"
                @update:sort="onSort"
            >
                <template v-for="c in columns" :key="c.key" #[`cell-${c.key}`]="{ row }">
                    <AdRow v-if="isPart(c.key)" :row="row" :part="c.key" :currency="currency" :data-at="freshness" @open="drawer.open" />
                </template>
                <template v-if="result && rows.length" #totals>
                    <tr>
                        <td v-for="c in columns" :key="c.key" class="px-3 py-2.5 text-[13px] font-semibold" :class="c.numeric ? 'text-end tabular-nums' : ''">
                            <template v-if="c.key === 'creative'">{{ t('ads.control.explorer.totals') }}</template>
                            <template v-else-if="c.key === 'spend'">{{ money(result.totals.spend_tax) }}</template>
                            <template v-else-if="c.key === 'cac'">{{ result.totals.real_orders > 0 ? money(result.totals.spend_tax / result.totals.real_orders) : '—' }}</template>
                            <template v-else-if="c.key === 'return'">
                                <span data-test="totals-real-roas" class="block text-sm font-semibold">{{ formatRoas(result.totals.real_roas, locale) }}</span>
                                <span class="block text-xs font-normal text-fg-3">{{ t('ads.control.row.meta') }} {{ formatRoas(result.totals.roas, locale) }}</span>
                            </template>
                        </td>
                    </tr>
                </template>
            </DataTable>

            <nav v-if="view !== 'tree' && result && result.meta.last_page > 1" class="flex items-center justify-between text-[13px]" :aria-label="t('ui.pagination')">
                <span class="tabular-nums text-fg-2">
                    {{ t('ads.control.explorer.page', { n: formatCount(result.meta.current_page, locale), total: formatCount(result.meta.last_page, locale) }) }}
                </span>
                <div class="flex gap-2">
                    <Link
                        v-if="result.meta.current_page > 1"
                        :href="pageHref(result.meta.current_page - 1)"
                        preserve-scroll
                        class="inline-flex h-11 items-center gap-1 rounded-md border border-line-strong bg-card px-3 font-medium hover:bg-surface-2 md:h-9"
                    >
                        <ChevronLeft class="rtl-flip size-4" aria-hidden="true" />{{ t('ads.control.explorer.prev') }}
                    </Link>
                    <Link
                        v-if="result.meta.current_page < result.meta.last_page"
                        :href="pageHref(result.meta.current_page + 1)"
                        preserve-scroll
                        class="inline-flex h-11 items-center gap-1 rounded-md border border-line-strong bg-card px-3 font-medium hover:bg-surface-2 md:h-9"
                    >
                        {{ t('ads.control.explorer.next') }}<ChevronRight class="rtl-flip size-4" aria-hidden="true" />
                    </Link>
                </div>
            </nav>
        </div>

        <AdDrawer
            :ad-id="drawer.adId.value"
            :filters="filters"
            :currency="currency"
            :data-at="freshness"
            :reload-only="['result', 'tree', 'tier_counts']"
            @close="drawer.close"
        />
    </AppLayout>
</template>
