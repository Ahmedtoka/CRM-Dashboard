<script setup lang="ts">
import CaseDrawer from '@/components/crm/cases/CaseDrawer.vue';
import CaseSlaChip from '@/components/crm/cases/CaseSlaChip.vue';
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
import DateRangePicker from '@/components/crm/DateRangePicker.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import FilterBar from '@/components/crm/FilterBar.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import Pagination from '@/components/crm/Pagination.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { useUrlFilters } from '@/composables/useUrlFilters';
import { useVisitLoading } from '@/composables/useVisitLoading';
import AppLayout from '@/layouts/AppLayout.vue';
import { casePriorityTone, caseStatusTone, caseTypeTone } from '@/lib/caseStatus';
import { formatCount, formatDateTime, formatListStamp } from '@/lib/format';
import type { Paginated } from '@/types/admin';
import type { CaseStatus, CaseType, SupportCase } from '@/types/crm';
import { Head, Link, router } from '@inertiajs/vue3';
import { ClipboardList, ListChecks } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';

const TYPES: CaseType[] = ['return', 'exchange', 'return_exchange', 'complaint', 'cancel_edit', 'delivery_followup'];
const TABS: Array<CaseStatus | 'all'> = ['all', 'new', 'in_progress', 'resolved', 'closed'];

// The server still shares `filters`; the page reads the same values from the URL (useUrlFilters).
defineOptions({ inheritAttrs: false });

const props = withDefaults(
    defineProps<{
        cases: Paginated<SupportCase>;
        counts: Record<'all' | CaseStatus, number>;
        team?: { id: number; name: string }[];
    }>(),
    { team: () => [] },
);

const { t, locale } = useI18n();
const { loading, track } = useVisitLoading();

// The filters live in the URL (shareable, back/forward); the server reads the same keys and whitelists `sort`.
const { filters, set, clear, query } = useUrlFilters(
    {
        q: '',
        type: null as CaseType | null,
        status: null as CaseStatus | null,
        /** Cairo calendar dates (Y-m-d); the server converts them to UTC bounds. */
        from: null as string | null,
        to: null as string | null,
        /** Open cases past their SLA (the «النهارده» link); no control, a chip only. */
        overdue: false,
        sort: '',
    },
    { replaceKeys: ['q'], keep: ['open'] },
);
watch(query, (q) => {
    router.get('/cases', q, track({ only: ['cases', 'counts', 'filters'], preserveState: true, preserveScroll: true, replace: true }));
});
const apply = (patch: Partial<typeof filters.value>) => set(patch);

const selectValue = (event: Event) => (event.target as HTMLSelectElement).value || null;

// The type and the date range show as removable chips; on a phone both live in the «فلاتر» popover (with a label).
const range = computed(() => ({ from: filters.value.from ?? '', to: filters.value.to ?? '' }));
const moreCount = computed(() => (filters.value.from || filters.value.to ? 1 : 0));
const currentTab = computed(() => filters.value.status ?? 'all');
const now = Date.now();
const chips = computed(() => {
    const f = filters.value;
    const out: { key: string; label: string }[] = [];
    if (f.type) out.push({ key: 'type', label: t(`cases.types.${f.type}`) });
    if (f.from || f.to) out.push({ key: 'date', label: t('cases.date_chip', { from: f.from ?? '…', to: f.to ?? '…' }) });
    if (f.overdue) out.push({ key: 'overdue', label: t('cases.overdue_chip') });
    return out;
});
const filtered = computed(() =>
    Boolean(filters.value.type || filters.value.from || filters.value.to || filters.value.q || filters.value.status || filters.value.overdue),
);
const summary = computed(() =>
    [t('ui.results', { n: props.cases.meta?.total ?? props.cases.data.length }), ...chips.value.map((c) => c.label)].join(' · '),
);

function removeChip(key: string): void {
    if (key === 'date') apply({ from: null, to: null });
    else if (key === 'overdue') apply({ overdue: false });
    else apply({ type: null });
}

