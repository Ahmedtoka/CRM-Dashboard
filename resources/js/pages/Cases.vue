<script setup lang="ts">
import CaseDrawer from '@/components/crm/cases/CaseDrawer.vue';
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
import DateRangePicker from '@/components/crm/DateRangePicker.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import FilterBar from '@/components/crm/FilterBar.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import Pagination from '@/components/crm/Pagination.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { casePriorityTone, caseStatusTone } from '@/lib/caseStatus';
import { formatCount, formatDateTime } from '@/lib/format';
import type { Paginated } from '@/types/admin';
import type { CaseStatus, CaseType, SupportCase } from '@/types/crm';
import { Head, router } from '@inertiajs/vue3';
import { ClipboardList } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Filters {
    type: CaseType | null;
    status: CaseStatus | null;
    q: string | null;
    /** Cairo calendar dates (Y-m-d); the server converts them to UTC bounds. */
    from: string | null;
    to: string | null;
}

const TYPES: CaseType[] = ['return', 'exchange', 'return_exchange', 'complaint', 'cancel_edit', 'delivery_followup'];
const TABS: Array<CaseStatus | 'all'> = ['all', 'new', 'in_progress', 'closed'];

const props = withDefaults(
    defineProps<{
        cases: Paginated<SupportCase>;
        filters: Filters;
        counts: Record<'all' | CaseStatus, number>;
        team?: { id: number; name: string }[];
    }>(),
    { team: () => [] },
);

const { t, locale } = useI18n();
const loading = ref(false);

function apply(patch: Partial<Filters>): void {
    const next = { ...props.filters, ...patch };
    const query = Object.fromEntries(Object.entries(next).filter(([, v]) => v !== null && v !== ''));
    router.get('/cases', query, { preserveState: true, preserveScroll: true, replace: true, onStart: () => (loading.value = true), onFinish: () => (loading.value = false) });
}

const selectValue = (event: Event) => (event.target as HTMLSelectElement).value || null;

// The type and the date range show as removable chips; the range picker lives in the «فلاتر» popover.
const range = computed(() => ({ from: props.filters.from ?? '', to: props.filters.to ?? '' }));
const moreCount = computed(() => (props.filters.from || props.filters.to ? 1 : 0));
const chips = computed(() => {
    const f = props.filters;
    const out: { key: string; label: string }[] = [];
    if (f.type) out.push({ key: 'type', label: t(`cases.types.${f.type}`) });
    if (f.from || f.to) out.push({ key: 'date', label: t('cases.date_chip', { from: f.from ?? '…', to: f.to ?? '…' }) });
    return out;
});
const filtered = computed(() => Boolean(props.filters.type || props.filters.from || props.filters.to || props.filters.q || props.filters.status));

function removeChip(key: string): void {
    if (key === 'date') apply({ from: null, to: null });
    else apply({ type: null });
}

const selectedCaseId = ref<number | null>(null);
const drawerOpen = ref(false);

function openCase(row: SupportCase): void {
    selectedCaseId.value = row.id;
    drawerOpen.value = true;
}

function reload(): void {
    router.reload({ only: ['cases', 'counts'] });
}

