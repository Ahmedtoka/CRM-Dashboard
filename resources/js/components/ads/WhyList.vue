<script setup lang="ts">
/** The written reasons behind a tier (WinnerScorer reasons), translated here with the numbers formatted for the locale. */
import { useI18n } from '@/composables/useI18n';
import { formatAdsMoney, formatPct, formatQty, formatRoas } from '@/lib/ads';
import type { AdReason } from '@/types/ads';
import { computed } from 'vue';

const props = withDefaults(defineProps<{ reasons: AdReason[]; currency?: string }>(), { currency: 'EGP' });

const { t, locale } = useI18n();

const lines = computed(() =>
    props.reasons.map((r) => {
        const p = r.params;
        const v: Record<string, string | number> = {};
        for (const [k, val] of Object.entries(p)) {
            v[k] =
                k === 'roas' || k === 'threshold'
                    ? formatRoas(val, locale.value)
                    : k === 'ctr'
                      ? formatPct(val, locale.value)
                      : k === 'spend' || k === 'cpa'
                        ? formatAdsMoney(val, locale.value, props.currency)
                        : k === 'pct' || k === 'ctr_drop'
                          ? formatPct(val / 100, locale.value, 0)
                          : k === 'frequency'
                            ? formatQty(val, locale.value)
                            : val;
        }

        return { key: r.key, text: t(`ads.reasons.${r.key}`, v), bad: ['roas_below', 'recent_down', 'fatigue'].includes(r.key) };
    }),
);
</script>

<template>
    <ul class="space-y-0.5 text-2xs leading-snug">
        <li v-for="l in lines" :key="l.key" class="flex items-start gap-1.5" :class="l.bad ? 'text-destructive' : 'text-muted-foreground'">
            <span class="mt-1 size-1 shrink-0 rounded-full bg-current" aria-hidden="true" />
            <span>{{ l.text }}</span>
        </li>
    </ul>
</template>
