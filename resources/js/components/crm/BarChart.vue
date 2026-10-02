<script setup lang="ts">
import { readable } from '@/composables/usePlatform';
import { useI18n } from '@/composables/useI18n';
import { useElementSize } from '@vueuse/core';
import { computed, ref, useId } from 'vue';

export interface BarDatum {
    key: string;
    label: string;
    value: number;
    /** Only for platform series; other series use the neutral accent. */
    color?: string;
}

const props = withDefaults(
    defineProps<{
        title: string;
        items: BarDatum[];
        orientation?: 'horizontal' | 'vertical';
        format?: (value: number) => string;
        /** Vertical charts: show every n-th axis label. */
        labelEvery?: number;
    }>(),
    { orientation: 'horizontal', labelEvery: 1 },
);

const { t, dir } = useI18n();
const id = useId();
const rtl = computed(() => dir.value === 'rtl');
const fmt = (v: number) => (props.format ? props.format(v) : String(v));
const max = computed(() => Math.max(1, ...props.items.map((i) => i.value)));
const total = computed(() => props.items.reduce((sum, i) => sum + i.value, 0));

// Horizontal: fixed label column + bar track + value label, mirrored in RTL. The SVG itself is
// laid out left-to-right (`direction: ltr`), so every x and text-anchor below is physical: in RTL
// the label column is the right edge, anchored at its right end, and nothing inherits the page's
// RTL anchoring (which cut «Messenger» to one letter).
// The viewBox follows the box's real width, so the 11 px labels stay 11 px on a phone instead of
// shrinking with a fixed 480-wide drawing.
const H_ROW = 26;
const H_VALUE = 88;
const box = ref<HTMLElement | null>(null);
const { width: boxWidth } = useElementSize(box);
const H_WIDTH = computed(() => Math.max(280, Math.round(boxWidth.value) || 480));
const H_LABEL = computed(() => Math.min(140, Math.max(88, Math.round(H_WIDTH.value * 0.24))));
const trackWidth = computed(() => H_WIDTH.value - H_LABEL.value - H_VALUE);

/**
 * The value sits just past the bar's end; when it would not fit before the drawing's edge (a long
 * money value), it moves inside the bar's end instead, so it is never clipped.
 */
function valuePlacement(text: string, barX: number, w: number): { valueX: number; valueAnchor: 'start' | 'end'; valueInside: boolean } {
    const textWidth = text.length * 6.6 + 4;
    const fitsInside = w >= textWidth + 12;
    if (rtl.value) {
        const outside = barX - 6;
        if (outside - textWidth >= 0) return { valueX: outside, valueAnchor: 'end', valueInside: false };
        return fitsInside ? { valueX: barX + 6, valueAnchor: 'start', valueInside: true } : { valueX: textWidth, valueAnchor: 'end', valueInside: false };
    }
    const outside = barX + w + 6;
    if (outside + textWidth <= H_WIDTH.value) return { valueX: outside, valueAnchor: 'start', valueInside: false };
    return fitsInside ? { valueX: barX + w - 6, valueAnchor: 'end', valueInside: true } : { valueX: H_WIDTH.value - textWidth, valueAnchor: 'start', valueInside: false };
}

const hBars = computed(() =>
    props.items.map((item, index) => {
        const w = Math.max(item.value > 0 ? 2 : 0, (item.value / max.value) * trackWidth.value);
        const y = index * H_ROW;
        const barX = rtl.value ? H_WIDTH.value - H_LABEL.value - w : H_LABEL.value;
        return {
            ...item,
            y,
            w,
            barX,
            labelX: rtl.value ? H_WIDTH.value - 4 : 4,
            labelAnchor: rtl.value ? 'end' : 'start',
            ...valuePlacement(fmt(item.value), barX, w),
            fill: item.color ? readable(item.color) : undefined,
        };
    }),
);

// Vertical: 24-hour style columns with an axis label under every n-th bar.
const V_WIDTH = H_WIDTH;
const V_HEIGHT = 160;
const V_TOP = 16;
const V_AXIS = 18;
const plotHeight = V_HEIGHT - V_TOP - V_AXIS;

