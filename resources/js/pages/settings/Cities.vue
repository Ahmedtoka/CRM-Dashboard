<script setup lang="ts">
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import FormDialog from '@/components/crm/FormDialog.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import { useCrud } from '@/composables/useCrud';
import { useI18n } from '@/composables/useI18n';
import { useUrlFilters } from '@/composables/useUrlFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMoney } from '@/lib/format';
import { sortRows } from '@/lib/sort';
import type { CityRow } from '@/types/admin';
import { Head } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { MapPin, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';

const props = defineProps<{ cities: CityRow[] }>();

// A full list: it sorts in the browser, and the sort stays in the URL.
const { filters, set } = useUrlFilters({ sort: '' });
const shown = computed(() => sortRows(props.cities, filters.value.sort, (row, key) => (key === 'shipping_fee' ? Number(row.shipping_fee) : row[key as keyof CityRow])));

const { t, locale } = useI18n();
const crud = useCrud('/settings/cities', 'cities');

const open = ref(false);
const editingId = ref<number | null>(null);
const form = reactive({ name_ar: '', name_en: '', shipping_fee: 0 as number | string });

function edit(row: CityRow | null): void {
    editingId.value = row?.id ?? null;
    Object.assign(form, { name_ar: row?.name_ar ?? '', name_en: row?.name_en ?? '', shipping_fee: row ? Number(row.shipping_fee) : 0 });
    crud.error.value = null;
    open.value = true;
}

async function submit(): Promise<void> {
    if (await crud.save(editingId.value, { ...form })) open.value = false;
}

const columns = computed<Column[]>(() => [
    { key: 'name_ar', label: t('settings.cities.name_ar'), sortable: true },
    { key: 'name_en', label: t('settings.cities.name_en'), sortable: true },
    { key: 'shipping_fee', label: t('settings.cities.fee'), numeric: true, sortable: true },
    { key: 'actions', label: t('ui.actions'), align: 'end' },
]);

const breadcrumbs = computed(() => [{ title: t('settings.cities.title'), href: '/settings/cities' }]);
const input = 'h-9 w-full rounded-md border border-input bg-background px-3 text-sm';
const iconBtn = 'rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground';
</script>

<template>
    <Head :title="t('settings.cities.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-3xl space-y-4 p-3 md:p-6">
            <PageHeader :title="t('settings.cities.title')">
                <button type="button" class="inline-flex h-8 items-center gap-1.5 rounded-md bg-primary px-3 text-xs font-medium text-primary-foreground" @click="edit(null)">
                    <Plus class="size-3.5" aria-hidden="true" />{{ t('settings.cities.add') }}
                </button>
            </PageHeader>

            <DataTable
                table-id="settings-cities"
                :columns="columns"
                :rows="shown"
                :caption="t('settings.cities.title')"
                :sort="filters.sort || null"
                @update:sort="set({ sort: $event })"
            >
                <template #empty>
                    <EmptyState :icon="MapPin" :title="t('settings.cities.empty')">
                        <template #action>
                            <Button size="sm" @click="edit(null)"><Plus aria-hidden="true" />{{ t('settings.cities.add') }}</Button>
                        </template>
                    </EmptyState>
                </template>
                <template #cell-name_ar="{ row }"><span class="font-medium" dir="rtl">{{ row.name_ar }}</span></template>
                <template #cell-name_en="{ row }"><span dir="ltr">{{ row.name_en ?? '—' }}</span></template>
                <template #cell-shipping_fee="{ row }"><span class="whitespace-nowrap tabular-nums">{{ formatMoney(row.shipping_fee, locale) }}</span></template>
                <template #cell-actions="{ row }">
                    <span class="inline-flex gap-0.5">
                        <button type="button" :class="iconBtn" :title="t('ui.edit')" :aria-label="`${t('ui.edit')} ${row.name_ar}`" @click="edit(row)"><Pencil class="size-3.5" /></button>
                        <button type="button" :class="[iconBtn, 'hover:text-destructive']" :title="t('ui.delete')" :aria-label="`${t('ui.delete')} ${row.name_ar}`" @click="crud.remove(row.id, t('ui.confirm_delete', { name: row.name_ar }))">
                            <Trash2 class="size-3.5" />
                        </button>
                    </span>
                </template>
            </DataTable>
        </div>

        <FormDialog v-model:open="open" :title="editingId ? t('settings.cities.edit') : t('settings.cities.add')" :busy="crud.busy.value" :error="crud.error.value" @submit="submit">
            <label class="grid gap-1">
                <span class="text-sm font-semibold">{{ t('settings.cities.name_ar') }}</span>
                <input v-model="form.name_ar" dir="rtl" required maxlength="255" :class="input" />
            </label>
            <label class="grid gap-1">
                <span class="text-sm font-semibold">{{ t('settings.cities.name_en') }}</span>
                <input v-model="form.name_en" dir="ltr" required maxlength="255" :class="input" />
            </label>
            <label class="grid gap-1">
                <span class="text-sm font-semibold">{{ t('settings.cities.fee') }}</span>
                <input v-model.number="form.shipping_fee" type="number" min="0" step="0.01" required dir="ltr" :class="input" />
            </label>
        </FormDialog>
    </AppLayout>
</template>
