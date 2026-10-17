<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

export interface LedgerFigure {
    key: string;
    label: string;
    /** Already formatted for the locale (Arabic-Indic digits, «ج.م» after the number). */
    value: string;
    /** A small unit beside the value («ج.م», «د»). */
    unit?: string;
    /** The delta or context under the figure («أمس ١٣٨»). */
    note?: string | null;
    tone?: 'up' | 'down' | 'neutral';
    href?: string | null;
}

/**
 * Today's ledger (DESIGN §3 Ledger): four to six figures in one panel with dividers, a line of context under each.
 * Not separate cards. Two columns on a phone, up to six from md.
 */
defineProps<{ figures: LedgerFigure[]; label: string }>();

const notes = { up: 'text-ok', down: 'text-danger', neutral: 'text-fg-3' };
</script>

<template>
    <section
        :aria-label="label"
        class="grid grid-cols-2 overflow-hidden rounded-lg border border-line bg-card md:grid-cols-[repeat(var(--ledger-cols),minmax(0,1fr))]"
        :style="{ '--ledger-cols': Math.min(Math.max(figures.length, 1), 6) }"
    >
        <component
            :is="figure.href ? Link : 'div'"
            v-for="figure in figures"
            :key="figure.key"
            :href="figure.href ?? undefined"
            class="min-w-0 border-line px-4 py-3.5 [&:nth-child(even)]:border-s max-md:[&:nth-child(n+3)]:border-t md:border-s md:first:border-s-0"
            :class="figure.href ? 'transition-colors hover:bg-surface-2' : ''"
            :data-ledger="figure.key"
        >
            <div class="truncate text-[12.5px] font-medium text-fg-2">{{ figure.label }}</div>
            <div class="whitespace-nowrap text-2xl font-bold tabular-nums leading-snug">
                {{ figure.value }}<small v-if="figure.unit" class="ms-1 text-13 font-semibold text-fg-2">{{ figure.unit }}</small>
            </div>
            <div v-if="figure.note" class="truncate text-xs" :class="notes[figure.tone ?? 'neutral']">{{ figure.note }}</div>
        </component>
    </section>
</template>
