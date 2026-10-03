<script setup lang="ts">
import type { Component } from 'vue';

withDefaults(
    defineProps<{ label: string; tone?: 'neutral' | 'positive' | 'warning' | 'negative' | 'info' | 'overdue'; icon?: Component; dot?: boolean }>(),
    { tone: 'neutral', icon: undefined, dot: false },
);

// Text colour carries the meaning in both themes: ≥ 4.5:1 on the tinted background.
const tones = {
    neutral: 'bg-muted text-muted-foreground',
    positive: 'bg-success/15 text-emerald-800 dark:bg-success/25 dark:text-emerald-200',
    warning: 'bg-warning/20 text-amber-900 dark:bg-warning/25 dark:text-amber-100',
    negative: 'bg-destructive/10 text-destructive dark:bg-destructive/25 dark:text-red-200',
    info: 'bg-info/10 text-blue-800 dark:bg-info/25 dark:text-blue-100',
    overdue: 'bg-overdue/15 text-orange-800 dark:bg-overdue/25 dark:text-orange-100',
};
const dots = { neutral: 'bg-muted-foreground', positive: 'bg-success', warning: 'bg-warning', negative: 'bg-destructive', info: 'bg-info', overdue: 'bg-overdue' };
</script>

<template>
    <span class="inline-flex h-5 shrink-0 items-center gap-1 whitespace-nowrap rounded-full px-2 text-2xs font-medium" :class="tones[tone]">
        <span v-if="dot" class="size-1.5 rounded-full" :class="dots[tone]" aria-hidden="true" />
        <component :is="icon" v-else-if="icon" class="size-3" aria-hidden="true" />
        {{ label }}
    </span>
</template>
