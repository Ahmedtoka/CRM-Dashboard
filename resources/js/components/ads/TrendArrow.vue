<script setup lang="ts">
/** ROAS trend of the last 7 days vs the 7 before: arrow plus percentage (spend change in the tooltip). */
import { useI18n } from '@/composables/useI18n';
import { formatPct } from '@/lib/ads';
import type { AdTrend } from '@/types/ads';
import { ArrowDownRight, ArrowRight, ArrowUpRight } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{ trend: AdTrend }>();

const { t, locale } = useI18n();

const look = computed(
    () =>
        ({
            up: { icon: ArrowUpRight, cls: 'text-emerald-700 dark:text-emerald-300' },
            down: { icon: ArrowDownRight, cls: 'text-destructive' },
            flat: { icon: ArrowRight, cls: 'text-muted-foreground' },
        })[props.trend.dir],
);
const pct = (v: number | null) => (v === null ? null : formatPct(Math.abs(v) / 100, locale.value, 0));
const label = computed(() => pct(props.trend.roas_pct));
const tip = computed(() =>
    props.trend.spend_pct === null
        ? t('ads.trend.no_prior')
        : t('ads.trend.tip', { roas: label.value ?? '—', spend: pct(props.trend.spend_pct) ?? '—' }),
);
</script>

<template>
    <span class="inline-flex items-center gap-0.5 text-2xs font-semibold tabular-nums" :class="look.cls" :title="tip">
        <component :is="look.icon" class="rtl-flip size-3" aria-hidden="true" />
        <span>{{ label ?? t(`ads.trend.${trend.dir}`) }}</span>
        <span class="sr-only">{{ t(`ads.trend.${trend.dir}`) }}</span>
    </span>
</template>
