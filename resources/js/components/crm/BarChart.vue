<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { computed, useId } from 'vue';

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
const ACCENT = '#6366f1';

// Horizontal: fixed label column + bar track + value label, mirrored in RTL.
const H_ROW = 26;
const H_WIDTH = 480;
const H_LABEL = 120;
const H_VALUE = 72;
const trackWidth = H_WIDTH - H_LABEL - H_VALUE;

const hBars = computed(() =>
    props.items.map((item, index) => {
        const w = Math.max(item.value > 0 ? 2 : 0, (item.value / max.value) * trackWidth);
        const y = index * H_ROW;
        const barX = rtl.value ? H_WIDTH - H_LABEL - w : H_LABEL;
        return {
            ...item,
            y,
            w,
            barX,
            labelX: rtl.value ? H_WIDTH - 4 : 4,
            valueX: rtl.value ? barX - 6 : barX + w + 6,
        };
    }),
);

// Vertical: 24-hour style columns with an axis label under every n-th bar.
const V_WIDTH = 600;
const V_HEIGHT = 160;
const V_TOP = 16;
const V_AXIS = 18;
const plotHeight = V_HEIGHT - V_TOP - V_AXIS;

const vBars = computed(() => {
    const slot = V_WIDTH / Math.max(1, props.items.length);
    return props.items.map((item, index) => {
        const h = (item.value / max.value) * plotHeight;
        const position = rtl.value ? props.items.length - 1 - index : index;
        const x = position * slot + slot * 0.15;
        return { ...item, index, x, w: slot * 0.7, h, y: V_TOP + plotHeight - h, cx: position * slot + slot / 2 };
    });
});
</script>

<template>
    <figure class="rounded-lg border bg-card p-3">
        <figcaption :id="`${id}-title`" class="mb-2 text-xs font-medium text-foreground">{{ title }}</figcaption>
        <p v-if="total === 0" class="py-6 text-center text-xs text-muted-foreground">{{ t('reports.no_data') }}</p>

        <svg
            v-else-if="orientation === 'horizontal'"
            :viewBox="`0 0 ${H_WIDTH} ${items.length * H_ROW}`"
            class="w-full"
            role="img"
            :aria-labelledby="`${id}-title`"
        >
            <g v-for="bar in hBars" :key="bar.key">
                <title>{{ bar.label }}: {{ fmt(bar.value) }}</title>
                <text :x="bar.labelX" :y="bar.y + 17" :text-anchor="rtl ? 'end' : 'start'" class="fill-muted-foreground text-[11px]">{{ bar.label }}</text>
                <rect :x="rtl ? H_VALUE : H_LABEL" :y="bar.y + 6" :width="trackWidth" height="14" rx="3" class="fill-muted" />
                <rect :x="bar.barX" :y="bar.y + 6" :width="bar.w" height="14" rx="3" :fill="bar.color ?? ACCENT" />
                <text :x="bar.valueX" :y="bar.y + 17" :text-anchor="rtl ? 'end' : 'start'" class="fill-foreground text-[11px] font-medium tabular-nums">
                    {{ fmt(bar.value) }}
                </text>
            </g>
        </svg>

        <svg v-else :viewBox="`0 0 ${V_WIDTH} ${V_HEIGHT}`" class="w-full" role="img" :aria-labelledby="`${id}-title`">
            <line x1="0" :x2="V_WIDTH" :y1="V_TOP + plotHeight" :y2="V_TOP + plotHeight" class="stroke-border" />
            <g v-for="bar in vBars" :key="bar.key">
                <title>{{ bar.label }}: {{ fmt(bar.value) }}</title>
                <rect :x="bar.x" :y="bar.y" :width="bar.w" :height="Math.max(0, bar.h)" rx="2" :fill="bar.color ?? ACCENT" />
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
