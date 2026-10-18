<script setup lang="ts">
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
import FilterBar from '@/components/crm/FilterBar.vue';
import IconAction from '@/components/crm/IconAction.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import SectionTabs from '@/components/crm/SectionTabs.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import ToggleSwitch from '@/components/crm/ToggleSwitch.vue';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCount } from '@/lib/format';
import { sectionTabs } from '@/lib/sectionTabs';
import { cn } from '@/lib/utils';
import type { BotFlowOption, BotIntentRoute, BotIntentRow, BotScriptOption } from '@/types/admin';
import { Head, Link, router } from '@inertiajs/vue3';
import { ChevronDown, FileText, Pencil, RotateCw, SearchX } from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps<{ intents: BotIntentRow[]; scripts: BotScriptOption[]; detailTokens: string[]; flows: BotFlowOption[] }>();

const { t, locale, dir } = useI18n();
const api = useApi();
const toast = useToast();

const ROUTES: BotIntentRoute[] = ['answer', 'lookup', 'collect_then_handover', 'handover'];
const PRIORITIES = ['low', 'medium', 'high'] as const;
const SCRIPT_PREFIX = 'script.';
const SKELETON_ROWS = 8;

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

function queueLabel(queue: string | null | undefined): string {
    return t(`settings.bot_intents.queues.${queue === 'agents' || queue === 'senior' ? queue : 'default'}`);
}

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

/** The intent open in the edit drawer. Closing the drawer keeps its unsaved edits (the row shows «مش محفوظ»). */
const editing = computed(() => rows.value.find((r) => r.id === openId.value) ?? null);

function openEditor(row: BotIntentRow): void {
    openId.value = row.id;
}

/** «يحوّل»: the routes that end with a person, shown amber in the table. */
function handsOver(route: BotIntentRoute): boolean {
    return route === 'handover' || route === 'collect_then_handover';
}

/** A few of the customer phrasings the intent is matched by. */
function examples(row: BotIntentRow): string {
    const list = (row.keywords ?? []).slice(0, 3).map((k) => `«${k}»`);
    return list.join('، ') + ((row.keywords?.length ?? 0) > 3 ? ' …' : '');
}

const columns = computed<Column[]>(() => [
    { key: 'intent', label: t('settings.bot_intents.col_intent'), primary: true },
    { key: 'route', label: t('settings.bot_intents.col_route') },
    { key: 'examples', label: t('settings.bot_intents.col_examples'), class: 'max-w-sm' },
    { key: 'actions', label: t('ui.actions'), align: 'end' },
]);

function clearFilters(): void {
    query.value = '';
    groupFilter.value = '';
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
    { title: t('nav.bot'), href: '/bot' },
    { title: t('settings.bot_intents.title'), href: '/bot/intents' },
]);

const selectClass = 'h-10 w-full rounded-md border border-line-strong bg-card px-2 text-sm disabled:opacity-50';
</script>

