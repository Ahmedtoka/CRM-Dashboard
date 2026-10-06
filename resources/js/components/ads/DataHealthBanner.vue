<script setup lang="ts">
/**
 * One banner for the whole Ads page: the worst data problem first, then the others, at most three account names per
 * reason plus "and N more". Reasons arrive from the server (DataHealth::forFilter); the history-start and
 * under-review notes are added here, last.
 */
import Callout from '@/components/crm/Callout.vue';
import { useI18n } from '@/composables/useI18n';
import { formatNumber } from '@/i18n';
import type { AdsDataHealth } from '@/types/ads';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        dataHealth?: AdsDataHealth;
        numbersUnderReview?: boolean;
        clampedToHistory?: boolean;
    }>(),
    { dataHealth: () => ({ reasons: [] }), numbersUnderReview: false, clampedToHistory: false },
);

const { t, locale } = useI18n();

interface Line {
    key: string;
    text: string;
}

const lines = computed<Line[]>(() => {
    const out: Line[] = props.dataHealth.reasons.map((r) => {
        const names = r.accounts.join('، ');
        const more = r.more > 0 ? ` ${t('ads.health.and_more', { n: r.more })}` : '';
        const unverifiedMore = (r.unverified_more ?? 0) > 0 ? ` ${t('ads.health.and_more', { n: r.unverified_more ?? 0 })}` : '';
        const unverified = r.unverified?.length ? t('ads.health.unverified', { names: r.unverified.join('، ') }) + unverifiedMore : '';
        // accounts never judged are named once, in their own part
        const params = r.reason === 'stale' ? { hours: formatNumber(locale.value, r.hours ?? 3) } : undefined;
        const head = r.accounts.length ? `${t(`ads.health.reasons.${r.reason}`, params)}: ${names}${more}` : '';

        return { key: r.reason, text: [head, unverified].filter(Boolean).join(' · ') };
    });
    if (props.clampedToHistory) out.push({ key: 'history_start', text: t('ads.health.reasons.history_start') });
    if (props.numbersUnderReview) out.push({ key: 'under_review', text: t('ads.health.reasons.under_review') });

    return out;
});

const tone = computed<'danger' | 'warning' | 'info'>(() => {
    const first = props.dataHealth.reasons[0]?.reason;
    if (first === 'reconnect') return 'danger';

    return first ? 'warning' : 'info';
});
</script>

<template>
    <Callout v-if="lines.length" :tone="tone" :title="t('ads.health.title')" data-testid="ads-data-health">
        <ul class="space-y-0.5">
            <li v-for="(line, i) in lines" :key="line.key" :class="i === 0 ? 'font-medium' : ''">{{ line.text }}</li>
        </ul>
    </Callout>
</template>
