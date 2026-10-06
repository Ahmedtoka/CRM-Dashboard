<script setup lang="ts">
/** «محتاج قرار» (spec 4.1, U 5.2): approvals (S1) on top, S5 alert cards, stop suggestions; tabs open/snoozed/closed/log. */
import AdDrawer from '@/components/ads/AdDrawer.vue';
import AdStatusButton from '@/components/ads/AdStatusButton.vue';
import AdsFilterBar from '@/components/ads/AdsFilterBar.vue';
import AlertsSection from '@/components/ads/AlertsSection.vue';
import CreativeThumb from '@/components/ads/CreativeThumb.vue';
import DataHealthBanner from '@/components/ads/DataHealthBanner.vue';
import WhyList from '@/components/ads/WhyList.vue';
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { useAdDrawer } from '@/composables/useAdDrawer';
import { useI18n } from '@/composables/useI18n';
import { usePathVisitLoading } from '@/composables/usePathVisitLoading';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatAdsMoney, reasonTexts } from '@/lib/ads';
import { buildHref, readQuery, withParam } from '@/lib/adsFilters';
import { formatCount, formatDateTime } from '@/lib/format';
import type { AdActionLogRow, AdSuggestion, AdsDecisionsProps, DecisionsTab } from '@/types/ads';
import { Head, Link, router } from '@inertiajs/vue3';
import { CheckCircle2, History } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<AdsDecisionsProps>();
const { t, locale } = useI18n();
const loading = usePathVisitLoading('/ads/decisions');
const drawer = useAdDrawer();
const TABS: DecisionsTab[] = ['open', 'snoozed', 'closed', 'log'];
const search = () => (typeof window === 'undefined' ? '' : window.location.search);
/** Switching tab drops the log filters (they belong to the log tab only). */
const tabHref = (tab: DecisionsTab) => {
    const q = readQuery(search());
    for (const k of ['who', 'level', 'result']) delete q[k];
    return buildHref('/ads/decisions', withParam(q, 'tab', tab, { tab: 'open' }));
};
const count = (tab: DecisionsTab) => (tab === 'log' ? null : props.counts[tab]);
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
const crumbs = computed(() => [{ label: t('nav.ads'), href: '/ads' }, { label: t('ads.control.decisions.title') }]);
const appCrumbs = computed(() => crumbs.value.map((c) => ({ title: c.label, href: c.href ?? '/ads/decisions' })));
const selectClass = 'h-9 rounded-md border border-input bg-background px-2 text-xs';
</script>

