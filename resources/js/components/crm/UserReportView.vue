<script setup lang="ts">
import BarChart from '@/components/crm/BarChart.vue';
import DataTable from '@/components/crm/DataTable.vue';
import Heatmap from '@/components/crm/Heatmap.vue';
import PlatformBadge from '@/components/crm/PlatformBadge.vue';
import SettingsPanel from '@/components/crm/settings/SettingsPanel.vue';
import Ledger, { type LedgerFigure } from '@/components/today/Ledger.vue';
import { useI18n } from '@/composables/useI18n';
import { formatAvgSeconds, formatCount, formatMinutes, formatMoney } from '@/lib/format';
import type { SharedData } from '@/types';
import type { HeatmapGrid, ManagedUser, ReportRange, UserMetrics } from '@/types/admin';
import type { PlatformValue } from '@/types/crm';
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronRight } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * One person's numbers (mockup V.team drill and V.me): a ledger of the headline figures, the rest as a key-value
 * panel, then her role in the chats, per platform, the hour heatmap and the activity link. `points` (her own
 * «نقطي» on /reports/me) takes the ledger's last slot; the online time moves to the panel then.
 */
const props = withDefaults(
    defineProps<{
        range: ReportRange;
        platform: PlatformValue | null;
        user: ManagedUser;
        metrics: UserMetrics;
        heatmap: HeatmapGrid;
        points?: number | null;
    }>(),
    { points: null },
);

const { t, locale } = useI18n();
const page = usePage<SharedData>();

const canSeeActivity = computed(() => ['admin', 'supervisor'].includes(page.props.auth.user.role ?? ''));
const n = (v: number) => formatCount(v, locale.value);
const hasPoints = computed(() => props.points !== null && props.points !== undefined);

const figures = computed<LedgerFigure[]>(() => {
    const m = props.metrics;
    const last: LedgerFigure = hasPoints.value
        ? { key: 'points', label: t('queue_points.mine'), value: n(props.points ?? 0) }
        : { key: 'online', label: t('reports.user.online_time'), value: formatMinutes(m.online_minutes, locale.value) };
    return [
        { key: 'conversations', label: t('reports.user.conversations_handled'), value: n(m.conversations_handled) },
        { key: 'resolved', label: t('reports.user.resolved'), value: n(m.resolved) },
        { key: 'first_response', label: t('reports.user.avg_first_response'), value: formatAvgSeconds(m.avg_first_response_sec, locale.value) },
        { key: 'orders', label: t('reports.user.orders_count'), value: n(m.orders_count) },
        { key: 'revenue', label: t('reports.user.orders_total'), value: formatMoney(m.orders_total, locale.value) },
        last,
    ];
});

const details = computed(() => {
    const m = props.metrics;
    return [
        { key: 'messages_sent', label: t('reports.user.messages_sent'), value: n(m.messages_sent) },
        { key: 'avg_response', label: t('reports.user.avg_response'), value: formatAvgSeconds(m.avg_response_sec, locale.value) },
        { key: 'comments', label: t('reports.user.comments_handled'), value: n(m.comments_handled) },
        { key: 'private_replies', label: t('reports.user.private_replies'), value: n(m.private_replies) },
        { key: 'cod', label: t('reports.user.cod_count'), value: n(m.cod_count) },
        { key: 'payment_links', label: t('reports.user.payment_links'), value: `${n(m.payment_link_paid)}/${n(m.payment_link_count)}` },
        ...(hasPoints.value ? [{ key: 'online', label: t('reports.user.online_time'), value: formatMinutes(m.online_minutes, locale.value) }] : []),
    ];
});

const roles = computed(() => [
    { key: 'first', label: t('reports.user.first_responses'), value: props.metrics.first_responses },
    { key: 'continued', label: t('reports.user.continued'), value: props.metrics.continued },
    { key: 'follow_ups', label: t('reports.user.follow_ups'), value: props.metrics.follow_ups },
]);

const platformRows = computed(() =>
    (page.props.platforms ?? []).map((p) => ({
        id: p.value,
        platform: p.value,
        ...(props.metrics.by_platform?.[p.value] ?? { messages_sent: 0, orders_count: 0 }),
    })),
);

const activityHref = computed(() => `/reports/activity?user_id=${props.user.id}&from=${props.range.from}&to=${props.range.to}`);
</script>

<template>
    <div class="space-y-4">
        <Ledger :figures="figures" :label="t('reports.hub.figures')" />

        <slot name="after-ledger" />

        <div class="grid gap-4 lg:grid-cols-2 [&>*]:min-w-0">
            <SettingsPanel :title="t('reports.hub.more_figures')">
                <dl>
                    <div
                        v-for="d in details"
                        :key="d.key"
                        class="flex items-center justify-between gap-3 border-b border-line py-2.5 text-[13.5px] last:border-b-0"
                    >
                        <dt class="min-w-0 text-fg-2">{{ d.label }}</dt>
                        <dd class="shrink-0 font-semibold tabular-nums">{{ d.value }}</dd>
                    </div>
                </dl>
            </SettingsPanel>
            <BarChart :title="t('reports.roles_breakdown')" :items="roles" :format="n" />
        </div>

        <section class="space-y-2" :aria-label="t('reports.platform_table')">
            <h2 class="text-base font-bold">{{ t('reports.platform_table') }}</h2>
            <DataTable
                table-id="user-report-platforms"
                :columns="[
                    { key: 'platform', label: t('orders.columns.platform'), primary: true },
                    { key: 'messages_sent', label: t('reports.user.messages_sent'), numeric: true },
                    { key: 'orders_count', label: t('reports.user.orders_count'), numeric: true },
                ]"
                :rows="platformRows"
            >
                <template #cell-platform="{ row }"><PlatformBadge :platform="row.platform" show-label /></template>
                <template #cell-messages_sent="{ value }"
                    ><span class="tabular-nums">{{ n(value as number) }}</span></template
                >
                <template #cell-orders_count="{ value }"
                    ><span class="tabular-nums">{{ n(value as number) }}</span></template
                >
            </DataTable>
        </section>

        <Heatmap :title="t('reports.heatmap_title')" :grid="heatmap" />

        <section class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-line bg-card px-4 py-3 text-13">
            <h2 class="font-bold text-foreground">{{ t('reports.recent_activity') }}</h2>
            <Link
                v-if="canSeeActivity"
                :href="activityHref"
                class="inline-flex min-h-10 items-center gap-1 font-semibold text-brand-ink hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
                {{ t('reports.activity_link') }}
                <ChevronRight class="rtl-flip size-4" aria-hidden="true" />
            </Link>
            <p v-else class="text-fg-2">{{ t('reports.activity_unavailable') }}</p>
        </section>
    </div>
</template>
