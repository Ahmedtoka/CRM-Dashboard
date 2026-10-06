<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { computed } from 'vue';

/** Determinate progress for long jobs (sync, bulk approve, backfill): «n من m». */
const props = withDefaults(defineProps<{ value: number; max: number; label?: string; /** What is counted: «٣ من ٥ حسابات». */ unit?: string }>(), {
    label: undefined,
    unit: undefined,
});

const { t } = useI18n();
const percent = computed(() => (props.max > 0 ? Math.min(100, Math.max(0, Math.round((props.value / props.max) * 100))) : 0));
const text = computed(() => [t('ui.progress', { n: props.value, m: props.max }), props.unit].filter(Boolean).join(' '));
</script>

<template>
    <div class="space-y-1">
        <div class="flex items-center justify-between gap-2 text-2xs text-muted-foreground">
            <span v-if="label" class="truncate">{{ label }}</span>
            <span class="ms-auto tabular-nums">{{ text }}</span>
        </div>
        <div
            class="h-1.5 overflow-hidden rounded-full bg-elevated"
            role="progressbar"
            aria-valuemin="0"
            :aria-valuenow="value"
            :aria-valuemax="max"
            :aria-valuetext="text"
            :aria-label="label"
        >
            <div class="h-full rounded-full bg-primary transition-[width] duration-300 motion-reduce:transition-none" :style="{ width: `${percent}%` }" />
        </div>
    </div>
</template>
