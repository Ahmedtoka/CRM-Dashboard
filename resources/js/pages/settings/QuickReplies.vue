<script setup lang="ts">
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
import FormDialog from '@/components/crm/FormDialog.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import PlatformBadge from '@/components/crm/PlatformBadge.vue';
import PlatformCheckboxes from '@/components/crm/PlatformCheckboxes.vue';
import { useCrud } from '@/composables/useCrud';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import type { QuickReplyRow } from '@/types/admin';
import type { PlatformValue } from '@/types/crm';
import { Head } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';

defineProps<{ quickReplies: QuickReplyRow[] }>();

const { t } = useI18n();
const crud = useCrud('/settings/quick-replies', 'quickReplies');

const open = ref(false);
const editingId = ref<number | null>(null);
const form = reactive({ shortcut: '', title: '', body: '', platforms: [] as PlatformValue[] });

function edit(row: QuickReplyRow | null): void {
    editingId.value = row?.id ?? null;
    Object.assign(form, { shortcut: row?.shortcut ?? '', title: row?.title ?? '', body: row?.body ?? '', platforms: [...(row?.platforms ?? [])] });
    crud.error.value = null;
    open.value = true;
}

async function submit(): Promise<void> {
    const payload = { ...form, shortcut: form.shortcut.replace(/^\//, ''), platforms: form.platforms.length ? form.platforms : null };
    if (await crud.save(editingId.value, payload)) open.value = false;
}

const columns = computed<Column[]>(() => [
    { key: 'shortcut', label: t('settings.quick_replies.shortcut') },
    { key: 'title', label: t('settings.quick_replies.title_label') },
    { key: 'body', label: t('settings.quick_replies.body') },
    { key: 'platforms', label: t('ui.platforms') },
    { key: 'creator', label: t('settings.quick_replies.creator') },
    { key: 'actions', label: t('ui.actions'), align: 'end' },
]);

const breadcrumbs = computed(() => [{ title: t('settings.quick_replies.title'), href: '/settings/quick-replies' }]);
const input = 'h-9 w-full rounded-md border border-input bg-background px-3 text-sm';
const iconBtn = 'rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground';
</script>

<template>
    <Head :title="t('settings.quick_replies.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-4 p-4">
            <PageHeader :title="t('settings.quick_replies.title')">
                <button type="button" class="inline-flex h-8 items-center gap-1.5 rounded-md bg-primary px-3 text-xs font-medium text-primary-foreground" @click="edit(null)">
                    <Plus class="size-3.5" aria-hidden="true" />{{ t('settings.quick_replies.add') }}
                </button>
            </PageHeader>

            <DataTable :columns="columns" :rows="quickReplies" :empty="t('settings.quick_replies.empty')" :caption="t('settings.quick_replies.title')">
                <template #cell-shortcut="{ row }"><code class="rounded bg-muted px-1.5 py-0.5" dir="ltr">/{{ row.shortcut }}</code></template>
                <template #cell-body="{ row }"><span class="line-clamp-2 max-w-md" dir="auto">{{ row.body }}</span></template>
                <template #cell-platforms="{ row }">
                    <span v-if="!row.platforms?.length" class="text-muted-foreground">{{ t('ui.all_platforms') }}</span>
                    <span v-else class="flex gap-1"><PlatformBadge v-for="p in row.platforms" :key="p" :platform="p" size="xs" /></span>
                </template>
                <template #cell-creator="{ row }">{{ row.creator?.name ?? '—' }}</template>
                <template #cell-actions="{ row }">
                    <span class="inline-flex gap-0.5">
                        <button type="button" :class="iconBtn" :title="t('ui.edit')" :aria-label="`${t('ui.edit')} ${row.title}`" @click="edit(row)"><Pencil class="size-3.5" /></button>
                        <button type="button" :class="[iconBtn, 'hover:text-red-700']" :title="t('ui.delete')" :aria-label="`${t('ui.delete')} ${row.title}`" @click="crud.remove(row.id, t('ui.confirm_delete', { name: row.title }))">
                            <Trash2 class="size-3.5" />
                        </button>
                    </span>
                </template>
            </DataTable>
        </div>

        <FormDialog v-model:open="open" :title="editingId ? t('settings.quick_replies.edit') : t('settings.quick_replies.add')" :busy="crud.busy.value" :error="crud.error.value" @submit="submit">
            <div class="grid grid-cols-[8rem_1fr] gap-2">
                <label class="grid gap-1">
                    <span class="text-xs font-medium">{{ t('settings.quick_replies.shortcut') }}</span>
                    <input v-model="form.shortcut" :class="input" dir="ltr" required maxlength="50" />
                </label>
                <label class="grid gap-1">
                    <span class="text-xs font-medium">{{ t('settings.quick_replies.title_label') }}</span>
                    <input v-model="form.title" :class="input" dir="auto" required maxlength="255" />
                </label>
            </div>
            <label class="grid gap-1">
                <span class="text-xs font-medium">{{ t('settings.quick_replies.body') }}</span>
                <textarea v-model="form.body" rows="4" dir="auto" required maxlength="4000" class="rounded-md border border-input bg-background px-3 py-2 text-sm" />
            </label>
            <PlatformCheckboxes v-model="form.platforms" :legend="t('settings.rules.platforms')" />
        </FormDialog>
    </AppLayout>
</template>
