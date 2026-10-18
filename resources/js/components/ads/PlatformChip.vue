<script setup lang="ts">
/**
 * Ad platform chip (Meta / TikTok / Google): a small brand tile + the name, optional trailing figure. Every platform
 * gets the same tile, weight and size so TikTok reads first-class next to Meta (round 2026-10-17 phase 5).
 */
import { AD_PLATFORM_LABELS } from '@/lib/ads';
import type { AdPlatformValue } from '@/types/ads';
import { computed } from 'vue';

const props = withDefaults(defineProps<{ platform: AdPlatformValue | string | null | undefined; size?: 'xs' | 'sm'; iconOnly?: boolean }>(), {
    size: 'sm',
    iconOnly: false,
});

const known = computed(() => (['meta', 'tiktok', 'google'] as string[]).includes(String(props.platform)) ? (props.platform as AdPlatformValue) : null);
const label = computed(() => (known.value ? AD_PLATFORM_LABELS[known.value] : (props.platform ?? '—')));
/** Tile fills: the platform's own colour (TikTok's black gets a hairline so it holds on the dark theme). */
const TILE: Record<AdPlatformValue, string> = {
    meta: 'bg-[#0866FF] text-white',
    tiktok: 'bg-[#111014] text-white ring-1 ring-inset ring-white/25',
    google: 'bg-white text-[#1A73E8] ring-1 ring-inset ring-line-strong',
};
</script>

<template>
    <span
        data-platform-chip
        :data-platform="known ?? undefined"
        class="inline-flex shrink-0 items-center whitespace-nowrap font-medium text-foreground"
        :class="[
            iconOnly ? '' : 'gap-1.5 rounded-full border border-line bg-card',
            iconOnly ? '' : size === 'xs' ? 'h-6 pe-2 ps-1 text-2xs' : 'h-7 pe-2.5 ps-1 text-xs',
        ]"
        :title="iconOnly ? String(label) : undefined"
    >
        <span
            class="inline-grid shrink-0 place-items-center rounded-full"
            :class="[known ? TILE[known] : 'bg-sunken text-fg-2', size === 'xs' ? 'size-4' : 'size-5']"
            aria-hidden="true"
        >
            <!-- Meta: the loop -->
            <svg v-if="known === 'meta'" viewBox="0 0 24 24" class="size-[70%]" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round">
                <path d="M3.5 15.5c0-4 2-8 4.4-8 3.3 0 5.6 9 8.4 9 1.8 0 2.7-1.6 2.7-3.6 0-3.1-1.7-5.4-3.6-5.4-2.7 0-4.8 5-6.8 7.6-.9 1.2-1.8 1.9-2.8 1.9-1.4 0-2.3-.6-2.3-1.5Z" />
            </svg>
            <!-- TikTok: the note -->
            <svg v-else-if="known === 'tiktok'" viewBox="0 0 24 24" class="size-[64%]" fill="currentColor">
                <path d="M13.2 3h3.1c.2 2.1 1.6 3.7 3.7 3.9v3.1c-1.4 0-2.7-.4-3.8-1.1v6.2c0 3.2-2.5 5.6-5.6 5.6S5 18.3 5 15.2s2.6-5.7 5.9-5.5v3.2c-1.4-.3-2.7.8-2.7 2.3 0 1.3 1 2.4 2.4 2.4s2.6-1.1 2.6-2.6V3Z" />
            </svg>
            <!-- Google: the G -->
            <svg v-else-if="known === 'google'" viewBox="0 0 24 24" class="size-[70%]" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round">
                <path d="M19 12h-6.5M19 12a7 7 0 1 1-2-4.9" />
            </svg>
        </span>
        <span v-if="!iconOnly" dir="ltr">{{ label }}</span>
        <span v-else class="sr-only">{{ label }}</span>
        <slot />
    </span>
</template>
