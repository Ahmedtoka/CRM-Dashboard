<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import type { QuickReply, QuickReplyCategory } from '@/types/crm';
import { Search, X } from 'lucide-vue-next';
import { computed, nextTick, onMounted, ref, watch } from 'vue';

const props = defineProps<{ items: QuickReply[]; categories: QuickReplyCategory[]; query: string; activeIndex: number; searchable?: boolean }>();
const emit = defineEmits<{ pick: [reply: QuickReply]; hover: [index: number]; close: []; 'update:query': [q: string] }>();

const { t } = useI18n();
const root = ref<HTMLElement | null>(null);
const searchInput = ref<HTMLInputElement | null>(null);

type Tab = 'all' | 'shared' | 'mine';
const tabs: Tab[] = ['all', 'shared', 'mine'];
const tab = ref<Tab>('all');

const normalizedQuery = computed(() => props.query.trim().toLowerCase());

// Case-insensitive match on shortcut, title or body, scoped to the active tab.
const filtered = computed(() =>
    props.items.filter((r) => {
        if (tab.value === 'shared' && r.scope !== 'shared') return false;
        if (tab.value === 'mine' && r.scope !== 'personal') return false;
        if (!normalizedQuery.value) return true;

        return (
            r.shortcut.toLowerCase().includes(normalizedQuery.value) ||
            r.title.toLowerCase().includes(normalizedQuery.value) ||
            r.body.toLowerCase().includes(normalizedQuery.value)
        );
    }),
);

interface ReplyGroup {
    key: string;
    name: string;
    items: QuickReply[];
}

// Grouped by category, in the category's own sort order; uncategorised last.
const replyGroups = computed<ReplyGroup[]>(() => {
    const byCategory = new Map<number | null, QuickReply[]>();
    for (const reply of filtered.value) {
        const key = reply.category?.id ?? null;
        if (!byCategory.has(key)) byCategory.set(key, []);
        byCategory.get(key)!.push(reply);
    }

    const groups: ReplyGroup[] = [];
    for (const category of props.categories) {
        const items = byCategory.get(category.id);
        if (items?.length) groups.push({ key: `c${category.id}`, name: category.name, items });
    }
    const uncategorized = byCategory.get(null);
    if (uncategorized?.length) groups.push({ key: 'uncategorized', name: t('replies.uncategorized'), items: uncategorized });

    return groups;
});

// The flattened, on-screen display order — Composer navigates this array with
// `activeIndex`, so arrow-key order must match exactly what's rendered below.
const visible = computed<QuickReply[]>(() => replyGroups.value.flatMap((g) => g.items));

interface Row {
    reply: QuickReply;
    index: number;
}

interface Group {
    key: string;
    name: string;
    rows: Row[];
}

// Same grouping as `replyGroups`, but each row also carries its position in
// the flattened `visible` array (a running cursor across groups) so the
// highlighted row always matches `activeIndex`.
const grouped = computed<Group[]>(() => {
    let cursor = 0;

    return replyGroups.value.map((g) => ({
        key: g.key,
        name: g.name,
        rows: g.items.map((reply) => ({ reply, index: cursor++ })),
    }));
});

const active = computed<QuickReply | null>(() => visible.value[props.activeIndex] ?? null);

interface Segment {
    text: string;
    variable: boolean;
}

// Highlights `{variable}` tokens in the preview pane.
const previewSegments = computed<Segment[]>(() => {
    const body = active.value?.body ?? '';
    const segments: Segment[] = [];
    const re = /\{[^{}\s]+\}/gu;
    let last = 0;
    let match: RegExpExecArray | null;
    while ((match = re.exec(body))) {
        if (match.index > last) segments.push({ text: body.slice(last, match.index), variable: false });
        segments.push({ text: match[0], variable: true });
        last = match.index + match[0].length;
    }
    if (last < body.length) segments.push({ text: body.slice(last), variable: false });

    return segments;
});

watch(
    () => props.activeIndex,
    (index) => nextTick(() => root.value?.querySelector<HTMLElement>(`[data-index="${index}"]`)?.scrollIntoView({ block: 'nearest' })),
);

// Switching tabs, or editing the search text, re-scopes the list — the
// highlighted row resets to the first match either way.
watch(tab, () => emit('hover', 0));
watch(() => props.query, () => emit('hover', 0));

onMounted(() => {
    if (props.searchable) nextTick(() => searchInput.value?.focus());
});

// The search box (button-triggered mode) doesn't share the textarea Composer
// binds its own keydown handler to, so it needs its own arrow/Enter/Escape
// handling — mirrors Composer's onKeydown for the slash-triggered case.
function onSearchKeydown(event: KeyboardEvent): void {
    if (event.isComposing) return;
    const count = visible.value.length;

    if (event.key === 'Escape') {
        event.preventDefault();
        emit('close');
        return;
    }
    if (count && (event.key === 'ArrowDown' || event.key === 'ArrowUp')) {
        event.preventDefault();
        emit('hover', (props.activeIndex + (event.key === 'ArrowDown' ? 1 : count - 1)) % count);
        return;
    }
    if (count && event.key === 'Enter') {
        event.preventDefault();
        emit('pick', visible.value[props.activeIndex]);
    }
}

