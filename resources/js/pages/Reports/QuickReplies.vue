<script setup lang="ts">
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import PlatformBadge from '@/components/crm/PlatformBadge.vue';
import PeriodControls from '@/components/crm/reports/PeriodControls.vue';
import { teamTabs } from '@/components/crm/reports/teamTabs';
import SectionTabs from '@/components/crm/SectionTabs.vue';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { useReportFilters } from '@/composables/useReportFilters';
import { useUrlFilters } from '@/composables/useUrlFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCount, formatDateTime, slashShortcut } from '@/lib/format';
import { sortRows } from '@/lib/sort';
import type { QuickReplyAgentRow, QuickReplyTopRow, QuickReplyUnusedRow, ReportRange } from '@/types/admin';
import type { PlatformValue } from '@/types/crm';
import { Head, usePage } from '@inertiajs/vue3';
import { Download } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    range: ReportRange;
    platform: PlatformValue | null;
    top: QuickReplyTopRow[];
    perAgent: QuickReplyAgentRow[];
    unused: QuickReplyUnusedRow[];
}>();

const { t, locale } = useI18n();
const page = usePage();
const tabs = computed(() => teamTabs(t, page.url));
// Each table sorts client-side (full lists) and keeps its own sort in the URL; a range change keeps them.
const { filters: sorts, set: setSort, query: sortQuery } = useUrlFilters({ sort_top: '', sort_used: '', sort_unused: '' });
const { visit } = useReportFilters(() => sortQuery.value as Record<string, string>);
const n = (v: number) => formatCount(v, locale.value);

const exportHref = computed(() => {
    const query = new URLSearchParams({ from: props.range.from, to: props.range.to });
    if (props.platform) query.set('platform', props.platform);
    return `/reports/quick-replies/export?${query.toString()}`;
});

const scopeLabel = (scope: QuickReplyTopRow['scope']) =>
    t(scope === 'shared' ? 'reports.quick_replies.scope_shared' : 'reports.quick_replies.scope_personal');

const topRows = computed(() => sortRows(props.top, sorts.value.sort_top));
const agentRows = computed(() =>
    sortRows(
        props.perAgent.map((row) => ({ ...row, id: row.user.id })),
        sorts.value.sort_used,
    ),
);
const unusedRows = computed(() => sortRows(props.unused, sorts.value.sort_unused));

const topColumns = computed<Column[]>(() => [
    { key: 'shortcut', label: t('reports.quick_replies.shortcut') },
    { key: 'title', label: t('reports.quick_replies.title_label'), primary: true },
    { key: 'category', label: t('reports.quick_replies.category') },
    { key: 'scope', label: t('reports.quick_replies.scope') },
    { key: 'uses', label: t('reports.quick_replies.uses'), numeric: true, sortable: true },
    { key: 'users', label: t('reports.quick_replies.users'), numeric: true, sortable: true },
    { key: 'platforms', label: t('ui.platforms') },
    { key: 'last_used_at', label: t('reports.quick_replies.last_used'), sortable: true },
]);

const agentColumns = computed<Column[]>(() => [
    { key: 'user', label: t('reports.quick_replies.agent'), primary: true },
    { key: 'uses', label: t('reports.quick_replies.uses'), numeric: true, sortable: true },
    { key: 'replies', label: t('reports.quick_replies.replies'), numeric: true, sortable: true },
]);

const unusedColumns = computed<Column[]>(() => [
    { key: 'shortcut', label: t('reports.quick_replies.shortcut') },
    { key: 'title', label: t('reports.quick_replies.title_label'), primary: true },
    { key: 'scope', label: t('reports.quick_replies.scope') },
    { key: 'last_used_at', label: t('reports.quick_replies.last_used'), sortable: true },
]);

const breadcrumbs = computed(() => [{ title: t('reports.quick_replies.title'), href: '/reports/quick-replies' }]);
</script>

