<script setup lang="ts">
import { CircleAlert } from 'lucide-vue-next';
import type { Component } from 'vue';

/**
 * Empty and error states in one language (DESIGN §3 EmptyState): what happened, what to do, one button (the `action`
 * slot). `error` says what failed and how to fix it, never a bare «حصل خطأ» and never an apology.
 */
withDefaults(defineProps<{ icon?: Component; title: string; body?: string; variant?: 'empty' | 'error' }>(), {
    icon: undefined,
    body: undefined,
    variant: 'empty',
});
</script>

<template>
    <div
        class="flex flex-1 flex-col items-center justify-center gap-2.5 px-6 py-10 text-center"
        :role="variant === 'error' ? 'alert' : undefined"
        :data-empty-variant="variant"
    >
        <div
            v-if="icon || variant === 'error'"
            class="mb-1 grid size-14 place-items-center rounded-xl"
            :class="variant === 'error' ? 'bg-danger-soft text-danger' : 'bg-brand-soft text-brand-ink'"
        >
            <component :is="icon ?? CircleAlert" class="size-[26px]" aria-hidden="true" />
        </div>
        <p class="text-base font-bold text-foreground">{{ title }}</p>
        <p v-if="body" class="max-w-[46ch] text-[13.5px] text-fg-2">{{ body }}</p>
        <slot />
        <div v-if="$slots.action" class="mt-1.5 flex flex-wrap items-center justify-center gap-2"><slot name="action" /></div>
    </div>
</template>
