<script setup lang="ts">
import BarChart from '@/components/crm/BarChart.vue';
import DataTable from '@/components/crm/DataTable.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import ReportFilters from '@/components/crm/ReportFilters.vue';
import StatCard from '@/components/crm/StatCard.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { useI18n } from '@/composables/useI18n';
import { useReportFilters } from '@/composables/useReportFilters';
import { formatNumber, translate } from '@/i18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCount } from '@/lib/format';
import type { BotMetrics, ReportRange } from '@/types/admin';
import type { PlatformValue } from '@/types/crm';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{ range: ReportRange; platform: PlatformValue | null; metrics: BotMetrics }>();

const { t, locale } = useI18n();
const { visit } = useReportFilters();
const n = (v: number) => formatCount(v, locale.value);

const cards = computed(() => {
    const m = props.metrics;
    return [
        { label: t('reports.bot.messages_sent'), value: n(m.messages_sent) },
        { label: t('reports.bot.conversations_touched'), value: n(m.conversations_touched) },
        { label: t('reports.bot.auto_resolved'), value: n(m.auto_resolved) },
        { label: t('reports.bot.handovers'), value: n(m.handovers) },
        { label: t('reports.bot.handover_rate'), value: formatNumber(locale.value, m.handover_rate, { style: 'percent', maximumFractionDigits: 0 }) },
        { label: t('reports.bot.comments_replied'), value: n(m.comments_replied) },
        { label: t('reports.bot.comments_hidden'), value: n(m.comments_hidden) },
        { label: t('reports.bot.private_replies'), value: n(m.private_replies) },
        { label: t('reports.bot.ai_runs'), value: n(m.ai_runs) },
        {
            label: t('reports.bot.ai_cost'),
            value: formatNumber(locale.value, m.ai_cost_usd, { style: 'currency', currency: 'USD', minimumFractionDigits: 2, maximumFractionDigits: 4 }),
        },
    ];
});

// Unknown reason codes are shown raw.
const reasons = computed(() =>
    Object.entries(props.metrics.handover_reasons ?? {}).map(([reason, value]) => {
        const key = `reports.reasons.${reason}`;
        const label = translate(locale.value, key);
        return { key: reason, label: label === key ? reason : label, value };
    }),
);

// One row per guided flow; flows nobody entered in the period sink to the bottom (server order).
const flowRows = computed(() => (props.metrics.flows ?? []).map((row) => ({ ...row, id: row.key })));
const flowColumns = computed(() => [
    { key: 'title', label: t('reports.flows.flow') },
    { key: 'started', label: t('reports.flows.started'), align: 'end' as const },
    { key: 'finished', label: t('reports.flows.finished'), align: 'end' as const },
    { key: 'handovers', label: t('reports.flows.handovers'), align: 'end' as const },
    { key: 'cases', label: t('reports.flows.cases'), align: 'end' as const },
]);

const breadcrumbs = computed(() => [{ title: t('reports.bot_title'), href: '/reports/bot' }]);
</script>

<template>
    <Head :title="t('reports.bot_title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-7xl space-y-4 p-3 md:p-6">
            <PageHeader :title="t('reports.bot_title')">
                <ReportFilters :range="range" :platform="platform" @change="visit" />
            </PageHeader>

            <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                <StatCard v-for="card in cards" :key="card.label" :label="card.label" :value="card.value" />
            </div>

            <section class="space-y-2" aria-labelledby="bot-flows-title">
                <div>
                    <h2 id="bot-flows-title" class="text-sm font-semibold">{{ t('reports.flows.title') }}</h2>
                    <p class="text-xs text-muted-foreground">{{ t('reports.flows.hint') }}</p>
                </div>
                <DataTable :columns="flowColumns" :rows="flowRows" :empty="t('reports.no_data')" :caption="t('reports.flows.title')">
                    <template #cell-title="{ row }">
                        <span class="flex items-center gap-1.5">
                            <span class="font-medium" dir="auto">{{ row.title }}</span>
                            <StatusChip v-if="!row.is_active" :label="t('reports.flows.inactive')" />
                        </span>
                    </template>
                    <template #cell-started="{ value }"><span class="tabular-nums">{{ n(value as number) }}</span></template>
                    <template #cell-finished="{ value }"><span class="tabular-nums">{{ n(value as number) }}</span></template>
                    <template #cell-handovers="{ value }"><span class="tabular-nums">{{ n(value as number) }}</span></template>
                    <template #cell-cases="{ row }">
                        <span v-if="row.records_cases" class="tabular-nums">{{ n(row.cases) }}</span>
                        <span v-else class="text-muted-foreground" aria-hidden="true">—</span>
                    </template>
                </DataTable>
                <p class="text-2xs text-muted-foreground">{{ t('reports.flows.note') }}</p>
            </section>

            <div class="grid gap-4 lg:grid-cols-2">
                <BarChart :title="t('reports.handover_reasons')" :items="reasons" :format="n" />
            </div>
        </div>
    </AppLayout>
</template>