<template>
    <Head :title="t('ads.control.decisions.title')" />
    <AppLayout :breadcrumbs="appCrumbs">
        <div class="mx-auto w-full min-w-0 max-w-[1400px] space-y-4 p-3 md:p-6">
            <PageHeader :title="t('ads.control.decisions.title')" :description="t('ads.control.decisions.description')" :breadcrumbs="crumbs" :freshness="freshness" />
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
            <div class="space-y-4 transition-opacity" :class="loading ? 'opacity-60' : ''" :aria-busy="loading" data-test="page-body">

            <nav class="scrollbar-none flex gap-1 overflow-x-auto border-b border-border" :aria-label="t('ads.control.decisions.title')">
                <Link
                    v-for="tab in TABS"
                    :key="tab"
                    :data-test="`tab-${tab}`"
                    :href="tabHref(tab)"
                    class="-mb-px inline-flex h-10 shrink-0 items-center gap-1 border-b-2 px-3 text-xs font-medium"
                    :class="filters.tab === tab ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground'"
                    :aria-current="filters.tab === tab ? 'page' : undefined"
                >
                    {{ t(`ads.control.decisions.tab_${tab}`) }}
                    <span v-if="count(tab) !== null" class="tabular-nums">{{ formatCount(count(tab) ?? 0, locale) }}</span>
                </Link>
            </nav>

            <template v-if="filters.tab === 'open'">
                <section v-if="approvals && approvals.count > 0" data-test="approvals" class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-surface-accent p-4">
                    <div>
                        <h2 class="text-sm font-semibold">{{ t('ads.control.decisions.approvals') }}</h2>
                        <p class="text-xs">{{ t('ads.control.decisions.approvals_body', { n: formatCount(approvals.count, locale) }) }}</p>
                    </div>
                    <Link :href="approvals.href" class="inline-flex h-11 items-center rounded-md bg-primary px-4 text-xs font-medium text-primary-foreground">
                        {{ t('ads.control.decisions.approvals_open') }}
                    </Link>
                </section>

                <AlertsSection v-if="alertsMeta" :alerts="alerts" :meta="alertsMeta" mode="open" :data-at="freshness" :currency="currency" @open-ad="drawer.open" />

                <section v-if="suggestions.length" data-test="suggestions" class="space-y-2">
                    <h2 class="text-sm font-semibold">{{ t('ads.control.decisions.suggestions') }}</h2>
                    <ul class="space-y-2">
                        <li v-for="s in suggestions" :key="s.ad_id" class="flex flex-wrap items-start gap-3 rounded-lg bg-card p-3 shadow-card">
                            <button type="button" class="shrink-0" :aria-label="t('ads.control.row.preview')" @click="drawer.open(s.ad_id)">
                                <CreativeThumb :ad="thumb(s)" :size="56" :show-pills="false" />
                            </button>
                            <div class="min-w-0 flex-1 space-y-1">
                                <button type="button" class="block max-w-full truncate text-start text-sm font-medium hover:underline" dir="auto" @click="drawer.open(s.ad_id)">{{ s.name }}</button>
                                <p class="truncate text-2xs text-muted-foreground">
                                    <span dir="auto">{{ s.account }}</span><template v-if="s.campaign"> · <span dir="auto">{{ s.campaign }}</span></template> ·
                                    {{ t(`ads.control.objective.${s.objective}`) }}
                                </p>
                                <WhyList :reasons="s.reasons" :currency="currency" />
                                <button type="button" class="text-2xs text-muted-foreground hover:underline" @click="drawer.open(s.ad_id)">
                                    {{ t('ads.control.decisions.spend_days', { amount: formatAdsMoney(s.spend_tax, locale, currency) }) }}
                                </button>
                            </div>
                            <div class="flex w-full items-center justify-end gap-2 sm:w-auto">
                                <button type="button" class="h-11 rounded-md px-3 text-xs text-primary hover:bg-muted" @click="drawer.open(s.ad_id)">{{ t('ads.control.row.why') }}</button>
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
                                    size="md"
                                />
                            </div>
                        </li>
                    </ul>
                </section>
                <EmptyState v-if="!suggestions.length && !(approvals && approvals.count) && !alerts.length" :icon="CheckCircle2" :title="t('ads.control.decisions.empty_open')" />
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
                    <select :value="filters.who ?? ''" :class="selectClass" :aria-label="t('ads.control.decisions.log_who')" @change="setLog('who', ($event.target as HTMLSelectElement).value || null)">
                        <option value="">{{ t('ads.control.decisions.log_who') }}: {{ t('ads.control.decisions.any') }}</option>
                        <option v-for="u in log_users" :key="u.id" :value="u.id">{{ u.name }}</option>
                    </select>
                    <select :value="filters.level ?? ''" :class="selectClass" :aria-label="t('ads.control.decisions.log_level')" @change="setLog('level', ($event.target as HTMLSelectElement).value || null)">
                        <option value="">{{ t('ads.control.decisions.log_level') }}: {{ t('ads.control.decisions.any') }}</option>
                        <option v-for="l in ['campaign', 'adset', 'ad']" :key="l" :value="l">{{ t(`ads.campaigns.level_${l}`) }}</option>
                    </select>
                    <select :value="filters.result ?? ''" :class="selectClass" :aria-label="t('ads.control.decisions.log_result')" @change="setLog('result', ($event.target as HTMLSelectElement).value || null)">
                        <option value="">{{ t('ads.control.decisions.log_result') }}: {{ t('ads.control.decisions.any') }}</option>
                        <option v-for="r in ['ok', 'error', 'pending']" :key="r" :value="r">{{ t(`ads.control.decisions.result_${r}`) }}</option>
                    </select>
                </div>
                <DataTable table-id="ads-decisions-log" :columns="logColumns" :rows="log" :empty="t('ads.control.decisions.empty_log')" :empty-icon="History" :caption="t('ads.control.decisions.tab_log')">
                    <template #cell-at="{ row }"><span class="tabular-nums">{{ row.at ? formatDateTime(row.at, locale) : '—' }}</span></template>
                    <template #cell-user="{ row }"><bdi>{{ row.user ?? '—' }}</bdi></template>
                    <template #cell-name="{ row }">
                        <button v-if="row.ad_id" type="button" class="max-w-full truncate text-start hover:underline" dir="auto" @click="drawer.open(row.ad_id)">{{ row.name }}</button>
                        <span v-else dir="auto">{{ row.name }}</span>
                        <span class="block text-2xs text-muted-foreground">{{ t(`ads.campaigns.level_${row.level}`) }} · <span dir="auto">{{ row.account }}</span></span>
                    </template>
                    <template #cell-change="{ row }">{{ row.to_status === 'PAUSED' ? t('ads.control.write.done_stop') : t('ads.control.write.done_run') }}</template>
                    <template #cell-result="{ row }">
                        <StatusChip :label="t(`ads.control.decisions.result_${row.result}`)" :tone="resultTone(row.result)" />
                        <span v-if="row.error" class="block text-2xs text-destructive">{{ row.error }}</span>
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
