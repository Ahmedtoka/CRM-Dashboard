<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { formatNumber } from '@/i18n';
import type { HeatmapGrid } from '@/types/admin';
import { computed, useId } from 'vue';

const props = defineProps<{ title: string; grid: HeatmapGrid }>();

const { t, locale } = useI18n();
const id = useId();

const max = computed(() => Math.max(0, ...props.grid.flat()));

// 2023-01-01 was a Sunday: weekday 0 in the metrics grid.
const dayNames = computed(() =>
    Array.from({ length: 7 }, (_, d) =>
        new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-EG' : 'en-GB', { weekday: 'short', timeZone: 'UTC' }).format(new Date(Date.UTC(2023, 0, 1 + d))),
    ),
);
const hours = Array.from({ length: 24 }, (_, h) => h);
const hourLabel = (h: number) => formatNumber(locale.value, h, { useGrouping: false });

// Five-step scale from the surface accent to full primary; the top two steps get the primary-foreground text for contrast.
const STEPS = ['bg-muted', 'bg-surface-accent', 'bg-primary/40 text-foreground', 'bg-primary/70 text-primary-foreground', 'bg-primary text-primary-foreground'];
function step(value: number): string {
    if (value <= 0 || max.value === 0) return STEPS[0];
    return STEPS[Math.min(4, 1 + Math.floor((value / max.value) * 3.999))];
}
</script>

<template>
    <figure class="rounded-lg bg-card p-3 shadow-card">
        <figcaption :id="`${id}-title`" class="mb-1 text-sm font-bold text-foreground">{{ title }}</figcaption>
        <p class="mb-2 text-2xs text-muted-foreground">{{ t('reports.heatmap_hint') }}</p>
        <div class="scrollbar-thin overflow-x-auto">
            <table class="w-full min-w-[640px] border-separate border-spacing-0.5 text-2xs" :aria-labelledby="`${id}-title`">
                <thead>
                    <tr>
                        <th scope="col" class="w-12"><span class="sr-only">{{ t('reports.day') }}</span></th>
                        <th v-for="h in hours" :key="h" scope="col" class="font-normal tabular-nums text-muted-foreground">
                            {{ h % 3 === 0 ? hourLabel(h) : '' }}<span v-if="h % 3 !== 0" class="sr-only">{{ hourLabel(h) }}</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, d) in grid" :key="d">
                        <th scope="row" class="whitespace-nowrap pe-1 text-start font-normal text-muted-foreground">{{ dayNames[d] }}</th>
                        <td
                            v-for="(value, h) in row"
                            :key="h"
                            class="h-6 rounded-sm text-center tabular-nums"
                            :class="step(value)"
                            :title="`${dayNames[d]} ${hourLabel(h)}:00 — ${formatNumber(locale, value)}`"
                        >
                            <span class="sr-only">{{ dayNames[d] }} {{ hourLabel(h) }}:00: </span>{{ value > 0 && max > 0 && value >= max * 0.75 ? formatNumber(locale, value) : '' }}<span
                                v-if="!(value > 0 && max > 0 && value >= max * 0.75)"
                                class="sr-only"
                                >{{ formatNumber(locale, value) }}</span
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </figure>
</template>
