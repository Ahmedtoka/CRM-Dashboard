<script setup lang="ts" generic="T extends { id: number | string }">
import { useI18n } from '@/composables/useI18n';
import { formatCount } from '@/lib/format';

export interface Column {
    key: string;
    label: string;
    align?: 'start' | 'end' | 'center';
    class?: string;
}

const props = withDefaults(defineProps<{ columns: Column[]; rows: T[]; clickable?: boolean; empty?: string; caption?: string; loading?: boolean }>(), {
    clickable: false,
    loading: false,
});

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
    <div class="scrollbar-thin relative overflow-x-auto rounded-lg bg-card shadow-card">
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
                    <td :colspan="columns.length" class="px-3 py-8 text-center text-muted-foreground">{{ empty ?? t('ui.empty') }}</td>
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
                    <td v-for="col in columns" :key="col.key" class="px-3 py-2 align-middle" :class="[alignClass(col.align), col.class]">
                        <slot :name="`cell-${col.key}`" :row="row" :value="cell(row, col.key)">{{ defaultCell(row, col.key) ?? '—' }}</slot>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
