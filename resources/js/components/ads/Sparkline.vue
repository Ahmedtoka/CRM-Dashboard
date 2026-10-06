<script setup lang="ts">
/** 14-day spend bars + real ROAS line (U 3.1). Numbers live in the drawer; this is shape only, with a text label. */
import { useI18n } from '@/composables/useI18n';
import { formatAdsMoney, formatRoas } from '@/lib/ads';
import type { AdSeriesPoint } from '@/types/ads';
import { computed } from 'vue';

const props = withDefaults(defineProps<{ points: AdSeriesPoint[]; width?: number; height?: number; currency?: string }>(), { width: 112, height: 28, currency: 'EGP' });
const { t, locale } = useI18n();

const maxSpend = computed(() => Math.max(0, ...props.points.map((p) => p.spend)));
const maxRoas = computed(() => Math.max(0, ...props.points.map((p) => p.roas ?? 0)));
const step = computed(() => (props.points.length ? props.width / props.points.length : 0));

const bars = computed(() =>
    props.points.map((p, i) => {
        const h = maxSpend.value > 0 ? Math.max(1, (p.spend / maxSpend.value) * (props.height - 2)) : 0;
        return { x: i * step.value + 1, y: props.height - h, w: Math.max(1, step.value - 2), h, key: p.date };
    }),
);

/** ROAS line split at days without ROAS (no spend). */
const lines = computed(() => {
    const out: string[] = [];
    let cur: string[] = [];
    props.points.forEach((p, i) => {
        if (p.roas === null || maxRoas.value === 0) {
            if (cur.length > 1) out.push(cur.join(' '));
            cur = [];
            return;
        }
        const x = i * step.value + step.value / 2;
        const y = props.height - 1 - (p.roas / maxRoas.value) * (props.height - 4);
        cur.push(`${x.toFixed(1)},${y.toFixed(1)}`);
    });
    if (cur.length > 1) out.push(cur.join(' '));
    return out;
});

const total = computed(() => props.points.reduce((s, p) => s + p.spend, 0));
const label = computed(() =>
    t('ads.control.spark.label', { spend: formatAdsMoney(total.value, locale.value, props.currency), roas: formatRoas(maxRoas.value || null, locale.value) }),
);
</script>

<template>
    <!-- Time runs left to right in every chart of the app, RTL included. -->
    <svg v-if="maxSpend > 0" :width="width" :height="height" :viewBox="`0 0 ${width} ${height}`" role="img" :aria-label="label" class="block max-w-full overflow-visible">
        <title>{{ label }}</title>
        <rect v-for="b in bars" :key="b.key" :x="b.x" :y="b.y" :width="b.w" :height="b.h" rx="1" class="fill-chart-1/40" />
        <polyline v-for="(l, i) in lines" :key="i" :points="l" fill="none" stroke-width="1.5" stroke-linejoin="round" class="stroke-chart-2" />
    </svg>
    <span v-else class="text-2xs text-muted-foreground">{{ t('ads.control.spark.empty') }}</span>
</template>
