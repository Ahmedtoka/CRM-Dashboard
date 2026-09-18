<script setup lang="ts">
import PlatformBadge from '@/components/crm/PlatformBadge.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { useApi } from '@/composables/useApi';
import { useCommandPalette } from '@/composables/useCommandPalette';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import { highlightParts } from '@/lib/highlight';
import { orderStatusTone } from '@/lib/orderStatus';
import type { PlatformValue } from '@/types/crm';
import { router } from '@inertiajs/vue3';
import { Search } from 'lucide-vue-next';
import { computed, nextTick, ref, watch } from 'vue';

interface SearchItem {
    id: number;
    title: string;
    subtitle: string;
    href: string | null;
}

interface ConversationSearchItem extends SearchItem {
    platform: PlatformValue;
    at: string | null;
}

interface OrderSearchItem extends SearchItem {
    status: string;
}

type GroupKey = 'customers' | 'conversations' | 'orders' | 'products';

interface SearchResults {
    customers: SearchItem[];
    conversations: ConversationSearchItem[];
    orders: OrderSearchItem[];
    products: SearchItem[];
}

const { open, hide } = useCommandPalette();
const { t } = useI18n();
const api = useApi();
const toast = useToast();

const inputEl = ref<HTMLInputElement>();
const query = ref('');
const trimmed = computed(() => query.value.trim());
const results = ref<SearchResults | null>(null);
const loading = ref(false);
const errored = ref(false);
const activeIndex = ref(0);

let timer: number | undefined;
let seq = 0;
/** The in-flight `/search` request, aborted as soon as it can no longer be shown (final fix wave I3). */
let inFlight: AbortController | null = null;

/** Arabic terms take the slower, unindexed LIKE path server-side — debounce them longer. */
const ARABIC = /[؀-ۿ]/;
const DEBOUNCE_MS = 250;
const DEBOUNCE_ARABIC_MS = 400;

function abortInFlight(): void {
    inFlight?.abort();
    inFlight = null;
}

const GROUPS: Array<{ key: GroupKey; labelKey: string }> = [
    { key: 'customers', labelKey: 'search.group_customers' },
    { key: 'conversations', labelKey: 'search.group_conversations' },
    { key: 'orders', labelKey: 'search.group_orders' },
    { key: 'products', labelKey: 'search.group_products' },
];

const groups = computed(() => GROUPS.map((g) => ({ ...g, items: results.value?.[g.key] ?? [] })));
const flatItems = computed(() => groups.value.flatMap((g) => g.items.map((item) => ({ group: g.key, item }))));

watch(flatItems, () => (activeIndex.value = 0));

async function runSearch(term: string): Promise<void> {
    // `seq` was already bumped for this term by the `query` watcher below, so
    // it's captured here (not incremented) — any request whose term is no
    // longer current when the response lands is discarded.
    const current = seq;
    abortInFlight();
    const controller = new AbortController();
    inFlight = controller;
    loading.value = true;
    errored.value = false;
    try {
        const { data } = await api.get<SearchResults>('/search', { params: { q: term }, signal: controller.signal });
        if (current === seq) results.value = data;
    } catch {
        // An aborted request always coincides with a `seq` bump (newer query or
        // palette closed), so this guard also keeps a cancellation from showing as an error.
        if (current === seq) {
            errored.value = true;
            results.value = null;
        }
    } finally {
        if (inFlight === controller) inFlight = null;
        if (current === seq) loading.value = false;
    }
}

watch(query, (value) => {
    window.clearTimeout(timer);
    // Invalidate the previous query's results the instant the input changes —
    // not just when the debounced request resolves — so a fast Enter during
    // the debounce (or while a request is still in flight) can never open a
    // result that belonged to an earlier, now-stale query. The stale request
    // itself is cancelled too, so fast typing never stacks server-side scans.
    seq++;
    abortInFlight();
    results.value = null;
    errored.value = false;
    const term = value.trim();
    if (term.length < 2) {
        loading.value = false;
        return;
    }
    loading.value = true;
    timer = window.setTimeout(() => void runSearch(term), ARABIC.test(term) ? DEBOUNCE_ARABIC_MS : DEBOUNCE_MS);
});

watch(open, (isOpen) => {
    if (isOpen) {
        void nextTick(() => inputEl.value?.focus());
        return;
    }
    window.clearTimeout(timer);
    seq++;
    abortInFlight();
    query.value = '';
    results.value = null;
    loading.value = false;
    errored.value = false;
    activeIndex.value = 0;
});

