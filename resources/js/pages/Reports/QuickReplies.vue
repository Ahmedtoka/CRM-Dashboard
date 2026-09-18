<script setup lang="ts">
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import PlatformBadge from '@/components/crm/PlatformBadge.vue';
import ReportFilters from '@/components/crm/ReportFilters.vue';
import { useI18n } from '@/composables/useI18n';
import { useReportFilters } from '@/composables/useReportFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCount, formatDateTime } from '@/lib/format';
import type { QuickReplyAgentRow, QuickReplyTopRow, QuickReplyUnusedRow, ReportRange } from '@/types/admin';
import type { PlatformValue } from '@/types/crm';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    range: ReportRange;
    platform: PlatformValue | null;
    top: QuickReplyTopRow[];
    perAgent: QuickReplyAgentRow[];
    unused: QuickReplyUnusedRow[];
}>();

const { t, locale } = useI18n();
const { visit } = useReportFilters();
const n = (v: number) => formatCount(v, locale.value);

const exportHref = computed(() => {
    const query = new URLSearchParams({ from: props.range.from, to: props.range.to });
    if (props.platform) query.set('platform', props.platform);
    return `/reports/quick-replies/export?${query.toString()}`;
});

const scopeLabel = (scope: QuickReplyTopRow['scope']) => t(scope === 'shared' ? 'reports.quick_replies.scope_shared' : 'reports.quick_replies.scope_personal');

const topRows = computed(() => props.top);
const agentRows = computed(() => props.perAgent.map((row) => ({ ...row, id: row.user.id })));
const unusedRows = computed(() => props.unused);

const topColumns = computed<Column[]>(() => [
    { key: 'shortcut', label: t('reports.quick_replies.shortcut') },
    { key: 'title', label: t('reports.quick_replies.title_label') },
    { key: 'category', label: t('reports.quick_replies.category') },
    { key: 'scope', label: t('reports.quick_replies.scope') },
    { key: 'uses', label: t('reports.quick_replies.uses'), align: 'end' },
    { key: 'users', label: t('reports.quick_replies.users'), align: 'end' },
    { key: 'platforms', label: t('ui.platforms') },
    { key: 'last_used_at', label: t('reports.quick_replies.last_used') },
]);

const agentColumns = computed<Column[]>(() => [
    { key: 'user', label: t('reports.quick_replies.agent') },
    { key: 'uses', label: t('reports.quick_replies.uses'), align: 'end' },
    { key: 'replies', label: t('reports.quick_replies.replies'), align: 'end' },
]);

const unusedColumns = computed<Column[]>(() => [
    { key: 'shortcut', label: t('reports.quick_replies.shortcut') },
    { key: 'title', label: t('reports.quick_replies.title_label') },
    { key: 'scope', label: t('reports.quick_replies.scope') },
    { key: 'last_used_at', label: t('reports.quick_replies.last_used') },
]);

const breadcrumbs = computed(() => [{ title: t('reports.quick_replies.title'), href: '/reports/quick-replies' }]);
</script>

<template>
    <Head :title="t('reports.quick_replies.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-7xl space-y-4 p-3 md:p-6">
            <PageHeader :title="t('reports.quick_replies.title')">
                <ReportFilters :range="range" :platform="platform" @change="visit" />
                <a
                    :href="exportHref"
                    class="inline-flex h-8 items-center gap-1.5 rounded-md border border-border bg-card px-3 text-xs font-medium hover:bg-muted/50"
                >
                    {{ t('reports.quick_replies.export') }}
                </a>
            </PageHeader>

            <section class="space-y-2">
                <h2 class="text-sm font-medium">{{ t('reports.quick_replies.top') }}</h2>
                <DataTable :columns="topColumns" :rows="topRows" :empty="t('reports.quick_replies.empty')" :caption="t('reports.quick_replies.top')">
                    <template #cell-scope="{ value }">{{ scopeLabel(value as QuickReplyTopRow['scope']) }}</template>
                    <template #cell-uses="{ value }"><span class="tabular-nums">{{ n(value as number) }}</span></template>
                    <template #cell-users="{ value }"><span class="tabular-nums">{{ n(value as number) }}</span></template>
                    <template #cell-platforms="{ value }">
                        <span class="flex flex-wrap items-center gap-1.5">
                            <span v-for="(count, p) in value as Record<string, number>" :key="p" class="inline-flex items-center gap-0.5">
                                <PlatformBadge :platform="p as PlatformValue" size="xs" />
                                <span class="text-2xs tabular-nums text-muted-foreground">{{ n(count) }}</span>
                            </span>
                        </span>
                    </template>
                    <template #cell-last_used_at="{ value }">
                        <span class="whitespace-nowrap tabular-nums text-muted-foreground">{{ formatDateTime(value as string | null, locale) || '—' }}</span>
                    </template>
                </DataTable>
            </section>

            <section class="space-y-2">
                <h2 class="text-sm font-medium">{{ t('reports.quick_replies.per_agent') }}</h2>
                <DataTable :columns="agentColumns" :rows="agentRows" :empty="t('reports.quick_replies.empty')" :caption="t('reports.quick_replies.per_agent')">
                    <template #cell-user="{ row }">
                        <span class="inline-flex items-center gap-2 whitespace-nowrap font-medium">
                            <span class="size-2.5 rounded-full" :style="{ backgroundColor: row.user.color ?? '#94a3b8' }" aria-hidden="true" />
                            {{ row.user.name }}
                        </span>
                    </template>
                    <template #cell-uses="{ value }"><span class="tabular-nums">{{ n(value as number) }}</span></template>
                    <template #cell-replies="{ value }"><span class="tabular-nums">{{ n(value as number) }}</span></template>
                </DataTable>
            </section>

            <section class="space-y-2">
                <div>
                    <h2 class="text-sm font-medium">{{ t('reports.quick_replies.unused') }}</h2>
                </div>
                <DataTable :columns="unusedColumns" :rows="unusedRows" :empty="t('reports.quick_replies.empty')" :caption="t('reports.quick_replies.unused')">
                    <template #cell-scope="{ value }">{{ scopeLabel(value as QuickReplyUnusedRow['scope']) }}</template>
                    <template #cell-last_used_at="{ value }">
                        <span class="whitespace-nowrap tabular-nums text-muted-foreground">{{ formatDateTime(value as string | null, locale) || '—' }}</span>
                    </template>
                </DataTable>
            </section>
        </div>
    </AppLayout>
</template>