defineExpose({ visible });
</script>

<template>
    <div
        ref="root"
        class="absolute inset-x-3 bottom-full z-20 mb-1 grid max-h-80 grid-cols-1 overflow-hidden rounded-lg border bg-popover shadow-lg sm:grid-cols-[minmax(0,1fr)_14rem]"
    >
        <div class="flex min-h-0 flex-col">
            <div class="flex items-center gap-1 border-b px-2 py-1.5">
                <div role="tablist" class="flex flex-1 gap-1">
                    <button
                        v-for="option in tabs"
                        :key="option"
                        type="button"
                        role="tab"
                        :aria-selected="tab === option"
                        class="rounded px-2 py-1 text-2xs font-medium"
                        :class="tab === option ? 'bg-accent text-accent-foreground' : 'text-muted-foreground hover:bg-muted'"
                        @click="tab = option"
                    >
                        {{ t(`replies.tab_${option}`) }}
                    </button>
                </div>
                <button v-if="searchable" type="button" class="rounded p-1 text-muted-foreground hover:bg-muted" :aria-label="t('common.close')" @click="emit('close')">
                    <X class="size-3.5" />
                </button>
            </div>

            <div v-if="searchable" class="flex items-center gap-1.5 border-b px-2 py-1.5">
                <Search class="size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
                <input
                    ref="searchInput"
                    :value="query"
                    type="text"
                    dir="auto"
                    :placeholder="t('replies.search')"
                    :aria-label="t('replies.search')"
                    aria-controls="quick-reply-menu"
                    class="w-full bg-transparent text-xs outline-none placeholder:text-muted-foreground"
                    @input="emit('update:query', ($event.target as HTMLInputElement).value)"
                    @keydown="onSearchKeydown"
                />
            </div>

            <div id="quick-reply-menu" class="scrollbar-thin min-h-0 flex-1 overflow-y-auto p-1" role="listbox" :aria-label="t('replies.picker_button')">
                <p v-if="!visible.length" class="px-2 py-3 text-center text-xs text-muted-foreground">{{ t('replies.empty') }}</p>
                <template v-for="group in grouped" :key="group.key">
                    <p class="px-2 pb-0.5 pt-1.5 text-2xs font-medium text-muted-foreground">{{ group.name }}</p>
                    <button
                        v-for="row in group.rows"
                        :key="row.reply.id"
                        :data-index="row.index"
                        type="button"
                        role="option"
                        tabindex="-1"
                        :aria-selected="row.index === activeIndex"
                        class="flex w-full flex-col items-start gap-0.5 rounded-md px-2 py-1.5 text-start"
                        :class="row.index === activeIndex ? 'bg-accent text-accent-foreground' : 'hover:bg-muted'"
                        @mousedown.prevent="emit('pick', row.reply)"
                        @mousemove="emit('hover', row.index)"
                    >
                        <span class="flex items-center gap-2 text-xs">
                            <kbd class="rounded bg-muted px-1 font-mono text-2xs text-muted-foreground" dir="ltr">/{{ row.reply.shortcut }}</kbd>
                            <span class="font-medium">{{ row.reply.title }}</span>
                            <span v-if="row.reply.scope === 'personal'" class="rounded bg-muted px-1 text-2xs text-muted-foreground">{{ t('replies.personal_badge') }}</span>
                        </span>
                        <span class="line-clamp-1 text-2xs text-muted-foreground" dir="auto">{{ row.reply.body }}</span>
                    </button>
                </template>
            </div>
        </div>

        <div v-if="active" class="hidden min-h-0 flex-col gap-2 border-s p-2.5 sm:flex">
            <p class="text-2xs font-medium text-muted-foreground">{{ t('replies.preview') }}</p>
            <p class="scrollbar-thin max-h-40 overflow-y-auto text-xs leading-5" dir="auto">
                <template v-for="(segment, i) in previewSegments" :key="i"
                    ><span v-if="segment.variable" class="rounded bg-amber-100 px-1 text-amber-900">{{ segment.text }}</span
                    ><template v-else>{{ segment.text }}</template></template
                >
            </p>
            <div v-if="active.attachments.length" class="flex flex-wrap gap-1.5">
                <template v-for="a in active.attachments" :key="a.id">
                    <img v-if="a.thumb_url" :src="a.thumb_url" class="size-10 rounded object-cover" alt="" />
                    <span v-else class="flex size-10 items-center justify-center rounded bg-muted text-sm" :title="a.original_name ?? ''">📎</span>
                </template>
            </div>
        </div>
    </div>
</template>
