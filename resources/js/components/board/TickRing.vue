<script setup lang="ts">
import { useBoardContext } from '@/lib/board/context';
import { computed } from 'vue';

/** A window's silence ring: it empties as the clock runs out (full while no clock runs). A leaf of the ticker. */
const props = withDefaults(defineProps<{ until?: string | null; total: number }>(), { until: null });

const board = useBoardContext();
const RING = 106.8;

const dash = computed(() => {
    const at = Date.parse(props.until ?? '');
    if (Number.isNaN(at)) return '0.0';
    const left = Math.max(0, (at - board.now.value) / 1000);

    return (RING * (1 - Math.min(1, left / Math.max(1, props.total)))).toFixed(1);
});
</script>

<template>
    <svg class="ring" viewBox="0 0 40 40" aria-hidden="true">
        <circle class="bg" cx="20" cy="20" r="17" />
        <circle class="fg" cx="20" cy="20" r="17" :stroke-dasharray="RING" :stroke-dashoffset="dash" />
    </svg>
</template>
