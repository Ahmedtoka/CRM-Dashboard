<script setup lang="ts">
import PageHeader from '@/components/crm/PageHeader.vue';
import ToggleSwitch from '@/components/crm/ToggleSwitch.vue';
import { buttonVariants } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import AppLayout from '@/layouts/AppLayout.vue';
import { cn } from '@/lib/utils';
import type { BotFlowOption, BotIntentRoute, BotIntentRow, BotScriptOption } from '@/types/admin';
import { Head, Link, router } from '@inertiajs/vue3';
import { ChevronDown, FileText, LoaderCircle, Pencil, RotateCw, Search } from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps<{ intents: BotIntentRow[]; scripts: BotScriptOption[]; detailTokens: string[]; flows: BotFlowOption[] }>();

const { t, locale } = useI18n();
const api = useApi();
const toast = useToast();

const ROUTES: BotIntentRoute[] = ['answer', 'lookup', 'collect_then_handover', 'handover'];
const PRIORITIES = ['low', 'medium', 'high'] as const;
const SCRIPT_PREFIX = 'script.';
const SKELETON_CARDS = 6;

type Draft = Pick<BotIntentRow, 'route' | 'flow_key' | 'priority' | 'queue' | 'script_keys' | 'is_active'>;

const rows = ref<BotIntentRow[]>(props.intents.map((i) => ({ ...i })));
const drafts = reactive<Record<number, Draft>>({});
const saving = ref<number[]>([]);
const reloading = ref(false);
const query = ref('');
/** Group chip filter ('' = all groups) and the one card whose editor is open. */
const groupFilter = ref('');
const openId = ref<number | null>(null);

function draftOf(row: BotIntentRow): Draft {
    return {
        route: row.route,
        flow_key: row.flow_key ?? null,
        priority: row.priority,
        queue: row.queue,
        script_keys: [...(row.script_keys ?? [])],
        is_active: row.is_active,
    };
}

function resetDrafts(list: BotIntentRow[]): void {
    for (const row of list) drafts[row.id] = draftOf(row);
}
resetDrafts(rows.value);

// A server refresh replaces rows, but never throws away a row the user is still editing.
watch(
    () => props.intents,
    (list) => {
        const editing = new Set(rows.value.filter((r) => isDirty(r)).map((r) => r.id));
        rows.value = list.map((i) => ({ ...i }));
        resetDrafts(rows.value.filter((r) => !editing.has(r.id)));
    },
);

function isDirty(row: BotIntentRow): boolean {
    const d = drafts[row.id];
    return !!d && JSON.stringify(d) !== JSON.stringify(draftOf(row));
}

const scriptsBySuffix = computed(() => new Map(props.scripts.map((s) => [s.key.slice(SCRIPT_PREFIX.length), s])));

function label(row: BotIntentRow): string {
    return (locale.value === 'ar' ? row.label_ar : row.label_en) || row.label_ar || row.key;
}

/** Group headers are information (which part of the flow an intent belongs to); unknown groups show their raw key. */
function groupLabel(group: string): string {
    const key = `settings.bot_intents.groups.${group}`;
    const text = t(key);
    return text === key ? group : text;
}

function tokenLabel(token: string): string {
    const optional = token.endsWith('?');
    const names = token
        .replace(/\?$/, '')
        .split('|')
        .map((alt) => {
            const key = `settings.bot_intents.tokens.${alt.trim()}`;
            const text = t(key);
            return text === key ? alt : text;
        })
        .join(t('settings.bot_intents.or'));
    return optional ? `${names} ${t('settings.bot_intents.optional')}` : names;
}

function normalize(value: string): string {
    return value.toLowerCase().replace(/[أإآ]/g, 'ا').replace(/ة/g, 'ه').replace(/ى/g, 'ي').trim();
}

/** Every group in catalog order with its size, for the filter chips (independent of the search). */
const allGroups = computed(() => {
    const counts = new Map<string, number>();
    for (const row of rows.value) counts.set(row.group, (counts.get(row.group) ?? 0) + 1);
    return [...counts].map(([group, count]) => ({ group, count }));
});

