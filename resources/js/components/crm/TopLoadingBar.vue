<script setup lang="ts">
import { useLoadingBar } from '@/composables/useLoadingBar';

/**
 * The single global loading bar (human bot flow Task 5): pinned to the top edge
 * above the sidebar and dialogs, growing from the reading start — right in
 * Arabic, left in English — in the Business Suite primary blue, which already
 * has light and dark values in app.css.
 */
const { active, progress, instant } = useLoadingBar();
</script>

<template>
    <div class="pointer-events-none fixed inset-x-0 top-0 z-[200] h-[3px]" aria-hidden="true">
        <div
            class="absolute start-0 top-0 h-full rounded-e-full bg-primary duration-200 ease-out motion-reduce:transition-none"
            :class="[active ? 'opacity-100' : 'opacity-0', instant ? 'transition-none' : 'transition-[width,opacity]']"
            :style="{ width: `${progress}%`, boxShadow: '0 0 10px hsl(var(--primary) / 0.55), 0 0 4px hsl(var(--primary) / 0.4)' }"
        />
    </div>
</template>