<template>
    <Head :title="t('settings.bot_intents.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-6xl space-y-4 p-3 md:p-6">
            <PageHeader :title="t('nav.bot_flows')" :description="t('flows.section_description')">
                <Link href="/bot/knowledge" :class="cn(buttonVariants({ variant: 'outline' }), 'gap-1.5')">
                    <FileText class="size-4" aria-hidden="true" />{{ t('settings.bot_intents.edit_scripts') }}
                </Link>
            </PageHeader>
            <SectionTabs :tabs="sectionTabs.botFlows(t)" :label="t('nav.bot_flows')" />

            <FilterBar
                :search="query"
                :search-placeholder="t('settings.bot_intents.search')"
                :chips="groupFilter ? [{ key: 'group', label: groupLabel(groupFilter) }] : []"
                :summary="t('settings.bot_intents.showing', { n: filtered.length })"
                @update:search="query = $event"
                @remove="groupFilter = ''"
                @clear="clearFilters"
            >
                <template #inline>
                    <select v-model="groupFilter" :class="[selectClass, 'sm:w-auto']" :aria-label="t('settings.bot_intents.group_filter')">
                        <option value="">{{ t('settings.bot_intents.all_groups') }} ({{ formatCount(rows.length, locale) }})</option>
                        <option v-for="chip in allGroups" :key="chip.group" :value="chip.group">
                            {{ groupLabel(chip.group) }} ({{ formatCount(chip.count, locale) }})
                        </option>
                    </select>
                    <Button type="button" variant="ghost" class="shrink-0 gap-1.5" :loading="reloading" @click="refresh">
                        <RotateCw class="size-4" aria-hidden="true" />{{ t('settings.bot_intents.refresh') }}
                    </Button>
                </template>
            </FilterBar>

            <DataTable
                table-id="bot-intents"
                :columns="columns"
                :rows="reloading ? [] : filtered"
                :loading="reloading"
                :skeleton-rows="SKELETON_ROWS"
                clickable
                mobile="cards"
                :empty="t('settings.bot_intents.empty')"
                :empty-icon="SearchX"
                :caption="t('settings.bot_intents.title')"
                :row-class="(row) => (openId === row.id ? 'bg-brand-soft/40' : undefined)"
                @row-click="openEditor"
            >
                <template #cell-intent="{ row }">
                    <div class="min-w-0">
                        <p
                            class="truncate font-semibold"
                            :class="drafts[row.id].is_active ? 'text-foreground' : 'text-fg-3'"
                            dir="auto"
                            :title="row.key"
                        >
                            {{ label(row) }}
                        </p>
                        <p class="text-xs text-fg-3">{{ groupLabel(row.group) }}</p>
                        <div v-if="!drafts[row.id].is_active || isDirty(row)" class="mt-1 flex flex-wrap gap-1">
                            <StatusChip v-if="!drafts[row.id].is_active" size="sm" :label="t('settings.bot_intents.inactive')" />
                            <StatusChip v-if="isDirty(row)" size="sm" tone="warn" :label="t('settings.bot_intents.unsaved')" />
                        </div>
                    </div>
                </template>
                <template #cell-route="{ row }">
                    <div class="flex flex-col items-start gap-1">
                        <StatusChip
                            size="sm"
                            :tone="handsOver(drafts[row.id].route) ? 'warn' : 'brand'"
                            :label="t(`settings.bot_intents.routes.${drafts[row.id].route}`)"
                        />
                        <span v-if="drafts[row.id].flow_key" class="text-xs text-fg-2" dir="auto">{{ flowTitle(drafts[row.id].flow_key) }}</span>
                        <!-- Priority, queue and scripts stay visible in the list, as before the redesign. -->
                        <span class="text-xs text-fg-2">
                            {{ t(`settings.bot_intents.priorities.${drafts[row.id].priority}`) }} · {{ queueLabel(drafts[row.id].queue) }}
                        </span>
                        <span v-if="drafts[row.id].script_keys?.length" class="text-xs text-fg-2" dir="auto">{{ scriptsSummary(row) }}</span>
                    </div>
                </template>
                <template #cell-examples="{ row }">
                    <span v-if="row.keywords?.length" class="text-13 text-fg-2" dir="auto">{{ examples(row) }}</span>
                    <span v-else class="text-fg-3">—</span>
                </template>
                <template #cell-actions="{ row }">
                    <IconAction :icon="Pencil" :label="`${t('settings.bot_intents.edit')}: ${label(row)}`" @click.stop="openEditor(row)" />
                </template>
            </DataTable>
        </div>

        <!-- The edit drawer: how the bot handles one intent (the same PATCH as before). -->
        <Sheet :open="editing !== null" @update:open="(open: boolean) => !open && (openId = null)">
            <SheetContent :side="dir === 'rtl' ? 'left' : 'right'" class="flex w-full flex-col gap-0 overflow-y-auto p-0 sm:max-w-md">
                <template v-if="editing">
                    <SheetHeader class="border-b border-line p-4 text-start">
                        <SheetTitle class="pe-8 text-lg" dir="auto">{{ label(editing) }}</SheetTitle>
                        <SheetDescription class="text-13 text-fg-2">{{ groupLabel(editing.group) }}</SheetDescription>
                    </SheetHeader>

                    <div :id="`intent-editor-${editing.id}`" class="flex-1 space-y-4 p-4">
                        <p v-if="editing.required_details?.length" class="rounded-md bg-surface-2 px-3 py-2 text-13 text-fg-2">
                            {{ t('settings.bot_intents.details', { list: editing.required_details.map(tokenLabel).join('، ') }) }}
                        </p>
                        <label class="grid gap-1.5">
                            <span class="text-13 font-semibold">{{ t('settings.bot_intents.route') }}</span>
                            <select v-model="drafts[editing.id].route" :class="selectClass" :disabled="saving.includes(editing.id)">
                                <option v-for="r in ROUTES" :key="r" :value="r">{{ t(`settings.bot_intents.routes.${r}`) }}</option>
                            </select>
                        </label>
                        <label class="grid gap-1.5">
                            <span class="text-13 font-semibold">{{ t('settings.bot_intents.flow') }}</span>
                            <select
                                :value="drafts[editing.id].flow_key ?? ''"
                                :class="selectClass"
                                :disabled="saving.includes(editing.id)"
                                @change="setFlow(editing, ($event.target as HTMLSelectElement).value)"
                            >
                                <option value="">{{ t('settings.bot_intents.no_flow') }}</option>
                                <option v-for="f in flows" :key="f.key" :value="f.key">
                                    {{ f.title_ar }}{{ f.is_active ? '' : ` ${t('settings.bot_intents.inactive_flow')}` }}
                                </option>
                            </select>
                        </label>
                        <div class="grid grid-cols-2 gap-3">
                            <label class="grid gap-1.5">
                                <span class="text-13 font-semibold">{{ t('settings.bot_intents.priority') }}</span>
                                <select v-model="drafts[editing.id].priority" :class="selectClass" :disabled="saving.includes(editing.id)">
                                    <option v-for="p in PRIORITIES" :key="p" :value="p">{{ t(`settings.bot_intents.priorities.${p}`) }}</option>
                                </select>
                            </label>
                            <label class="grid gap-1.5">
                                <span class="text-13 font-semibold">{{ t('settings.bot_intents.queue') }}</span>
                                <select
                                    :value="drafts[editing.id].queue ?? ''"
                                    :class="selectClass"
                                    :disabled="saving.includes(editing.id)"
                                    @change="setQueue(editing, ($event.target as HTMLSelectElement).value)"
                                >
                                    <option value="">{{ t('settings.bot_intents.queues.default') }}</option>
                                    <option value="agents">{{ t('settings.bot_intents.queues.agents') }}</option>
                                    <option value="senior">{{ t('settings.bot_intents.queues.senior') }}</option>
                                </select>
                            </label>
                        </div>
                        <div class="grid gap-1.5">
                            <span :id="`intent-scripts-${editing.id}`" class="text-13 font-semibold">{{ t('settings.bot_intents.scripts') }}</span>
                            <DropdownMenu>
                                <DropdownMenuTrigger
                                    :disabled="saving.includes(editing.id)"
                                    :aria-labelledby="`intent-scripts-${editing.id}`"
                                    class="flex h-10 w-full items-center justify-between gap-1 rounded-md border border-line-strong bg-card px-3 text-sm disabled:opacity-50"
                                >
                                    <span class="truncate" :class="drafts[editing.id].script_keys?.length ? '' : 'text-fg-3'" dir="auto">{{
                                        scriptsSummary(editing)
                                    }}</span>
                                    <ChevronDown class="size-4 shrink-0 text-fg-3" aria-hidden="true" />
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="start" class="max-h-80 w-64 overflow-y-auto">
                                    <DropdownMenuLabel class="text-xs">{{ t('settings.bot_intents.scripts') }}</DropdownMenuLabel>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuCheckboxItem
                                        v-for="[suffix, script] in scriptsBySuffix"
                                        :key="script.id"
                                        :checked="drafts[editing.id].script_keys?.includes(suffix)"
                                        class="text-13"
                                        @select.prevent="toggleScript(editing, suffix)"
                                    >
                                        <span class="min-w-0 flex-1 truncate" dir="auto">{{ script.title }}</span>
                                        <span v-if="!script.is_active" class="ms-2 shrink-0 text-xs text-fg-3">{{
                                            t('settings.bot_intents.inactive_script')
                                        }}</span>
                                    </DropdownMenuCheckboxItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                        <div class="flex items-center justify-between gap-3 rounded-md border border-line px-3 py-2.5">
                            <span class="text-13 font-semibold">{{ t('settings.bot_intents.active') }}</span>
                            <ToggleSwitch
                                v-model="drafts[editing.id].is_active"
                                :label="`${t('settings.bot_intents.active')}: ${label(editing)}`"
                                :disabled="saving.includes(editing.id)"
                            />
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 border-t border-line p-4">
                        <Button type="button" variant="ghost" :disabled="saving.includes(editing.id)" @click="cancel(editing)">{{
                            t('common.cancel')
                        }}</Button>
                        <Button type="button" :loading="saving.includes(editing.id)" :disabled="!isDirty(editing)" @click="save(editing)">
                            {{ t('settings.bot_intents.save') }}
                        </Button>
                    </div>
                </template>
            </SheetContent>
        </Sheet>
    </AppLayout>
</template>