const selectedCaseId = ref<number | null>(null);
const drawerOpen = ref(false);

function openCase(row: SupportCase): void {
    selectedCaseId.value = row.id;
    drawerOpen.value = true;
}

// A link from another screen («تأكيدات الأوردرات», the order page): /cases?open=ID opens that case's drawer.
onMounted(() => {
    const id = Number(new URLSearchParams(window.location.search).get('open'));
    if (Number.isInteger(id) && id > 0) openCase({ id } as SupportCase);
});

function reload(): void {
    router.reload({ only: ['cases', 'counts'] });
}

const columns = computed<Column[]>(() => [
    { key: 'id', label: t('cases.columns.id'), hideOnMobile: true, sortable: true },
    { key: 'type', label: t('cases.columns.type'), primary: true },
    { key: 'customer', label: t('cases.columns.customer') },
    { key: 'assigned', label: t('cases.assigned'), hideOnMobile: true },
    { key: 'priority', label: t('cases.columns.priority') },
    { key: 'status', label: t('cases.columns.status') },
    { key: 'date', label: t('cases.columns.date'), sortable: true },
]);

/** The first line of the request section, shown under the case type. */
function requestLine(row: SupportCase): string | null {
    return row.summary_sections.find((s) => s.key === 'request')?.lines[0] ?? null;
}

const breadcrumbs = computed(() => [{ title: t('cases.title'), href: '/cases' }]);
const selectClass = 'h-9 w-full rounded-md border border-input bg-background px-2 text-xs sm:w-auto';
const fieldLabel = 'mb-1 block text-2xs font-medium text-muted-foreground';
</script>

