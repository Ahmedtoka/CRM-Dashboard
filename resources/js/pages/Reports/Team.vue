<script setup lang="ts">
import BarChart from '@/components/crm/BarChart.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import Leaderboard from '@/components/crm/Leaderboard.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import PeriodControls from '@/components/crm/reports/PeriodControls.vue';
import RatingsSection from '@/components/crm/reports/RatingsSection.vue';
import { teamTabs } from '@/components/crm/reports/teamTabs';
import SectionTabs from '@/components/crm/SectionTabs.vue';
import SkeletonList from '@/components/crm/SkeletonList.vue';
import Ledger, { type LedgerFigure } from '@/components/today/Ledger.vue';
import { useI18n } from '@/composables/useI18n';
import { useReportFilters } from '@/composables/useReportFilters';
import { formatNumber } from '@/i18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatAvgSeconds, formatCount, formatMoney } from '@/lib/format';
import type { SharedData } from '@/types';
import type { HeatmapGrid, LeaderboardRow, ReportRange, TeamMetrics } from '@/types/admin';
import type { PlatformValue } from '@/types/crm';
import type { TeamRatings } from '@/types/today';
import { Head, router, usePage } from '@inertiajs/vue3';
import { BarChart3 } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    range: ReportRange;
    platform: PlatformValue | null;
    metrics: TeamMetrics;
    leaderboard: LeaderboardRow[];
    heatmap: HeatmapGrid;
    online_user_ids: number[];
    ratings: TeamRatings;
}>();

const { t, locale } = useI18n();
const page = usePage<SharedData>();
const { visit, loading } = useReportFilters();
const n = (v: number) => formatCount(v, locale.value);
const tabs = computed(() => teamTabs(t, page.url));

// The period's ledger (mockup V.team): six figures; the bot's messages and the chats needing a person sit as notes.
const figures = computed<LedgerFigure[]>(() => {
    const m = props.metrics;
    return [
        { key: 'inbound', label: t('reports.cards.inbound'), value: n(m.inbound_messages) },
        {
            key: 'outbound',
            label: t('reports.cards.outbound'),
            value: n(m.outbound_messages),
            note: t('reports.hub.bot_note', { n: n(m.bot_messages) }),
        },
        { key: 'first_response', label: t('reports.cards.avg_first_response'), value: formatAvgSeconds(m.avg_first_response_sec, locale.value) },
        {
            key: 'waiting',
            label: t('reports.cards.waiting_now'),
            value: n(m.waiting_now),
            note: t('reports.hub.needs_human_note', { n: n(m.needs_human_now) }),
            tone: m.needs_human_now > 0 ? 'down' : 'neutral',
        },
        { key: 'orders', label: t('reports.cards.orders'), value: n(m.orders_count) },
        { key: 'revenue', label: t('reports.cards.revenue'), value: formatMoney(m.orders_total, locale.value) },
    ];
});

// Platform series: bars take the platform's brand color.
const byPlatform = computed(() =>
    (page.props.platforms ?? []).map((p) => ({
        key: p.value,
        label: p.label,
        color: p.color,
        value: props.metrics.by_platform?.[p.value]?.inbound ?? 0,
    })),
);
const revenueByPlatform = computed(() =>
    (page.props.platforms ?? []).map((p) => ({
        key: p.value,
        label: p.label,
        color: p.color,
        value: props.metrics.by_platform?.[p.value]?.orders_total ?? 0,
    })),
);

const byHour = computed(() =>
    (props.metrics.by_hour ?? []).map((value, hour) => ({
        key: String(hour),
        label: formatNumber(locale.value, hour, { useGrouping: false }),
        value,
    })),
);

function openUser(userId: number): void {
    router.visit(`/reports/users/${userId}?from=${props.range.from}&to=${props.range.to}`);
}

// Nothing happened in the range: no messages, no orders, nobody on the leaderboard.
const empty = computed(() => {
    const m = props.metrics;
    return m.inbound_messages + m.outbound_messages + m.bot_messages + m.orders_count === 0 && props.leaderboard.length === 0;
});

const breadcrumbs = computed(() => [{ title: t('reports.team_title'), href: '/reports/team' }]);
</script>

<template>
    <Head :title="t('reports.team_title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-7xl space-y-4 p-3 md:p-6">
            <PageHeader :title="t('reports.hub.title')" :description="t('reports.hub.description')">
                <PeriodControls :range="range" :platform="platform" @change="visit" />
            </PageHeader>
            <SectionTabs :tabs="tabs" :label="t('section_tabs.team.label')" />

            <SkeletonList v-if="loading" variant="tiles" :count="6" />
            <Ledger v-else :figures="figures" :label="t('reports.hub.figures')" />

            <div v-if="empty && !loading" class="rounded-lg border border-line bg-card">
                <EmptyState :icon="BarChart3" :title="t('reports.no_data')" />
            </div>
            <template v-else>
                <section class="space-y-2" :aria-label="t('reports.leaderboard')">
                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5">
                        <h2 class="text-base font-bold">{{ t('reports.leaderboard') }}</h2>
                        <p class="text-13 text-fg-2">{{ t('reports.leaderboard_hint') }}</p>
                    </div>
                    <Leaderboard :rows="leaderboard" :online-user-ids="online_user_ids" @open="openUser" />
                </section>

                <div class="grid gap-4 lg:grid-cols-2 [&>*]:min-w-0">
                    <BarChart :title="t('reports.by_platform')" :items="byPlatform" :format="n" />
                    <BarChart :title="t('reports.revenue_by_platform')" :items="revenueByPlatform" :format="(v) => formatMoney(v, locale)" />
                </div>
                <BarChart :title="t('reports.by_hour')" :items="byHour" orientation="vertical" :label-every="3" :format="n" />
            </template>

            <RatingsSection :ratings="ratings" :range="range" />
        </div>
    </AppLayout>
</template>
