<script setup lang="ts">
import DigestCard from '@/components/ads/DigestCard.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import SectionTabs from '@/components/crm/SectionTabs.vue';
import SkeletonList from '@/components/crm/SkeletonList.vue';
import DayToggle from '@/components/today/DayToggle.vue';
import HourBars from '@/components/today/HourBars.vue';
import Ledger from '@/components/today/Ledger.vue';
import TeamLine from '@/components/today/TeamLine.vue';
import TodayCard from '@/components/today/TodayCard.vue';
import UrgentStrip from '@/components/today/UrgentStrip.vue';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCount } from '@/lib/format';
import { confirmationsRows } from '@/lib/orderConfirmations';
import { sectionTabs } from '@/lib/sectionTabs';
import { adsRows, chatsRows, formatTodayDate, hourBars, ledgerFigures, oldestStamp, ordersRows, whyRows } from '@/lib/today';
import type { SharedData } from '@/types';
import type { DigestData } from '@/types/ads';
import type { TeamRow, TodayCardsData, TodayMode, UrgentItem } from '@/types/today';
import { Deferred, Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    mode: TodayMode;
    date: string;
    generated_at: string;
    /** The cards' own cache stamp (deferred with them): the page shows the older of the two. */
    cards_generated_at?: string;
    urgent: UrgentItem[] | null;
    cards?: TodayCardsData;
    team?: TeamRow[];
    /** The 09:00 ads digest (S5, deferred) on the today view; null on yesterday, absent while it loads. */
    digest?: DigestData | null;
}>();

const { t, locale } = useI18n();
/** The shared props the tab strip reads (role, the confirmations badge, the URL) and the room link. */
const page = usePage<SharedData>();

const breadcrumbs = computed(() => [{ title: t('today.title'), href: '/today' }]);
const c = computed(() => props.cards);
const freshness = computed(() => oldestStamp([props.generated_at, props.cards_generated_at]));
const figures = computed(() => (c.value ? ledgerFigures(c.value, t, locale.value) : []));
/** Today runs to the hour of the cards' stamp (Cairo); yesterday is the whole day. */
const lastHour = computed(() => {
    if (props.mode === 'yesterday') return 23;
    const at = Date.parse(props.cards_generated_at ?? props.generated_at);
    if (Number.isNaN(at)) return 23;

    return Number(new Intl.DateTimeFormat('en-GB', { hour: 'numeric', hourCycle: 'h23', timeZone: 'Africa/Cairo' }).format(new Date(at)));
});
const hours = computed(() => hourBars(c.value?.chats.by_hour, lastHour.value, locale.value));
const whyShares = computed(() => Object.fromEntries((c.value?.why.reasons ?? []).map((r) => [r.key, r.share])));
</script>

<template>
    <Head :title="t('today.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-[1400px] space-y-4 p-4 md:space-y-5 md:p-6">
            <PageHeader
                :title="mode === 'today' ? t('today.title') : t('today.title_yesterday')"
                :description="mode === 'today' ? t('today.description') : t('today.description_yesterday')"
                :freshness="freshness"
            >
                <template #extra>
                    <span class="text-[15px] font-medium text-fg-2" data-today-date>{{ formatTodayDate(date, locale) }}</span>
                </template>
                <DayToggle :mode="mode" />
            </PageHeader>
            <SectionTabs :tabs="sectionTabs.today(t, page.props.auth.user?.role)" :label="t('section_tabs.today.label')" />

            <Deferred data="cards">
                <template #fallback>
                    <SkeletonList variant="tiles" :count="6" :aria-label="t('today.loading')" />
                </template>
                <Ledger v-if="c" :figures="figures" :label="t('today.ledger.label')" data-today-ledger />
            </Deferred>

            <div class="grid gap-4 lg:grid-cols-[minmax(0,1.35fr)_minmax(0,1fr)] [&>*]:min-w-0">
                <UrgentStrip v-if="urgent !== null" :items="urgent" class="self-start" />
                <div class="flex flex-col gap-4" :class="urgent === null ? 'lg:col-span-2' : ''">
                    <Deferred data="team">
                        <template #fallback>
                            <SkeletonList variant="table" :count="4" />
                        </template>
                        <TeamLine v-if="team" :rows="team" :mode="mode" :can-see-board="page.props.canSeeBoard === true" />
                    </Deferred>
                    <Deferred data="cards">
                        <template #fallback>
                            <SkeletonList variant="cards" :count="1" />
                        </template>
                        <TodayCard
                            v-if="c && hours.length"
                            :title="t('today.chart.title')"
                            :hint="mode === 'today' ? t('today.chart.hint_today') : t('today.chart.hint_yesterday')"
                            :rows="[]"
                            data-today-hours
                        >
                            <HourBars :bars="hours" :label="t('today.chart.title')" :unit="t('today.chart.unit')" />
                        </TodayCard>
                    </Deferred>
                </div>
            </div>

            <Deferred data="cards">
                <template #fallback>
                    <SkeletonList variant="cards" :count="3" />
                </template>
                <div v-if="c" class="grid items-start gap-4 md:grid-cols-2 xl:grid-cols-3 [&>*]:min-w-0">
                    <TodayCard
                        :title="t('today.cards.orders')"
                        :hint="t('today.cards.orders_hint')"
                        :rows="ordersRows(c.orders, t, locale)"
                        :href="c.orders.links.count"
                        :link-label="t('today.cards.all_orders')"
                        data-today-orders-hint
                    />
                    <TodayCard
                        v-if="c.confirmations"
                        :title="t('order_confirmations.today.title')"
                        :rows="confirmationsRows(c.confirmations, t, locale)"
                        :href="c.confirmations.links.sent"
                        :link-label="t('today.cards.open')"
                        data-today-confirmations
                    />
                    <TodayCard
                        :title="t('today.cards.why')"
                        :hint="c.why.total > 0 ? t('today.why.total', { n: formatCount(c.why.total, locale) }) : t('today.cards.why_hint')"
                        :rows="whyRows(c.why, t, locale)"
                        :bars="whyShares"
                        :empty="t('today.cards.why_empty')"
                    />
                    <TodayCard :title="t('today.cards.chats')" :rows="chatsRows(c.chats, t, locale)" />
                    <TodayCard
                        :title="t('today.cards.ads')"
                        :hint="mode === 'today' ? t('today.cards.ads_window_today') : t('today.cards.ads_window_yesterday')"
                        :rows="c.ads ? adsRows(c.ads, t, locale) : []"
                        :empty="t('today.cards.ads_none')"
                    >
                        <p v-if="c.ads?.currency === 'mixed'" class="mt-2 text-xs text-fg-3">{{ t('today.cards.ads_mixed') }}</p>
                    </TodayCard>
                </div>
            </Deferred>

            <Deferred v-if="mode === 'today'" data="digest">
                <template #fallback>
                    <SkeletonList variant="tiles" :count="4" />
                </template>
                <DigestCard v-if="digest" :digest="digest" data-today-digest />
            </Deferred>
        </div>
    </AppLayout>
</template>
