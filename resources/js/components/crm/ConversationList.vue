<script setup lang="ts">
import ConversationItem from '@/components/crm/ConversationItem.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import FilterBar from '@/components/crm/FilterBar.vue';
import InboxFilterPanel from '@/components/crm/InboxFilterPanel.vue';
import { useI18n } from '@/composables/useI18n';
import { conversationState } from '@/lib/conversationState';
import { formatCount } from '@/lib/format';
import type { SharedData } from '@/types';
import type { Conversation, InboxCounts, InboxFilters, InboxModerator, InboxQuickFilter, Tag } from '@/types/crm';
import { usePage } from '@inertiajs/vue3';
import { useVirtualizer } from '@tanstack/vue-virtual';
import { Inbox, LoaderCircle, SearchX, Wifi, WifiOff } from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        conversations: Conversation[];
        filters: InboxFilters;
        counts: InboxCounts | null;
        selectedId: number | null;
        moderators: InboxModerator[];
        tags: Tag[];
        loading?: boolean;
        loadingMore?: boolean;
        hasMore?: boolean;
        live?: boolean;
        pollFailed?: boolean;
        queueEnabled?: boolean;
        /** Any filter is active (the empty state then offers «مسح الفلاتر»). */
        filtered?: boolean;
    }>(),
    { loading: false, loadingMore: false, hasMore: false, live: false, pollFailed: false, queueEnabled: false, filtered: false },
);

const emit = defineEmits<{
    update: [patch: Partial<InboxFilters>];
    clear: [];
    refresh: [];
    select: [id: number];
    loadMore: [];
    tagMenu: [id: number, x: number, y: number];
}>();

const { t, locale } = useI18n();
const page = usePage<SharedData>();

const ROW = 72;
const SKELETON_ROWS = 10;
type TabValue = 'waiting' | 'with_moderator' | 'bot' | 'closed';
const TABS: (TabValue | null)[] = [null, 'waiting', 'with_moderator', 'bot', 'closed'];

const isSupervisor = computed(() => ['supervisor', 'admin'].includes(page.props.auth.user?.role ?? ''));

// The «كمان» flags (spec §1.2 More): several at once, joined with AND (R4). The senior queue is supervisor+.
const flagOptions = computed<InboxQuickFilter[]>(() => [
    'needs_human',
    'mine',
    'comment',
    'ad',
    'customer_new',
    'customer_repeat',
    'open_order',
    'has_return',
    'stuck_order',
    'queue_high',
    ...(isSupervisor.value ? (['queue_senior'] as const) : []),
    'spam',
    'low_priority',
    'test',
]);

const platformOptions = computed(() => {
    const user = page.props.auth.user;
    const all = page.props.platforms ?? [];
    return user.role === 'moderator' ? all.filter((p) => user.platforms?.includes(p.value)) : all;
});

const names = computed(() => new Map(props.moderators.map((m) => [m.id, m.name])));

// ---- counts on the status tabs ----
const countLabel = (n: number | undefined): string => {
    if (n === undefined || !props.counts) return '';
    return n > props.counts.capped_at ? `${formatCount(props.counts.capped_at, locale.value)}+` : formatCount(n, locale.value);
};
const tabCount = (tab: TabValue | null) => (tab ? countLabel(props.counts?.status[tab]) : '');
const tabActive = (tab: TabValue | null) => (props.filters.status ?? null) === tab || (tab === 'closed' && props.filters.status === 'resolved');

const tabList = ref<HTMLElement | null>(null);
function onTabKeydown(event: KeyboardEvent): void {
    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
    const tabs = Array.from(tabList.value?.querySelectorAll<HTMLButtonElement>('[role="tab"]') ?? []);
    const index = tabs.indexOf(document.activeElement as HTMLButtonElement);
    const rtl = getComputedStyle(tabList.value!).direction === 'rtl';
    const forward = event.key === (rtl ? 'ArrowLeft' : 'ArrowRight');
    const next =
        event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (index + (forward ? 1 : -1) + tabs.length) % tabs.length;
    event.preventDefault();
    tabs[next]?.focus();
}

// ---- active filters as chips ----
const LEGACY_STATUS = ['open', 'pending'] as const;
const chips = computed(() => {
    const f = props.filters;
    const out: { key: string; label: string }[] = [];
    if (f.status && (LEGACY_STATUS as readonly string[]).includes(f.status)) out.push({ key: 'status', label: t(`inbox.status.${f.status}`) });
    if (f.queue) out.push({ key: 'queue', label: t(`inbox.queue_state.${f.queue}`) });
    if (f.assignee) {
        const label =
            f.assignee === 'me' ? t('inbox.assignee.me') : f.assignee === 'none' ? t('inbox.assignee.none') : (names.value.get(Number(f.assignee)) ?? `#${f.assignee}`);
        out.push({ key: 'assignee', label });
    }
    if (f.platform) out.push({ key: 'platform', label: platformOptions.value.find((p) => p.value === f.platform)?.label ?? f.platform });
    if (f.tag) out.push({ key: 'tag', label: props.tags.find((tag) => tag.id === f.tag)?.name ?? `#${f.tag}` });
    for (const flag of f.flags) out.push({ key: `flag:${flag}`, label: t(`inbox.filters.${flag}`) });
    return out;
});
const moreCount = computed(() => [props.filters.queue, props.filters.assignee, props.filters.platform, props.filters.tag].filter(Boolean).length + props.filters.flags.length);

