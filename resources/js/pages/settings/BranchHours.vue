<script setup lang="ts">
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import FormDialog from '@/components/crm/FormDialog.vue';
import IconAction from '@/components/crm/IconAction.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { useCrud } from '@/composables/useCrud';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BranchRow } from '@/types/admin';
import { Head } from '@inertiajs/vue3';
import { Eye, EyeOff, Pencil, Store } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';

// «مواعيد الفروع» (audit 2026-10-16): hours, phone and the active flag of the branches the bot lists.
// Branches are not created or deleted here; the full branches page stays removed.
defineProps<{ branches: BranchRow[] }>();

const { t, locale } = useI18n();
const crud = useCrud('/settings/branch-hours', 'branches');

const open = ref(false);
const editing = ref<BranchRow | null>(null);
const form = reactive({ hours: '', phone: '' });
const toggling = ref<number | null>(null);

function edit(row: BranchRow): void {
    editing.value = row;
    Object.assign(form, { hours: row.hours ?? '', phone: row.phone ?? '' });
    crud.error.value = null;
    open.value = true;
}

async function submit(): Promise<void> {
    if (editing.value && (await crud.save(editing.value.id, { hours: form.hours, phone: form.phone }))) open.value = false;
}

async function toggle(row: BranchRow): Promise<void> {
    toggling.value = row.id;
    await crud.save(row.id, { is_active: !row.is_active });
    toggling.value = null;
}

const area = (row: BranchRow) => (locale.value === 'en' ? row.area_en || row.area_ar : row.area_ar);

const columns = computed<Column[]>(() => [
    { key: 'name', label: t('settings.branch_hours.branch') },
    { key: 'hours', label: t('settings.branch_hours.hours') },
    { key: 'phone', label: t('settings.branch_hours.phone') },
    { key: 'is_active', label: t('settings.branch_hours.status') },
    { key: 'actions', label: t('ui.actions'), align: 'end' },
]);

const breadcrumbs = computed(() => [{ title: t('settings.branch_hours.title'), href: '/settings/branch-hours' }]);
const input = 'h-9 w-full rounded-md border border-input bg-background px-3 text-sm';
</script>

<template>
    <Head :title="t('settings.branch_hours.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-4xl space-y-4 p-3 md:p-6">
            <PageHeader :title="t('settings.branch_hours.title')" :description="t('settings.branch_hours.description')" />

            <DataTable
                table-id="settings-branch-hours"
                :columns="columns"
                :rows="branches"
                :caption="t('settings.branch_hours.title')"
                :row-class="(r) => (r.is_active ? undefined : 'opacity-70')"
            >
                <template #empty>
                    <EmptyState :icon="Store" :title="t('settings.branch_hours.empty')" :body="t('settings.branch_hours.empty_body')" />
                </template>
                <template #cell-name="{ row }">
                    <span class="block font-medium" dir="auto">{{ row.name }}</span>
                    <span class="block text-xs text-muted-foreground" dir="auto">{{ area(row) }}</span>
                </template>
                <template #cell-hours="{ row }">
                    <span v-if="row.hours" class="whitespace-pre-line" dir="auto">{{ row.hours }}</span>
                    <span v-else class="text-muted-foreground">{{ t('settings.branch_hours.no_hours') }}</span>
                </template>
                <template #cell-phone="{ row }">
                    <span class="whitespace-nowrap tabular-nums" dir="ltr">{{ row.phone || '—' }}</span>
                </template>
                <template #cell-is_active="{ row }">
                    <StatusChip
                        :label="row.is_active ? t('settings.branch_hours.active') : t('settings.branch_hours.inactive')"
                        :tone="row.is_active ? 'positive' : 'neutral'"
                        dot
                    />
                </template>
                <template #cell-actions="{ row }">
                    <span class="inline-flex gap-0.5">
                        <IconAction :icon="Pencil" size="sm" :label="t('settings.branch_hours.edit', { name: row.name })" @click="edit(row)" />
                        <IconAction
                            :icon="row.is_active ? EyeOff : Eye"
                            size="sm"
                            :loading="toggling === row.id"
                            :label="t(row.is_active ? 'settings.branch_hours.deactivate' : 'settings.branch_hours.activate', { name: row.name })"
                            @click="toggle(row)"
                        />
                    </span>
                </template>
            </DataTable>
        </div>

        <FormDialog
            v-model:open="open"
            :title="editing ? t('settings.branch_hours.edit', { name: editing.name }) : ''"
            :busy="crud.busy.value"
            :error="crud.error.value"
            @submit="submit"
        >
            <label class="grid gap-1">
                <span class="text-sm font-semibold">{{ t('settings.branch_hours.hours') }}</span>
                <textarea
                    v-model="form.hours"
                    dir="auto"
                    rows="2"
                    maxlength="200"
                    :placeholder="t('settings.branch_hours.hours_placeholder')"
                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                />
                <span class="text-xs text-muted-foreground">{{ t('settings.branch_hours.hours_hint') }}</span>
            </label>
            <label class="grid gap-1">
                <span class="text-sm font-semibold">{{ t('settings.branch_hours.phone') }}</span>
                <input v-model="form.phone" type="tel" dir="ltr" maxlength="30" inputmode="tel" :class="input" />
            </label>
        </FormDialog>
    </AppLayout>
</template>
