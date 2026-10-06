<script setup lang="ts" generic="T extends { id: number | string }">
import EmptyState from '@/components/crm/EmptyState.vue';
import SkeletonList from '@/components/crm/SkeletonList.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { useDensity, type Density } from '@/composables/useDensity';
import { useI18n } from '@/composables/useI18n';
import { formatCount } from '@/lib/format';
import { nextSort, parseSort } from '@/lib/sort';
import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-vue-next';
import { computed, ref, type Component } from 'vue';

export interface Column {
    key: string;
    label: string;
    align?: 'start' | 'end' | 'center';
    class?: string;
    /** Force a direction on the cell (phone numbers and order numbers are always left-to-right). */
    dir?: 'ltr' | 'auto';
    /** Left out of the phone card. */
    hideOnMobile?: boolean;
    /** The phone card's title row. */
    primary?: boolean;
    /** Clickable header: emits `update:sort` (`-key` first, then `key`). The server (or sortRows) must accept the key. */
    sortable?: boolean;
    /** Money and counts: tabular digits, end-aligned unless `align` says otherwise. */
    numeric?: boolean;
}

type RowId = number | string;

const props = withDefaults(
    defineProps<{
        columns: Column[];
        rows: T[];
        clickable?: boolean;
        empty?: string;
        caption?: string;
        loading?: boolean;
        /** Below md: one card per row, or the table scrolls inside its own box. */
        mobile?: 'cards' | 'scroll';
        emptyIcon?: Component;
        sort?: string | null;
        stickyHeader?: boolean;
        stickyFirstColumn?: boolean;
        /** Remembers density per table (localStorage) and shows the مريح / مضغوط toggle. */
        tableId?: string;
        skeletonRows?: number;
        selectable?: boolean;
    }>(),
    {
        clickable: false,
        loading: false,
        empty: undefined,
        caption: undefined,
        mobile: 'cards',
        emptyIcon: undefined,
        sort: null,
        stickyHeader: true,
        stickyFirstColumn: false,
        tableId: undefined,
        skeletonRows: 8,
        selectable: false,
    },
);

const emit = defineEmits<{ rowClick: [row: T]; 'update:sort': [sort: string] }>();
const densityModel = defineModel<Density>('density');
const selected = defineModel<RowId[]>('selected', { default: () => [] });

const { t, locale } = useI18n();

const stored = props.tableId ? useDensity(props.tableId) : ref<Density>('comfortable');
const density = computed<Density>({
    get: () => densityModel.value ?? stored.value,
    set: (value) => {
        stored.value = value;
        densityModel.value = value;
    },
});
const cellPad = computed(() => (density.value === 'compact' ? 'px-2 py-1' : 'px-3 py-2'));

const sortState = computed(() => parseSort(props.sort));
const showSkeleton = computed(() => props.loading && props.rows.length === 0);
const colspan = computed(() => props.columns.length + (props.selectable ? 1 : 0));

function alignClass(col: Column): string {
    const align = col.align ?? (col.numeric ? 'end' : 'start');

    return align === 'end' ? 'text-end' : align === 'center' ? 'text-center' : 'text-start';
}

function colClasses(col: Column, index: number): unknown[] {
    return [alignClass(col), col.numeric ? 'tabular-nums' : '', col.class, props.stickyFirstColumn && index === 0 ? 'crm-sticky-first' : ''];
}

function ariaSort(col: Column): 'ascending' | 'descending' | 'none' | undefined {
    if (!col.sortable) return undefined;
    if (sortState.value?.key !== col.key) return 'none';

    return sortState.value.dir === 'desc' ? 'descending' : 'ascending';
}

function onSort(col: Column): void {
    emit('update:sort', nextSort(props.sort, col.key));
}

const ids = computed(() => props.rows.map((row) => row.id));
const allSelected = computed(() => ids.value.length > 0 && ids.value.every((id) => selected.value.includes(id)));
const someSelected = computed(() => !allSelected.value && ids.value.some((id) => selected.value.includes(id)));

function toggleAll(): void {
    selected.value = allSelected.value ? selected.value.filter((id) => !ids.value.includes(id)) : [...ids.value, ...selected.value.filter((id) => !ids.value.includes(id))];
}

function toggleRow(row: T): void {
    selected.value = selected.value.includes(row.id) ? selected.value.filter((id) => id !== row.id) : [...selected.value, row.id];
}

function onKey(event: KeyboardEvent, row: T): void {
    if (props.clickable && (event.key === 'Enter' || event.key === ' ')) {
        event.preventDefault();
        emit('rowClick', row);
    }
}

function cell(row: T, key: string): unknown {
    return (row as Record<string, unknown>)[key];
}

/** A column with no slot of its own: a bare number still has to read in the page's own digits. */
function defaultCell(row: T, key: string): unknown {
    const value = cell(row, key);

    return typeof value === 'number' ? formatCount(value, locale.value) : value;
}
</script>