const columns = computed<Column[]>(() => [
    { key: 'id', label: t('cases.columns.id'), hideOnMobile: true },
    { key: 'type', label: t('cases.columns.type'), primary: true },
    { key: 'customer', label: t('cases.columns.customer') },
    { key: 'order', label: t('cases.columns.order'), hideOnMobile: true },
    { key: 'priority', label: t('cases.columns.priority') },
    { key: 'status', label: t('cases.columns.status') },
    { key: 'date', label: t('cases.columns.date') },
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
        <div class="mx-auto w-full max-w-7xl space-y-4 p-3 md:p-6">
            <PageHeader :title="t('cases.title')" />

            <div class="flex flex-wrap gap-1.5" role="tablist" :aria-label="t('cases.title')">
                <button
                    v-for="tab in TABS"
                    :key="tab"
                    type="button"
                    role="tab"
                    :aria-selected="(filters.status ?? 'all') === tab"
                    class="inline-flex h-8 items-center gap-1.5 rounded-full px-3 text-xs font-medium"
                    :class="(filters.status ?? 'all') === tab ? 'bg-primary text-primary-foreground' : 'bg-card text-muted-foreground hover:text-foreground'"
                    @click="apply({ status: tab === 'all' ? null : tab })"
                >
                    {{ t(`cases.tabs.${tab}`) }}
                    <span
                        v-if="(counts[tab] ?? 0) > 0"
                        class="inline-flex h-4 min-w-4 items-center justify-center rounded-full px-1 text-2xs tabular-nums"
                        :class="(filters.status ?? 'all') === tab ? 'bg-primary-foreground/20' : 'bg-muted'"
                    >
                        {{ formatCount(counts[tab] ?? 0, locale) }}
                    </span>
                </button>
            </div>

            <div class="rounded-lg bg-card p-3 shadow-card">
                <FilterBar
                    :search="filters.q ?? ''"
                    :search-placeholder="t('cases.search')"
                    :chips="chips"
                    :more-count="moreCount"
                    @update:search="apply({ q: $event || null })"
                    @remove="removeChip"
                    @clear="apply({ type: null, from: null, to: null, q: null })"
                >
                    <template #inline>
                        <select :value="filters.type ?? ''" :class="selectClass" :aria-label="t('cases.type_all')" @change="apply({ type: selectValue($event) as CaseType | null })">
                            <option value="">{{ t('cases.type_all') }}</option>
                            <option v-for="ty in TYPES" :key="ty" :value="ty">{{ t(`cases.types.${ty}`) }}</option>
                        </select>
                    </template>
                    <template #more>
                        <div>
                            <span :class="fieldLabel">{{ t('cases.filter_date') }}</span>
                            <DateRangePicker :model-value="range" @update:model-value="apply({ from: $event.from || null, to: $event.to || null })" />
                        </div>
                    </template>
                </FilterBar>
            </div>

            <div>
                <DataTable :columns="columns" :rows="cases.data" clickable :loading="loading" :caption="t('cases.title')" @row-click="openCase">
                    <template #empty>
                        <EmptyState :icon="ClipboardList" :title="t('cases.empty')" :body="filtered ? t('cases.empty_filtered') : t('cases.empty_body')" />
                    </template>
                    <template #cell-id="{ row }"><span class="font-medium tabular-nums" dir="ltr">#{{ row.id }}</span></template>
                    <template #cell-type="{ row }">
                        <span class="block whitespace-nowrap">{{ t(`cases.types.${row.type}`) }}</span>
                        <span v-if="requestLine(row)" class="block max-w-[16rem] truncate text-2xs text-muted-foreground" dir="auto" :title="requestLine(row) ?? undefined">
                            {{ requestLine(row) }}
                        </span>
                    </template>
                    <template #cell-customer="{ row }">
                        <span class="block max-w-[12rem] truncate">{{ row.customer?.name ?? '—' }}</span>
                        <span v-if="row.customer?.phone" class="block text-2xs text-muted-foreground" dir="ltr">{{ row.customer.phone }}</span>
                    </template>
                    <template #cell-order="{ row }"><span dir="ltr">{{ row.order_number ?? '—' }}</span></template>
                    <template #cell-priority="{ row }">
                        <StatusChip :label="t(`cases.priority.${row.priority}`)" :tone="casePriorityTone[row.priority]" />
                    </template>
                    <template #cell-status="{ row }">
                        <StatusChip :label="t(`cases.tabs.${row.status}`)" :tone="caseStatusTone[row.status]" />
                    </template>
                    <template #cell-date="{ row }"><span class="whitespace-nowrap tabular-nums text-muted-foreground">{{ formatDateTime(row.created_at, locale) }}</span></template>
                </DataTable>
                <Pagination :page="cases" />
            </div>
        </div>

        <CaseDrawer v-model:open="drawerOpen" :case-id="selectedCaseId" :team="team" @updated="reload" />
    </AppLayout>
</template>