const filtered = computed(() => {
    const q = normalize(query.value);
    return rows.value.filter(
        (row) =>
            (!groupFilter.value || row.group === groupFilter.value) &&
            (!q ||
                [row.key, row.label_ar, row.label_en, row.group, groupLabel(row.group), ...(row.keywords ?? [])].some((v) =>
                    normalize(v ?? '').includes(q),
                )),
    );
});

const groups = computed(() => {
    const order: string[] = [];
    const byGroup = new Map<string, BotIntentRow[]>();
    for (const row of filtered.value) {
        if (!byGroup.has(row.group)) {
            byGroup.set(row.group, []);
            order.push(row.group);
        }
        byGroup.get(row.group)!.push(row);
    }
    return order.map((group) => ({ group, rows: byGroup.get(group)! }));
});

function scriptsSummary(row: BotIntentRow): string {
    const keys = drafts[row.id]?.script_keys ?? [];
    if (!keys.length) return t('settings.bot_intents.no_scripts');
    const first = scriptsBySuffix.value.get(keys[0])?.title ?? keys[0];
    return keys.length > 1 ? `${first} ${t('settings.bot_intents.more_scripts', { n: keys.length - 1 })}` : first;
}

function toggleScript(row: BotIntentRow, suffix: string): void {
    const d = drafts[row.id];
    d.script_keys = d.script_keys?.includes(suffix) ? d.script_keys.filter((k) => k !== suffix) : [...(d.script_keys ?? []), suffix];
}

function flowTitle(key: string | null | undefined): string | null {
    if (!key) return null;
    return props.flows.find((f) => f.key === key)?.title_ar ?? key;
}

function queueLabel(queue: string | null | undefined): string {
    return t(`settings.bot_intents.queues.${queue === 'agents' || queue === 'senior' ? queue : 'default'}`);
}

function toggleEditor(row: BotIntentRow): void {
    openId.value = openId.value === row.id ? null : row.id;
}

/** Closing the editor without saving drops that card's unsaved changes. */
function cancel(row: BotIntentRow): void {
    drafts[row.id] = draftOf(row);
    openId.value = null;
}

function setFlow(row: BotIntentRow, value: string): void {
    drafts[row.id].flow_key = value || null;
}

function setQueue(row: BotIntentRow, value: string): void {
    drafts[row.id].queue = value === 'agents' || value === 'senior' ? value : null;
}

async function save(row: BotIntentRow): Promise<void> {
    if (saving.value.includes(row.id) || !isDirty(row)) return;
    saving.value = [...saving.value, row.id];
    try {
        const { data } = await api.patch<{ data: BotIntentRow }>(`/settings/bot-intents/${row.id}`, drafts[row.id]);
        const index = rows.value.findIndex((r) => r.id === row.id);
        if (index !== -1) rows.value[index] = { ...data.data };
        drafts[row.id] = draftOf(data.data);
        toast.push(t('settings.bot_intents.saved', { name: label(data.data) }));
        if (openId.value === row.id) openId.value = null;
    } catch (e) {
        toast.push(apiErrorMessage(e, t('common.error')), 'error');
    } finally {
        saving.value = saving.value.filter((id) => id !== row.id);
    }
}

function refresh(): void {
    router.reload({ only: ['intents', 'scripts', 'flows'], onStart: () => (reloading.value = true), onFinish: () => (reloading.value = false) });
}

const breadcrumbs = computed(() => [
    { title: t('settings.bot.title'), href: '/settings/bot' },
    { title: t('settings.bot_intents.title'), href: '/settings/bot-intents' },
]);

const selectClass = 'h-9 w-full rounded-md border border-input bg-background px-2 text-sm disabled:opacity-50';
const chipClass = 'inline-flex max-w-full items-center gap-1 truncate rounded-full bg-elevated px-2 py-0.5 text-2xs text-foreground';
const priorityDot: Record<(typeof PRIORITIES)[number], string> = { high: 'bg-destructive', medium: 'bg-warning', low: 'bg-muted-foreground/40' };
</script>

