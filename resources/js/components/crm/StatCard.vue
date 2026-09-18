<script setup lang="ts">
withDefaults(defineProps<{ label: string; value: string | number; hint?: string; tone?: 'default' | 'positive' | 'warning' | 'negative' }>(), {
    tone: 'default',
});
</script>

<template>
    <div
        class="rounded-lg bg-card px-4 py-3 shadow-card"
        :class="{ 'border-s-4 border-success': tone === 'positive', 'border-s-4 border-warning': tone === 'warning' }"
    >
        <p class="text-2xs font-medium text-muted-foreground">{{ label }}</p>
        <p class="mt-0.5 text-xl font-bold tabular-nums text-foreground" :class="{ 'text-destructive': tone === 'negative' }">
            {{ value }}
        </p>
        <p v-if="hint" class="text-2xs text-muted-foreground">{{ hint }}</p>
        <slot />
    </div>
</template>