function move(delta: number): void {
    const n = flatItems.value.length;
    if (!n) return;
    activeIndex.value = (activeIndex.value + delta + n) % n;
}

function isActive(group: GroupKey, id: number): boolean {
    const entry = flatItems.value[activeIndex.value];
    return !!entry && entry.group === group && entry.item.id === id;
}

function setActive(group: GroupKey, id: number): void {
    const idx = flatItems.value.findIndex((e) => e.group === group && e.item.id === id);
    if (idx !== -1) activeIndex.value = idx;
}

async function copyProductName(title: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(title);
    } catch {
        // Clipboard permission denied/unavailable: still confirm via toast, nothing else to fall back to.
    }
    toast.push(t('search.copied'));
}

function activate(group: GroupKey, item: SearchItem): void {
    if (group === 'products') {
        void copyProductName(item.title);
    } else if (item.href) {
        router.visit(item.href);
    }
    hide();
}

function openActive(): void {
    const entry = flatItems.value[activeIndex.value];
    if (entry) activate(entry.group, entry.item);
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="gap-0 p-0 sm:max-w-xl">
            <DialogTitle class="sr-only">{{ t('search.placeholder') }}</DialogTitle>

            <div class="flex items-center gap-2 border-b border-border px-4 py-3">
                <Search class="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                <input
                    ref="inputEl"
                    v-model="query"
                    type="text"
                    dir="auto"
                    :placeholder="t('search.placeholder')"
                    :aria-label="t('search.placeholder')"
                    class="h-8 w-full border-0 bg-transparent text-sm text-foreground outline-none placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-0"
                    @keydown.down.prevent="move(1)"
                    @keydown.up.prevent="move(-1)"
                    @keydown.enter.prevent="openActive"
                />
            </div>

            <div class="scrollbar-thin max-h-[60vh] overflow-y-auto p-2">
                <p v-if="trimmed.length > 0 && trimmed.length < 2" class="px-2 py-6 text-center text-xs text-muted-foreground">
                    {{ t('search.min_chars') }}
                </p>
                <p v-else-if="loading" class="px-2 py-6 text-center text-xs text-muted-foreground">{{ t('common.loading') }}</p>
                <p v-else-if="errored" class="px-2 py-6 text-center text-xs text-destructive">{{ t('common.error') }}</p>
                <p v-else-if="trimmed.length >= 2 && flatItems.length === 0" class="px-2 py-6 text-center text-xs text-muted-foreground">
                    {{ t('search.empty') }}
                </p>
                <template v-else>
                    <section v-for="group in groups" v-show="group.items.length" :key="group.key">
                        <h3 class="px-2 pb-1 pt-2 text-2xs font-semibold uppercase tracking-wide text-muted-foreground">{{ t(group.labelKey) }}</h3>
                        <ul>
                            <li v-for="item in group.items" :key="`${group.key}-${item.id}`">
                                <button
                                    type="button"
                                    class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-start text-sm"
                                    :class="isActive(group.key, item.id) ? 'bg-muted' : 'hover:bg-muted'"
                                    @mousemove="setActive(group.key, item.id)"
                                    @click="activate(group.key, item)"
                                >
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate font-medium">
                                            <template v-for="(part, i) in highlightParts(item.title, query)" :key="i">
                                                <mark v-if="part.match" class="rounded bg-warning/40 text-foreground">{{ part.text }}</mark>
                                                <template v-else>{{ part.text }}</template>
                                            </template>
                                        </span>
                                        <span v-if="item.subtitle" class="block truncate text-2xs text-muted-foreground">
                                            <template v-for="(part, i) in highlightParts(item.subtitle, query)" :key="i">
                                                <mark v-if="part.match" class="rounded bg-warning/40 text-foreground">{{ part.text }}</mark>
                                                <template v-else>{{ part.text }}</template>
                                            </template>
                                        </span>
                                    </span>
                                    <PlatformBadge v-if="group.key === 'conversations'" :platform="(item as ConversationSearchItem).platform" size="xs" />
                                    <StatusChip
                                        v-if="group.key === 'orders'"
                                        :label="t(`orders.statuses.${(item as OrderSearchItem).status}`)"
                                        :tone="orderStatusTone[(item as OrderSearchItem).status]"
                                    />
                                </button>
                            </li>
                        </ul>
                    </section>
                </template>
            </div>
        </DialogContent>
    </Dialog>
</template>
