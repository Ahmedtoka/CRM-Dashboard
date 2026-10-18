<script setup lang="ts">
/**
 * The Ads panel (DESIGN §3 Panel): a white surface with a hairline, a header (title + count badge + one line + an
 * end slot for «افتحي الكل») and a body. No shadow; border only (§2.5 e0).
 */
import NavBadge from '@/components/crm/NavBadge.vue';
import { useId } from 'vue';

withDefaults(defineProps<{ title?: string; sub?: string; count?: number | null; urgent?: boolean; flush?: boolean; tag?: 'section' | 'div' }>(), {
    title: undefined,
    sub: undefined,
    count: null,
    urgent: false,
    flush: false,
    tag: 'section',
});

const id = useId();
</script>

<template>
    <component :is="tag" class="min-w-0 overflow-hidden rounded-lg border border-line bg-card" :aria-labelledby="title ? id : undefined">
        <header v-if="title || $slots.end" class="flex flex-wrap items-center gap-x-2 gap-y-1 px-4 pb-2.5 pt-3.5">
            <h2 v-if="title" :id="id" class="text-base font-bold leading-snug text-foreground">{{ title }}</h2>
            <NavBadge v-if="count" :count="count" :urgent="urgent" />
            <span v-if="sub" class="text-xs text-fg-3">{{ sub }}</span>
            <span v-if="$slots.end" class="ms-auto flex items-center gap-2"><slot name="end" /></span>
        </header>
        <div :class="flush ? '' : 'px-4 pb-4'"><slot /></div>
    </component>
</template>
