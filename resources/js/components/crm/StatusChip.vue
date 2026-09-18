<script setup lang="ts">
withDefaults(defineProps<{ label: string; tone?: 'neutral' | 'positive' | 'warning' | 'negative' | 'info' }>(), { tone: 'neutral' });

const tones = {
    neutral: 'bg-muted text-muted-foreground',
    positive: 'bg-success/15 text-foreground',
    warning: 'bg-warning/20 text-foreground',
    negative: 'bg-destructive/10 text-destructive',
    info: 'bg-surface-accent text-foreground',
};
</script>

<template>
    <span class="inline-flex h-5 shrink-0 items-center whitespace-nowrap rounded-full px-2 text-2xs font-medium" :class="tones[tone]">{{ label }}</span>
</template>
