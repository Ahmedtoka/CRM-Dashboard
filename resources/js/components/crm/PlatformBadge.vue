<script setup lang="ts">
import { usePlatform } from '@/composables/usePlatform';
import type { PlatformValue } from '@/types/crm';

const props = withDefaults(defineProps<{ platform: PlatformValue | null | undefined; showLabel?: boolean; size?: 'xs' | 'sm' }>(), {
    showLabel: false,
    size: 'sm',
});

const info = usePlatform(() => props.platform);
</script>

<template>
    <span
        class="inline-flex shrink-0 items-center gap-1 rounded-full border bg-card font-medium text-muted-foreground"
        :class="size === 'xs' ? 'h-4 px-1 text-2xs' : 'h-5 px-1.5 text-xs'"
        :title="info.label"
    >
        <component :is="info.icon" :class="size === 'xs' ? 'size-2.5' : 'size-3'" :style="{ color: info.color }" aria-hidden="true" />
        <span v-if="showLabel">{{ info.label }}</span>
        <span v-else class="sr-only">{{ info.label }}</span>
    </span>
</template>
