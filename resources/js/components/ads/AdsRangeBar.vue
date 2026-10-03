<script setup lang="ts">
/** Range (Cairo days) + platform + buyer for every Ads report; a change is an Inertia visit that keeps state and scroll. */
import DateRangePicker from '@/components/crm/DateRangePicker.vue';
import { useI18n } from '@/composables/useI18n';
import { AD_PLATFORM_LABELS, AD_PLATFORMS, type AdsQueryValue, visitAds } from '@/lib/ads';
import type { SharedData } from '@/types';
import type { ReportRange } from '@/types/admin';
import type { AdPlatformValue, AdsFilters, AdsOption } from '@/types/ads';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        filters: AdsFilters;
        platforms?: string[];
        /** Buyer options (supervisor+); the select is hidden for media buyers and when empty. */
        buyers?: AdsOption[];
        /** Page-specific params kept on a filter change (status, sort, per page…). The page number is always reset. */
        keep?: Record<string, AdsQueryValue>;
        showBuyer?: boolean;
    }>(),
    { platforms: undefined, buyers: () => [], keep: () => ({}), showBuyer: true },
);

const { t } = useI18n();
const page = usePage<SharedData>();

const platformOptions = computed(() => (props.platforms?.length ? props.platforms : AD_PLATFORMS) as AdPlatformValue[]);
const buyerVisible = computed(() => props.showBuyer && page.props.ads?.isBuyer !== true && props.buyers.length > 0);
const range = computed<ReportRange>(() => ({ from: props.filters.from, to: props.filters.to }));

function go(changes: Record<string, AdsQueryValue>): void {
    visitAds({
        ...props.keep,
        from: props.filters.from,
        to: props.filters.to,
        platform: props.filters.platform,
        buyer: props.filters.buyer,
        ...changes,
        page: null,
    });
}

const selectClass = 'h-9 rounded-md border border-input bg-background px-2 text-xs';
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <DateRangePicker month :model-value="range" @update:model-value="go({ from: $event.from, to: $event.to })" />
        <select
            :value="filters.platform ?? ''"
            :class="selectClass"
            :aria-label="t('ads.filters.platform')"
            @change="go({ platform: ($event.target as HTMLSelectElement).value || null })"
        >
            <option value="">{{ t('ui.all_platforms') }}</option>
            <option v-for="p in platformOptions" :key="p" :value="p">{{ AD_PLATFORM_LABELS[p] ?? p }}</option>
        </select>
        <select
            v-if="buyerVisible"
            :value="filters.buyer ?? ''"
            :class="selectClass"
            :aria-label="t('ads.filters.buyer')"
            @change="go({ buyer: ($event.target as HTMLSelectElement).value || null })"
        >
            <option value="">{{ t('ads.filters.all_buyers') }}</option>
            <option v-for="b in buyers" :key="b.id" :value="b.id">{{ b.name }}</option>
        </select>
    </div>
</template>
