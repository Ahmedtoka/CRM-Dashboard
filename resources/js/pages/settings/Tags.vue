<script setup lang="ts">
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
import FormDialog from '@/components/crm/FormDialog.vue';
import IconAction from '@/components/crm/IconAction.vue';
import StarterEmptyState from '@/components/crm/StarterEmptyState.vue';
import SettingsShell from '@/components/crm/settings/SettingsShell.vue';
import { Button } from '@/components/ui/button';
import { useCrud } from '@/composables/useCrud';
import { useI18n } from '@/composables/useI18n';
import { useSettingsBreadcrumbs } from '@/composables/useSettingsBreadcrumbs';
import { useUrlFilters } from '@/composables/useUrlFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCount } from '@/lib/format';
import { sortRows } from '@/lib/sort';
import type { TagRow } from '@/types/admin';
import { Head } from '@inertiajs/vue3';
import { Pencil, Plus, Tag as TagIcon, Trash2 } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';

const props = defineProps<{ tags: TagRow[]; starterExamples: string[] }>();

// A full list: it sorts in the browser, and the sort stays in the URL.
const { filters, set } = useUrlFilters({ sort: '' });
const shown = computed(() => sortRows(props.tags, filters.value.sort));

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
    { key: 'name', label: t('settings.tags.name'), sortable: true, primary: true },
    { key: 'conversations_count', label: t('settings.tags.conversations'), numeric: true, sortable: true },
    { key: 'actions', label: t('ui.actions'), align: 'end' },
]);

const title = computed(() => t('settings_hub.items.tags'));
const breadcrumbs = useSettingsBreadcrumbs(() => title.value);
const input =
    'h-10 w-full rounded-md border border-line-strong bg-card px-3 text-sm focus-visible:border-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40';
</script>

<template>
    <Head :title="title" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <SettingsShell :title="title" :description="t('settings.tags.description')" width="form">
            <template #actions>
                <Button @click="edit(null)"><Plus aria-hidden="true" />{{ t('settings.tags.add') }}</Button>
            </template>

            <div v-if="!tags.length" class="rounded-lg border border-line bg-card">
                <StarterEmptyState
                    :icon="TagIcon"
                    :title="t('settings.starter.tags_title')"
                    :body="t('settings.starter.tags_body')"
                    :preview="starterExamples"
                    endpoint="/settings/tags/examples"
                    reload-prop="tags"
                >
                    <button type="button" class="min-h-10 px-2 text-13 font-semibold text-brand-ink hover:underline" @click="edit(null)">
                        {{ t('settings.starter.or_create') }}
                    </button>
                </StarterEmptyState>
            </div>

            <DataTable
                table-id="settings-tags"
                v-else
                :columns="columns"
                :rows="shown"
                :empty="t('settings.tags.empty')"
                :caption="title"
                :sort="filters.sort || null"
                @update:sort="set({ sort: $event })"
            >
                <template #cell-name="{ row }">
                    <span
                        class="inline-flex h-7 max-w-full items-center gap-1.5 rounded-full border border-line-strong bg-card px-2.5 text-13 font-semibold"
                    >
                        <span
                            class="size-2.5 shrink-0 rounded-full"
                            :class="row.color ? '' : 'bg-fg-3'"
                            :style="row.color ? { backgroundColor: row.color } : undefined"
                            aria-hidden="true"
                        />
                        <span class="truncate" dir="auto">{{ row.name }}</span>
                    </span>
                </template>
                <template #cell-conversations_count="{ row }"
                    ><span class="tabular-nums">{{ formatCount(row.conversations_count, locale) }}</span></template
                >
                <template #cell-actions="{ row }">
                    <span class="inline-flex gap-0.5">
                        <IconAction :icon="Pencil" size="sm" :label="`${t('ui.edit')} ${row.name}`" @click="edit(row)" />
                        <IconAction
                            :icon="Trash2"
                            size="sm"
                            variant="destructive"
                            :label="`${t('ui.delete')} ${row.name}`"
                            @click="crud.remove(row.id, t('ui.confirm_delete', { name: row.name }))"
                        />
                    </span>
                </template>
            </DataTable>
        </SettingsShell>

        <FormDialog
            v-model:open="open"
            :title="editingId ? t('settings.tags.edit') : t('settings.tags.add')"
            :busy="crud.busy.value"
            :error="crud.error.value"
            @submit="submit"
        >
            <div class="grid grid-cols-[1fr_5rem] gap-2">
                <label class="grid gap-1">
                    <span class="text-sm font-semibold">{{ t('settings.tags.name') }}</span>
                    <input v-model="form.name" dir="auto" required maxlength="100" :class="input" />
                </label>
                <label class="grid gap-1">
                    <span class="text-sm font-semibold">{{ t('settings.tags.color') }}</span>
                    <input v-model="form.color" type="color" class="h-10 w-full rounded-md border border-line-strong bg-card p-1" />
                </label>
            </div>
        </FormDialog>
    </AppLayout>
</template>
