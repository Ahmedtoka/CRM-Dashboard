<script setup lang="ts">
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
import FormDialog from '@/components/crm/FormDialog.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import { useCrud } from '@/composables/useCrud';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCount } from '@/lib/format';
import type { TagRow } from '@/types/admin';
import { Head } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';

defineProps<{ tags: TagRow[] }>();

const { t, locale } = useI18n();
const crud = useCrud('/settings/tags', 'tags');

const open = ref(false);
const editingId = ref<number | null>(null);
const form = reactive({ name: '', color: '#6366f1' });

function edit(row: TagRow | null): void {
    editingId.value = row?.id ?? null;
    Object.assign(form, { name: row?.name ?? '', color: row?.color ?? '#6366f1' });
    crud.error.value = null;
    open.value = true;
}

async function submit(): Promise<void> {
    if (await crud.save(editingId.value, { ...form })) open.value = false;
}

const columns = computed<Column[]>(() => [
    { key: 'name', label: t('settings.tags.name') },
    { key: 'conversations_count', label: t('settings.tags.conversations'), align: 'end' },
    { key: 'actions', label: t('ui.actions'), align: 'end' },
]);

const breadcrumbs = computed(() => [{ title: t('settings.tags.title'), href: '/settings/tags' }]);
const iconBtn = 'rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground';
</script>

<template>
    <Head :title="t('settings.tags.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="max-w-3xl space-y-4 p-4">
            <PageHeader :title="t('settings.tags.title')">
                <button type="button" class="inline-flex h-8 items-center gap-1.5 rounded-md bg-primary px-3 text-xs font-medium text-primary-foreground" @click="edit(null)">
                    <Plus class="size-3.5" aria-hidden="true" />{{ t('settings.tags.add') }}
                </button>
            </PageHeader>

            <DataTable :columns="columns" :rows="tags" :empty="t('settings.tags.empty')" :caption="t('settings.tags.title')">
                <template #cell-name="{ row }">
                    <span class="inline-flex items-center gap-1.5">
                        <span class="size-2.5 rounded-full" :style="{ backgroundColor: row.color ?? '#94a3b8' }" aria-hidden="true" />
                        <span class="font-medium" dir="auto">{{ row.name }}</span>
                    </span>
                </template>
                <template #cell-conversations_count="{ row }"><span class="tabular-nums">{{ formatCount(row.conversations_count, locale) }}</span></template>
                <template #cell-actions="{ row }">
                    <span class="inline-flex gap-0.5">
                        <button type="button" :class="iconBtn" :title="t('ui.edit')" :aria-label="`${t('ui.edit')} ${row.name}`" @click="edit(row)"><Pencil class="size-3.5" /></button>
                        <button type="button" :class="[iconBtn, 'hover:text-red-700']" :title="t('ui.delete')" :aria-label="`${t('ui.delete')} ${row.name}`" @click="crud.remove(row.id, t('ui.confirm_delete', { name: row.name }))">
                            <Trash2 class="size-3.5" />
                        </button>
                    </span>
                </template>
            </DataTable>
        </div>

        <FormDialog v-model:open="open" :title="editingId ? t('settings.tags.edit') : t('settings.tags.add')" :busy="crud.busy.value" :error="crud.error.value" @submit="submit">
            <div class="grid grid-cols-[1fr_5rem] gap-2">
                <label class="grid gap-1">
                    <span class="text-xs font-medium">{{ t('settings.tags.name') }}</span>
                    <input v-model="form.name" dir="auto" required maxlength="100" class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm" />
                </label>
                <label class="grid gap-1">
                    <span class="text-xs font-medium">{{ t('settings.tags.color') }}</span>
                    <input v-model="form.color" type="color" class="h-9 w-full rounded-md border border-input bg-background p-1" />
                </label>
            </div>
        </FormDialog>
    </AppLayout>
</template>
