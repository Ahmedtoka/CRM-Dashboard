<script setup lang="ts">
/** «النهارده» (U 2.3, spec 4.2): decisions first, money today vs usual by this hour, last 7 complete days, buyers, best/worst. */
import AdDrawer from '@/components/ads/AdDrawer.vue';
import AdRow from '@/components/ads/AdRow.vue';
import AdStatusButton from '@/components/ads/AdStatusButton.vue';
import AdsFilterBar from '@/components/ads/AdsFilterBar.vue';
import ComboChart from '@/components/ads/ComboChart.vue';
import CreativeThumb from '@/components/ads/CreativeThumb.vue';
import DataHealthBanner from '@/components/ads/DataHealthBanner.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import ProgressBar from '@/components/crm/ProgressBar.vue';
import { useAdDrawer } from '@/composables/useAdDrawer';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatAdsMoney, formatDayShort, formatPct, formatRoas, reasonTexts } from '@/lib/ads';
import { buildHref, carryQuery, readQuery } from '@/lib/adsFilters';
import { formatCount } from '@/lib/format';
import type { AdSuggestion, AdsTodayProps } from '@/types/ads';
import { Head, Link } from '@inertiajs/vue3';
import { CheckCircle2 } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<AdsTodayProps>();
const { t, locale } = useI18n();
const drawer = useAdDrawer();
const money = (v: number | null) => formatAdsMoney(v, locale.value, props.currency);
const n = (v: number) => formatCount(v, locale.value);
const d = computed(() => props.today.decisions);
const m = computed(() => props.today.money_today);
const w = computed(() => props.today.last7.totals);
const shared = computed(() => carryQuery(readQuery(typeof window === 'undefined' ? '' : window.location.search)));
/** Links keep the shared filters (accounts, buyer, platform) and set their own range. */
const href = (path: string, extra: Record<string, string> = {}) => buildHref(path, { ...shared.value, ...extra });
const decisionsCount = computed(() => d.value.approvals + d.value.suggestions_total + d.value.alerts.length);

const usualLabel = computed(() =>
    m.value.baseline === 'snapshots' ? t('ads.control.today.usual') : m.value.baseline === 'prorated' ? t('ads.control.today.usual_prorated') : t('ads.control.today.usual_none'),
);
const hourLabels = computed(() => m.value.hours.map((h) => String(h.hour)));
const hourBars = computed(() => [{ key: 'today', label: t('ads.control.today.spent'), values: m.value.hours.map((h) => h.today), color: 'hsl(var(--chart-1))', format: (v: number) => money(v) }]);
const hourLines = computed(() => [
    { key: 'usual', label: usualLabel.value, values: m.value.hours.map((h) => h.usual), color: 'hsl(var(--chart-3))', format: (v: number) => money(v), dashed: true },
]);
const days = computed(() => props.today.last7.daily);
const dayBars = computed(() => [{ key: 'spend', label: t('ads.control.today.spent'), values: days.value.map((x) => x.spend_tax), color: 'hsl(var(--chart-1))', format: (v: number) => money(v) }]);
const dayLines = computed(() => [
    { key: 'rev', label: t('ads.control.numbers.revenue'), values: days.value.map((x) => x.real_revenue), color: 'hsl(var(--chart-2))', format: (v: number) => money(v) },
]);
const reasonLine = (s: AdSuggestion) =>
    reasonTexts(s.reasons, locale.value, props.currency)
        .map((r) => r.text)
        .join(' · ');
const thumb = (s: AdSuggestion) => ({ name: s.name, thumbnail_url: s.thumbnail_url, image_url: null, type: null, effective_status: s.status, created_time: null });
const crumbs = computed(() => [{ label: t('nav.ads'), href: '/ads' }, { label: t('ads.control.today.title') }]);
const appCrumbs = computed(() => [{ title: t('nav.ads'), href: '/ads' }]);
const LISTS = ['best', 'worst'] as const;
const listHref = (list: (typeof LISTS)[number]) =>
    href('/ads/explorer', list === 'best' ? { range: 'last7', health: 'winning', sort: '-roas' } : { range: 'last7', health: 'losing', sort: '-spend' });
</script>