<template>
    <Head :title="t('reports.quick_replies.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-7xl space-y-4 p-3 md:p-6">
            <PageHeader :title="t('reports.hub.title')" :description="t('reports.hub.description')">
                <PeriodControls :range="range" :platform="platform" @change="visit" />
                <Button as="a" :href="exportHref" variant="secondary" :aria-label="t('reports.quick_replies.export')" data-export>
                    <Download aria-hidden="true" />
                    {{ t('reports.hub.csv') }}
                </Button>
            </PageHeader>
            <SectionTabs :tabs="tabs" :label="t('section_tabs.team.label')" />

            <section class="space-y-2">
                <h2 class="text-base font-bold">{{ t('reports.quick_replies.top') }}</h2>
                <DataTable
                    table-id="report-qr-top"
                    :sort="sorts.sort_top || null"
                    @update:sort="setSort({ sort_top: $event })"
                    :columns="topColumns"
                    :rows="topRows"
                    :empty="t('reports.quick_replies.empty')"
                    :caption="t('reports.quick_replies.top')"
                >
                    <template #cell-shortcut="{ value }"
                        ><kbd class="text-12 rounded-sm border border-line bg-surface-2 px-1.5 py-0.5 font-mono" dir="ltr">{{
                            slashShortcut(String(value))
                        }}</kbd></template
                    >
                    <template #cell-title="{ value }"
                        ><span dir="auto">{{ value }}</span></template
                    >
                    <template #cell-scope="{ value }">{{ scopeLabel(value as QuickReplyTopRow['scope']) }}</template>
                    <template #cell-uses="{ value }"
                        ><span class="tabular-nums">{{ n(value as number) }}</span></template
                    >
                    <template #cell-users="{ value }"
                        ><span class="tabular-nums">{{ n(value as number) }}</span></template
                    >
                    <template #cell-platforms="{ value }">
                        <span class="flex flex-wrap items-center gap-1.5">
                            <span v-for="(count, p) in value as Record<string, number>" :key="p" class="inline-flex items-center gap-0.5">
                                <PlatformBadge :platform="p as PlatformValue" size="xs" />
                                <span class="text-xs tabular-nums text-fg-3">{{ n(count) }}</span>
                            </span>
                        </span>
                    </template>
                    <template #cell-last_used_at="{ value }">
                        <span class="whitespace-nowrap tabular-nums text-fg-2">{{ formatDateTime(value as string | null, locale) || '—' }}</span>
                    </template>
                </DataTable>
            </section>

            <section class="space-y-2">
                <h2 class="text-base font-bold">{{ t('reports.quick_replies.per_agent') }}</h2>
                <DataTable
                    table-id="report-qr-agents"
                    :sort="sorts.sort_used || null"
                    @update:sort="setSort({ sort_used: $event })"
                    :columns="agentColumns"
                    :rows="agentRows"
                    :empty="t('reports.quick_replies.empty')"
                    :caption="t('reports.quick_replies.per_agent')"
                >
                    <template #cell-user="{ row }">
                        <span class="inline-flex items-center gap-2 whitespace-nowrap font-semibold">
                            <span
                                class="size-2.5 shrink-0 rounded-full"
                                :style="{ backgroundColor: row.user.color ?? 'hsl(var(--fg-3))' }"
                                aria-hidden="true"
                            />
                            {{ row.user.name }}
                        </span>
                    </template>
                    <template #cell-uses="{ value }"
                        ><span class="tabular-nums">{{ n(value as number) }}</span></template
                    >
                    <template #cell-replies="{ value }"
                        ><span class="tabular-nums">{{ n(value as number) }}</span></template
                    >
                </DataTable>
            </section>

            <section class="space-y-2">
                <h2 class="text-base font-bold">{{ t('reports.quick_replies.unused') }}</h2>
                <DataTable
                    table-id="report-qr-unused"
                    :sort="sorts.sort_unused || null"
                    @update:sort="setSort({ sort_unused: $event })"
                    :columns="unusedColumns"
                    :rows="unusedRows"
                    :empty="t('reports.quick_replies.empty')"
                    :caption="t('reports.quick_replies.unused')"
                >
                    <template #cell-shortcut="{ value }"
                        ><kbd class="text-12 rounded-sm border border-line bg-surface-2 px-1.5 py-0.5 font-mono" dir="ltr">{{
                            slashShortcut(String(value))
                        }}</kbd></template
                    >
                    <template #cell-title="{ value }"
                        ><span dir="auto">{{ value }}</span></template
                    >
                    <template #cell-scope="{ value }">{{ scopeLabel(value as QuickReplyUnusedRow['scope']) }}</template>
                    <template #cell-last_used_at="{ value }">
                        <span class="whitespace-nowrap tabular-nums text-fg-2">{{ formatDateTime(value as string | null, locale) || '—' }}</span>
                    </template>
                </DataTable>
            </section>
        </div>
    </AppLayout>
</template>
