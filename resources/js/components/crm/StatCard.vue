<script setup lang="ts">
withDefaults(defineProps<{ label: string; value: string | number; hint?: string; tone?: 'default' | 'positive' | 'warning' | 'negative' }>(), {
    tone: 'default',
});
</script>

<template>
    <div class="rounded-lg border bg-card px-3 py-2">
        <p class="text-2xs font-medium text-muted-foreground">{{ label }}</p>
        <p
            class="mt-0.5 text-base font-semibold tabular-nums"
            :class="{ 'text-emerald-600': tone === 'positive', 'text-amber-600': tone === 'warning', 'text-red-600': tone === 'negative' }"
        >
            {{ value }}
        </p>
        <p v-if="hint" class="text-2xs text-muted-foreground">{{ hint }}</p>
        <slot />
    </div>
</template>
