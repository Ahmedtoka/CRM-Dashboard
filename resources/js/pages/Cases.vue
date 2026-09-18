<script setup lang="ts">
import CaseDrawer from '@/components/crm/cases/CaseDrawer.vue';
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import Pagination from '@/components/crm/Pagination.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { casePriorityTone, caseStatusTone } from '@/lib/caseStatus';
import { formatDateTime } from '@/lib/format';
import type { Paginated } from '@/types/admin';
import type { CaseStatus, CaseType, SupportCase } from '@/types/crm';
import { Head, router } from '@inertiajs/vue3';
import { Search } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

interface Filters {
    type: CaseType | null;
    status: CaseStatus | null;
    q: string | null;
    /** Cairo calendar dates (Y-m-d); the server converts them to UTC bounds. */
    from: string | null;
    to: string | null;
}

const TYPES: CaseType[] = ['return_exchange', 'complaint', 'cancel_edit', 'delivery_followup'];
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

const search = ref(props.filters.q ?? '');
let timer: number | undefined;
watch(search, (value) => {
    window.clearTimeout(timer);
    timer = window.setTimeout(() => apply({ q: value.trim() || null }), 350);
});
onBeforeUnmount(() => window.clearTimeout(timer));

const selectValue = (event: Event) => (event.target as HTMLSelectElement).value || null;
const inputValue = (event: Event) => (event.target as HTMLInputElement).value || null;

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
    { key: 'id', label: t('cases.columns.id') },
    { key: 'type', label: t('cases.columns.type') },
    { key: 'customer', label: t('cases.columns.customer') },
    { key: 'order', label: t('cases.columns.order') },
    { key: 'priority', label: t('cases.columns.priority') },
    { key: 'status', label: t('cases.columns.status') },
    { key: 'date', label: t('cases.columns.date') },
]);

/** The first line of the 📝 request section, shown under the case type. */
function requestLine(row: SupportCase): string | null {
    return row.summary_sections.find((s) => s.key === 'request')?.lines[0] ?? null;
}

const breadcrumbs = computed(() => [{ title: t('cases.title'), href: '/cases' }]);
const selectClass = 'h-8 rounded-md border border-input bg-background px-2 text-xs';
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
                        class="inline-flex h-4 min-w-4 items-center justify-center rounded-full px-1 text-2xs tabular-nums"
                        :class="(filters.status ?? 'all') === tab ? 'bg-primary-foreground/20' : 'bg-muted'"
                    >
                        {{ counts[tab] ?? 0 }}
                    </span>
                </button>
            </div>

            <div class="flex flex-wrap items-center gap-2 rounded-lg bg-card p-3 shadow-card">
                <div class="relative min-w-[14rem] flex-1 sm:max-w-xs">
                    <Search class="pointer-events-none absolute start-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
                    <input v-model="search" type="search" :placeholder="t('cases.search')" :aria-label="t('cases.search')" class="h-8 w-full rounded-full border border-input bg-elevated pe-2 ps-8 text-sm" />
                </div>
                <select :value="filters.type ?? ''" :class="selectClass" :aria-label="t('cases.type_all')" @change="apply({ type: selectValue($event) as CaseType | null })">
                    <option value="">{{ t('cases.type_all') }}</option>
                    <option v-for="ty in TYPES" :key="ty" :value="ty">{{ t(`cases.types.${ty}`) }}</option>
                </select>
                <label class="flex items-center gap-1 text-xs text-muted-foreground">
                    {{ t('cases.date_from') }}
                    <input type="date" :value="filters.from ?? ''" :max="filters.to ?? undefined" :class="selectClass" @change="apply({ from: inputValue($event) })" />
                </label>
                <label class="flex items-center gap-1 text-xs text-muted-foreground">
                    {{ t('cases.date_to') }}
                    <input type="date" :value="filters.to ?? ''" :min="filters.from ?? undefined" :class="selectClass" @change="apply({ to: inputValue($event) })" />
                </label>
            </div>

            <div>
                <DataTable :columns="columns" :rows="cases.data" clickable :loading="loading" :empty="t('cases.empty')" :caption="t('cases.title')" @row-click="openCase">
                    <template #cell-id="{ row }"><span class="font-medium tabular-nums" dir="ltr">#{{ row.id }}</span></template>
                    <template #cell-type="{ row }">
                        <span class="block whitespace-nowrap" dir="rtl">{{ row.type_label }}</span>
                        <span v-if="requestLine(row)" class="block max-w-[16rem] truncate text-2xs text-muted-foreground" dir="rtl" :title="requestLine(row) ?? undefined">
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
