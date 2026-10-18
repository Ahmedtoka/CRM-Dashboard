<script setup lang="ts">
import DateRangePicker from '@/components/crm/DateRangePicker.vue';
import { useI18n } from '@/composables/useI18n';
import type { SharedData } from '@/types';
import type { ReportRange } from '@/types/admin';
import type { PlatformValue } from '@/types/crm';
import { usePage } from '@inertiajs/vue3';

/**
 * The report header's period control (mockup «الفترة»): the range presets, and the platform select when the page
 * filters by platform. Sits in PageHeader's actions slot; wraps on a phone.
 */
withDefaults(defineProps<{ range: ReportRange; platform?: PlatformValue | null; showPlatform?: boolean }>(), {
    platform: null,
    showPlatform: true,
});
const emit = defineEmits<{ change: [range: ReportRange, platform: PlatformValue | null] }>();

const { t } = useI18n();
const page = usePage<SharedData>();
</script>

<template>
    <div class="flex min-w-0 flex-wrap items-center gap-2" data-period-controls>
        <DateRangePicker :model-value="range" @update:model-value="emit('change', $event, platform)" />
        <select
            v-if="showPlatform"
            :value="platform ?? ''"
            class="h-9 rounded-md border border-line-strong bg-card px-2 text-13 text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            :aria-label="t('ui.platforms')"
            @change="emit('change', range, (($event.target as HTMLSelectElement).value || null) as PlatformValue | null)"
        >
            <option value="">{{ t('ui.all_platforms') }}</option>
            <option v-for="p in page.props.platforms" :key="p.value" :value="p.value">{{ p.label }}</option>
        </select>
    </div>
</template>
