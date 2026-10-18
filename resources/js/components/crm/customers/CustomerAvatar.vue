<script setup lang="ts">
import { usePlatform } from '@/composables/usePlatform';
import type { PlatformValue } from '@/types/crm';
import { computed, ref } from 'vue';

/**
 * A customer's picture, or her initial on the soft brand tint, with the channel she came from as a small dot on the
 * corner (DESIGN §3 ChannelDot). Decorative: the name next to it carries the meaning.
 */
const props = withDefaults(defineProps<{ name: string | null; src?: string | null; platform?: PlatformValue | null; size?: 'sm' | 'lg' }>(), {
    src: null,
    platform: null,
    size: 'sm',
});

const broken = ref(false);
const initial = computed(() => (props.name ?? '').trim().charAt(0) || '؟');
const channel = usePlatform(() => props.platform);
</script>

<template>
    <span class="relative inline-flex shrink-0" :class="size === 'lg' ? 'size-14' : 'size-8'" aria-hidden="true" data-customer-avatar>
        <img v-if="src && !broken" :src="src" alt="" class="size-full rounded-full object-cover" loading="lazy" @error="broken = true" />
        <span
            v-else
            class="grid size-full place-items-center rounded-full bg-brand-soft font-bold text-brand-ink"
            :class="size === 'lg' ? 'text-xl' : 'text-13'"
            >{{ initial }}</span
        >
        <span
            v-if="platform"
            class="absolute -bottom-0.5 -end-0.5 grid place-items-center rounded-full bg-card ring-2 ring-card"
            :class="size === 'lg' ? 'size-5' : 'size-4'"
        >
            <component :is="channel.icon" :class="size === 'lg' ? 'size-3.5' : 'size-3'" :style="{ color: channel.color }" />
        </span>
    </span>
</template>