<template>
    <Head :title="t('ads.control.today.title')" />
    <AppLayout :breadcrumbs="appCrumbs">
        <div class="mx-auto w-full min-w-0 max-w-[1400px] space-y-4 p-3 md:p-6">
            <PageHeader :title="t('ads.control.today.title')" :description="t('ads.control.today.description')" :breadcrumbs="crumbs" :freshness="freshness" />
            <DataHealthBanner :data-health="data_health" :numbers-under-review="numbers_under_review" :clamped-to-history="clamped_to_history" />
            <AdsFilterBar
                path="/ads"
                :filters="filters"
                :account-options="account_options"
                :buyers="buyers"
                :platforms="platforms"
                :show="{ range: false, status: false, list: false, presets: false }"
            />

            <!-- Decisions block: what needs me now comes first -->
            <section class="space-y-3 rounded-lg bg-card p-4 shadow-card" aria-labelledby="today-decisions">
                <div class="flex items-center justify-between gap-2">
                    <h2 id="today-decisions" class="text-sm font-semibold">
                        {{ t('ads.control.today.decisions') }} <span class="tabular-nums text-muted-foreground">({{ n(decisionsCount) }})</span>
                    </h2>
                    <Link :href="href('/ads/decisions')" class="inline-flex h-9 items-center text-xs text-primary hover:underline">{{ t('ads.control.today.open_all') }}</Link>
                </div>
                <Link v-if="d.approvals > 0" data-test="approvals-link" href="/ads/approvals" class="block rounded-md bg-surface-accent px-3 py-2 text-xs font-medium hover:underline">
                    {{ t('ads.control.today.approvals', { n: d.approvals }) }}
                </Link>
                <ul v-if="d.suggestions.length" class="divide-y divide-border">
                    <li v-for="s in d.suggestions" :key="s.ad_id" class="flex flex-wrap items-center gap-3 py-2">
                        <button type="button" class="shrink-0" :aria-label="t('ads.control.row.preview')" @click="drawer.open(s.ad_id)">
                            <CreativeThumb :ad="thumb(s)" :size="40" :show-pills="false" />
                        </button>
                        <div class="min-w-0 flex-1">
                            <button type="button" class="block max-w-full truncate text-start text-xs font-medium hover:underline" dir="auto" @click="drawer.open(s.ad_id)">{{ s.name }}</button>
                            <p class="truncate text-2xs text-muted-foreground">{{ reasonLine(s) }}</p>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <AdStatusButton
                                :account-id="s.account_id"
                                :account="s.account"
                                :platform="String(s.platform)"
                                level="ad"
                                :external-id="s.external_id"
                                :name="s.name"
                                :status="s.status"
                                :can-write="s.can_write ?? false"
                                :reason="reasonLine(s)"
                                :data-at="freshness"
                                :currency="currency"
                            />
                            <button type="button" class="h-7 rounded-md px-2 text-2xs text-primary hover:bg-muted" @click="drawer.open(s.ad_id)">{{ t('ads.control.row.why') }}</button>
                        </div>
                    </li>
                </ul>
                <EmptyState v-else-if="d.approvals === 0" :icon="CheckCircle2" :title="t('ads.control.today.empty_decisions')" />
            </section>

            <div class="grid gap-4 lg:grid-cols-2">
                <!-- Money today -->
                <section class="min-w-0 space-y-3 rounded-lg bg-card p-4 shadow-card">
                    <h2 class="text-sm font-semibold">{{ t('ads.control.today.money_today') }}</h2>
                    <dl class="grid grid-cols-2 gap-3 text-xs tabular-nums">
                        <div>
                            <dt class="text-muted-foreground">{{ t('ads.control.today.spent') }}</dt>
                            <dd>
                                <Link :href="href('/ads/explorer', { range: 'today', sort: '-spend' })" class="text-lg font-bold hover:underline">{{ money(m.spend_so_far) }}</Link>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">{{ usualLabel }}</dt>
                            <dd class="text-lg font-semibold">{{ money(m.usual_by_now) }}</dd>
                            <dd v-if="m.ratio !== null" class="text-2xs" :class="m.ratio >= 2 ? 'font-semibold text-destructive' : 'text-muted-foreground'">
                                {{ t('ads.control.today.ratio', { x: m.ratio }) }}
                            </dd>
                        </div>
                        <div class="col-span-2">
                            <Link :href="href('/ads/explorer', { range: 'today', sort: '-conversations', objective: 'messages' })" class="hover:underline">
                                {{ t('ads.control.today.chats_orders', { chats: n(m.conversations), orders: n(m.orders) }) }}
                            </Link>
                        </div>
                    </dl>
                    <ComboChart v-if="m.hours.length" :title="t('ads.control.today.by_hour')" :labels="hourLabels" :bars="hourBars" :lines="hourLines" :height="140" />
                </section>

                <!-- Last 7 complete days -->
                <section class="min-w-0 space-y-3 rounded-lg bg-card p-4 shadow-card">
                    <h2 class="text-sm font-semibold">{{ t('ads.control.today.last7') }}</h2>
                    <dl class="grid grid-cols-2 gap-3 text-xs tabular-nums">
                        <div>
                            <dt class="text-muted-foreground">{{ t('ads.control.today.spent') }}</dt>
                            <dd>
                                <Link :href="href('/ads/numbers', { range: 'last7' })" class="text-lg font-bold hover:underline">{{ money(w.spend_tax) }}</Link>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">{{ t('ads.control.today.real_orders') }}</dt>
                            <dd>
                                <Link :href="href('/ads/numbers', { range: 'last7' })" class="text-lg font-bold hover:underline">{{ n(w.real_orders) }}</Link>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">{{ t('ads.control.today.real_roas') }}</dt>
                            <dd data-test="real-roas" class="text-2xl font-bold">{{ formatRoas(w.real_roas, locale) }}</dd>
                            <dd class="text-2xs text-muted-foreground">{{ t('ads.control.today.meta_roas') }} {{ formatRoas(w.roas, locale) }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">{{ t('ads.control.today.losers', { pct: formatPct(w.losers_spend_share, locale, 0) }) }}</dt>
                            <dd>
                                <Link data-test="losers-link" :href="href('/ads/explorer', { range: 'last7', health: 'losing', sort: '-spend' })" class="text-primary hover:underline">
                                    {{ t('ads.control.today.who') }}
                                </Link>
                            </dd>
                        </div>
                    </dl>
                    <ComboChart
                        v-if="days.length"
                        :title="t('ads.control.today.last7')"
                        :labels="days.map((x) => x.date)"
                        :label-format="(l: string) => formatDayShort(l, locale)"
                        :bars="dayBars"
                        :lines="dayLines"
                        :height="140"
                    />
                </section>
            </div>

            <!-- Buyers strip (managers) -->
            <section v-if="today.buyers" data-test="buyers-strip" class="space-y-2 rounded-lg bg-card p-4 shadow-card">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="text-sm font-semibold">{{ t('ads.control.today.buyers') }}</h2>
                    <Link :href="href('/ads/numbers', { range: 'last7', section: 'buyers' })" class="text-xs text-primary hover:underline">{{ t('ads.control.today.see_all') }}</Link>
                </div>
                <ul class="divide-y divide-border text-xs">
                    <li v-for="b in today.buyers" :key="b.buyer_id ?? 'none'" class="grid grid-cols-2 items-center gap-x-4 gap-y-1 py-2 md:grid-cols-[10rem_1fr_1fr_12rem_auto]">
                        <Link v-if="b.buyer_id" :href="href(`/ads/buyers/${b.buyer_id}`, { range: 'last7' })" class="truncate font-medium hover:underline">{{ b.name }}</Link>
                        <span v-else class="truncate text-muted-foreground">{{ b.name }}</span>
                        <span class="tabular-nums">{{ money(b.spend_tax) }}</span>
                        <span class="tabular-nums">
                            {{ t('ads.control.today.real_roas') }} {{ formatRoas(b.real_roas, locale) }}
                            <template v-if="b.target_roas !== null"> / {{ t('ads.control.today.target', { x: b.target_roas }) }}</template>
                        </span>
                        <ProgressBar v-if="b.budget_used_pct !== null" :value="Math.round(b.budget_used_pct)" :max="100" :label="t('ads.control.today.budget')" />
                        <span v-else />
                        <Link
                            v-if="b.buyer_id && b.open_decisions > 0"
                            :href="href('/ads/decisions', { buyer: String(b.buyer_id) })"
                            class="text-primary hover:underline"
                        >
                            {{ t('ads.control.today.open_decisions', { n: b.open_decisions }) }}
                        </Link>
                    </li>
                </ul>
            </section>

            <!-- Best / worst -->
            <div class="grid gap-4 lg:grid-cols-2">
                <section v-for="list in LISTS" :key="list" class="min-w-0 space-y-2 rounded-lg bg-card p-4 shadow-card">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-semibold">{{ t(`ads.control.today.${list}`) }}</h2>
                        <Link :href="listHref(list)" class="text-xs text-primary hover:underline">{{ t('ads.control.today.see_all') }}</Link>
                    </div>
                    <ul v-if="today[list].length" class="divide-y divide-border">
                        <li v-for="r in today[list]" :key="r.id" class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-2 py-2 sm:grid-cols-[minmax(0,1fr)_auto_auto]">
                            <AdRow :row="r" part="creative" :currency="currency" density="compact" @open="drawer.open" />
                            <AdRow :row="r" part="return" :currency="currency" class="hidden sm:block" @open="drawer.open" />
                            <AdRow :row="r" part="status" :currency="currency" :data-at="freshness" @open="drawer.open" />
                        </li>
                    </ul>
                    <p v-else class="text-xs text-muted-foreground">{{ t(list === 'best' ? 'ads.control.today.none_best' : 'ads.control.today.none_worst') }}</p>
                </section>
            </div>
        </div>
        <AdDrawer :ad-id="drawer.adId.value" :filters="filters" :currency="currency" :data-at="freshness" @close="drawer.close" />
    </AppLayout>
</template>
