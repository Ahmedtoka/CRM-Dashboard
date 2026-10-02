<script setup lang="ts">
/** Ads Hub — الكرييتف الكسبان: scored creatives over the effective window (7–30 days), Arena card grid (spec §8.4). */
import AdsRangeBar from '@/components/ads/AdsRangeBar.vue';
import CreativePreviewModal from '@/components/ads/CreativePreviewModal.vue';
import CreativeThumb from '@/components/ads/CreativeThumb.vue';
import WinnerBadge from '@/components/ads/WinnerBadge.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { type AdsQueryValue, formatAdsMoney, formatDayLong, formatPct, formatQty, formatRoas, visitAds } from '@/lib/ads';
import type { AdsWinnersProps, CreativeStatusFilter, WinnerRow, WinnerSort } from '@/types/ads';
import { Head } from '@inertiajs/vue3';
import { Lightbulb, Trophy } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<AdsWinnersProps>();

const { t, locale } = useI18n();
const money = (v: number | null) => formatAdsMoney(v, locale.value, props.currency);

const SORTS: WinnerSort[] = ['score', 'roas', 'spend', 'revenue', 'date'];
const STATUSES: CreativeStatusFilter[] = ['all', 'active', 'inactive'];

const keep = computed<Record<string, AdsQueryValue>>(() => ({
    status: props.filters.status === 'all' ? null : props.filters.status,
    sort: props.filters.sort === 'score' ? null : props.filters.sort,
}));

function go(changes: Record<string, AdsQueryValue>): void {
    const f = props.filters;
    visitAds({ from: f.from, to: f.to, platform: f.platform, buyer: f.buyer, ...keep.value, ...changes });
}

const windowLabel = computed(() =>
    t('ads.winners.window', { from: formatDayLong(props.window.from, locale.value), to: formatDayLong(props.window.to, locale.value) }),
);
const clamped = computed(() => props.window.from !== props.filters.from || props.window.to !== props.filters.to);

const scoreBar = (score: number) => (score >= 75 ? 'bg-success' : score >= 50 ? 'bg-warning' : 'bg-destructive');

const selected = ref<WinnerRow | null>(null);
const modalOpen = ref(false);
function openWinner(w: WinnerRow): void {
    selected.value = w;
    modalOpen.value = true;
}

const chip = (on: boolean) =>
    on ? 'border-primary bg-primary text-primary-foreground' : 'border-border bg-background text-muted-foreground hover:text-foreground';

const breadcrumbs = computed(() => [
    { title: t('nav.ads'), href: '/ads' },
    { title: t('nav.ads_winners'), href: '/ads/winners' },
]);
</script>