function removeChip(key: string): void {
    if (key.startsWith('flag:')) {
        const flag = key.slice(5);
        emit('update', { flags: props.filters.flags.filter((f) => f !== flag) });
        return;
    }
    emit('update', { [key]: null } as Partial<InboxFilters>);
}

// ---- virtualised rows ----
const scrollEl = ref<HTMLElement | null>(null);
// One extra row under the last conversation while more pages exist: the spinner / «تحميل المزيد».
const rowCount = computed(() => props.conversations.length + (props.hasMore ? 1 : 0));
const virtualizer = useVirtualizer(
    computed(() => ({
        count: rowCount.value,
        getScrollElement: () => scrollEl.value,
        estimateSize: () => ROW,
        overscan: 8,
        getItemKey: (index: number) => props.conversations[index]?.id ?? 'more',
    })),
);
const rows = computed(() => virtualizer.value.getVirtualItems());
const totalSize = computed(() => virtualizer.value.getTotalSize());

const states = computed(() => {
    const map = new Map<number, ReturnType<typeof conversationState>>();
    for (const item of rows.value) {
        const c = props.conversations[item.index];
        if (c) map.set(c.id, conversationState(c, t, names.value));
    }
    return map;
});

// Infinite load: the last rendered row is within 10 of the end.
watch(
    () => [rows.value.at(-1)?.index ?? -1, props.conversations.length, props.hasMore, props.loadingMore, props.loading] as const,
    ([last, length, hasMore, loadingMore, loading]) => {
        if (hasMore && !loadingMore && !loading && last >= length - 10) emit('loadMore');
    },
);

// A new filter set starts at the top.
watch(
    () => props.loading,
    (now) => {
        if (now && scrollEl.value) scrollEl.value.scrollTop = 0;
    },
);

// For tools/perf/browser-bench.mjs: how many rows are loaded (not mounted).
watch(
    () => props.conversations.length,
    (n) => ((window as unknown as { __inboxLoadedRows?: number }).__inboxLoadedRows = n),
    { immediate: true },
);
onBeforeUnmount(() => delete (window as unknown as { __inboxLoadedRows?: number }).__inboxLoadedRows);

function scrollToId(id: number): void {
    const index = props.conversations.findIndex((c) => c.id === id);
    if (index !== -1) virtualizer.value.scrollToIndex(index, { align: 'auto' });
}

function focusRow(id: number): void {
    scrollToId(id);
    void nextTick(() => requestAnimationFrame(() => scrollEl.value?.querySelector<HTMLButtonElement>(`[data-conversation-id="${id}"]`)?.focus()));
}

// Arrow keys move focus between conversations (rows off screen are scrolled in first); Enter/Space opens.
function onKeydown(event: KeyboardEvent): void {
    if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;
    const current = (document.activeElement as HTMLElement | null)?.closest<HTMLElement>('[data-conversation-id]');
    if (!current) return;
    const index = props.conversations.findIndex((c) => c.id === Number(current.dataset.conversationId));
    const next = props.conversations[Math.min(props.conversations.length - 1, Math.max(0, index + (event.key === 'ArrowDown' ? 1 : -1)))];
    if (next) {
        event.preventDefault();
        focusRow(next.id);
    }
}

// ---- search and the popover, reachable from the page's `/` and `f` shortcuts ----
const bar = ref<InstanceType<typeof FilterBar> | null>(null);
const filtersOpen = ref(false);

function onSearch(value: string): void {
    const q = value || null;
    if (q !== (props.filters.q ?? null)) emit('update', { q });
}

defineExpose({
    scrollToId,
    focusSearch: () => bar.value?.focusSearch(),
    openFilters: () => (filtersOpen.value = true),
});
</script>

