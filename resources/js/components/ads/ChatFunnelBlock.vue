<script setup lang="ts">
/** محادثات → وصلت لموظفة → أوردر → اتسلم → مرتجع, and why they did not buy (C 3.2, spec 5.3). */
import { Skeleton } from '@/components/ui/skeleton';
import { useI18n } from '@/composables/useI18n';
import { formatPct } from '@/lib/ads';
import { formatCount } from '@/lib/format';
import type { ChatFunnel, LostReason } from '@/types/ads';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        funnel: ChatFunnel | null;
        loading?: boolean;
        error?: boolean;
        /** Off when the host already titles the section (the ad drawer). */
        heading?: boolean;
        /** The empty line: one ad (drawer) or every ad of the filter (Numbers). */
        scope?: 'ad' | 'all';
    }>(),
    { loading: false, error: false, heading: true, scope: 'ad' },
);
const emit = defineEmits<{ retry: [] }>();
const { t, locale } = useI18n();

const share = (n: number, d: number) => (d > 0 ? n / d : null);
const stages = computed(() => {
    const f = props.funnel;
    if (!f) return [];
    return [
        { key: 'chats', value: f.chats, share: null },
        { key: 'to_agent', value: f.to_agent, share: share(f.to_agent, f.chats) },
        { key: 'orders', value: f.orders, share: share(f.orders, f.chats) },
        { key: 'delivered', value: f.delivered, share: share(f.delivered, f.orders) },
        { key: 'returned', value: f.returned, share: share(f.returned, f.orders) },
    ];
});
const reasons = computed(() => {
    const entries = Object.entries(props.funnel?.reasons ?? {}).filter(([, n]) => (n ?? 0) > 0) as [LostReason, number][];
    const total = entries.reduce((s, [, n]) => s + n, 0);
    return entries.sort((a, b) => b[1] - a[1]).map(([key, n]) => ({ key, n, share: share(n, total) ?? 0 }));
});
</script>

<template>
    <section class="space-y-3" :aria-label="t('ads.funnel.title')" data-chat-funnel>
        <h3 v-if="heading" class="text-sm font-semibold" :title="t('ads.funnel.multi_touch')">{{ t('ads.funnel.title') }}</h3>
        <div v-if="error && !funnel" class="space-y-1 text-xs" role="alert">
            <p>{{ t('ads.funnel.load_failed') }}</p>
            <button type="button" class="h-11 rounded-md px-2 text-primary underline" @click="emit('retry')">{{ t('ads.funnel.retry') }}</button>
        </div>
        <div v-else-if="loading && !funnel" class="grid grid-cols-2 gap-2 sm:grid-cols-5" aria-busy="true">
            <Skeleton v-for="i in 5" :key="i" class="h-12" />
        </div>
        <p v-else-if="!funnel || funnel.chats === 0" class="text-xs text-muted-foreground">{{ t(scope === 'all' ? 'ads.funnel.empty_all' : 'ads.funnel.empty') }}</p>
        <template v-else>
            <ol class="grid grid-cols-2 gap-2 sm:grid-cols-5">
                <li v-for="s in stages" :key="s.key" class="rounded-md bg-muted/60 px-2 py-1.5" :data-funnel-stage="s.key">
                    <p class="text-2xs text-muted-foreground">{{ t(`ads.funnel.${s.key}`) }}</p>
                    <p class="text-sm font-semibold tabular-nums">
                        {{ formatCount(s.value, locale) }}
                        <span v-if="s.share !== null" class="text-2xs font-normal text-muted-foreground">({{ formatPct(s.share, locale, 0) }})</span>
                    </p>
                </li>
            </ol>
            <div>
                <p class="mb-1 text-xs font-medium">{{ t('ads.funnel.why') }}</p>
                <p v-if="!reasons.length" class="text-xs text-muted-foreground">{{ t('ads.funnel.no_reasons') }}</p>
                <ul v-else class="space-y-1">
                    <li v-for="r in reasons" :key="r.key" class="grid grid-cols-[7rem_minmax(0,1fr)_3rem] items-center gap-2 text-xs" :data-funnel-reason="r.key">
                        <span class="truncate">{{ t(`outcomes.${r.key}`) }}</span>
                        <span class="h-2 rounded-full bg-muted" aria-hidden="true"><span class="block h-2 rounded-full bg-primary" :style="{ width: `${Math.round(r.share * 100)}%` }" /></span>
                        <span class="text-end tabular-nums">{{ formatPct(r.share, locale, 0) }}</span>
                    </li>
                </ul>
            </div>
        </template>
    </section>
</template>
