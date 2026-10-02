<script setup lang="ts">
/**
 * Bars + lines on one time axis, inline SVG (no chart library). Series on `axis: 'right'` get their own
 * scale on the right edge (ROAS next to money). The time axis always runs left → right (dates read LTR,
 * as in the owner's Arena charts); title, legend and tooltip follow the page direction. The svg needs CSS
 * `direction: ltr` too (the `dir` attribute alone does not reach SVG text), or RTL pages flip every text-anchor.
 */
import { useI18n } from '@/composables/useI18n';
import { computed, onBeforeUnmount, onMounted, ref, useId } from 'vue';

export interface ComboSeries {
    key: string;
    label: string;
    values: (number | null)[];
    /** Any CSS colour, theme tokens included: `hsl(var(--chart-1))`. */
    color: string;
    format: (value: number) => string;
    axis?: 'left' | 'right';
    dashed?: boolean;
}

const props = withDefaults(
    defineProps<{
        title: string;
        labels: string[];
        labelFormat?: (label: string) => string;
        bars?: ComboSeries[];
        lines?: ComboSeries[];
        leftFormat?: (value: number) => string;
        rightFormat?: (value: number) => string;
        height?: number;
    }>(),
    { bars: () => [], lines: () => [], height: 260, labelFormat: undefined, leftFormat: undefined, rightFormat: undefined },
);

const { t } = useI18n();
const id = useId();
const box = ref<HTMLElement | null>(null);
const width = ref(640);
let observer: ResizeObserver | null = null;

onMounted(() => {
    if (!box.value) return;
    width.value = Math.max(280, box.value.clientWidth);
    if (typeof ResizeObserver !== 'undefined') {
        observer = new ResizeObserver((entries) => {
            const w = entries[0]?.contentRect.width;
            if (w) width.value = Math.max(280, Math.round(w));
        });
        observer.observe(box.value);
    }
});
onBeforeUnmount(() => observer?.disconnect());

const all = computed(() => [...props.bars, ...props.lines]);
const hasRight = computed(() => props.lines.some((s) => s.axis === 'right'));
const empty = computed(() => props.labels.length === 0 || all.value.every((s) => s.values.every((v) => v === null || v === 0)));

const PAD = { top: 14, bottom: 26, left: 52 };
const padRight = computed(() => (hasRight.value ? 44 : 12));
const plotW = computed(() => Math.max(1, width.value - PAD.left - padRight.value));
const plotH = computed(() => Math.max(1, props.height - PAD.top - PAD.bottom));
const n = computed(() => Math.max(1, props.labels.length));
const slot = computed(() => plotW.value / n.value);

function niceMax(v: number): number {
    if (!(v > 0)) return 1;
    const exp = Math.pow(10, Math.floor(Math.log10(v)));
    const f = v / exp;
    const nice = f <= 1 ? 1 : f <= 2 ? 2 : f <= 2.5 ? 2.5 : f <= 5 ? 5 : 10;

    return nice * exp;
}

const maxOf = (series: ComboSeries[]) => Math.max(0, ...series.flatMap((s) => s.values.map((v) => v ?? 0)));
const leftMax = computed(() => niceMax(maxOf([...props.bars, ...props.lines.filter((s) => s.axis !== 'right')])));
const rightMax = computed(() => niceMax(maxOf(props.lines.filter((s) => s.axis === 'right'))));

const TICKS = 4;
const ticks = computed(() => Array.from({ length: TICKS + 1 }, (_, i) => i / TICKS));
const yFor = (value: number, axis: 'left' | 'right' = 'left') =>
    PAD.top + plotH.value - (value / (axis === 'right' ? rightMax.value : leftMax.value)) * plotH.value;
const cx = (i: number) => PAD.left + slot.value * (i + 0.5);

const barRects = computed(() => {
    const count = props.bars.length;
    if (!count) return [];
    const group = Math.min(slot.value * 0.72, 56 * count);
    const w = Math.max(1, group / count);

    return props.bars.flatMap((s, si) =>
        s.values.map((v, i) => {
            const value = v ?? 0;
            const y = yFor(value);
            return {
                key: `${s.key}-${i}`,
                x: cx(i) - group / 2 + si * w,
                y,
                w: Math.max(1, w - (count > 1 ? 1 : 0)),
                h: Math.max(0, PAD.top + plotH.value - y),
                color: s.color,
            };
        }),
    );
});

