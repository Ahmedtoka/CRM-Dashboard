<script setup lang="ts" generic="T extends { id: number | string }">
import EmptyState from '@/components/crm/EmptyState.vue';
import { useI18n } from '@/composables/useI18n';
import { formatCount } from '@/lib/format';
import type { Component } from 'vue';

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
}

const props = withDefaults(
    defineProps<{
        columns: Column[];
        rows: T[];
        clickable?: boolean;
        empty?: string;
        caption?: string;
        loading?: boolean;
        /** Below md: one card per row, or the table scrolls sideways inside its own box. */
        mobile?: 'cards' | 'scroll';
        emptyIcon?: Component;
    }>(),
    {
        clickable: false,
        loading: false,
        empty: undefined,
        caption: undefined,
        mobile: 'cards',
        emptyIcon: undefined,
    },
);

const emit = defineEmits<{ rowClick: [row: T] }>();

const { t, locale } = useI18n();

const alignClass = (align?: Column['align']) => (align === 'end' ? 'text-end' : align === 'center' ? 'text-center' : 'text-start');

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
    <ul v-if="mobile === 'cards'" class="space-y-2 md:hidden" :aria-label="caption">
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
                    <span class="min-w-0 truncate" :class="col.primary ? 'text-start' : 'text-end'" :dir="col.dir">
                        <slot :name="`cell-${col.key}`" :row="row" :value="cell(row, col.key)">{{ defaultCell(row, col.key) ?? '—' }}</slot>
                    </span>
                </div>
            </component>
        </li>
    </ul>
    <div class="scrollbar-thin relative overflow-x-auto rounded-lg bg-card shadow-card" :class="mobile === 'cards' ? 'hidden md:block' : ''">
        <div v-if="loading" class="absolute inset-x-0 top-0 h-0.5 animate-pulse bg-primary/60" aria-hidden="true" />
        <table class="w-full text-xs" :aria-busy="loading">
            <caption v-if="caption" class="sr-only">{{ caption }}</caption>
            <thead class="border-b border-border/60 bg-card text-2xs font-semibold text-muted-foreground">
                <tr>
                    <th v-for="col in columns" :key="col.key" scope="col" class="whitespace-nowrap px-3 py-2 font-semibold" :class="[alignClass(col.align), col.class]">
                        {{ col.label }}
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="!rows.length">
                    <td :colspan="columns.length" class="text-muted-foreground">
                        <slot name="empty"><EmptyState :icon="emptyIcon" :title="empty ?? t('ui.empty')" /></slot>
                    </td>
                </tr>
                <tr
                    v-for="row in rows"
                    :key="row.id"
                    class="border-t border-border/60 first:border-t-0"
                    :class="clickable ? 'cursor-pointer hover:bg-muted focus-visible:bg-muted' : ''"
                    :tabindex="clickable ? 0 : undefined"
                    @click="clickable && emit('rowClick', row)"
                    @keydown="onKey($event, row)"
                >
                    <td v-for="col in columns" :key="col.key" class="px-3 py-2 align-middle" :dir="col.dir" :class="[alignClass(col.align), col.class]">
                        <slot :name="`cell-${col.key}`" :row="row" :value="cell(row, col.key)">{{ defaultCell(row, col.key) ?? '—' }}</slot>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