const vBars = computed(() => {
    const slot = V_WIDTH.value / Math.max(1, props.items.length);
    return props.items.map((item, index) => {
        const h = (item.value / max.value) * plotHeight;
        const position = rtl.value ? props.items.length - 1 - index : index;
        const x = position * slot + slot * 0.15;
        return { ...item, fill: item.color ? readable(item.color) : undefined, index, x, w: slot * 0.7, h, y: V_TOP + plotHeight - h, cx: position * slot + slot / 2 };
    });
});
</script>

<template>
    <figure ref="box" class="min-w-0 rounded-lg bg-card p-3 shadow-card">
        <figcaption :id="`${id}-title`" class="mb-2 text-sm font-bold text-foreground">{{ title }}</figcaption>
        <p v-if="total === 0" class="py-6 text-center text-xs text-muted-foreground">{{ t('reports.no_data') }}</p>

        <svg
            v-else-if="orientation === 'horizontal'"
            :viewBox="`0 0 ${H_WIDTH} ${items.length * H_ROW}`"
            class="w-full"
            style="direction: ltr"
            role="img"
            :aria-labelledby="`${id}-title`"
        >
            <g v-for="bar in hBars" :key="bar.key">
                <title>{{ bar.label }}: {{ fmt(bar.value) }}</title>
                <text :x="bar.labelX" :y="bar.y + 17" :text-anchor="bar.labelAnchor" class="fill-muted-foreground text-[11px]" unicode-bidi="plaintext">{{ bar.label }}</text>
                <rect :x="rtl ? H_VALUE : H_LABEL" :y="bar.y + 6" :width="trackWidth" height="14" rx="3" class="fill-muted" />
                <rect :x="bar.barX" :y="bar.y + 6" :width="bar.w" height="14" rx="3" :class="!bar.fill ? 'fill-primary' : ''" :style="bar.fill ? { fill: bar.fill } : undefined" />
                <text
                    :x="bar.valueX"
                    :y="bar.y + 17"
                    :text-anchor="bar.valueAnchor"
                    class="text-[11px] font-medium tabular-nums"
                    :class="!bar.valueInside ? 'fill-foreground' : bar.fill === 'hsl(var(--foreground))' ? 'fill-background' : 'fill-white'"
                >
                    {{ fmt(bar.value) }}
                </text>
            </g>
        </svg>

        <svg v-else :viewBox="`0 0 ${V_WIDTH} ${V_HEIGHT}`" class="w-full" style="direction: ltr" role="img" :aria-labelledby="`${id}-title`">
            <line x1="0" :x2="V_WIDTH" :y1="V_TOP + plotHeight" :y2="V_TOP + plotHeight" class="stroke-border" />
            <g v-for="bar in vBars" :key="bar.key">
                <title>{{ bar.label }}: {{ fmt(bar.value) }}</title>
                <rect :x="bar.x" :y="bar.y" :width="bar.w" :height="Math.max(0, bar.h)" rx="2" :class="!bar.fill ? 'fill-primary' : ''" :style="bar.fill ? { fill: bar.fill } : undefined" />
                <text
                    v-if="bar.value > 0 && bar.value === max"
                    :x="bar.cx"
                    :y="bar.y - 4"
                    text-anchor="middle"
                    class="fill-foreground text-[10px] font-medium tabular-nums"
                >
                    {{ fmt(bar.value) }}
                </text>
                <text
                    v-if="bar.index % labelEvery === 0"
                    :x="bar.cx"
                    :y="V_HEIGHT - 4"
                    text-anchor="middle"
                    class="fill-muted-foreground text-[10px] tabular-nums"
                >
                    {{ bar.label }}
                </text>
            </g>
        </svg>

        <!-- Screen readers get the exact values as a list. -->
        <ul class="sr-only">
            <li v-for="item in items" :key="item.key">{{ item.label }}: {{ fmt(item.value) }}</li>
        </ul>
    </figure>
</template>
