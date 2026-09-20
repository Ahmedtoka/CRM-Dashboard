<script setup lang="ts">
import ConversationItem from '@/components/crm/ConversationItem.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import { useI18n } from '@/composables/useI18n';
import { useNow } from '@/composables/useNow';
import type { SharedData } from '@/types';
import type { Conversation, InboxFilters, InboxQuickFilter, Tag } from '@/types/crm';
import { usePage } from '@inertiajs/vue3';
import { Inbox, LoaderCircle, Search, WifiOff } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps<{
    conversations: Conversation[];
    filters: InboxFilters;
    selectedId: number | null;
    loading: boolean;
    loadingMore: boolean;
    hasMore: boolean;
    live: boolean;
    tags: Tag[];
}>();

const emit = defineEmits<{ 'update:filters': [filters: InboxFilters]; select: [id: number]; loadMore: []; tagMenu: [id: number, x: number, y: number] }>();

const { t } = useI18n();
const now = useNow();
const page = usePage<SharedData>();

const QUICK_FILTERS = [
    'waiting', 'needs_human', 'bot', 'mine', 'comment', 'ad', 'low_priority', 'spam',
    'customer_new', 'customer_repeat', 'open_order', 'has_return', 'stuck_order',
    // The team's own runs of a public test link (design 2026-09-21 §4).
    'test',
] as const;
const STATUSES = ['open', 'pending', 'resolved'] as const;
const SKELETON_ROWS = 6;

// Handover queues come first: they are where agents work. The senior queue is supervisor/admin only
// (the server returns no rows for a moderator anyway).
const queueFilters = computed<InboxQuickFilter[]>(() => {
    const role = page.props.auth.user?.role;
    return role === 'supervisor' || role === 'admin' ? ['queue_all', 'queue_high', 'queue_senior'] : ['queue_all', 'queue_high'];
});

const chipClass = (f: InboxQuickFilter) => [
    'inline-flex h-8 shrink-0 items-center gap-1.5 rounded-full px-3 text-xs font-semibold transition-colors',
    props.filters.filter === f ? 'bg-surface-accent text-primary' : 'bg-elevated text-muted-foreground hover:text-foreground',
];

const platformOptions = computed(() => {
    const user = page.props.auth.user;
    const all = page.props.platforms ?? [];
    return user.role === 'moderator' ? all.filter((p) => user.platforms?.includes(p.value)) : all;
});

function update(patch: Partial<InboxFilters>): void {
    emit('update:filters', { ...props.filters, ...patch });
}

const search = ref(props.filters.q ?? '');
let searchTimer: number | undefined;
watch(search, (value) => {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => update({ q: value.trim() || null }), 300);
});
onBeforeUnmount(() => window.clearTimeout(searchTimer));

function selectValue(event: Event): string | null {
    return (event.target as HTMLSelectElement).value || null;
}

const listEl = ref<HTMLElement | null>(null);

// Arrow keys move focus between conversations; Enter/Space opens (native button).
function onKeydown(event: KeyboardEvent): void {
    if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;
    const items = Array.from(listEl.value?.querySelectorAll<HTMLButtonElement>('[data-conversation-id]') ?? []);
    const index = items.indexOf(document.activeElement as HTMLButtonElement);
    const next = items[Math.min(items.length - 1, Math.max(0, index + (event.key === 'ArrowDown' ? 1 : -1)))];
    if (next) {
        event.preventDefault();
        next.focus();
    }
}

function onScroll(event: Event): void {
    const el = event.target as HTMLElement;
    if (props.hasMore && !props.loadingMore && el.scrollTop + el.clientHeight >= el.scrollHeight - 120) emit('loadMore');
}

const selectClass = 'h-8 min-w-0 flex-1 rounded-md border border-input bg-background px-2 text-xs';
</script>