<template>
    <section class="min-h-0 min-w-0 flex-col border-e bg-card" :aria-label="t('inbox.list_title')">
        <div class="space-y-2 border-b bg-card px-3 pb-2 pt-3">
            <div class="flex items-center gap-2">
                <h2 class="text-base font-bold">{{ t('inbox.list_title') }}</h2>
                <button
                    type="button"
                    class="ms-auto inline-flex size-7 items-center justify-center rounded-full hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    :class="live ? 'text-muted-foreground/70' : pollFailed ? 'text-amber-600 dark:text-amber-400' : 'text-muted-foreground'"
                    :title="live ? t('inbox.live') : pollFailed ? t('inbox.poll_failed') : t('alerts.polling')"
                    :aria-label="live ? t('inbox.live') : pollFailed ? t('inbox.poll_failed') : t('alerts.polling')"
                    @click="emit('refresh')"
                >
                    <Wifi v-if="live" class="size-4" aria-hidden="true" />
                    <WifiOff v-else class="size-4" aria-hidden="true" />
                </button>
            </div>

            <FilterBar
                ref="bar"
                v-model:open="filtersOpen"
                :search="filters.q ?? ''"
                :search-placeholder="t('inbox.search')"
                :chips="chips"
                :more-count="moreCount"
                @update:search="onSearch"
                @remove="removeChip"
                @clear="emit('clear')"
            >
                <template #more>
                    <InboxFilterPanel
                        :filters="filters"
                        :counts="counts"
                        :queue-enabled="queueEnabled"
                        :moderators="moderators"
                        :tags="tags"
                        :platforms="platformOptions"
                        :flag-options="flagOptions"
                        @update="emit('update', $event)"
                    />
                </template>
                <template #tabs>
                    <div
                        ref="tabList"
                        role="tablist"
                        :aria-label="t('inbox.status_label')"
                        class="scrollbar-none -mx-3 flex gap-0.5 overflow-x-auto px-3"
                        @keydown="onTabKeydown"
                    >
                        <button
                            v-for="tab in TABS"
                            :key="tab ?? 'all'"
                            type="button"
                            role="tab"
                            :aria-selected="tabActive(tab)"
                            :tabindex="tabActive(tab) ? 0 : -1"
                            class="inline-flex h-8 shrink-0 items-center gap-1 rounded-full px-2 text-xs transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            :class="tabActive(tab) ? 'bg-surface-accent font-semibold text-primary' : 'text-muted-foreground hover:bg-muted hover:text-foreground'"
                            @click="emit('update', { status: tab })"
                        >
                            {{ t(`inbox.tabs.${tab ?? 'all'}`) }}
                            <span v-if="tabCount(tab)" class="tabular-nums" :class="tabActive(tab) ? 'text-primary/80' : 'text-muted-foreground/80'">{{
                                tabCount(tab)
                            }}</span>
                        </button>
                    </div>
                </template>
            </FilterBar>
        </div>

        <div ref="scrollEl" data-conversation-list class="scrollbar-thin relative min-h-0 flex-1 overflow-y-auto overscroll-contain" @keydown="onKeydown">
            <div v-if="loading" :aria-busy="true" :aria-label="t('common.loading')">
                <div v-for="n in SKELETON_ROWS" :key="n" class="flex h-[72px] items-center gap-3 px-3" aria-hidden="true">
                    <div class="size-10 shrink-0 animate-pulse rounded-full bg-elevated" />
                    <div class="min-w-0 flex-1 space-y-2.5">
                        <div class="flex items-center gap-2">
                            <div class="h-3 w-2/5 animate-pulse rounded bg-elevated" />
                            <div class="ms-auto h-2.5 w-8 animate-pulse rounded bg-elevated" />
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="h-4 w-14 animate-pulse rounded-full bg-elevated" />
                            <div class="h-2.5 flex-1 animate-pulse rounded bg-elevated" />
                        </div>
                    </div>
                </div>
            </div>

            <EmptyState v-else-if="!conversations.length && filtered" :icon="SearchX" :title="t('inbox.empty_filtered')">
                <template #action>
                    <button
                        type="button"
                        class="inline-flex h-8 items-center rounded-full border border-input px-3 text-xs font-medium hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        @click="emit('clear')"
                    >
                        {{ t('inbox.clear_filters') }}
                    </button>
                </template>
            </EmptyState>
            <EmptyState v-else-if="!conversations.length" :icon="Inbox" :title="t('inbox.empty_list')" />

            <div v-else role="list" class="relative w-full" :style="{ height: `${totalSize}px` }">
                <div
                    v-for="item in rows"
                    :key="String(item.key)"
                    role="listitem"
                    class="absolute start-0 top-0 w-full"
                    :style="{ height: `${ROW}px`, transform: `translateY(${item.start}px)` }"
                >
                    <ConversationItem
                        v-if="conversations[item.index]"
                        :conversation="conversations[item.index]"
                        :state="states.get(conversations[item.index].id) ?? null"
                        :active="conversations[item.index].id === selectedId"
                        @select="emit('select', $event)"
                        @contextmenu="(id, event) => emit('tagMenu', id, event.clientX, event.clientY)"
                    />
                    <div v-else class="flex h-[72px] items-center justify-center">
                        <LoaderCircle v-if="loadingMore" class="size-4 animate-spin text-muted-foreground" :aria-label="t('common.loading')" />
                        <button v-else type="button" class="text-xs text-primary hover:underline" @click="emit('loadMore')">{{ t('inbox.load_more') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>
