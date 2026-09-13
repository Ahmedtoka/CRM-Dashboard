<script setup lang="ts">
import BarChart from '@/components/crm/BarChart.vue';
import Leaderboard from '@/components/crm/Leaderboard.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import ReportFilters from '@/components/crm/ReportFilters.vue';
import StatCard from '@/components/crm/StatCard.vue';
import { useI18n } from '@/composables/useI18n';
import { useReportFilters } from '@/composables/useReportFilters';
import { formatNumber } from '@/i18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCount, formatMoney, formatSeconds } from '@/lib/format';
import type { SharedData } from '@/types';
import type { HeatmapGrid, LeaderboardRow, ReportRange, TeamMetrics } from '@/types/admin';
import type { PlatformValue } from '@/types/crm';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    range: ReportRange;
    platform: PlatformValue | null;
    metrics: TeamMetrics;
    leaderboard: LeaderboardRow[];
    heatmap: HeatmapGrid;
    online_user_ids: number[];
}>();

const { t, locale } = useI18n();
const page = usePage<SharedData>();
const { visit } = useReportFilters();
const n = (v: number) => formatCount(v, locale.value);

const cards = computed(() => {
    const m = props.metrics;
    return [
        { label: t('reports.cards.inbound'), value: n(m.inbound_messages) },
        { label: t('reports.cards.outbound'), value: n(m.outbound_messages) },
        { label: t('reports.cards.bot_messages'), value: n(m.bot_messages) },
        { label: t('reports.cards.avg_first_response'), value: formatSeconds(m.avg_first_response_sec, locale.value) },
        { label: t('reports.cards.waiting_now'), value: n(m.waiting_now), tone: m.waiting_now > 0 ? 'warning' : 'default' },
        { label: t('reports.cards.needs_human_now'), value: n(m.needs_human_now), tone: m.needs_human_now > 0 ? 'negative' : 'default' },
        { label: t('reports.cards.orders'), value: n(m.orders_count) },
        { label: t('reports.cards.revenue'), value: formatMoney(m.orders_total, locale.value) },
    ] as const;
});

// Platform series: bars take the platform's brand color.
const byPlatform = computed(() =>
    (page.props.platforms ?? []).map((p) => ({ key: p.value, label: p.label, color: p.color, value: props.metrics.by_platform?.[p.value]?.inbound ?? 0 })),
);
const revenueByPlatform = computed(() =>
    (page.props.platforms ?? []).map((p) => ({ key: p.value, label: p.label, color: p.color, value: props.metrics.by_platform?.[p.value]?.orders_total ?? 0 })),
);

const byHour = computed(() =>
    (props.metrics.by_hour ?? []).map((value, hour) => ({ key: String(hour), label: formatNumber(locale.value, hour, { useGrouping: false }), value })),
);

function openUser(userId: number): void {
    router.visit(`/reports/users/${userId}?from=${props.range.from}&to=${props.range.to}`);
}

const breadcrumbs = computed(() => [{ title: t('reports.team_title'), href: '/reports/team' }]);
</script>

<template>
    <Head :title="t('reports.team_title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-4 p-4">
            <PageHeader :title="t('reports.team_title')" />
            <ReportFilters :range="range" :platform="platform" @change="visit" />

            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                <StatCard v-for="card in cards" :key="card.label" :label="card.label" :value="card.value" :tone="'tone' in card ? card.tone : 'default'" />
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <BarChart :title="t('reports.by_platform')" :items="byPlatform" :format="n" />
                <BarChart :title="t('reports.revenue_by_platform')" :items="revenueByPlatform" :format="(v) => formatMoney(v, locale)" />
            </div>
            <BarChart :title="t('reports.by_hour')" :items="byHour" orientation="vertical" :label-every="3" :format="n" />

            <section class="space-y-2">
                <div>
                    <h2 class="text-sm font-medium">{{ t('reports.leaderboard') }}</h2>
                    <p class="text-2xs text-muted-foreground">{{ t('reports.leaderboard_hint') }}</p>
                </div>
                <Leaderboard :rows="leaderboard" :online-user-ids="online_user_ids" @open="openUser" />
            </section>
        </div>
    </AppLayout>
</template>