<template>
    <section class="min-h-0 flex-col border-e bg-card" :aria-label="t('inbox.title')">
        <div class="space-y-2 border-b bg-card p-3">
            <h2 class="text-lg font-bold">{{ t('inbox.title') }}</h2>
            <div class="relative">
                <Search class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
                <input
                    v-model="search"
                    type="search"
                    :placeholder="t('inbox.search')"
                    :aria-label="t('inbox.search')"
                    class="h-9 w-full rounded-full border-0 bg-elevated pe-2 ps-9 text-sm placeholder:text-muted-foreground"
                />
            </div>
            <div class="flex gap-2">
                <select :value="filters.platform ?? ''" :class="selectClass" :aria-label="t('inbox.platform_all')" @change="update({ platform: selectValue($event) as InboxFilters['platform'] })">
                    <option value="">{{ t('inbox.platform_all') }}</option>
                    <option v-for="p in platformOptions" :key="p.value" :value="p.value">{{ p.label }}</option>
                </select>
                <select :value="filters.status ?? ''" :class="selectClass" :aria-label="t('inbox.status.all')" @change="update({ status: selectValue($event) as InboxFilters['status'] })">
                    <option value="">{{ t('inbox.status.all') }}</option>
                    <option v-for="s in STATUSES" :key="s" :value="s">{{ t(`inbox.status.${s}`) }}</option>
                </select>
                <select :value="filters.tag ?? ''" :class="selectClass" :aria-label="t('inbox.tag_all')" @change="update({ tag: Number(selectValue($event)) || null })">
                    <option value="">{{ t('inbox.tag_all') }}</option>
                    <option v-for="tag in tags" :key="tag.id" :value="tag.id">{{ tag.name }}</option>
                </select>
            </div>
            <div class="scrollbar-thin -mx-3 flex items-center gap-1.5 overflow-x-auto px-3 pb-0.5">
                <div role="group" :aria-label="t('inbox.queues')" class="flex shrink-0 gap-1.5">
                    <button
                        v-for="f in queueFilters"
                        :key="f"
                        type="button"
                        :aria-pressed="filters.filter === f"
                        :class="chipClass(f)"
                        @click="update({ filter: filters.filter === f ? null : f })"
                    >
                        <span v-if="f === 'queue_high'" class="size-1.5 rounded-full bg-destructive" aria-hidden="true" />
                        {{ t(`inbox.filters.${f}`) }}
                    </button>
                </div>
                <span class="mx-0.5 h-5 w-px shrink-0 bg-border" aria-hidden="true" />
                <button
                    v-for="f in QUICK_FILTERS"
                    :key="f"
                    type="button"
                    :aria-pressed="filters.filter === f"
                    :class="chipClass(f)"
                    @click="update({ filter: filters.filter === f ? null : f })"
                >
                    {{ t(`inbox.filters.${f}`) }}
                </button>
            </div>
        </div>

        <p v-if="!live" class="flex items-center gap-1.5 border-b bg-muted/60 px-3 py-1 text-2xs text-muted-foreground">
            <WifiOff class="size-3" aria-hidden="true" />{{ t('alerts.polling') }}
        </p>

        <div
            ref="listEl"
            data-conversation-list
            class="scrollbar-thin relative min-h-0 flex-1 overflow-y-auto"
            @keydown="onKeydown"
            @scroll.passive="onScroll"
        >
            <div v-if="loading" :aria-busy="true" :aria-label="t('common.loading')">
                <div v-for="n in SKELETON_ROWS" :key="n" class="mx-1.5 my-0.5 flex gap-3 px-3 py-2.5" aria-hidden="true">
                    <div class="size-11 shrink-0 animate-pulse rounded-full bg-elevated" />
                    <div class="min-w-0 flex-1 space-y-2 pt-1">
                        <div class="flex items-center gap-2">
                            <div class="h-3 w-2/5 animate-pulse rounded bg-elevated" />
                            <div class="ms-auto h-2.5 w-8 animate-pulse rounded bg-elevated" />
                        </div>
                        <div class="h-2.5 w-4/5 animate-pulse rounded bg-elevated" />
                        <div class="h-4 w-16 animate-pulse rounded-full bg-elevated" />
                    </div>
                </div>
            </div>
            <EmptyState v-else-if="!conversations.length" :icon="Inbox" :title="t('inbox.empty_list')" />
            <ConversationItem
                v-for="c in loading ? [] : conversations"
                :key="c.id"
                :conversation="c"
                :active="c.id === selectedId"
                :now="now"
                @select="emit('select', $event)"
                @contextmenu="(id, event) => emit('tagMenu', id, event.clientX, event.clientY)"
            />
            <div v-if="hasMore && !loading" class="p-3 text-center">
                <button type="button" class="inline-flex items-center gap-1.5 text-xs text-primary hover:underline" :disabled="loadingMore" @click="emit('loadMore')">
                    <LoaderCircle v-if="loadingMore" class="size-3 animate-spin" aria-hidden="true" />{{ t('inbox.load_more') }}
                </button>
            </div>
        </div>
    </section>
</template>
