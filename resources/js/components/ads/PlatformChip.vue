<script setup lang="ts">
/** Ad platform chip (Meta / TikTok / Google): brand dot + label, optional trailing figure. */
import { AD_PLATFORM_COLORS, AD_PLATFORM_LABELS } from '@/lib/ads';
import type { AdPlatformValue } from '@/types/ads';
import { computed } from 'vue';

const props = withDefaults(defineProps<{ platform: AdPlatformValue | string | null | undefined; size?: 'xs' | 'sm' }>(), { size: 'sm' });

const color = computed(() => AD_PLATFORM_COLORS[props.platform as AdPlatformValue] ?? 'hsl(var(--muted-foreground))');
const label = computed(() => AD_PLATFORM_LABELS[props.platform as AdPlatformValue] ?? props.platform ?? '—');
</script>

<template>
    <span
        class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full border border-border bg-card font-medium text-foreground"
        :class="size === 'xs' ? 'h-5 px-1.5 text-2xs' : 'h-6 px-2 text-xs'"
    >
        <span class="size-2 rounded-full" :style="{ backgroundColor: color }" aria-hidden="true" />
        <span dir="ltr">{{ label }}</span>
        <slot />
    </span>
</template>
