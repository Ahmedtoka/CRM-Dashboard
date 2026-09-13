<script setup lang="ts">
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
import FormDialog from '@/components/crm/FormDialog.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import { useCrud } from '@/composables/useCrud';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMoney } from '@/lib/format';
import type { CityRow } from '@/types/admin';
import { Head } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';

defineProps<{ cities: CityRow[] }>();

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
    { key: 'name_ar', label: t('settings.cities.name_ar') },
    { key: 'name_en', label: t('settings.cities.name_en') },
    { key: 'shipping_fee', label: t('settings.cities.fee'), align: 'end' },
    { key: 'actions', label: t('ui.actions'), align: 'end' },
]);

const breadcrumbs = computed(() => [{ title: t('settings.cities.title'), href: '/settings/cities' }]);
const input = 'h-9 w-full rounded-md border border-input bg-background px-3 text-sm';
const iconBtn = 'rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground';
</script>

<template>
    <Head :title="t('settings.cities.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="max-w-3xl space-y-4 p-4">
            <PageHeader :title="t('settings.cities.title')">
                <button type="button" class="inline-flex h-8 items-center gap-1.5 rounded-md bg-primary px-3 text-xs font-medium text-primary-foreground" @click="edit(null)">
                    <Plus class="size-3.5" aria-hidden="true" />{{ t('settings.cities.add') }}
                </button>
            </PageHeader>

            <DataTable :columns="columns" :rows="cities" :empty="t('settings.cities.empty')" :caption="t('settings.cities.title')">
                <template #cell-name_ar="{ row }"><span class="font-medium" dir="rtl">{{ row.name_ar }}</span></template>
                <template #cell-name_en="{ row }"><span dir="ltr">{{ row.name_en ?? '—' }}</span></template>
                <template #cell-shipping_fee="{ row }"><span class="whitespace-nowrap tabular-nums">{{ formatMoney(row.shipping_fee, locale) }}</span></template>
                <template #cell-actions="{ row }">
                    <span class="inline-flex gap-0.5">
                        <button type="button" :class="iconBtn" :title="t('ui.edit')" :aria-label="`${t('ui.edit')} ${row.name_ar}`" @click="edit(row)"><Pencil class="size-3.5" /></button>
                        <button type="button" :class="[iconBtn, 'hover:text-red-700']" :title="t('ui.delete')" :aria-label="`${t('ui.delete')} ${row.name_ar}`" @click="crud.remove(row.id, t('ui.confirm_delete', { name: row.name_ar }))">
                            <Trash2 class="size-3.5" />
                        </button>
                    </span>
                </template>
            </DataTable>
        </div>

        <FormDialog v-model:open="open" :title="editingId ? t('settings.cities.edit') : t('settings.cities.add')" :busy="crud.busy.value" :error="crud.error.value" @submit="submit">
            <label class="grid gap-1">
                <span class="text-xs font-medium">{{ t('settings.cities.name_ar') }}</span>
                <input v-model="form.name_ar" dir="rtl" required maxlength="255" :class="input" />
            </label>
            <label class="grid gap-1">
                <span class="text-xs font-medium">{{ t('settings.cities.name_en') }}</span>
                <input v-model="form.name_en" dir="ltr" required maxlength="255" :class="input" />
            </label>
            <label class="grid gap-1">
                <span class="text-xs font-medium">{{ t('settings.cities.fee') }}</span>
                <input v-model.number="form.shipping_fee" type="number" min="0" step="0.01" required dir="ltr" :class="input" />
            </label>
        </FormDialog>
    </AppLayout>
</template>
