<script setup lang="ts">
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import FilterBar from '@/components/crm/FilterBar.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import Pagination from '@/components/crm/Pagination.vue';
import PlatformBadge from '@/components/crm/PlatformBadge.vue';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { useUrlFilters } from '@/composables/useUrlFilters';
import { useVisitLoading } from '@/composables/useVisitLoading';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCount, formatDateTime, formatMoney } from '@/lib/format';
import type { Paginated } from '@/types/admin';
import type { Customer } from '@/types/crm';
import { Head, router } from '@inertiajs/vue3';
import { SearchX } from 'lucide-vue-next';
import { computed, watch } from 'vue';

type CustomerRow = Customer & { last_contact_at?: string | null };

// The server still shares `filters`; the page reads the same values from the URL (useUrlFilters).
defineOptions({ inheritAttrs: false });

defineProps<{ customers: Paginated<CustomerRow> }>();

const { t, locale } = useI18n();
const { loading, track } = useVisitLoading();

// Search and sort live in the URL; the server whitelists the sort keys (CustomerController::SORTS).
const { filters, set, clear, query } = useUrlFilters({ q: '', sort: '' }, { replaceKeys: ['q'] });
watch(query, (q) => {
    router.get('/customers', q, track({ only: ['customers', 'filters'], preserveState: true, preserveScroll: true, replace: true }));
});

const columns = computed<Column[]>(() => [
    { key: 'name', label: t('customers.columns.name'), primary: true, sortable: true },
    { key: 'phone', label: t('customers.columns.phone'), dir: 'ltr' },
    { key: 'identities', label: t('customers.columns.platforms'), hideOnMobile: true },
    { key: 'orders_count', label: t('customers.columns.orders'), numeric: true, sortable: true },
    { key: 'total_spent', label: t('customers.columns.spent'), numeric: true, sortable: true },
    { key: 'last_contact_at', label: t('customers.columns.last_contact'), sortable: true },
]);

const breadcrumbs = computed(() => [{ title: t('customers.title'), href: '/customers' }]);
</script>

<template>
    <Head :title="t('customers.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-7xl space-y-4 p-3 md:p-6">
            <PageHeader :title="t('customers.title')" />

            <div class="rounded-lg bg-card p-3 shadow-card">
                <FilterBar :search="filters.q" :search-placeholder="t('customers.search')" :chips="[]" @update:search="set({ q: $event })" @clear="clear()" />
            </div>

            <div>
                <DataTable
                    table-id="customers"
                    :columns="columns"
                    :rows="customers.data"
                    clickable
                    :loading="loading"
                    :empty="t('customers.empty')"
                    :caption="t('customers.title')"
                    :sort="filters.sort || null"
                    @update:sort="set({ sort: $event })"
                    @row-click="router.visit(`/customers/${$event.id}`)"
                >
                    <template v-if="filters.q" #empty>
                        <EmptyState :icon="SearchX" :title="t('customers.empty')">
                            <template #action>
                                <Button variant="outline" size="sm" @click="set({ q: '' })">{{ t('customers.clear_search') }}</Button>
                            </template>
                        </EmptyState>
                    </template>
                    <template #cell-name="{ row }"><span class="font-medium" dir="auto">{{ row.name ?? '—' }}</span></template>
                    <template #cell-phone="{ row }"><span dir="ltr">{{ row.phone ?? '—' }}</span></template>
                    <template #cell-identities="{ row }">
                        <span class="flex gap-1">
                            <PlatformBadge v-for="identity in row.identities ?? []" :key="identity.id" :platform="identity.platform" size="xs" />
                        </span>
                    </template>
                    <template #cell-orders_count="{ row }"><span class="tabular-nums">{{ formatCount(row.orders_count, locale) }}</span></template>
                    <template #cell-total_spent="{ row }"><span class="whitespace-nowrap tabular-nums">{{ formatMoney(row.total_spent, locale) }}</span></template>
                    <template #cell-last_contact_at="{ row }"><span class="whitespace-nowrap tabular-nums text-muted-foreground">{{ formatDateTime(row.last_contact_at, locale) || '—' }}</span></template>
                </DataTable>
                <Pagination :page="customers" />
            </div>
        </div>
    </AppLayout>
</template>
