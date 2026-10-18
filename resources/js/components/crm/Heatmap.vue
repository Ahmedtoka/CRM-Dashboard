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
        new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-EG' : 'en-GB', { weekday: 'short', timeZone: 'UTC' }).format(
            new Date(Date.UTC(2023, 0, 1 + d)),
        ),
    ),
);
const hours = Array.from({ length: 24 }, (_, h) => h);
const hourLabel = (h: number) => formatNumber(locale.value, h, { useGrouping: false });

// Five-step scale from the sunken surface to full brand; the top two steps take the on-brand text for contrast.
const STEPS = ['bg-sunken', 'bg-brand-soft', 'bg-brand/40 text-foreground', 'bg-brand/70 text-brand-on', 'bg-brand text-brand-on'];
function step(value: number): string {
    if (value <= 0 || max.value === 0) return STEPS[0];
    return STEPS[Math.min(4, 1 + Math.floor((value / max.value) * 3.999))];
}
</script>

<template>
    <figure class="rounded-lg border border-line bg-card p-3">
        <figcaption :id="`${id}-title`" class="mb-1 text-sm font-bold text-foreground">{{ title }}</figcaption>
        <p class="mb-2 text-2xs text-fg-2">{{ t('reports.heatmap_hint') }}</p>
        <!-- `relative`: the sr-only spans are absolutely positioned; without a positioned scroller they
             escape it and widen the whole page on a phone (the layout viewport grew to 657 px). -->
        <div class="scrollbar-thin relative overflow-x-auto">
            <table class="w-full min-w-[640px] table-fixed border-separate border-spacing-0.5 text-2xs" :aria-labelledby="`${id}-title`">
                <thead>
                    <tr>
                        <th scope="col" class="w-12">
                            <span class="sr-only">{{ t('reports.day') }}</span>
                        </th>
                        <th v-for="h in hours" :key="h" scope="col" class="font-normal tabular-nums text-fg-2">
                            {{ h % 3 === 0 ? hourLabel(h) : '' }}<span v-if="h % 3 !== 0" class="sr-only">{{ hourLabel(h) }}</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, d) in grid" :key="d">
                        <th scope="row" class="whitespace-nowrap pe-1 text-start font-normal text-fg-2">{{ dayNames[d] }}</th>
                        <td
                            v-for="(value, h) in row"
                            :key="h"
                            class="h-6 rounded-sm text-center tabular-nums"
                            :class="step(value)"
                            :title="`${dayNames[d]} ${hourLabel(h)}:00 — ${formatNumber(locale, value)}`"
                        >
                            <span class="sr-only">{{ dayNames[d] }} {{ hourLabel(h) }}:00: </span
                            >{{ value > 0 && max > 0 && value >= max * 0.75 ? formatNumber(locale, value) : ''
                            }}<span v-if="!(value > 0 && max > 0 && value >= max * 0.75)" class="sr-only">{{ formatNumber(locale, value) }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </figure>
</template>