<template>
    <Head :title="t('cases.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full min-w-0 max-w-7xl space-y-4 p-3 md:p-6">
            <PageHeader :title="t('cases.title')" :description="t('cases.description')">
                <!-- «المتابعة» (queue ops): the open cases, who has them and what to do next. -->
                <Button as-child variant="outline" size="sm">
                    <Link href="/cases/follow-up" data-cases-follow-up><ListChecks aria-hidden="true" />{{ t('cases.follow_up.link') }}</Link>
                </Button>
            </PageHeader>

            <!-- The status tabs (مفتوحة · قيد المتابعة · اتحلّت …): one strip, a count on each, the selected one underlined. -->
            <div class="scrollbar-none -mx-3 overflow-x-auto border-b border-line px-3 md:mx-0 md:px-0">
                <div class="flex min-w-max gap-1" role="tablist" :aria-label="t('cases.title')">
                    <button
                        v-for="tab in TABS"
                        :key="tab"
                        type="button"
                        role="tab"
                        :aria-selected="currentTab === tab"
                        class="relative -mb-px inline-flex h-10 items-center gap-1.5 border-b-2 px-3 text-13 font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring"
                        :class="currentTab === tab ? 'border-brand text-brand-ink' : 'border-transparent text-fg-2 hover:text-foreground'"
                        :data-case-tab="tab"
                        @click="apply({ status: tab === 'all' ? null : tab })"
                    >
                        {{ t(`cases.tabs.${tab}`) }}
                        <span
                            v-if="(counts[tab] ?? 0) > 0"
                            class="inline-flex h-[18px] min-w-[18px] items-center justify-center rounded-full px-1.5 text-[11px] font-bold tabular-nums"
                            :class="currentTab === tab ? 'bg-brand text-brand-on' : 'bg-sunken text-fg-2'"
                        >
                            {{ formatCount(counts[tab] ?? 0, locale) }}
                        </span>
                    </button>
                </div>
            </div>

            <FilterBar
                :search="filters.q"
                :search-placeholder="t('cases.search')"
                :chips="chips"
                :more-count="moreCount"
                :summary="summary"
                @update:search="set({ q: $event })"
                @remove="removeChip"
                @clear="clear(['status', 'sort'])"
            >
                <template #inline>
                    <select
                        :value="filters.type ?? ''"
                        :class="selectClass"
                        :aria-label="t('cases.type_all')"
                        data-filter="type"
                        @change="apply({ type: selectValue($event) as CaseType | null })"
                    >
                        <option value="">{{ t('cases.type_all') }}</option>
                        <option v-for="ty in TYPES" :key="ty" :value="ty">{{ t(`cases.types.${ty}`) }}</option>
                    </select>
                </template>
                <template #more>
                    <div data-filter="date">
                        <span :class="fieldLabel">{{ t('cases.filter_date') }}</span>
                        <DateRangePicker :model-value="range" @update:model-value="apply({ from: $event.from || null, to: $event.to || null })" />
                    </div>
                </template>
            </FilterBar>

            <div class="min-w-0">
                <DataTable
                    table-id="cases"
                    card-grid
                    :columns="columns"
                    :rows="cases.data"
                    clickable
                    :loading="loading"
                    :caption="t('cases.title')"
                    :sort="filters.sort || null"
                    @update:sort="set({ sort: $event })"
                    @row-click="openCase"
                >
                    <template #empty>
                        <EmptyState
                            :icon="ClipboardList"
                            :title="t('cases.empty')"
                            :body="filtered ? t('cases.empty_filtered') : t('cases.empty_body')"
                        >
                            <template v-if="chips.length || filters.q" #action>
                                <Button variant="outline" size="sm" @click="clear(['status', 'sort'])">{{ t('ui.clear_filters') }}</Button>
                            </template>
                        </EmptyState>
                    </template>
                    <template #cell-id="{ row }"
                        ><span class="font-semibold tabular-nums" dir="ltr">#{{ row.id }}</span></template
                    >
                    <template #cell-type="{ row }">
                        <span class="flex min-w-0 flex-col items-start gap-1">
                            <StatusChip :label="t(`cases.types.${row.type}`)" :tone="caseTypeTone[row.type] ?? 'neutral'" />
                            <span
                                v-if="requestLine(row)"
                                class="block max-w-[18rem] truncate text-xs text-fg-2"
                                dir="auto"
                                :title="requestLine(row) ?? undefined"
                            >
                                {{ requestLine(row) }}
                            </span>
                        </span>
                    </template>
                    <template #cell-customer="{ row }">
                        <span class="block max-w-[12rem] truncate font-semibold" dir="auto">{{ row.customer?.name ?? '—' }}</span>
                        <span class="block text-xs text-fg-2">
                            <bdi v-if="row.customer?.phone" dir="ltr" class="tabular-nums">{{ row.customer.phone }}</bdi>
                            <template v-if="row.order_number">
                                <template v-if="row.customer?.phone"> · </template>{{ t('cases.columns.order') }}
                                <bdi dir="ltr" class="tabular-nums">{{ row.order_number }}</bdi>
                            </template>
                        </span>
                    </template>
                    <template #cell-assigned="{ row }">
                        <span v-if="row.assigned_to" class="block max-w-[10rem] truncate" dir="auto">{{ row.assigned_to.name }}</span>
                        <span v-else class="text-fg-3">{{ t('cases.unassigned') }}</span>
                    </template>
                    <template #cell-priority="{ row }">
                        <StatusChip :label="t(`cases.priority.${row.priority}`)" :tone="casePriorityTone[row.priority]" size="sm" />
                    </template>
                    <template #cell-status="{ row }">
                        <span class="flex flex-wrap items-center gap-1">
                            <StatusChip :label="t(`cases.tabs.${row.status}`)" :tone="caseStatusTone[row.status]" />
                            <CaseSlaChip :sla="row.sla" />
                        </span>
                    </template>
                    <template #cell-date="{ row }">
                        <span class="whitespace-nowrap tabular-nums text-fg-2" :title="formatDateTime(row.created_at, locale)">{{
                            formatListStamp(row.created_at, locale, now)
                        }}</span>
                    </template>
                </DataTable>
                <Pagination :page="cases" />
            </div>
        </div>

        <CaseDrawer v-model:open="drawerOpen" :case-id="selectedCaseId" :team="team" @updated="reload" />
    </AppLayout>
</template>
