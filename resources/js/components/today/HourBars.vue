<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { formatCount } from '@/lib/format';
import { computed, ref } from 'vue';

export interface HourBar {
    key: string;
    label: string;
    value: number;
}

/**
 * One series of bars by hour (DESIGN §3 Chart): `--chart` bars, a light grid with three ticks, time running the reading
 * way (oldest at the start: right in Arabic), a tip on each bar and the numbers in a list for screen readers.
 * The SVG itself is laid out left-to-right (DESIGN §5.6), so every x below is physical.
 */
const props = withDefaults(defineProps<{ bars: HourBar[]; label: string; unit?: string; height?: number }>(), { unit: '', height: 120 });

const { dir, locale } = useI18n();
const W = 460;
const PAD = 8;
const AXIS = 36;
const active = ref<number | null>(null);

const nice = (m: number) => {
    if (m <= 0) return 4;
    const mag = Math.pow(10, Math.floor(Math.log10(m)));

    return Math.max(4, (Math.ceil((m / mag) * 2) / 2) * mag);
};
const max = computed(() => nice(Math.max(...props.bars.map((b) => b.value), 0) * 1.05));
const ticks = computed(() => [0, 0.5, 1].map((f) => Math.round(max.value * f)));
const bw = computed(() => (W - PAD) / Math.max(props.bars.length, 1));
const rtl = computed(() => dir.value === 'rtl');
/** The axis sits at the end the time starts from (right in Arabic). */
const plotStart = computed(() => (rtl.value ? 0 : AXIS));
const y = (v: number) => props.height - (v / max.value) * (props.height - 14);
const slotX = (i: number) => (rtl.value ? plotStart.value + W - (i + 1) * bw.value : plotStart.value + PAD + i * bw.value);
const every = computed(() => Math.ceil(props.bars.length / 8));
const fmt = (v: number) => formatCount(v, locale.value);
/** A bar is at most 28 units wide, centred in its slot (one hour of a quiet night must not fill the panel). */
const barW = computed(() => Math.min(bw.value * 0.64, 28));
const tip = (b: HourBar) => `${b.label}: ${fmt(b.value)}${props.unit}`;
</script>

<template>
    <figure class="m-0">
        <svg
            class="block h-auto w-full overflow-visible"
            style="direction: ltr"
            :viewBox="`0 0 ${W + AXIS} ${height + 22}`"
            role="img"
            :aria-label="label"
            data-hour-bars
        >
            <g v-for="tk in ticks" :key="tk">
                <line :x1="plotStart" :x2="plotStart + W" :y1="y(tk)" :y2="y(tk)" class="stroke-line" stroke-width="1" />
                <text
                    :x="rtl ? W + 4 : AXIS - 4"
                    :y="y(tk) + 4"
                    :text-anchor="rtl ? 'start' : 'end'"
                    class="fill-fg-2 text-[12px] tabular-nums"
                >
                    {{ fmt(tk) }}
                </text>
            </g>
            <g
                v-for="(b, i) in bars"
                :key="b.key"
                @pointerenter="active = i"
                @pointerleave="active = null"
            >
                <title>{{ tip(b) }}</title>
                <rect :x="slotX(i)" y="0" :width="bw" :height="height" fill="transparent" />
                <rect
                    :x="slotX(i) + (bw - barW) / 2"
                    :y="y(b.value)"
                    :width="barW"
                    :height="Math.max(2, height - y(b.value))"
                    rx="4"
                    class="transition-colors"
                    :class="active === i ? 'fill-brand' : 'fill-chart-1'"
                />
                <text
                    v-if="i % every === 0"
                    :x="slotX(i) + bw / 2"
                    :y="height + 16"
                    text-anchor="middle"
                    class="fill-fg-2 text-[12px]"
                >
                    {{ b.label }}
                </text>
            </g>
            <g v-if="active !== null && bars[active]" aria-hidden="true">
                <text
                    :x="slotX(active) + bw / 2"
                    :y="Math.max(12, y(bars[active].value) - 6)"
                    text-anchor="middle"
                    class="fill-foreground text-[12px] font-semibold tabular-nums"
                >
                    {{ fmt(bars[active].value) }}
                </text>
            </g>
        </svg>
        <figcaption class="sr-only">
            <ul>
                <li v-for="b in bars" :key="b.key">{{ tip(b) }}</li>
            </ul>
        </figcaption>
    </figure>
</template>