<template>
    <Head :title="t('settings.bot_intents.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-7xl space-y-4 p-3 md:p-6">
            <PageHeader :title="t('settings.bot_intents.title')" :description="t('settings.bot_intents.description')">
                <Link href="/settings/bot-knowledge" :class="cn(buttonVariants({ variant: 'outline', size: 'sm' }), 'h-8 gap-1.5 text-xs')">
                    <FileText class="size-3.5" aria-hidden="true" />{{ t('settings.bot_intents.edit_scripts') }}
                </Link>
            </PageHeader>

            <div class="space-y-3 rounded-lg bg-card p-3 shadow-card">
                <div class="flex flex-wrap items-center gap-2">
                    <div class="relative w-full max-w-sm">
                        <Search
                            class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <input
                            v-model="query"
                            type="search"
                            :placeholder="t('settings.bot_intents.search')"
                            :aria-label="t('settings.bot_intents.search')"
                            class="h-9 w-full rounded-full border-0 bg-elevated pe-3 ps-9 text-sm placeholder:text-muted-foreground"
                        />
                    </div>
                    <span class="text-xs tabular-nums text-muted-foreground" aria-live="polite">{{
                        t('settings.bot_intents.showing', { n: filtered.length })
                    }}</span>
                    <button
                        type="button"
                        :class="cn(buttonVariants({ variant: 'ghost', size: 'sm' }), 'ms-auto h-9 gap-1.5 rounded-full px-3 text-xs')"
                        :disabled="reloading"
                        @click="refresh"
                    >
                        <LoaderCircle v-if="reloading" class="size-3.5 animate-spin" aria-hidden="true" />
                        <RotateCw v-else class="size-3.5" aria-hidden="true" />{{ t('settings.bot_intents.refresh') }}
                    </button>
                </div>

                <div
                    class="scrollbar-thin -mx-1 flex gap-1.5 overflow-x-auto px-1 pb-0.5"
                    role="group"
                    :aria-label="t('settings.bot_intents.group_filter')"
                >
                    <button
                        v-for="chip in [{ group: '', count: rows.length }, ...allGroups]"
                        :key="chip.group || 'all'"
                        type="button"
                        :aria-pressed="groupFilter === chip.group"
                        class="inline-flex h-8 shrink-0 items-center gap-1.5 rounded-full border px-3 text-xs font-medium transition-colors"
                        :class="
                            groupFilter === chip.group
                                ? 'border-primary bg-surface-accent text-primary'
                                : 'border-border bg-background text-muted-foreground hover:bg-muted hover:text-foreground'
                        "
                        @click="groupFilter = chip.group"
                    >
                        {{ chip.group ? groupLabel(chip.group) : t('settings.bot_intents.all_groups') }}
                        <span class="tabular-nums opacity-70">{{ chip.count }}</span>
                    </button>
                </div>
            </div>

            <div v-if="reloading" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3" aria-busy="true">
                <div v-for="n in SKELETON_CARDS" :key="`skeleton-${n}`" class="space-y-2 rounded-lg bg-card p-4 shadow-card" aria-hidden="true">
                    <div class="h-4 w-36 animate-pulse rounded bg-elevated" />
                    <div class="h-3 w-20 animate-pulse rounded bg-elevated" />
                    <div class="flex gap-1.5 pt-1">
                        <div v-for="c in 3" :key="c" class="h-5 w-16 animate-pulse rounded-full bg-elevated" />
                    </div>
                </div>
            </div>

            <p v-else-if="!groups.length" class="rounded-lg bg-card px-3 py-10 text-center text-sm text-muted-foreground shadow-card">
                {{ t('settings.bot_intents.empty') }}
            </p>

            <section
                v-for="{ group, rows: groupRows } in reloading ? [] : groups"
                :key="group"
                class="space-y-2"
                :aria-labelledby="`intent-group-${group}`"
            >
                <h2 :id="`intent-group-${group}`" class="text-sm font-semibold text-foreground">
                    {{ groupLabel(group) }}
                    <span class="ms-1 text-xs font-normal tabular-nums text-muted-foreground">({{ groupRows.length }})</span>
                </h2>

                <ul class="grid items-start gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <li
                        v-for="row in groupRows"
                        :key="row.id"
                        class="flex flex-col rounded-lg border bg-card shadow-card transition-colors"
                        :class="[isDirty(row) ? 'border-primary' : 'border-transparent', openId === row.id ? 'ring-1 ring-primary/30' : '']"
                    >
                        <div class="flex items-start gap-3 p-3">
                            <span
                                class="mt-1.5 size-2 shrink-0 rounded-full"
                                :class="priorityDot[drafts[row.id].priority]"
                                :title="t(`settings.bot_intents.priorities.${drafts[row.id].priority}`)"
                                aria-hidden="true"
                            />
                            <div class="min-w-0 flex-1">
                                <h3
                                    class="truncate text-sm font-semibold"
                                    :class="drafts[row.id].is_active ? 'text-foreground' : 'text-muted-foreground'"
                                    dir="auto"
                                >
                                    {{ label(row) }}
                                </h3>
                                <p class="truncate text-2xs text-muted-foreground" dir="ltr">{{ row.key }}</p>
                            </div>
                            <button
                                type="button"
                                class="inline-flex h-8 shrink-0 items-center gap-1 rounded-md px-2.5 text-xs font-medium text-primary hover:bg-surface-accent"
                                :aria-expanded="openId === row.id"
                                :aria-controls="`intent-editor-${row.id}`"
                                @click="toggleEditor(row)"
                            >
                                <Pencil v-if="openId !== row.id" class="size-3.5" aria-hidden="true" />
                                <ChevronDown v-else class="size-3.5 rotate-180" aria-hidden="true" />
                                {{ openId === row.id ? t('settings.bot_intents.close_editor') : t('settings.bot_intents.edit') }}
                                <span class="sr-only">{{ label(row) }}</span>
                            </button>
                        </div>

                        <!-- Summary of the current handling (reflects unsaved edits too). -->
                        <div class="flex flex-wrap gap-1.5 px-3 pb-3">
                            <span :class="[chipClass, 'bg-surface-accent font-medium text-primary']">{{
                                t(`settings.bot_intents.routes.${drafts[row.id].route}`)
                            }}</span>
                            <span v-if="drafts[row.id].flow_key" :class="chipClass" dir="auto">{{ flowTitle(drafts[row.id].flow_key) }}</span>
                            <span :class="chipClass">{{ t(`settings.bot_intents.priorities.${drafts[row.id].priority}`) }}</span>
                            <span :class="chipClass">{{ queueLabel(drafts[row.id].queue) }}</span>
                            <span v-if="drafts[row.id].script_keys?.length" :class="chipClass" dir="auto">{{ scriptsSummary(row) }}</span>
                            <span v-if="!drafts[row.id].is_active" :class="[chipClass, 'bg-muted text-muted-foreground']">{{
                                t('settings.bot_intents.inactive')
                            }}</span>
                            <span v-if="isDirty(row)" :class="[chipClass, 'bg-warning/20']">{{ t('settings.bot_intents.unsaved') }}</span>
                        </div>
                        <p v-if="row.required_details?.length" class="-mt-1 px-3 pb-3 text-2xs text-muted-foreground">
                            {{ t('settings.bot_intents.details', { list: row.required_details.map(tokenLabel).join('، ') }) }}
                        </p>

                        <div v-if="openId === row.id" :id="`intent-editor-${row.id}`" class="space-y-3 border-t border-border/60 bg-elevated/40 p-3">
                            <label class="grid gap-1">
                                <span class="text-xs font-semibold">{{ t('settings.bot_intents.route') }}</span>
                                <select v-model="drafts[row.id].route" :class="selectClass" :disabled="saving.includes(row.id)">
                                    <option v-for="r in ROUTES" :key="r" :value="r">{{ t(`settings.bot_intents.routes.${r}`) }}</option>
                                </select>
                            </label>
                            <label class="grid gap-1">
                                <span class="text-xs font-semibold">{{ t('settings.bot_intents.flow') }}</span>
                                <select
                                    :value="drafts[row.id].flow_key ?? ''"
                                    :class="selectClass"
                                    :disabled="saving.includes(row.id)"
                                    @change="setFlow(row, ($event.target as HTMLSelectElement).value)"
                                >
                                    <option value="">{{ t('settings.bot_intents.no_flow') }}</option>
                                    <option v-for="f in flows" :key="f.key" :value="f.key">
                                        {{ f.title_ar }}{{ f.is_active ? '' : ` ${t('settings.bot_intents.inactive_flow')}` }}
                                    </option>
                                </select>
                            </label>
                            <div class="grid grid-cols-2 gap-2">
                                <label class="grid gap-1">
                                    <span class="text-xs font-semibold">{{ t('settings.bot_intents.priority') }}</span>
                                    <select v-model="drafts[row.id].priority" :class="selectClass" :disabled="saving.includes(row.id)">
                                        <option v-for="p in PRIORITIES" :key="p" :value="p">{{ t(`settings.bot_intents.priorities.${p}`) }}</option>
                                    </select>
                                </label>
                                <label class="grid gap-1">
                                    <span class="text-xs font-semibold">{{ t('settings.bot_intents.queue') }}</span>
                                    <select
                                        :value="drafts[row.id].queue ?? ''"
                                        :class="selectClass"
                                        :disabled="saving.includes(row.id)"
                                        @change="setQueue(row, ($event.target as HTMLSelectElement).value)"
                                    >
                                        <option value="">{{ t('settings.bot_intents.queues.default') }}</option>
                                        <option value="agents">{{ t('settings.bot_intents.queues.agents') }}</option>
                                        <option value="senior">{{ t('settings.bot_intents.queues.senior') }}</option>
                                    </select>
                                </label>
                            </div>
                            <div class="grid gap-1">
                                <span :id="`intent-scripts-${row.id}`" class="text-xs font-semibold">{{ t('settings.bot_intents.scripts') }}</span>
                                <DropdownMenu>
                                    <DropdownMenuTrigger
                                        :disabled="saving.includes(row.id)"
                                        :aria-labelledby="`intent-scripts-${row.id}`"
                                        class="flex h-9 w-full items-center justify-between gap-1 rounded-md border border-input bg-background px-2 text-sm disabled:opacity-50"
                                    >
                                        <span
                                            class="truncate"
                                            :class="drafts[row.id].script_keys?.length ? '' : 'text-muted-foreground'"
                                            dir="auto"
                                            >{{ scriptsSummary(row) }}</span
                                        >
                                        <ChevronDown class="size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="start" class="max-h-80 w-64 overflow-y-auto">
                                        <DropdownMenuLabel class="text-xs">{{ t('settings.bot_intents.scripts') }}</DropdownMenuLabel>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuCheckboxItem
                                            v-for="[suffix, script] in scriptsBySuffix"
                                            :key="script.id"
                                            :checked="drafts[row.id].script_keys?.includes(suffix)"
                                            class="text-xs"
                                            @select.prevent="toggleScript(row, suffix)"
                                        >
                                            <span class="min-w-0 flex-1 truncate" dir="auto">{{ script.title }}</span>
                                            <span v-if="!script.is_active" class="ms-2 shrink-0 text-2xs text-muted-foreground">{{
                                                t('settings.bot_intents.inactive_script')
                                            }}</span>
                                        </DropdownMenuCheckboxItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                            <div class="flex items-center justify-between gap-3 rounded-md bg-card px-3 py-2">
                                <span class="text-xs font-semibold">{{ t('settings.bot_intents.active') }}</span>
                                <ToggleSwitch
                                    v-model="drafts[row.id].is_active"
                                    :label="`${t('settings.bot_intents.active')}: ${label(row)}`"
                                    :disabled="saving.includes(row.id)"
                                />
                            </div>
                            <div class="flex justify-end gap-2">
                                <button
                                    type="button"
                                    class="inline-flex h-9 items-center rounded-md px-3 text-xs font-medium text-muted-foreground hover:bg-muted hover:text-foreground"
                                    :disabled="saving.includes(row.id)"
                                    @click="cancel(row)"
                                >
                                    {{ t('common.cancel') }}
                                </button>
                                <button
                                    type="button"
                                    class="inline-flex h-9 items-center gap-1.5 rounded-md px-4 text-xs font-medium transition-colors disabled:cursor-not-allowed"
                                    :class="
                                        isDirty(row)
                                            ? 'bg-primary text-primary-foreground hover:bg-primary-hover'
                                            : 'bg-elevated text-muted-foreground opacity-60'
                                    "
                                    :disabled="!isDirty(row) || saving.includes(row.id)"
                                    @click="save(row)"
                                >
                                    <LoaderCircle v-if="saving.includes(row.id)" class="size-3.5 animate-spin" aria-hidden="true" />{{
                                        t('settings.bot_intents.save')
                                    }}
                                </button>
                            </div>
                        </div>
                    </li>
                </ul>
            </section>
        </div>
    </AppLayout>
</template>
