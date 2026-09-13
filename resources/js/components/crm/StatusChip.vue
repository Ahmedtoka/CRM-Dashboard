<script setup lang="ts">
withDefaults(defineProps<{ label: string; tone?: 'neutral' | 'positive' | 'warning' | 'negative' | 'info' }>(), { tone: 'neutral' });

const tones = {
    neutral: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
    positive: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
    warning: 'bg-amber-50 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    negative: 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-300',
    info: 'bg-indigo-50 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300',
};
</script>

<template>
    <span class="inline-flex h-5 shrink-0 items-center whitespace-nowrap rounded px-1.5 text-2xs font-medium" :class="tones[tone]">{{ label }}</span>
</template>