<template>
    <div class="min-w-0">
        <div v-if="tableId || $slots.toolbar" class="mb-2 flex items-center gap-2">
            <slot name="toolbar" />
            <div v-if="tableId" class="ms-auto hidden items-center rounded-full border border-border bg-card p-0.5 text-2xs font-medium md:inline-flex" role="group" :aria-label="t('table.density')">
                <button
                    v-for="option in ['comfortable', 'compact'] as const"
                    :key="option"
                    type="button"
                    class="rounded-full px-2.5 py-1"
                    :class="density === option ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground'"
                    :aria-pressed="density === option"
                    :data-density-option="option"
                    @click="density = option"
                >
                    {{ t(`table.density_${option}`) }}
                </button>
            </div>
        </div>

        <template v-if="mobile === 'cards'">
            <SkeletonList v-if="showSkeleton" variant="cards" :count="3" class="md:hidden" />
            <ul v-else class="space-y-2 md:hidden" :aria-label="caption">
                <li v-if="!rows.length" class="rounded-lg bg-card shadow-card">
                    <slot name="empty"><EmptyState :icon="emptyIcon" :title="empty ?? t('ui.empty')" /></slot>
                </li>
                <li v-for="row in rows" :key="row.id">
                    <component
                        :is="clickable ? 'button' : 'div'"
                        :type="clickable ? 'button' : undefined"
                        class="block w-full rounded-lg bg-card p-3 text-start shadow-card"
                        :class="clickable ? 'hover:bg-muted focus-visible:bg-muted' : ''"
                        @click="clickable && emit('rowClick', row)"
                    >
                        <div
                            v-for="col in columns.filter((c) => !c.hideOnMobile)"
                            :key="col.key"
                            class="flex min-w-0 items-baseline justify-between gap-3 py-0.5 text-xs"
                            :class="col.primary ? 'mb-1 text-sm font-semibold' : ''"
                        >
                            <span v-if="!col.primary" class="shrink-0 text-2xs text-muted-foreground">{{ col.label }}</span>
                            <span class="min-w-0 truncate" :class="[col.primary ? 'text-start' : 'text-end', col.numeric ? 'tabular-nums' : '']" :dir="col.dir">
                                <slot :name="`cell-${col.key}`" :row="row" :value="cell(row, col.key)">{{ defaultCell(row, col.key) ?? '—' }}</slot>
                            </span>
                        </div>
                    </component>
                </li>
            </ul>
        </template>

        <div
            data-table-box
            class="scrollbar-thin relative rounded-lg bg-card shadow-card"
            :class="[stickyHeader ? 'table-scroll-box' : 'overflow-x-auto', mobile === 'cards' ? 'hidden md:block' : '']"
        >
            <table class="w-full text-xs" :aria-busy="loading" :data-density="density">
                <caption v-if="caption" class="sr-only">{{ caption }}</caption>
                <thead class="bg-card text-2xs font-semibold text-muted-foreground" :class="stickyHeader ? 'crm-sticky-head' : 'border-b border-border/60'">
                    <tr>
                        <th v-if="selectable" scope="col" class="w-8" :class="cellPad">
                            <input
                                type="checkbox"
                                class="size-4 rounded border-input"
                                :checked="allSelected"
                                :indeterminate="someSelected"
                                :aria-label="t('table.select_all')"
                                @change="toggleAll"
                            />
                        </th>
                        <th
                            v-for="(col, index) in columns"
                            :key="col.key"
                            scope="col"
                            class="whitespace-nowrap font-semibold"
                            :class="[cellPad, ...colClasses(col, index)]"
                            :aria-sort="ariaSort(col)"
                        >
                            <button
                                v-if="col.sortable"
                                type="button"
                                class="inline-flex items-center gap-1 hover:text-foreground"
                                :aria-label="t('table.sort_by', { label: col.label })"
                                @click="onSort(col)"
                            >
                                {{ col.label }}
                                <ArrowDown v-if="sortState?.key === col.key && sortState.dir === 'desc'" class="size-3" aria-hidden="true" />
                                <ArrowUp v-else-if="sortState?.key === col.key" class="size-3" aria-hidden="true" />
                                <ArrowUpDown v-else class="size-3 opacity-40" aria-hidden="true" />
                            </button>
                            <template v-else>{{ col.label }}</template>
                        </th>
                    </tr>
                </thead>
                <tbody :class="loading && rows.length ? 'opacity-60 transition-opacity' : ''">
                    <template v-if="showSkeleton">
                        <tr v-for="n in skeletonRows" :key="`skeleton-${n}`" data-skeleton-row class="border-t border-border/60 first:border-t-0">
                            <td v-if="selectable" :class="cellPad"><Skeleton class="size-4" /></td>
                            <td v-for="col in columns" :key="col.key" :class="cellPad">
                                <Skeleton class="h-3.5" :class="col.numeric || col.align === 'end' ? 'ms-auto w-12' : 'w-3/4'" />
                            </td>
                        </tr>
                    </template>
                    <tr v-else-if="!rows.length">
                        <td :colspan="colspan" class="text-muted-foreground">
                            <slot name="empty"><EmptyState :icon="emptyIcon" :title="empty ?? t('ui.empty')" /></slot>
                        </td>
                    </tr>
                    <template v-else>
                        <tr
                            v-for="row in rows"
                            :key="row.id"
                            class="border-t border-border/60 first:border-t-0 hover:bg-muted/50"
                            :class="clickable ? 'cursor-pointer hover:bg-muted focus-visible:bg-muted' : ''"
                            :tabindex="clickable ? 0 : undefined"
                            @click="clickable && emit('rowClick', row)"
                            @keydown="onKey($event, row)"
                        >
                            <td v-if="selectable" :class="cellPad" @click.stop>
                                <input
                                    type="checkbox"
                                    class="size-4 rounded border-input"
                                    :checked="selected.includes(row.id)"
                                    :aria-label="t('table.select_row')"
                                    @change="toggleRow(row)"
                                />
                            </td>
                            <td v-for="(col, index) in columns" :key="col.key" class="align-middle" :dir="col.dir" :class="[cellPad, ...colClasses(col, index)]">
                                <slot :name="`cell-${col.key}`" :row="row" :value="cell(row, col.key)">{{ defaultCell(row, col.key) ?? '—' }}</slot>
                            </td>
                        </tr>
                    </template>
                </tbody>
                <tfoot v-if="$slots.totals" class="border-t-2 border-border bg-card font-semibold tabular-nums">
                    <slot name="totals" :columns="columns" />
                </tfoot>
            </table>
        </div>
    </div>
</template>