<template>
    <Head :title="t('ads.winners.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-[1400px] space-y-4 p-3 md:p-6">
            <PageHeader :title="t('ads.winners.title')" :description="windowLabel">
                <AdsRangeBar :filters="filters" :platforms="platforms" :buyers="buyers" :keep="keep" />
            </PageHeader>

            <p class="rounded-md bg-surface-accent px-3 py-2 text-2xs text-muted-foreground">
                {{ t('ads.winners.note') }}
                <span v-if="clamped" class="font-medium text-foreground">{{ t('ads.winners.clamped') }}</span>
            </p>

            <div class="flex flex-wrap items-center gap-2">
                <div class="flex flex-wrap items-center gap-1" role="group" :aria-label="t('ads.filters.status')">
                    <button
                        v-for="s in STATUSES"
                        :key="s"
                        type="button"
                        :aria-pressed="filters.status === s"
                        class="inline-flex h-8 items-center rounded-full border px-3 text-xs font-medium transition-colors"
                        :class="chip(filters.status === s)"
                        @click="go({ status: s === 'all' ? null : s })"
                    >
                        {{ t(`ads.creatives.status_${s}`) }}
                    </button>
                </div>
                <label class="sr-only" for="winner-sort">{{ t('ads.filters.sort') }}</label>
                <select
                    id="winner-sort"
                    :value="filters.sort"
                    class="ms-auto h-9 rounded-md border border-input bg-background px-2 text-xs"
                    @change="go({ sort: ($event.target as HTMLSelectElement).value })"
                >
                    <option v-for="s in SORTS" :key="s" :value="s">{{ t('ads.filters.sort') }}: {{ t(`ads.winners.sort_${s}`) }}</option>
                </select>
            </div>

            <EmptyState
                v-if="!winners.length"
                :icon="Trophy"
                :title="t('ads.winners.empty')"
                :body="t('ads.winners.empty_body')"
                class="rounded-lg bg-card shadow-card"
            />

            <ul v-else class="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
                <li v-for="w in winners" :key="w.ad.id">
                    <button
                        type="button"
                        class="flex h-full w-full flex-col overflow-hidden rounded-lg bg-card text-start shadow-card transition-shadow hover:shadow-md focus-visible:outline focus-visible:outline-2 focus-visible:outline-ring"
                        @click="openWinner(w)"
                    >
                        <div class="relative">
                            <CreativeThumb :ad="w.ad" size="fill" square />
                            <span class="absolute start-2 top-2"><WinnerBadge :tier="w.tier" /></span>
                        </div>
                        <div class="flex flex-1 flex-col gap-2 p-3">
                            <div class="min-w-0">
                                <p class="line-clamp-2 text-xs font-bold text-foreground" dir="auto">{{ w.ad.name }}</p>
                                <p v-if="w.ad.campaign" class="truncate text-2xs text-muted-foreground">
                                    {{ t('ads.table.campaign_short') }}: <span dir="auto">{{ w.ad.campaign }}</span>
                                </p>
                            </div>

                            <dl class="grid grid-cols-3 gap-x-2 gap-y-1.5 text-2xs">
                                <div>
                                    <dt class="text-muted-foreground">{{ t('ads.winners.spend') }}</dt>
                                    <dd class="font-semibold tabular-nums">
                                        {{ money(w.ad.spend_tax) }}
                                        <span class="block text-[10px] font-normal leading-tight text-muted-foreground">{{
                                            t('ads.money.pre_tax', { amount: money(w.spend) })
                                        }}</span>
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-muted-foreground">{{ t('ads.winners.revenue') }}</dt>
                                    <dd class="font-semibold tabular-nums">{{ money(w.revenue) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-muted-foreground">{{ t('ads.winners.orders') }}</dt>
                                    <dd class="font-semibold tabular-nums">{{ formatQty(w.orders, locale) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-muted-foreground">{{ t('ads.kpi.cpa') }}</dt>
                                    <dd class="font-semibold tabular-nums">{{ money(w.cpa) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-muted-foreground">{{ t('ads.kpi.ctr') }}</dt>
                                    <dd class="font-semibold tabular-nums">{{ formatPct(w.ctr, locale) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-muted-foreground">{{ t('ads.winners.cvr') }}</dt>
                                    <dd class="font-semibold tabular-nums">{{ formatPct(w.cvr, locale) }}</dd>
                                </div>
                            </dl>

                            <div>
                                <div class="mb-1 flex items-baseline justify-between gap-2 text-2xs">
                                    <span class="font-bold tabular-nums text-foreground">{{ t('ads.winners.score', { score: w.score }) }}</span>
                                    <span class="tabular-nums text-muted-foreground">{{
                                        t('ads.winners.smoothed', { roas: formatRoas(w.smoothed_roas, locale) })
                                    }}</span>
                                </div>
                                <div
                                    class="h-1.5 overflow-hidden rounded-full bg-muted"
                                    role="meter"
                                    :aria-label="t('ads.winners.score_label')"
                                    aria-valuemin="0"
                                    aria-valuemax="100"
                                    :aria-valuenow="w.score"
                                >
                                    <div class="h-full rounded-full" :class="scoreBar(w.score)" :style="{ width: `${w.score}%` }" />
                                </div>
                            </div>

                            <p class="mt-auto flex items-start gap-1.5 text-2xs text-muted-foreground">
                                <Lightbulb class="mt-px size-3.5 shrink-0 text-warning" aria-hidden="true" />
                                <span>{{ w.recommendation }}</span>
                            </p>
                        </div>
                    </button>
                </li>
            </ul>
        </div>

        <CreativePreviewModal
            v-model:open="modalOpen"
            :ad="selected?.ad ?? null"
            :filters="{ ...filters, from: window.from, to: window.to }"
            :smoothed-roas="selected?.smoothed_roas ?? null"
            :currency="currency"
        />
    </AppLayout>
</template>
