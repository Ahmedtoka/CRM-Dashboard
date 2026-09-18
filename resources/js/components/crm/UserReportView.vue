<script setup lang="ts">
import BarChart from '@/components/crm/BarChart.vue';
import DataTable from '@/components/crm/DataTable.vue';
import Heatmap from '@/components/crm/Heatmap.vue';
import PlatformBadge from '@/components/crm/PlatformBadge.vue';
import StatCard from '@/components/crm/StatCard.vue';
import { useI18n } from '@/composables/useI18n';
import { formatCount, formatMinutes, formatMoney, formatSeconds } from '@/lib/format';
import type { SharedData } from '@/types';
import type { HeatmapGrid, ManagedUser, ReportRange, UserMetrics } from '@/types/admin';
import type { PlatformValue } from '@/types/crm';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{ range: ReportRange; platform: PlatformValue | null; user: ManagedUser; metrics: UserMetrics; heatmap: HeatmapGrid }>();

const { t, locale } = useI18n();
const page = usePage<SharedData>();

const canSeeActivity = computed(() => ['admin', 'supervisor'].includes(page.props.auth.user.role ?? ''));
const n = (v: number) => formatCount(v, locale.value);

const cards = computed(() => [
    { label: t('reports.user.messages_sent'), value: n(props.metrics.messages_sent) },
    { label: t('reports.user.conversations_handled'), value: n(props.metrics.conversations_handled) },
    { label: t('reports.user.resolved'), value: n(props.metrics.resolved) },
    { label: t('reports.user.avg_first_response'), value: formatSeconds(props.metrics.avg_first_response_sec, locale.value) },
    { label: t('reports.user.avg_response'), value: formatSeconds(props.metrics.avg_response_sec, locale.value) },
    { label: t('reports.user.comments_handled'), value: n(props.metrics.comments_handled) },
    { label: t('reports.user.private_replies'), value: n(props.metrics.private_replies) },
    { label: t('reports.user.orders_count'), value: n(props.metrics.orders_count) },
    { label: t('reports.user.orders_total'), value: formatMoney(props.metrics.orders_total, locale.value) },
    { label: t('reports.user.cod_count'), value: n(props.metrics.cod_count) },
    { label: t('reports.user.payment_links'), value: `${n(props.metrics.payment_link_paid)}/${n(props.metrics.payment_link_count)}` },
    { label: t('reports.user.online_time'), value: formatMinutes(props.metrics.online_minutes, locale.value) },
]);

const roles = computed(() => [
    { key: 'first', label: t('reports.user.first_responses'), value: props.metrics.first_responses },
    { key: 'continued', label: t('reports.user.continued'), value: props.metrics.continued },
    { key: 'follow_ups', label: t('reports.user.follow_ups'), value: props.metrics.follow_ups },
]);

const platformRows = computed(() =>
    (page.props.platforms ?? []).map((p) => ({ id: p.value, platform: p.value, ...(props.metrics.by_platform?.[p.value] ?? { messages_sent: 0, orders_count: 0 }) })),
);

const activityHref = computed(() => `/reports/activity?user_id=${props.user.id}&from=${props.range.from}&to=${props.range.to}`);
</script>

<template>
    <div class="space-y-4">
        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            <StatCard v-for="card in cards" :key="card.label" :label="card.label" :value="card.value" />
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <BarChart :title="t('reports.roles_breakdown')" :items="roles" :format="n" />
            <section class="space-y-2">
                <h2 class="text-xs font-medium text-foreground">{{ t('reports.platform_table') }}</h2>
                <DataTable
                    :columns="[
                        { key: 'platform', label: t('orders.columns.platform') },
                        { key: 'messages_sent', label: t('reports.user.messages_sent'), align: 'end' },
                        { key: 'orders_count', label: t('reports.user.orders_count'), align: 'end' },
                    ]"
                    :rows="platformRows"
                >
                    <template #cell-platform="{ row }"><PlatformBadge :platform="row.platform" show-label /></template>
                    <template #cell-messages_sent="{ value }"><span class="tabular-nums">{{ n(value as number) }}</span></template>
                    <template #cell-orders_count="{ value }"><span class="tabular-nums">{{ n(value as number) }}</span></template>
                </DataTable>
            </section>
        </div>

        <Heatmap :title="t('reports.heatmap_title')" :grid="heatmap" />

        <section class="rounded-lg bg-card p-3 text-xs shadow-card">
            <h2 class="mb-1 font-medium text-foreground">{{ t('reports.recent_activity') }}</h2>
            <Link v-if="canSeeActivity" :href="activityHref" class="text-primary hover:underline">{{ t('reports.activity_link') }}</Link>
            <p v-else class="text-muted-foreground">{{ t('reports.activity_unavailable') }}</p>
        </section>
    </div>
</template>
