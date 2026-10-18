<script setup lang="ts">
/**
 * A settings panel (DESIGN §3 Panel): white surface, 1px line, 12px radius, no shadow. An optional header (title +
 * one line + an action at the far end); the body is usually a run of SettingsRow.
 */
withDefaults(defineProps<{ title?: string; description?: string; flush?: boolean }>(), { title: undefined, description: undefined, flush: false });
</script>

<template>
    <section class="rounded-lg border border-line bg-card" :aria-label="title">
        <header v-if="title || $slots.actions" class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-line px-4 pb-2.5 pt-3.5">
            <div class="min-w-0 flex-1">
                <h2 v-if="title" class="text-base font-bold leading-snug">{{ title }}</h2>
                <p v-if="description" class="mt-0.5 text-13 text-fg-2">{{ description }}</p>
            </div>
            <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2"><slot name="actions" /></div>
        </header>
        <div :class="flush ? '' : 'px-4 py-1'"><slot /></div>
    </section>
</template>
