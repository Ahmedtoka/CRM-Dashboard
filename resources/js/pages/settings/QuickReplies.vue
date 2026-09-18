<script setup lang="ts">
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import PlatformBadge from '@/components/crm/PlatformBadge.vue';
import StarterEmptyState from '@/components/crm/StarterEmptyState.vue';
import CategoryManager from '@/components/crm/replies/CategoryManager.vue';
import QuickReplyEditor from '@/components/crm/replies/QuickReplyEditor.vue';
import { useCrud } from '@/composables/useCrud';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import type { QuickReplyRow, QuickReplyVariable } from '@/types/admin';
import type { QuickReplyCategory } from '@/types/crm';
import { Head } from '@inertiajs/vue3';
import { MessageSquareText, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    shared: QuickReplyRow[];
    personal: QuickReplyRow[];
    categories: QuickReplyCategory[];
    canManageShared: boolean;
    variables: QuickReplyVariable[];
    starterExamples: string[];
}>();

const { t } = useI18n();

type TabKey = 'shared' | 'mine' | 'categories';
const tab = ref<TabKey>(props.canManageShared ? 'shared' : 'mine');

const sharedCrud = useCrud('/settings/quick-replies', 'shared');
const personalCrud = useCrud('/settings/quick-replies', 'personal');

const editorOpen = ref(false);
const editingScope = ref<'shared' | 'personal'>('personal');
const editingId = ref<number | null>(null);

const editingRow = computed<QuickReplyRow | null>(() => {
    const rows = editingScope.value === 'shared' ? props.shared : props.personal;
    return rows.find((r) => r.id === editingId.value) ?? null;
});

function categoryName(id: number | null): string {
    if (id === null) return t('replies.uncategorized');
    return props.categories.find((c) => c.id === id)?.name ?? t('replies.uncategorized');
}

function openAdd(scope: 'shared' | 'personal'): void {
    editingScope.value = scope;
    editingId.value = null;
    editorOpen.value = true;
}

function openEdit(scope: 'shared' | 'personal', row: QuickReplyRow): void {
    editingScope.value = scope;
    editingId.value = row.id;
    editorOpen.value = true;
}

function remove(scope: 'shared' | 'personal', row: QuickReplyRow): void {
    void (scope === 'shared' ? sharedCrud : personalCrud).remove(row.id, t('ui.confirm_delete', { name: row.title }));
}

function columnsFor(scope: 'shared' | 'personal', manageable: boolean): Column[] {
    const cols: Column[] = [
        { key: 'shortcut', label: t('settings.quick_replies.shortcut') },
        { key: 'title', label: t('settings.quick_replies.title_label') },
        { key: 'body', label: t('settings.quick_replies.body') },
        { key: 'category', label: t('replies.category') },
        { key: 'platforms', label: t('ui.platforms') },
        { key: 'use_count', label: t('replies.uses_column') },
        { key: 'creator', label: t('settings.quick_replies.creator') },
    ];
    if (manageable) cols.push({ key: 'actions', label: t('ui.actions'), align: 'end' });

    return cols;
}

const sharedColumns = computed(() => columnsFor('shared', props.canManageShared));
const personalColumns = computed(() => columnsFor('personal', true));

const breadcrumbs = computed(() => [{ title: t('settings.quick_replies.title'), href: '/settings/quick-replies' }]);
</script>

