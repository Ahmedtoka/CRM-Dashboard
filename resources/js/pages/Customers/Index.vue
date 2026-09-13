<script setup lang="ts">
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import Pagination from '@/components/crm/Pagination.vue';
import PlatformBadge from '@/components/crm/PlatformBadge.vue';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCount, formatDateTime, formatMoney } from '@/lib/format';
import type { Paginated } from '@/types/admin';
import type { Customer } from '@/types/crm';
import { Head, router } from '@inertiajs/vue3';
import { Search } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

type CustomerRow = Customer & { last_contact_at?: string | null };

const props = defineProps<{ customers: Paginated<CustomerRow>; filters: { q: string | null } }>();

const { t, locale } = useI18n();
const loading = ref(false);

const search = ref(props.filters.q ?? '');
let timer: number | undefined;
watch(search, (value) => {
    window.clearTimeout(timer);
    timer = window.setTimeout(() => {
        const q = value.trim();
        router.get('/customers', q ? { q } : {}, {
            preserveState: true,
            replace: true,
            onStart: () => (loading.value = true),
            onFinish: () => (loading.value = false),
        });
    }, 350);
});
onBeforeUnmount(() => window.clearTimeout(timer));

const columns = computed<Column[]>(() => [
    { key: 'name', label: t('customers.columns.name') },
    { key: 'phone', label: t('customers.columns.phone') },
    { key: 'identities', label: t('customers.columns.platforms') },
    { key: 'orders_count', label: t('customers.columns.orders'), align: 'end' },
    { key: 'total_spent', label: t('customers.columns.spent'), align: 'end' },
    { key: 'last_contact_at', label: t('customers.columns.last_contact') },
]);

const breadcrumbs = computed(() => [{ title: t('customers.title'), href: '/customers' }]);
</script>

<template>
    <Head :title="t('customers.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-4 p-4">
            <PageHeader :title="t('customers.title')" />

            <div class="relative max-w-sm">
                <Search class="pointer-events-none absolute start-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
                <input v-model="search" type="search" :placeholder="t('customers.search')" :aria-label="t('customers.search')" class="h-8 w-full rounded-md border border-input bg-background pe-2 ps-8 text-sm" />
            </div>

            <div>
                <DataTable :columns="columns" :rows="customers.data" clickable :loading="loading" :empty="t('customers.empty')" :caption="t('customers.title')" @row-click="router.visit(`/customers/${$event.id}`)">
                    <template #cell-name="{ row }"><span class="font-medium">{{ row.name ?? '—' }}</span></template>
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
