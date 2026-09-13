<script setup lang="ts">
import BarChart from '@/components/crm/BarChart.vue';
import DataTable from '@/components/crm/DataTable.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import ReportFilters from '@/components/crm/ReportFilters.vue';
import StatCard from '@/components/crm/StatCard.vue';
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

const ruleRows = computed(() => (props.metrics.rule_hits ?? []).map((row) => ({ ...row, id: row.rule_id })));

const breadcrumbs = computed(() => [{ title: t('reports.bot_title'), href: '/reports/bot' }]);
</script>

<template>
    <Head :title="t('reports.bot_title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-4 p-4">
            <PageHeader :title="t('reports.bot_title')" />
            <ReportFilters :range="range" :platform="platform" @change="visit" />

            <div class="grid grid-cols-2 gap-2 sm:grid-cols-5">
                <StatCard v-for="card in cards" :key="card.label" :label="card.label" :value="card.value" />
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <BarChart :title="t('reports.handover_reasons')" :items="reasons" :format="n" />
                <section class="space-y-2">
                    <h2 class="text-xs font-medium">{{ t('reports.rule_hits') }}</h2>
                    <DataTable
                        :columns="[
                            { key: 'name', label: t('reports.rule') },
                            { key: 'hits', label: t('reports.hits'), align: 'end' },
                        ]"
                        :rows="ruleRows"
                        :empty="t('reports.no_data')"
                    >
                        <template #cell-name="{ row }">{{ row.name ?? t('reports.deleted_rule', { id: row.rule_id }) }}</template>
                        <template #cell-hits="{ value }"><span class="tabular-nums">{{ n(value as number) }}</span></template>
                    </DataTable>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
