<script setup lang="ts">
import ConversationItem from '@/components/crm/ConversationItem.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import { useI18n } from '@/composables/useI18n';
import { useNow } from '@/composables/useNow';
import type { SharedData } from '@/types';
import type { Conversation, InboxFilters } from '@/types/crm';
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
}>();

const emit = defineEmits<{ 'update:filters': [filters: InboxFilters]; select: [id: number]; loadMore: [] }>();

const { t } = useI18n();
const now = useNow();
const page = usePage<SharedData>();

const QUICK_FILTERS = ['waiting', 'needs_human', 'bot', 'mine', 'comment', 'ad', 'low_priority', 'spam'] as const;
const STATUSES = ['open', 'pending', 'resolved'] as const;

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
        <div class="space-y-2 border-b p-3">
            <div class="relative">
                <Search class="pointer-events-none absolute start-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
                <input
                    v-model="search"
                    type="search"
                    :placeholder="t('inbox.search')"
                    :aria-label="t('inbox.search')"
                    class="h-8 w-full rounded-md border border-input bg-background pe-2 ps-8 text-sm placeholder:text-muted-foreground"
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
            </div>
            <div class="scrollbar-thin -mx-3 flex gap-1.5 overflow-x-auto px-3 pb-0.5">
                <button
                    v-for="f in QUICK_FILTERS"
                    :key="f"
                    type="button"
                    :aria-pressed="filters.filter === f"
                    class="shrink-0 rounded-full border px-2.5 py-1 text-xs transition-colors"
                    :class="filters.filter === f ? 'border-primary bg-primary text-primary-foreground' : 'bg-background text-muted-foreground hover:text-foreground'"
                    @click="update({ filter: filters.filter === f ? null : f })"
                >
                    {{ t(`inbox.filters.${f}`) }}
                </button>
            </div>
        </div>

        <p v-if="!live" class="flex items-center gap-1.5 border-b bg-muted/60 px-3 py-1 text-2xs text-muted-foreground">
            <WifiOff class="size-3" aria-hidden="true" />{{ t('alerts.polling') }}
        </p>

        <div ref="listEl" class="scrollbar-thin relative min-h-0 flex-1 overflow-y-auto" @keydown="onKeydown" @scroll.passive="onScroll">
            <div v-if="loading" class="absolute inset-x-0 top-0 h-0.5 animate-pulse bg-primary/60" aria-hidden="true" />
            <EmptyState v-if="!conversations.length && !loading" :icon="Inbox" :title="t('inbox.empty_list')" />
            <ConversationItem
                v-for="c in conversations"
                :key="c.id"
                :conversation="c"
                :active="c.id === selectedId"
                :now="now"
                @select="emit('select', $event)"
            />
            <div v-if="hasMore" class="p-3 text-center">
                <button type="button" class="inline-flex items-center gap-1.5 text-xs text-primary hover:underline" :disabled="loadingMore" @click="emit('loadMore')">
                    <LoaderCircle v-if="loadingMore" class="size-3 animate-spin" aria-hidden="true" />{{ t('inbox.load_more') }}
                </button>
            </div>
        </div>
    </section>
</template>
