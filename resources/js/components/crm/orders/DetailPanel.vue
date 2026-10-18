<script setup lang="ts">
import type { Component } from 'vue';

/**
 * A panel on a detail page (DESIGN §3 Panel): a bordered surface, a header with the title, an optional line under it
 * and actions at the far end, then the body. Used by the order, customer and case pages so their panels read alike.
 */
withDefaults(defineProps<{ title?: string; sub?: string; icon?: Component; flush?: boolean; tone?: 'default' | 'note' }>(), {
    title: undefined,
    sub: undefined,
    icon: undefined,
    flush: false,
    tone: 'default',
});
</script>

<template>
    <section
        class="min-w-0 overflow-hidden rounded-lg border"
        :class="tone === 'note' ? 'border-note/30 bg-note-soft' : 'border-line bg-card'"
        :aria-label="title"
    >
        <header v-if="title || $slots.end" class="flex min-h-11 items-center gap-2 px-4 pb-2.5 pt-3.5" :class="flush ? 'border-b border-line' : ''">
            <component :is="icon" v-if="icon" class="size-4 shrink-0" :class="tone === 'note' ? 'text-note-ink' : 'text-fg-2'" aria-hidden="true" />
            <div class="min-w-0 flex-1">
                <h2 class="truncate text-base font-bold leading-snug text-foreground">{{ title }}</h2>
                <p v-if="sub" class="truncate text-xs text-fg-2">{{ sub }}</p>
            </div>
            <div v-if="$slots.end" class="flex shrink-0 items-center gap-1.5"><slot name="end" /></div>
        </header>
        <div :class="flush ? '' : 'px-4 pb-4'"><slot /></div>
    </section>
</template>
