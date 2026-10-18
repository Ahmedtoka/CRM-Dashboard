<script setup lang="ts">
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import FormDialog from '@/components/crm/FormDialog.vue';
import IconAction from '@/components/crm/IconAction.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import SettingsShell from '@/components/crm/settings/SettingsShell.vue';
import { useCrud } from '@/composables/useCrud';
import { useI18n } from '@/composables/useI18n';
import { useSettingsBreadcrumbs } from '@/composables/useSettingsBreadcrumbs';
import { useToast } from '@/composables/useToast';
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
const toast = useToast();

/** BranchHoursController::HOURS_MAX: keeps the phone inside Messenger's 80-character card subtitle. */
const HOURS_MAX = 60;

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
    if (row.is_active && !window.confirm(t('settings.branch_hours.confirm_deactivate', { name: row.name }))) return;

    toggling.value = row.id;
    if (!(await crud.save(row.id, { is_active: !row.is_active }))) toast.push(crud.error.value ?? t('common.error'), 'error');
    toggling.value = null;
}

const area = (row: BranchRow) => (locale.value === 'en' ? row.area_en || row.area_ar : row.area_ar);

const columns = computed<Column[]>(() => [
    { key: 'name', label: t('settings.branch_hours.branch'), primary: true },
    { key: 'hours', label: t('settings.branch_hours.hours') },
    { key: 'phone', label: t('settings.branch_hours.phone') },
    { key: 'is_active', label: t('settings.branch_hours.status') },
    { key: 'actions', label: t('ui.actions'), align: 'end' },
]);

const title = computed(() => t('settings_hub.items.branch_hours'));
const breadcrumbs = useSettingsBreadcrumbs(() => title.value);
const input =
    'h-10 w-full rounded-md border border-line-strong bg-card px-3 text-sm focus-visible:border-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40';
</script>

<template>
    <Head :title="title" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <SettingsShell :title="title" :description="t('settings.branch_hours.description')" width="wide">
            <DataTable
                table-id="settings-branch-hours"
                :columns="columns"
                :rows="branches"
                :caption="title"
                :row-class="(r) => (r.is_active ? undefined : 'opacity-70')"
            >
                <template #empty>
                    <EmptyState :icon="Store" :title="t('settings.branch_hours.empty')" :body="t('settings.branch_hours.empty_body')" />
                </template>
                <template #cell-name="{ row }">
                    <span class="block font-semibold" dir="auto">{{ row.name }}</span>
                    <span class="block text-xs text-fg-3" dir="auto">{{ area(row) }}</span>
                </template>
                <template #cell-hours="{ row }">
                    <span v-if="row.hours" class="whitespace-pre-line" dir="auto">{{ row.hours }}</span>
                    <span v-else class="text-fg-3">{{ t('settings.branch_hours.no_hours') }}</span>
                </template>
                <template #cell-phone="{ row }">
                    <bdi v-if="row.phone" class="whitespace-nowrap tabular-nums" dir="ltr">{{ row.phone }}</bdi>
                    <span v-else class="text-fg-3">—</span>
                </template>
                <template #cell-is_active="{ row }">
                    <StatusChip
                        :label="row.is_active ? t('settings.branch_hours.active') : t('settings.branch_hours.inactive')"
                        :tone="row.is_active ? 'ok' : 'neutral'"
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
        </SettingsShell>

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
                    :maxlength="HOURS_MAX"
                    :placeholder="t('settings.branch_hours.hours_placeholder')"
                    class="w-full rounded-md border border-line-strong bg-card px-3 py-2 text-sm focus-visible:border-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40"
                />
                <span class="flex justify-between gap-2 text-xs text-fg-2">
                    <span>{{ t('settings.branch_hours.hours_hint') }}</span>
                    <span class="shrink-0 tabular-nums" aria-live="polite">{{
                        t('settings.branch_hours.hours_count', { n: form.hours.length, max: HOURS_MAX })
                    }}</span>
                </span>
            </label>
            <label class="grid gap-1">
                <span class="text-sm font-semibold">{{ t('settings.branch_hours.phone') }}</span>
                <input v-model="form.phone" type="tel" dir="ltr" maxlength="30" inputmode="tel" :class="input" />
            </label>
        </FormDialog>
    </AppLayout>
</template>
