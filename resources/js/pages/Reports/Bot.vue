<script setup lang="ts">
import BarChart from '@/components/crm/BarChart.vue';
import DataTable from '@/components/crm/DataTable.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import ReportFilters from '@/components/crm/ReportFilters.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import Ledger, { type LedgerFigure } from '@/components/today/Ledger.vue';
import { useI18n } from '@/composables/useI18n';
import { useReportFilters } from '@/composables/useReportFilters';
import { formatNumber, translate } from '@/i18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCount, formatUsd } from '@/lib/format';
import type { BotMetrics, ReportRange } from '@/types/admin';
import type { PlatformValue } from '@/types/crm';
import { Head, Link } from '@inertiajs/vue3';
import { Workflow } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    range: ReportRange;
    platform: PlatformValue | null;
    metrics: BotMetrics;
    /** b2: the team's «رد البوت ده غلط» notes. */
    feedback?: { open: number; last_30: number; by_reason: Record<string, number> };
}>();

const { t, locale } = useI18n();
const { visit } = useReportFilters();
const n = (v: number) => formatCount(v, locale.value);

const pct = (v: number) => formatNumber(locale.value, v, { style: 'percent', maximumFractionDigits: 0 });

/** The headline ledger (DESIGN §3 Ledger): one panel, six figures, a line of context under the two rates. */
const figures = computed<LedgerFigure[]>(() => {
    const m = props.metrics;
    const resolvedRate = m.conversations_touched > 0 ? m.auto_resolved / m.conversations_touched : 0;
    return [
        { key: 'conversations_touched', label: t('reports.bot.conversations_touched'), value: n(m.conversations_touched) },
        {
            key: 'auto_resolved',
            label: t('reports.bot.auto_resolved'),
            value: n(m.auto_resolved),
            note: m.conversations_touched > 0 ? t('reports.bot.resolved_note', { rate: pct(resolvedRate) }) : null,
        },
        {
            key: 'handovers',
            label: t('reports.bot.handovers'),
            value: n(m.handovers),
            note: t('reports.bot.handover_note', { rate: pct(m.handover_rate) }),
        },
        { key: 'messages_sent', label: t('reports.bot.messages_sent'), value: n(m.messages_sent) },
        { key: 'ai_runs', label: t('reports.bot.ai_runs'), value: n(m.ai_runs) },
        { key: 'ai_cost', label: t('reports.bot.ai_cost'), value: formatUsd(m.ai_cost_usd, locale.value) },
    ];
});

const comments = computed(() => [
    { key: 'comments_replied', label: t('reports.bot.comments_replied'), value: props.metrics.comments_replied },
    { key: 'comments_hidden', label: t('reports.bot.comments_hidden'), value: props.metrics.comments_hidden },
    { key: 'private_replies', label: t('reports.bot.private_replies'), value: props.metrics.private_replies },
]);

// Unknown reason codes are shown raw.
const reasons = computed(() =>
    Object.entries(props.metrics.handover_reasons ?? {})
        .map(([reason, value]) => {
            const key = `reports.reasons.${reason}`;
            const label = translate(locale.value, key);
            return { key: reason, label: label === key ? reason : label, value };
        })
        // «ليه بيحوّل»: the biggest reason first.
        .sort((x, y) => y.value - x.value),
);

// b2: «رد البوت ده غلط» by reason (last 30 days), biggest first.
const feedbackReasons = computed(() =>
    Object.entries(props.feedback?.by_reason ?? {})
        .map(([reason, value]) => ({ key: reason, label: t(`bot_brain.feedback.reasons.${reason}`), value }))
        .filter((r) => r.value > 0)
        .sort((x, y) => y.value - x.value),
);

// One row per guided flow; flows nobody entered in the period sink to the bottom (server order).
const flowRows = computed(() => (props.metrics.flows ?? []).map((row) => ({ ...row, id: row.key })));
const flowColumns = computed(() => [
    { key: 'title', label: t('reports.flows.flow') },
    { key: 'started', label: t('reports.flows.started'), numeric: true },
    { key: 'finished', label: t('reports.flows.finished'), numeric: true },
    { key: 'handovers', label: t('reports.flows.handovers'), numeric: true },
    { key: 'cases', label: t('reports.flows.cases'), numeric: true },
]);

const breadcrumbs = computed(() => [
    { title: t('nav.bot'), href: '/bot' },
    { title: t('nav.bot_performance'), href: '/bot/performance' },
]);
</script>

<template>
    <Head :title="t('nav.bot_performance')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-6xl space-y-4 p-3 md:p-6">
            <PageHeader
                :title="t('nav.bot_performance')"
                :description="t('reports.bot.page_hint')"
                :breadcrumbs="[{ label: t('nav.bot'), href: '/bot' }, { label: t('nav.bot_performance') }]"
            />

            <ReportFilters :range="range" :platform="platform" @change="visit" />

            <Ledger :figures="figures" :label="t('reports.bot.ledger')" />

            <div class="grid gap-4 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                <BarChart :title="t('reports.bot.why_handover')" :items="reasons" :format="n" />
                <BarChart :title="t('reports.bot.comments')" :items="comments" :format="n" />
            </div>

            <section v-if="feedback" class="space-y-2" aria-labelledby="bot-feedback-title" data-testid="bot-feedback-counts">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 id="bot-feedback-title" class="text-base font-bold">{{ t('bot_brain.feedback.review.counts_title') }}</h2>
                    <Link v-if="feedback.open" href="/bot/feedback" class="text-13 font-semibold text-brand hover:underline">
                        {{ t('bot_brain.feedback.review.open_count', { n: n(feedback.open) }) }}
                    </Link>
                </div>
                <BarChart v-if="feedbackReasons.length" :title="t('bot_brain.feedback.review.counts_title')" :items="feedbackReasons" :format="n" />
                <p v-else class="rounded-lg border border-line bg-card px-4 py-3 text-13 text-fg-3">
                    {{ t('bot_brain.feedback.review.empty_hint') }}
                </p>
            </section>

            <section class="space-y-2" aria-labelledby="bot-flows-title">
                <div>
                    <h2 id="bot-flows-title" class="text-base font-bold">{{ t('reports.flows.title') }}</h2>
                    <p class="text-13 text-fg-2">{{ t('reports.flows.hint') }}</p>
                </div>
                <DataTable
                    table-id="report-bot"
                    :columns="flowColumns"
                    :rows="flowRows"
                    :empty="t('reports.no_data')"
                    :empty-icon="Workflow"
                    :caption="t('reports.flows.title')"
                >
                    <template #cell-title="{ row }">
                        <span class="flex items-center gap-1.5">
                            <span class="font-medium" dir="auto">{{ row.title }}</span>
                            <StatusChip v-if="!row.is_active" :label="t('reports.flows.inactive')" />
                        </span>
                    </template>
                    <template #cell-started="{ value }"
                        ><span class="tabular-nums">{{ n(value as number) }}</span></template
                    >
                    <template #cell-finished="{ value }"
                        ><span class="tabular-nums">{{ n(value as number) }}</span></template
                    >
                    <template #cell-handovers="{ value }"
                        ><span class="tabular-nums">{{ n(value as number) }}</span></template
                    >
                    <template #cell-cases="{ row }">
                        <span v-if="row.records_cases" class="tabular-nums">{{ n(row.cases) }}</span>
                        <span v-else class="text-fg-3" aria-hidden="true">—</span>
                    </template>
                </DataTable>
                <p class="text-xs text-fg-3">{{ t('reports.flows.note') }}</p>
            </section>
        </div>
    </AppLayout>
</template>
