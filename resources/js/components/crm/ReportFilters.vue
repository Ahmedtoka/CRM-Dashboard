<script setup lang="ts">
import DateRangePicker from '@/components/crm/DateRangePicker.vue';
import { useI18n } from '@/composables/useI18n';
import type { SharedData } from '@/types';
import type { ReportRange } from '@/types/admin';
import type { PlatformValue } from '@/types/crm';
import { usePage } from '@inertiajs/vue3';

defineProps<{ range: ReportRange; platform: PlatformValue | null; showPlatform?: boolean }>();
const emit = defineEmits<{ change: [range: ReportRange, platform: PlatformValue | null] }>();

const { t } = useI18n();
const page = usePage<SharedData>();
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <DateRangePicker :model-value="range" @update:model-value="emit('change', $event, platform)" />
        <select
            v-if="showPlatform !== false"
            :value="platform ?? ''"
            class="h-8 rounded-md border border-input bg-background px-2 text-xs"
            :aria-label="t('ui.platforms')"
            @change="emit('change', range, (($event.target as HTMLSelectElement).value || null) as PlatformValue | null)"
        >
            <option value="">{{ t('ui.all_platforms') }}</option>
            <option v-for="p in page.props.platforms" :key="p.value" :value="p.value">{{ p.label }}</option>
        </select>
    </div>
</template>
