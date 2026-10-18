<script setup lang="ts">
import EmptyState from '@/components/crm/EmptyState.vue';
import FilterBar from '@/components/crm/FilterBar.vue';
import IconAction from '@/components/crm/IconAction.vue';
import PlatformBadge from '@/components/crm/PlatformBadge.vue';
import StarterEmptyState from '@/components/crm/StarterEmptyState.vue';
import CategoryManager from '@/components/crm/replies/CategoryManager.vue';
import QuickReplyEditor from '@/components/crm/replies/QuickReplyEditor.vue';
import SettingsPanel from '@/components/crm/settings/SettingsPanel.vue';
import SettingsShell from '@/components/crm/settings/SettingsShell.vue';
import { Button } from '@/components/ui/button';
import { useCrud } from '@/composables/useCrud';
import { useI18n } from '@/composables/useI18n';
import { useSettingsBreadcrumbs } from '@/composables/useSettingsBreadcrumbs';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCount, slashShortcut } from '@/lib/format';
import type { QuickReplyRow, QuickReplyVariable } from '@/types/admin';
import type { QuickReplyCategory } from '@/types/crm';
import { Head } from '@inertiajs/vue3';
import { MessageSquareText, Pencil, Plus, SearchX, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    shared: QuickReplyRow[];
    personal: QuickReplyRow[];
    categories: QuickReplyCategory[];
    canManageShared: boolean;
    variables: QuickReplyVariable[];
    starterExamples: string[];
}>();

const { t, locale } = useI18n();

type TabKey = 'shared' | 'mine' | 'categories';
const tab = ref<TabKey>(props.canManageShared ? 'shared' : 'mine');
const tabs = computed<{ key: TabKey; label: string }[]>(() => [
    { key: 'shared', label: t('replies.tab_shared') },
    { key: 'mine', label: t('replies.tab_mine') },
    ...(props.canManageShared ? [{ key: 'categories' as const, label: t('replies.tab_categories') }] : []),
]);

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

// The one primary action follows the open list: a shared reply on «المشتركة» (when she may add one), her own otherwise.
const addScope = computed<'shared' | 'personal'>(() => (tab.value === 'shared' && props.canManageShared ? 'shared' : 'personal'));
const addLabel = computed(() => (addScope.value === 'shared' ? t('settings.quick_replies.add') : t('settings.quick_replies.add_personal')));

// Search in the browser: both lists arrive whole.
const search = ref('');
/** «أحمد» finds «احمد», «ة» finds «ه» (same folding as the intents search). */
function fold(value: string): string {
    return value.toLowerCase().replace(/[أإآ]/g, 'ا').replace(/ة/g, 'ه').replace(/ى/g, 'ي').trim();
}

function matches(row: QuickReplyRow): boolean {
    const q = fold(search.value);
    if (!q) return true;
    return [row.title, row.shortcut, row.body].some((v) => fold(v ?? '').includes(q));
}

const list = computed(() => {
    if (tab.value === 'categories') return null;
    const scope = tab.value === 'shared' ? 'shared' : 'personal';
    const all = scope === 'shared' ? props.shared : props.personal;

    return { scope, all, rows: all.filter(matches), manageable: scope === 'personal' || props.canManageShared } as const;
});

const title = computed(() => (props.canManageShared ? t('settings_hub.items.quick_replies') : t('settings_hub.items.my_quick_replies')));
const breadcrumbs = useSettingsBreadcrumbs(() => title.value);
</script>