/** Polyline segments broken at nulls; single points become dots. */
const linePaths = computed(() =>
    props.lines.map((s) => {
        const axis = s.axis ?? 'left';
        const segments: string[] = [];
        let current: string[] = [];
        const dots: { x: number; y: number }[] = [];
        s.values.forEach((v, i) => {
            if (v === null) {
                if (current.length) segments.push(current.join(' '));
                current = [];
                return;
            }
            const p = { x: cx(i), y: yFor(v, axis) };
            current.push(`${p.x.toFixed(1)},${p.y.toFixed(1)}`);
            dots.push(p);
        });
        if (current.length) segments.push(current.join(' '));

        return { ...s, segments, dots, showDots: n.value <= 31 };
    }),
);

const labelEvery = computed(() => Math.max(1, Math.ceil(n.value / Math.max(1, Math.floor(plotW.value / 58)))));
const fmtLabel = (l: string) => (props.labelFormat ? props.labelFormat(l) : l);
const fmtLeft = (v: number) => (props.leftFormat ? props.leftFormat(v) : String(Math.round(v)));
const fmtRight = (v: number) => (props.rightFormat ? props.rightFormat(v) : String(v));

const hover = ref<number | null>(null);

function onMove(event: PointerEvent): void {
    const rect = (event.currentTarget as SVGElement).getBoundingClientRect();
    const scale = rect.width > 0 ? width.value / rect.width : 1;
    const x = (event.clientX - rect.left) * scale - PAD.left;
    hover.value = x < 0 || x > plotW.value ? null : Math.min(n.value - 1, Math.max(0, Math.floor(x / slot.value)));
}

const tip = computed(() => {
    if (hover.value === null) return null;
    const i = hover.value;
    const x = cx(i);

    return {
        label: fmtLabel(props.labels[i] ?? ''),
        rows: all.value.map((s) => ({
            key: s.key,
            label: s.label,
            color: s.color,
            dashed: !!s.dashed,
            value: s.values[i] === null || s.values[i] === undefined ? '—' : s.format(s.values[i] as number),
        })),
        // Physical position (the svg is LTR); flip to the other side past the middle.
        style: x > width.value / 2 ? { right: `${width.value - x + 10}px` } : { left: `${x + 10}px` },
    };
});
</script>

