<script setup lang="ts">
/** «محتاج قرار» (spec 4.1, U 5.2): approvals (S1) on top, S5 alert cards, stop suggestions; tabs open/snoozed/closed/log. */
import AdDrawer from '@/components/ads/AdDrawer.vue';
import AdStatusButton from '@/components/ads/AdStatusButton.vue';
import AdsFilterBar from '@/components/ads/AdsFilterBar.vue';
import AlertsSection from '@/components/ads/AlertsSection.vue';
import CreativeThumb from '@/components/ads/CreativeThumb.vue';
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
import type { AdActionLogRow, AdSuggestion, AdsDecisionsProps, DecisionsTab } from '@/types/ads';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { CheckCircle2, ChevronRight, History, Rocket } from 'lucide-vue-next';
import { computed } from 'vue';

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
const resultTone = (r: AdActionLogRow['result']) => (r === 'ok' ? 'positive' : r === 'pending' ? 'info' : 'negative');
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

                    <AlertsSection v-if="alertsMeta" :alerts="alerts" :meta="alertsMeta" mode="open" :data-at="freshness" :currency="currency" @open-ad="drawer.open" />

                    <AdsPanel v-if="suggestions.length" data-test="suggestions" :title="t('ads.control.decisions.suggestions')" :count="suggestions.length" flush>
                        <DecisionRow v-for="s in suggestions" :key="s.ad_id">
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
                        v-if="!suggestions.length && !(approvals && approvals.count) && !alerts.length"
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
                            <option v-for="r in ['ok', 'error', 'pending']" :key="r" :value="r">{{ t(`ads.control.decisions.result_${r}`) }}</option>
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
                        <template #cell-change="{ row }">{{ t(writeChangeKey(row.to_status)) }}</template>
                        <template #cell-result="{ row }">
                            <StatusChip :label="t(`ads.control.decisions.result_${row.result}`)" :tone="resultTone(row.result)" size="sm" />
                            <span v-if="row.error" class="block text-xs text-danger">{{ row.error }}</span>
                        </template>
                    </DataTable>
                </template>
            </div>
        </div>
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
