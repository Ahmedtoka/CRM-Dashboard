<script setup lang="ts">
import DateRangePicker from '@/components/crm/DateRangePicker.vue';
import FilterBar from '@/components/crm/FilterBar.vue';
import { useI18n } from '@/composables/useI18n';
import { formatDate } from '@/lib/format';
import type { SharedData } from '@/types';
import type { ReportRange } from '@/types/admin';
import type { PlatformValue } from '@/types/crm';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/** The report pages' filter bar (no search): the range and the platform, and a line saying what is shown. */
const props = defineProps<{ range: ReportRange; platform: PlatformValue | null; showPlatform?: boolean }>();
const emit = defineEmits<{ change: [range: ReportRange, platform: PlatformValue | null] }>();

const { t, locale } = useI18n();
const page = usePage<SharedData>();

const platformLabel = computed(() =>
    props.platform ? (page.props.platforms.find((p) => p.value === props.platform)?.label ?? props.platform) : t('ui.all_platforms'),
);
const summary = computed(() => {
    const range = t('range.summary', { from: formatDate(props.range.from, locale.value), to: formatDate(props.range.to, locale.value) });

    return props.showPlatform === false ? range : `${range} · ${platformLabel.value}`;
});
</script>

<template>
    <FilterBar :chips="[]" :summary="summary">
        <template #inline>
            <DateRangePicker :model-value="range" @update:model-value="emit('change', $event, platform)" />
            <select
                v-if="showPlatform !== false"
                :value="platform ?? ''"
                class="h-9 rounded-md border border-input bg-background px-2 text-xs"
                :aria-label="t('ui.platforms')"
                @change="emit('change', range, (($event.target as HTMLSelectElement).value || null) as PlatformValue | null)"
            >
                <option value="">{{ t('ui.all_platforms') }}</option>
                <option v-for="p in page.props.platforms" :key="p.value" :value="p.value">{{ p.label }}</option>
            </select>
        </template>
    </FilterBar>
</template>
