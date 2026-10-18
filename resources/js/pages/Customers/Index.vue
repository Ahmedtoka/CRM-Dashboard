<script setup lang="ts">
import CustomerAvatar from '@/components/crm/customers/CustomerAvatar.vue';
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
import { formatCount, formatDateTime, formatListStamp, formatMoney } from '@/lib/format';
import type { Paginated } from '@/types/admin';
import type { Customer } from '@/types/crm';
import { Head, router } from '@inertiajs/vue3';
import { SearchX, Users } from 'lucide-vue-next';
import { computed, watch } from 'vue';

type CustomerRow = Customer & { last_contact_at?: string | null };

// The server still shares `filters`; the page reads the same values from the URL (useUrlFilters).
defineOptions({ inheritAttrs: false });

const props = defineProps<{ customers: Paginated<CustomerRow> }>();

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
    { key: 'city', label: t('customers.columns.city'), hideOnMobile: true },
    { key: 'identities', label: t('customers.columns.platforms'), hideOnMobile: true },
    { key: 'orders_count', label: t('customers.columns.orders'), numeric: true, sortable: true },
    { key: 'total_spent', label: t('customers.columns.spent'), numeric: true, sortable: true },
    { key: 'last_contact_at', label: t('customers.columns.last_contact'), sortable: true },
]);

const now = Date.now();
const summary = computed(() => t('ui.results', { n: formatCount(props.customers.meta?.total ?? props.customers.data.length, locale.value) }));
const breadcrumbs = computed(() => [{ title: t('customers.title'), href: '/customers' }]);
</script>

<template>
    <Head :title="t('customers.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full min-w-0 max-w-7xl space-y-4 p-3 md:p-6">
            <PageHeader :title="t('customers.title')" :description="t('customers.description')" />

            <FilterBar
                :search="filters.q"
                :search-placeholder="t('customers.search')"
                :chips="[]"
                :summary="summary"
                @update:search="set({ q: $event })"
                @clear="clear()"
            />

            <div class="min-w-0">
                <DataTable
                    table-id="customers"
                    :columns="columns"
                    :rows="customers.data"
                    clickable
                    :loading="loading"
                    :empty="t('customers.empty')"
                    :empty-icon="Users"
                    :caption="t('customers.title')"
                    :sort="filters.sort || null"
                    @update:sort="set({ sort: $event })"
                    @row-click="router.visit(`/customers/${$event.id}`)"
                >
                    <template #empty>
                        <EmptyState
                            :icon="filters.q ? SearchX : Users"
                            :title="filters.q ? t('customers.empty_search') : t('customers.empty')"
                            :body="filters.q ? t('customers.empty_search_body') : undefined"
                        >
                            <template v-if="filters.q" #action>
                                <Button variant="outline" size="sm" @click="set({ q: '' })">{{ t('customers.clear_search') }}</Button>
                            </template>
                        </EmptyState>
                    </template>
                    <template #cell-name="{ row }">
                        <span class="inline-flex min-w-0 max-w-full items-center gap-2.5 align-middle">
                            <CustomerAvatar :name="row.name" :src="row.avatar_url" :platform="row.identities?.[0]?.platform ?? null" />
                            <span class="min-w-0 truncate font-semibold" dir="auto">{{ row.name ?? '—' }}</span>
                        </span>
                    </template>
                    <template #cell-phone="{ row }"
                        ><span class="tabular-nums" dir="ltr">{{ row.phone ?? '—' }}</span></template
                    >
                    <template #cell-city="{ row }"
                        ><span class="block max-w-[12rem] truncate" dir="auto">{{ row.city || '—' }}</span></template
                    >
                    <template #cell-identities="{ row }">
                        <span class="flex gap-1">
                            <PlatformBadge v-for="identity in row.identities ?? []" :key="identity.id" :platform="identity.platform" size="xs" />
                        </span>
                    </template>
                    <template #cell-orders_count="{ row }"
                        ><span class="tabular-nums">{{ formatCount(row.orders_count, locale) }}</span></template
                    >
                    <template #cell-total_spent="{ row }"
                        ><span class="whitespace-nowrap font-semibold tabular-nums">{{ formatMoney(row.total_spent, locale) }}</span></template
                    >
                    <template #cell-last_contact_at="{ row }"
                        ><span class="whitespace-nowrap tabular-nums text-fg-2" :title="formatDateTime(row.last_contact_at, locale) || undefined">{{
                            row.last_contact_at ? formatListStamp(row.last_contact_at, locale, now) : '—'
                        }}</span></template
                    >
                </DataTable>
                <Pagination :page="customers" />
            </div>
        </div>
    </AppLayout>
</template>
