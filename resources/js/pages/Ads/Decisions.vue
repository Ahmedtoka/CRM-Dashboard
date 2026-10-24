<script setup lang="ts">
/** «محتاج قرار» (spec 4.1, U 5.2): approvals (S1) on top, S5 alert cards, stop suggestions; tabs open/snoozed/closed/log. */
import AdDrawer from '@/components/ads/AdDrawer.vue';
import AdStatusButton from '@/components/ads/AdStatusButton.vue';
import AdsFilterBar from '@/components/ads/AdsFilterBar.vue';
import AlertsSection from '@/components/ads/AlertsSection.vue';
import CreativeThumb from '@/components/ads/CreativeThumb.vue';
import DecisionCard from '@/components/ads/DecisionCard.vue';
import WriteActionDialog from '@/components/ads/WriteActionDialog.vue';
import PlatformChip from '@/components/ads/PlatformChip.vue';
import AdsPanel from '@/components/ads/ui/AdsPanel.vue';
import DecisionRow from '@/components/ads/ui/DecisionRow.vue';
import DataHealthBanner from '@/components/ads/DataHealthBanner.vue';
import WhyList from '@/components/ads/WhyList.vue';
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import { Button } from '@/components/ui/button';
import PageHeader from '@/components/crm/PageHeader.vue';
import SectionTabs from '@/components/crm/SectionTabs.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { useAdDrawer } from '@/composables/useAdDrawer';
import { useI18n } from '@/composables/useI18n';
import { usePathVisitLoading } from '@/composables/usePathVisitLoading';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatAdsMoney, reasonTexts, writeChangeKey } from '@/lib/ads';
import { ADS_FIELD, ADS_META, ADS_TONE } from '@/lib/adsUi';
import { buildHref, readQuery, withParam } from '@/lib/adsFilters';
import { formatCount, formatDateTime } from '@/lib/format';
import { sectionTabs, type SectionTab } from '@/lib/sectionTabs';
import type { AdActionLogRow, AdSuggestion, AdsDecisionsProps, DecisionKind, DecisionsTab } from '@/types/ads';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { CheckCircle2, ChevronRight, FlaskConical, History, Rocket, Undo2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<AdsDecisionsProps>();
const { t, locale } = useI18n();
const loading = usePathVisitLoading('/ads/decisions');
const drawer = useAdDrawer();
const search = () => (typeof window === 'undefined' ? '' : window.location.search);
/** Switching tab drops the log filters (they belong to the log tab only). */
const tabHref = (tab: DecisionsTab) => {
    const q = readQuery(search());
    for (const k of ['who', 'level', 'result']) delete q[k];
    return buildHref('/ads/decisions', withParam(q, 'tab', tab, { tab: 'open' }));
};
/** D12: one strip with the launch approvals between «مفتوح» and «مأجّل»; the open count is the waiting work. */
const page = usePage();
const decisionTabs = computed<SectionTab[]>(() => {
    void page.url; // recompute the carried filters after every visit
    const own: Record<string, DecisionsTab> = { '/ads/decisions': 'open', '/ads/decisions?tab=snoozed': 'snoozed', '/ads/decisions?tab=closed': 'closed', '/ads/decisions?tab=log': 'log' };
    const awaiting = (page.props.adsCounters as { awaiting_approval?: number } | null | undefined)?.awaiting_approval ?? props.approvals?.count ?? null;

    return sectionTabs.decisions(t, awaiting).map((tab) => {
        const key = own[tab.href];
        return key ? { ...tab, href: tabHref(key), badge: key === 'open' ? props.counts.open : null } : tab;
    });
});
const setLog = (key: string, value: string | null) => router.get('/ads/decisions', withParam(readQuery(search()), key, value), { preserveState: true, preserveScroll: true, replace: true });
const reasonLine = (s: AdSuggestion) =>
    reasonTexts(s.reasons, locale.value, props.currency)
        .map((r) => r.text)
        .join(' · ');
const thumb = (s: AdSuggestion) => ({ name: s.name, thumbnail_url: s.thumbnail_url, image_url: null, type: null, effective_status: s.status, created_time: null });
const logColumns = computed<Column[]>(() => [
    { key: 'at', label: t('ads.control.decisions.log_at'), numeric: true, align: 'start' },
    { key: 'user', label: t('ads.control.decisions.log_who') },
    { key: 'name', label: t('ads.control.col.creative'), primary: true },
    { key: 'change', label: t('ads.control.col.status') },
    { key: 'result', label: t('ads.control.decisions.log_result') },
]);
const resultTone = (r: AdActionLogRow['result']) => (r === 'ok' ? 'positive' : r === 'pending' ? 'info' : r === 'dry_run' ? 'warning' : 'negative');
/** Round 3 «قرار اليوم»: the kind filter chips and the list; the old stop suggestions keep only the ads the day has no decision for. */
const KINDS: DecisionKind[] = ['stock_risk', 'cut', 'fix_creative', 'scale', 'keep'];
const kindFilter = ref<DecisionKind | null>(null);
const dailyItems = computed(() => (props.daily?.items ?? []).filter((d) => kindFilter.value === null || d.kind === kindFilter.value));
const decidedAdIds = computed(() => new Set((props.daily?.items ?? []).map((d) => d.ad.id)));
const restSuggestions = computed(() => props.suggestions.filter((s) => !decidedAdIds.value.has(s.ad_id)));
const kindTone = (k: DecisionKind) => ({ stock_risk: 'text-danger', cut: 'text-danger', fix_creative: 'text-warning', scale: 'text-ok', keep: 'text-fg-2' })[k];
/** The log's «تراجع»: the inverse is proposed by the server and confirmed in the same dialog. */
const undoRow = ref<AdActionLogRow | null>(null);
const undoOpen = ref(false);
function undo(row: AdActionLogRow): void {
    undoRow.value = row;
    undoOpen.value = true;
}
const budgetChange = (row: AdActionLogRow) =>
    t('ads.control.write.change_budget', {
        from: formatAdsMoney(Number(row.params?.from_minor ?? 0) / 100, locale.value, row.params?.currency ?? props.currency, 2),
        to: formatAdsMoney(Number(row.params?.to_minor ?? 0) / 100, locale.value, row.params?.currency ?? props.currency, 2),
    });
const crumbs = computed(() => [{ label: t('nav.ads'), href: '/today/ads' }, { label: t('ads.control.decisions.title') }]);
const appCrumbs = computed(() => crumbs.value.map((c) => ({ title: c.label, href: c.href ?? '/ads/decisions' })));
</script>

<template>
    <Head :title="t('ads.control.decisions.title')" />
    <AppLayout :breadcrumbs="appCrumbs">
        <div class="mx-auto w-full min-w-0 max-w-[1400px] space-y-5 p-4 md:p-6">
            <PageHeader :title="t('ads.control.decisions.title')" :description="t('ads.control.decisions.description')" :breadcrumbs="crumbs" :freshness="freshness" />
            <SectionTabs :tabs="decisionTabs" :label="t('section_tabs.decisions.label')" />
            <DataHealthBanner :data-health="data_health" :numbers-under-review="numbers_under_review" :clamped-to-history="clamped_to_history" />
            <AdsFilterBar
                path="/ads/decisions"
                :filters="filters"
                :account-options="account_options"
                :buyers="buyers"
                :platforms="platforms"
                :show="{ range: false, status: false, list: false, presets: false }"
            />
            <!-- Dims while a filter visit to this page runs (M7). -->
            <div class="space-y-5 transition-opacity" :class="loading ? 'opacity-60' : ''" :aria-busy="loading" data-test="page-body">
                <template v-if="filters.tab === 'open'">
                    <!-- Launch approvals waiting: one line and the way in (the approvals tab). -->
                    <section
                        v-if="approvals && approvals.count > 0"
                        data-test="approvals"
                        class="flex flex-wrap items-center gap-3 rounded-lg bg-brand-soft px-4 py-3"
                        aria-labelledby="dec-approvals"
                    >
                        <span class="grid size-9 shrink-0 place-items-center rounded-md bg-card text-brand-ink"><Rocket class="size-[18px]" aria-hidden="true" /></span>
                        <div class="min-w-0 flex-1">
                            <h2 id="dec-approvals" class="text-sm font-bold text-foreground">{{ t('ads.control.decisions.approvals') }}</h2>
                            <p class="text-[12.5px] text-fg-2">{{ t('ads.control.decisions.approvals_body', { n: formatCount(approvals.count, locale) }) }}</p>
                        </div>
                        <Link
                            :href="approvals.href"
                            class="inline-flex h-11 items-center gap-1 rounded-md bg-primary px-4 text-sm font-semibold text-primary-foreground hover:bg-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 md:h-9"
                        >
                            {{ t('ads.control.decisions.approvals_open') }}<ChevronRight class="rtl-flip size-4" aria-hidden="true" />
                        </Link>
                    </section>

                    <!-- Round 3: «قرار اليوم» — one decision per running ad, with the evidence and the exact change «نفّذ» sends. -->
                    <section v-if="daily" data-test="daily" aria-labelledby="dec-daily" class="space-y-3">
                        <div class="flex flex-wrap items-end justify-between gap-2">
                            <div>
                                <h2 id="dec-daily" class="text-base font-bold text-foreground">{{ t('ads.control.daily.title') }}</h2>
                                <p class="text-[12.5px] text-fg-2">{{ t('ads.control.daily.description') }}</p>
                            </div>
                            <div v-if="daily.items.length" class="flex flex-wrap gap-1.5" role="group" :aria-label="t('ads.control.daily.title')">
                                <button
                                    type="button"
                                    class="h-9 rounded-md border border-line px-3 text-xs font-semibold md:h-8"
                                    :class="kindFilter === null ? 'bg-foreground text-background' : 'bg-card text-fg-2 hover:bg-sunken'"
                                    @click="kindFilter = null"
                                >
                                    {{ t('ads.control.daily.all') }} · {{ formatCount(daily.items.length, locale) }}
                                </button>
                                <button
                                    v-for="k in KINDS"
                                    :key="k"
                                    type="button"
                                    :data-test="`kind-${k}`"
                                    class="h-9 rounded-md border border-line px-3 text-xs font-semibold md:h-8"
                                    :class="kindFilter === k ? 'bg-foreground text-background' : ['bg-card hover:bg-sunken', kindTone(k)]"
                                    :disabled="!daily.counts[k]"
                                    @click="kindFilter = kindFilter === k ? null : k"
                                >
                                    {{ t(`ads.control.daily.kind_${k}`) }} · {{ formatCount(daily.counts[k] ?? 0, locale) }}
                                </button>
                            </div>
                        </div>
                        <p v-if="!daily.execute && daily.items.length" data-test="dry-run-banner" class="flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/15 px-4 py-3 text-[12.5px]">
                            <FlaskConical class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                            <span>{{ t('ads.control.daily.dry_run_banner') }}</span>
                        </p>
                        <AdsPanel v-if="dailyItems.length" flush>
                            <DecisionCard
                                v-for="d in dailyItems"
                                :key="d.key"
                                :decision="d"
                                :currency="currency"
                                :execute="daily.execute"
                                :data-at="freshness"
                                @open-ad="drawer.open"
                            />
                        </AdsPanel>
                        <EmptyState v-else-if="!daily.items.length" :icon="CheckCircle2" :title="t('ads.control.daily.empty')" />
                    </section>

                    <AlertsSection v-if="alertsMeta" :alerts="alerts" :meta="alertsMeta" mode="open" :data-at="freshness" :currency="currency" @open-ad="drawer.open" />

                    <AdsPanel v-if="restSuggestions.length" data-test="suggestions" :title="t(daily?.items.length ? 'ads.control.decisions.suggestions_rest' : 'ads.control.decisions.suggestions')" :count="restSuggestions.length" flush>
                        <DecisionRow v-for="s in restSuggestions" :key="s.ad_id">
                            <template #thumb>
                                <button type="button" class="block size-full" :aria-label="t('ads.control.row.preview')" @click="drawer.open(s.ad_id)">
                                    <CreativeThumb :ad="thumb(s)" size="fill" square :show-pills="false" class="!aspect-auto h-full" />
                                </button>
                            </template>
                            <h3 class="flex min-w-0 items-center gap-2">
                                <button type="button" class="min-w-0 truncate text-start text-sm font-semibold hover:underline" dir="auto" @click="drawer.open(s.ad_id)">
                                    {{ s.name }}
                                </button>
                                <PlatformChip :platform="String(s.platform)" size="xs" icon-only />
                            </h3>
                            <p :class="ADS_META">
                                <bdi>{{ s.account }}</bdi><template v-if="s.campaign"> · <bdi>{{ s.campaign }}</bdi></template> ·
                                {{ t(`ads.control.objective.${s.objective}`) }}
                            </p>
                            <WhyList :reasons="s.reasons" :currency="currency" class="pt-1" />
                            <p class="pt-1 text-[12.5px] text-fg-2">
                                {{ t('ads.control.decisions.spend_days', { amount: formatAdsMoney(s.spend_tax, locale, currency) }) }}
                            </p>
                            <template #actions>
                                <Button variant="ghost" size="sm" class="h-11 md:h-[30px]" :class="ADS_TONE.quiet" @click="drawer.open(s.ad_id)">{{
                                    t('ads.control.row.why')
                                }}</Button>
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
                                    :spend-today="s.spend_today"
                                    :data-at="freshness"
                                    :currency="currency"
                                    size="sm"
                                />
                            </template>
                        </DecisionRow>
                    </AdsPanel>
                    <EmptyState
                        v-if="!suggestions.length && !(approvals && approvals.count) && !alerts.length && !daily?.items.length"
                        :icon="CheckCircle2"
                        :title="t('ads.control.decisions.empty_open')"
                    />
                </template>

                <template v-else-if="filters.tab === 'snoozed' || filters.tab === 'closed'">
                    <AlertsSection
                        v-if="alertsMeta"
                        :alerts="alerts"
                        :meta="alertsMeta"
                        :mode="filters.tab === 'snoozed' ? 'later' : 'closed'"
                        :data-at="freshness"
                        :currency="currency"
                        @open-ad="drawer.open"
                    />
                    <EmptyState v-else :icon="CheckCircle2" :title="t(`ads.control.decisions.empty_${filters.tab}`)" />
                </template>

                <template v-else>
                    <div class="flex flex-wrap gap-2">
                        <select
                            :value="filters.who ?? ''"
                            :class="ADS_FIELD"
                            :aria-label="t('ads.control.decisions.log_who')"
                            @change="setLog('who', ($event.target as HTMLSelectElement).value || null)"
                        >
                            <option value="">{{ t('ads.control.decisions.log_who') }}: {{ t('ads.control.decisions.any') }}</option>
                            <option v-for="u in log_users" :key="u.id" :value="u.id">{{ u.name }}</option>
                        </select>
                        <select
                            :value="filters.level ?? ''"
                            :class="ADS_FIELD"
                            :aria-label="t('ads.control.decisions.log_level')"
                            @change="setLog('level', ($event.target as HTMLSelectElement).value || null)"
                        >
                            <option value="">{{ t('ads.control.decisions.log_level') }}: {{ t('ads.control.decisions.any') }}</option>
                            <option v-for="l in ['campaign', 'adset', 'ad']" :key="l" :value="l">{{ t(`ads.campaigns.level_${l}`) }}</option>
                        </select>
                        <select
                            :value="filters.result ?? ''"
                            :class="ADS_FIELD"
                            :aria-label="t('ads.control.decisions.log_result')"
                            @change="setLog('result', ($event.target as HTMLSelectElement).value || null)"
                        >
                            <option value="">{{ t('ads.control.decisions.log_result') }}: {{ t('ads.control.decisions.any') }}</option>
                            <option v-for="r in ['ok', 'error', 'pending', 'dry_run']" :key="r" :value="r">{{ t(`ads.control.decisions.result_${r}`) }}</option>
                        </select>
                    </div>
                    <DataTable
                        table-id="ads-decisions-log"
                        :columns="logColumns"
                        :rows="log"
                        :empty="t('ads.control.decisions.empty_log')"
                        :empty-icon="History"
                        :caption="t('ads.control.decisions.tab_log')"
                    >
                        <template #cell-at="{ row }"><span class="tabular-nums text-fg-2">{{ row.at ? formatDateTime(row.at, locale) : '—' }}</span></template>
                        <template #cell-user="{ row }"><bdi>{{ row.user ?? '—' }}</bdi></template>
                        <template #cell-name="{ row }">
                            <button v-if="row.ad_id" type="button" class="max-w-full truncate text-start font-medium hover:underline" dir="auto" @click="drawer.open(row.ad_id)">
                                {{ row.name }}
                            </button>
                            <span v-else dir="auto" class="font-medium">{{ row.name }}</span>
                            <span class="flex items-center gap-1.5 text-xs text-fg-3">
                                <PlatformChip :platform="row.platform" size="xs" icon-only />{{ t(`ads.campaigns.level_${row.level}`) }} · <bdi>{{ row.account }}</bdi>
                            </span>
                        </template>
                        <template #cell-change="{ row }">
                            <span v-if="row.type === 'set_budget'" class="tabular-nums">{{ budgetChange(row) }}</span>
                            <template v-else>{{ t(writeChangeKey(row.to_status)) }}</template>
                        </template>
                        <template #cell-result="{ row }">
                            <span class="flex flex-wrap items-center gap-1.5">
                                <StatusChip :label="t(`ads.control.decisions.result_${row.result}`)" :tone="resultTone(row.result)" size="sm" />
                                <button
                                    v-if="row.undoable && row.public_id"
                                    type="button"
                                    data-test="undo"
                                    class="inline-flex h-9 items-center gap-1 rounded-md px-2 text-xs font-semibold text-fg-2 hover:bg-sunken md:h-7"
                                    :aria-label="t('ads.control.decisions.undo_aria', { name: row.name })"
                                    @click="undo(row)"
                                >
                                    <Undo2 class="rtl-flip size-3.5" aria-hidden="true" />{{ t('ads.control.decisions.undo') }}
                                </button>
                            </span>
                            <span v-if="row.error" class="block text-xs text-danger">{{ row.error }}</span>
                        </template>
                    </DataTable>
                </template>
            </div>
        </div>
        <WriteActionDialog
            v-if="undoRow"
            v-model:open="undoOpen"
            :account-id="undoRow.account_id"
            :account="undoRow.account"
            :platform="String(undoRow.platform)"
            :level="undoRow.level"
            :external-id="undoRow.external_id"
            :name="undoRow.name"
            :undo-of="undoRow.public_id"
            :currency="currency"
            @done="router.reload({ only: ['log'] })"
        />
        <AdDrawer
            :ad-id="drawer.adId.value"
            :filters="filters"
            :currency="currency"
            :data-at="freshness"
            :reload-only="['suggestions', 'counts', 'log', 'alerts', 'alertsMeta']"
            @close="drawer.close"
        />
    </AppLayout>
</template>