<template>
    <figure class="rounded-lg bg-card p-3 shadow-card md:p-4">
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
            <figcaption :id="`${id}-title`" class="text-sm font-bold text-foreground">{{ title }}</figcaption>
            <ul class="flex flex-wrap items-center gap-x-3 gap-y-1 text-2xs text-muted-foreground">
                <li v-for="s in bars" :key="s.key" class="inline-flex items-center gap-1.5">
                    <span class="inline-block size-2.5 rounded-sm" :style="{ backgroundColor: s.color }" aria-hidden="true" />{{ s.label }}
                </li>
                <li v-for="s in lines" :key="s.key" class="inline-flex items-center gap-1.5">
                    <svg width="18" height="6" aria-hidden="true">
                        <line
                            x1="0"
                            y1="3"
                            x2="18"
                            y2="3"
                            :style="{ stroke: s.color }"
                            stroke-width="2"
                            :stroke-dasharray="s.dashed ? '3 3' : undefined"
                        />
                    </svg>
                    {{ s.label }}<span v-if="s.axis === 'right'" class="text-muted-foreground/80">({{ t('ads.chart.right_axis') }})</span>
                </li>
            </ul>
        </div>

        <div ref="box" class="relative w-full">
            <p v-if="empty" class="flex items-center justify-center text-xs text-muted-foreground" :style="{ height: `${height}px` }">
                {{ t('ads.empty.chart') }}
            </p>
            <template v-else>
                <svg
                    dir="ltr"
                    :viewBox="`0 0 ${width} ${height}`"
                    :width="width"
                    :height="height"
                    class="block max-w-full touch-pan-y select-none [direction:ltr]"
                    role="img"
                    :aria-labelledby="`${id}-title`"
                    @pointermove="onMove"
                    @pointerleave="hover = null"
                >
                    <!-- grid + axes labels -->
                    <g v-for="tk in ticks" :key="tk">
                        <line
                            :x1="PAD.left"
                            :x2="PAD.left + plotW"
                            :y1="PAD.top + plotH * (1 - tk)"
                            :y2="PAD.top + plotH * (1 - tk)"
                            class="stroke-border"
                            :stroke-dasharray="tk === 0 ? undefined : '2 4'"
                        />
                        <text
                            :x="PAD.left - 6"
                            :y="PAD.top + plotH * (1 - tk) + 3"
                            text-anchor="end"
                            class="fill-muted-foreground text-[10px] tabular-nums"
                        >
                            {{ fmtLeft(leftMax * tk) }}
                        </text>
                        <text
                            v-if="hasRight"
                            :x="PAD.left + plotW + 6"
                            :y="PAD.top + plotH * (1 - tk) + 3"
                            text-anchor="start"
                            class="fill-muted-foreground text-[10px] tabular-nums"
                        >
                            {{ fmtRight(rightMax * tk) }}
                        </text>
                    </g>

                    <rect v-if="hover !== null" :x="PAD.left + slot * hover" :y="PAD.top" :width="slot" :height="plotH" class="fill-muted/60" />

                    <rect
                        v-for="b in barRects"
                        :key="b.key"
                        :x="b.x"
                        :y="b.y"
                        :width="b.w"
                        :height="b.h"
                        rx="2"
                        :style="{ fill: b.color }"
                        opacity="0.85"
                    />

                    <g v-for="l in linePaths" :key="l.key">
                        <polyline
                            v-for="(seg, si) in l.segments"
                            :key="si"
                            :points="seg"
                            fill="none"
                            :style="{ stroke: l.color }"
                            stroke-width="2"
                            stroke-linejoin="round"
                            stroke-linecap="round"
                            :stroke-dasharray="l.dashed ? '4 4' : undefined"
                        />
                        <template v-if="l.showDots || l.dots.length === 1">
                            <circle
                                v-for="(d, di) in l.dots"
                                :key="di"
                                :cx="d.x"
                                :cy="d.y"
                                :r="l.dots.length === 1 ? 4 : 2.5"
                                :style="{ fill: l.color }"
                                class="stroke-card"
                                stroke-width="1"
                            />
                        </template>
                    </g>

                    <template v-for="(label, i) in labels" :key="`x-${i}`">
                        <text
                            v-if="i % labelEvery === 0"
                            :x="cx(i)"
                            :y="height - 8"
                            text-anchor="middle"
                            class="fill-muted-foreground text-[10px] tabular-nums"
                        >
                            {{ fmtLabel(label) }}
                        </text>
                    </template>
                </svg>

                <div
                    v-if="tip"
                    class="pointer-events-none absolute top-2 z-10 min-w-40 rounded-md border border-border bg-popover px-2.5 py-2 text-2xs text-popover-foreground shadow-lg"
                    :style="tip.style"
                >
                    <p class="mb-1 font-semibold">{{ tip.label }}</p>
                    <p v-for="r in tip.rows" :key="r.key" class="flex items-center justify-between gap-3">
                        <span class="inline-flex items-center gap-1.5">
                            <span class="inline-block size-2 rounded-full" :style="{ backgroundColor: r.color }" aria-hidden="true" />{{ r.label }}
                        </span>
                        <span class="font-medium tabular-nums">{{ r.value }}</span>
                    </p>
                </div>
            </template>
        </div>

        <!-- Exact values for screen readers. -->
        <table v-if="!empty" class="sr-only">
            <caption>
                {{
                    title
                }}
            </caption>
            <thead>
                <tr>
                    <th scope="col">{{ t('ads.table.date') }}</th>
                    <th v-for="s in [...bars, ...lines]" :key="s.key" scope="col">{{ s.label }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(label, i) in labels" :key="i">
                    <th scope="row">{{ fmtLabel(label) }}</th>
                    <td v-for="s in [...bars, ...lines]" :key="s.key">
                        {{ s.values[i] === null || s.values[i] === undefined ? '—' : s.format(s.values[i] as number) }}
                    </td>
                </tr>
            </tbody>
        </table>
    </figure>
</template>
