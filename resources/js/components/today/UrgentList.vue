<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, CircleAlert, type LucideIcon } from 'lucide-vue-next';

export interface UrgentRow {
    key: string;
    /** The sentence with its number: «٤ عميلات مستنيين في الصالة». */
    label: string;
    /** One line of context: «أطول انتظار ١٧ دقيقة». */
    detail?: string | null;
    /** Where it gets solved. */
    href: string;
    tone: 'danger' | 'warn' | 'brand' | 'info';
    icon?: LucideIcon;
}

/**
 * «محتاج تدخلك» (DESIGN §3 UrgentList): one row per thing that needs the manager, most important first, each a link to
 * the place it gets solved. Colour comes with the words, never alone.
 */
withDefaults(defineProps<{ title: string; rows: UrgentRow[]; empty?: string; aside?: string }>(), { empty: undefined, aside: undefined });

const { dir } = useI18n();
const tiles = {
    danger: 'bg-danger-soft text-danger',
    warn: 'bg-warn-soft text-warn',
    brand: 'bg-brand-soft text-brand-ink',
    info: 'bg-info-soft text-info',
};
</script>

<template>
    <section class="overflow-hidden rounded-lg border border-line bg-card" aria-labelledby="urgent-list-title">
        <header class="flex items-center gap-2 border-b border-line px-4 py-3">
            <h2 id="urgent-list-title" class="text-base font-bold">{{ title }}</h2>
            <span v-if="aside" class="ms-auto text-xs text-fg-3">{{ aside }}</span>
        </header>
        <p v-if="rows.length === 0" class="px-4 py-3 text-sm text-fg-2">{{ empty }}</p>
        <ul v-else>
            <li v-for="row in rows" :key="row.key" class="border-b border-line last:border-b-0">
                <Link
                    :href="row.href"
                    class="flex w-full items-center gap-3 px-4 py-2.5 text-start transition-colors hover:bg-surface-2"
                    :data-urgent="row.key"
                    :data-tone="row.tone"
                >
                    <span class="grid size-8 shrink-0 place-items-center rounded-md" :class="tiles[row.tone]">
                        <component :is="row.icon ?? CircleAlert" class="size-[18px]" aria-hidden="true" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <b class="block font-semibold [overflow-wrap:anywhere]">{{ row.label }}</b>
                        <span v-if="row.detail" class="text-[12.5px] text-fg-2">{{ row.detail }}</span>
                    </span>
                    <component :is="dir === 'rtl' ? ChevronLeft : ChevronRight" class="size-4 shrink-0 text-fg-3" aria-hidden="true" />
                </Link>
            </li>
        </ul>
    </section>
</template>
