<script setup lang="ts">
import { useCrud } from '@/composables/useCrud';
import { useI18n } from '@/composables/useI18n';
import type { QuickReplyCategory } from '@/types/crm';
import { Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { reactive } from 'vue';

defineProps<{ categories: QuickReplyCategory[] }>();

const { t } = useI18n();
const crud = useCrud('/settings/quick-reply-categories', 'categories');

const editing = reactive<Record<number, { name: string; sort: number }>>({});
const newCategory = reactive({ name: '', sort: 100 });

function startEdit(category: QuickReplyCategory): void {
    editing[category.id] = { name: category.name, sort: category.sort };
}

function cancelEdit(id: number): void {
    delete editing[id];
}

async function saveEdit(id: number): Promise<void> {
    const draft = editing[id];
    if (!draft) return;
    if (await crud.save(id, draft)) delete editing[id];
}

async function add(): Promise<void> {
    if (!newCategory.name.trim()) return;
    if (await crud.save(null, { ...newCategory })) Object.assign(newCategory, { name: '', sort: 100 });
}

function remove(category: QuickReplyCategory): void {
    void crud.remove(category.id, t('ui.confirm_delete', { name: category.name }));
}

const input = 'h-8 rounded-md border border-input bg-background px-2 text-xs';
</script>

<template>
    <div class="space-y-3">
        <p v-if="crud.error.value" role="alert" class="rounded-md bg-red-50 px-3 py-2 text-xs text-red-700">{{ crud.error.value }}</p>

        <p v-if="!categories.length" class="text-xs text-muted-foreground">{{ t('ui.empty') }}</p>
        <ul class="space-y-1.5">
            <li v-for="c in categories" :key="c.id" class="flex items-center gap-2 rounded-md border bg-card px-2.5 py-1.5">
                <template v-if="editing[c.id]">
                    <input v-model="editing[c.id].name" :class="[input, 'flex-1']" dir="auto" maxlength="100" />
                    <input v-model.number="editing[c.id].sort" type="number" :class="[input, 'w-16']" min="0" max="10000" />
                    <button type="button" class="rounded px-2 py-1 text-xs text-primary" @click="saveEdit(c.id)">{{ t('common.save') }}</button>
                    <button type="button" class="rounded px-2 py-1 text-xs text-muted-foreground" @click="cancelEdit(c.id)">{{ t('common.cancel') }}</button>
                </template>
                <template v-else>
                    <span class="flex-1 text-sm" dir="auto">{{ c.name }}</span>
                    <span class="text-2xs text-muted-foreground">{{ t('replies.sort') }}: {{ c.sort }}</span>
                    <button type="button" class="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground" :aria-label="`${t('ui.edit')} ${c.name}`" @click="startEdit(c)">
                        <Pencil class="size-3.5" />
                    </button>
                    <button type="button" class="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-red-700" :aria-label="`${t('ui.delete')} ${c.name}`" @click="remove(c)">
                        <Trash2 class="size-3.5" />
                    </button>
                </template>
            </li>
        </ul>

        <div class="flex flex-wrap items-end gap-2 rounded-md border border-dashed px-2.5 py-2">
            <label class="grid flex-1 gap-1">
                <span class="text-2xs font-medium">{{ t('replies.category_name') }}</span>
                <input v-model="newCategory.name" :class="input" dir="auto" maxlength="100" />
            </label>
            <label class="grid gap-1">
                <span class="text-2xs font-medium">{{ t('replies.sort') }}</span>
                <input v-model.number="newCategory.sort" type="number" :class="[input, 'w-16']" min="0" max="10000" />
            </label>
            <button type="button" class="inline-flex h-8 items-center gap-1.5 rounded-md bg-primary px-3 text-xs font-medium text-primary-foreground" @click="add">
                <Plus class="size-3.5" aria-hidden="true" />{{ t('replies.add_category') }}
            </button>
        </div>
    </div>
</template>