<template>
    <Head :title="t('settings.quick_replies.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-7xl space-y-4 p-3 md:p-6">
            <PageHeader :title="t('settings.quick_replies.title')" />

            <div role="tablist" class="flex gap-1 border-b border-border">
                <button
                    type="button"
                    role="tab"
                    :aria-selected="tab === 'shared'"
                    class="border-b-2 px-3 py-2 text-sm font-medium"
                    :class="tab === 'shared' ? 'border-primary text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground'"
                    @click="tab = 'shared'"
                >
                    {{ t('replies.tab_shared') }}
                </button>
                <button
                    type="button"
                    role="tab"
                    :aria-selected="tab === 'mine'"
                    class="border-b-2 px-3 py-2 text-sm font-medium"
                    :class="tab === 'mine' ? 'border-primary text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground'"
                    @click="tab = 'mine'"
                >
                    {{ t('replies.tab_mine') }}
                </button>
                <button
                    v-if="canManageShared"
                    type="button"
                    role="tab"
                    :aria-selected="tab === 'categories'"
                    class="border-b-2 px-3 py-2 text-sm font-medium"
                    :class="tab === 'categories' ? 'border-primary text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground'"
                    @click="tab = 'categories'"
                >
                    {{ t('replies.tab_categories') }}
                </button>
            </div>

            <div v-if="tab === 'shared'" class="space-y-3">
                <StarterEmptyState
                    v-if="!shared.length"
                    :icon="MessageSquareText"
                    :title="t('settings.starter.replies_title')"
                    :body="t('settings.starter.replies_body')"
                    :preview="starterExamples"
                    :endpoint="canManageShared ? '/settings/quick-replies/examples' : undefined"
                    reload-prop="shared"
                >
                    <button v-if="canManageShared" type="button" class="text-xs font-medium text-primary hover:underline" @click="openAdd('shared')">
                        {{ t('settings.starter.or_create') }}
                    </button>
                </StarterEmptyState>
                <div v-if="canManageShared && shared.length" class="flex justify-end">
                    <button type="button" class="inline-flex h-8 items-center gap-1.5 rounded-md bg-primary px-3 text-xs font-medium text-primary-foreground" @click="openAdd('shared')">
                        <Plus class="size-3.5" aria-hidden="true" />{{ t('settings.quick_replies.add') }}
                    </button>
                </div>
                <DataTable v-if="shared.length" :columns="sharedColumns" :rows="shared" :empty="t('settings.quick_replies.empty')" :caption="t('replies.tab_shared')">
                    <template #cell-shortcut="{ row }"><code class="rounded bg-muted px-1.5 py-0.5" dir="ltr">/{{ row.shortcut }}</code></template>
                    <template #cell-body="{ row }"><span class="line-clamp-2 max-w-md" dir="auto">{{ row.body }}</span></template>
                    <template #cell-category="{ row }">{{ categoryName(row.category_id) }}</template>
                    <template #cell-use_count="{ row }">{{ row.use_count }}</template>
                    <template #cell-platforms="{ row }">
                        <span v-if="!row.platforms?.length" class="text-muted-foreground">{{ t('ui.all_platforms') }}</span>
                        <span v-else class="flex gap-1"><PlatformBadge v-for="p in row.platforms" :key="p" :platform="p" size="xs" /></span>
                    </template>
                    <template #cell-creator="{ row }">{{ row.creator?.name ?? '—' }}</template>
                    <template v-if="canManageShared" #cell-actions="{ row }">
                        <span class="inline-flex gap-0.5">
                            <button type="button" class="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground" :title="t('ui.edit')" :aria-label="`${t('ui.edit')} ${row.title}`" @click="openEdit('shared', row)">
                                <Pencil class="size-3.5" />
                            </button>
                            <button
                                type="button"
                                class="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-destructive"
                                :title="t('ui.delete')"
                                :aria-label="`${t('ui.delete')} ${row.title}`"
                                @click="remove('shared', row)"
                            >
                                <Trash2 class="size-3.5" />
                            </button>
                        </span>
                    </template>
                </DataTable>
            </div>

            <div v-else-if="tab === 'mine'" class="space-y-3">
                <StarterEmptyState
                    v-if="!personal.length"
                    :icon="MessageSquareText"
                    :title="t('settings.starter.replies_title')"
                    :body="t('settings.starter.replies_personal_body')"
                    reload-prop="personal"
                >
                    <button type="button" class="inline-flex h-10 items-center gap-1.5 rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground" @click="openAdd('personal')">
                        <Plus class="size-4" aria-hidden="true" />{{ t('settings.quick_replies.add_personal') }}
                    </button>
                </StarterEmptyState>
                <div v-if="personal.length" class="flex justify-end">
                    <button type="button" class="inline-flex h-8 items-center gap-1.5 rounded-md bg-primary px-3 text-xs font-medium text-primary-foreground" @click="openAdd('personal')">
                        <Plus class="size-3.5" aria-hidden="true" />{{ t('settings.quick_replies.add_personal') }}
                    </button>
                </div>
                <DataTable v-if="personal.length" :columns="personalColumns" :rows="personal" :empty="t('settings.quick_replies.empty')" :caption="t('replies.tab_mine')">
                    <template #cell-shortcut="{ row }"><code class="rounded bg-muted px-1.5 py-0.5" dir="ltr">/{{ row.shortcut }}</code></template>
                    <template #cell-body="{ row }"><span class="line-clamp-2 max-w-md" dir="auto">{{ row.body }}</span></template>
                    <template #cell-category="{ row }">{{ categoryName(row.category_id) }}</template>
                    <template #cell-use_count="{ row }">{{ row.use_count }}</template>
                    <template #cell-platforms="{ row }">
                        <span v-if="!row.platforms?.length" class="text-muted-foreground">{{ t('ui.all_platforms') }}</span>
                        <span v-else class="flex gap-1"><PlatformBadge v-for="p in row.platforms" :key="p" :platform="p" size="xs" /></span>
                    </template>
                    <template #cell-creator="{ row }">{{ row.creator?.name ?? '—' }}</template>
                    <template #cell-actions="{ row }">
                        <span class="inline-flex gap-0.5">
                            <button type="button" class="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground" :title="t('ui.edit')" :aria-label="`${t('ui.edit')} ${row.title}`" @click="openEdit('personal', row)">
                                <Pencil class="size-3.5" />
                            </button>
                            <button
                                type="button"
                                class="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-destructive"
                                :title="t('ui.delete')"
                                :aria-label="`${t('ui.delete')} ${row.title}`"
                                @click="remove('personal', row)"
                            >
                                <Trash2 class="size-3.5" />
                            </button>
                        </span>
                    </template>
                </DataTable>
            </div>

            <div v-else-if="tab === 'categories' && canManageShared">
                <CategoryManager :categories="categories" />
            </div>
        </div>

        <QuickReplyEditor v-model:open="editorOpen" :row="editingRow" :scope="editingScope" :categories="categories" :variables="variables" />
    </AppLayout>
</template>