<template>
    <Head :title="title" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <SettingsShell :title="title" :description="t('settings.quick_replies.description')" width="form">
            <template v-if="tab !== 'categories'" #actions>
                <Button @click="openAdd(addScope)"><Plus aria-hidden="true" />{{ addLabel }}</Button>
            </template>

            <div role="tablist" :aria-label="title" class="scrollbar-none -mt-1 flex gap-0.5 overflow-x-auto border-b border-line">
                <button
                    v-for="item in tabs"
                    :key="item.key"
                    type="button"
                    role="tab"
                    :aria-selected="tab === item.key"
                    class="relative inline-flex min-h-10 shrink-0 items-center whitespace-nowrap rounded-t-md px-3 pb-[11px] pt-2.5 text-sm font-semibold transition-colors"
                    :class="
                        tab === item.key
                            ? 'text-brand-ink after:absolute after:inset-x-2 after:-bottom-px after:h-0.5 after:rounded-full after:bg-brand'
                            : 'text-fg-2 hover:bg-surface-2 hover:text-foreground'
                    "
                    @click="tab = item.key"
                >
                    {{ item.label }}
                </button>
            </div>

            <template v-if="list">
                <SettingsPanel v-if="!list.all.length">
                    <StarterEmptyState
                        v-if="list.scope === 'shared'"
                        :icon="MessageSquareText"
                        :title="t('settings.starter.replies_title')"
                        :body="t('settings.starter.replies_body')"
                        :preview="starterExamples"
                        :endpoint="canManageShared ? '/settings/quick-replies/examples' : undefined"
                        reload-prop="shared"
                    >
                        <button
                            v-if="canManageShared"
                            type="button"
                            class="min-h-10 px-2 text-13 font-semibold text-brand-ink hover:underline"
                            @click="openAdd('shared')"
                        >
                            {{ t('settings.starter.or_create') }}
                        </button>
                    </StarterEmptyState>
                    <StarterEmptyState
                        v-else
                        :icon="MessageSquareText"
                        :title="t('settings.starter.replies_title')"
                        :body="t('settings.starter.replies_personal_body')"
                        reload-prop="personal"
                    >
                        <Button @click="openAdd('personal')"><Plus aria-hidden="true" />{{ t('settings.quick_replies.add_personal') }}</Button>
                    </StarterEmptyState>
                </SettingsPanel>

                <template v-else>
                    <FilterBar :search="search" :search-placeholder="t('replies.search')" :chips="[]" @update:search="search = $event" />

                    <SettingsPanel flush :data-reply-list="list.scope">
                        <EmptyState v-if="!list.rows.length" :icon="SearchX" :title="t('replies.empty')" />
                        <ul v-else class="divide-y divide-line">
                            <li v-for="row in list.rows" :key="row.id" class="space-y-1.5 px-4 py-3.5" :data-reply="row.id">
                                <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                                    <span class="min-w-0 font-semibold" dir="auto">{{ row.title }}</span>
                                    <kbd
                                        class="rounded-sm border border-line-strong bg-surface-2 px-1.5 py-0.5 font-mono text-xs text-fg-2"
                                        dir="ltr"
                                        >{{ slashShortcut(row.shortcut) }}</kbd
                                    >
                                    <span v-if="list.manageable" class="ms-auto inline-flex gap-0.5">
                                        <IconAction
                                            :icon="Pencil"
                                            size="sm"
                                            :label="`${t('ui.edit')} ${row.title}`"
                                            @click="openEdit(list.scope, row)"
                                        />
                                        <IconAction
                                            :icon="Trash2"
                                            size="sm"
                                            variant="destructive"
                                            :label="`${t('ui.delete')} ${row.title}`"
                                            @click="remove(list.scope, row)"
                                        />
                                    </span>
                                </div>
                                <p class="line-clamp-2 whitespace-pre-line text-13 text-fg-2" dir="auto">{{ row.body }}</p>
                                <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-fg-3">
                                    <span>{{ categoryName(row.category_id) }}</span>
                                    <span aria-hidden="true">·</span>
                                    <span v-if="!row.platforms?.length">{{ t('ui.all_platforms') }}</span>
                                    <span v-else class="inline-flex gap-1"
                                        ><PlatformBadge v-for="p in row.platforms" :key="p" :platform="p" size="xs"
                                    /></span>
                                    <span aria-hidden="true">·</span>
                                    <span class="tabular-nums">{{ t('replies.uses', { n: formatCount(row.use_count, locale) }) }}</span>
                                    <template v-if="row.creator && list.scope === 'shared'">
                                        <span aria-hidden="true">·</span>
                                        <span>{{ t('settings.quick_replies.creator') }} {{ row.creator.name }}</span>
                                    </template>
                                </p>
                            </li>
                        </ul>
                    </SettingsPanel>
                </template>
            </template>

            <CategoryManager v-else-if="canManageShared" :categories="categories" />
        </SettingsShell>

        <QuickReplyEditor v-model:open="editorOpen" :row="editingRow" :scope="editingScope" :categories="categories" :variables="variables" />
    </AppLayout>
</template>
