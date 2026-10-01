<script setup lang="ts">
import { CircleCheck, CircleX, Info, TriangleAlert } from 'lucide-vue-next';
import { computed, type Component } from 'vue';

const props = withDefaults(defineProps<{ tone?: 'info' | 'warning' | 'success' | 'danger'; title?: string; icon?: Component }>(), {
    tone: 'info',
    title: undefined,
    icon: undefined,
});

const tones = {
    info: 'bg-info/10 text-blue-900 dark:bg-info/20 dark:text-blue-100',
    warning: 'bg-warning/15 text-amber-900 dark:bg-warning/20 dark:text-amber-100',
    success: 'bg-success/12 text-emerald-900 dark:bg-success/20 dark:text-emerald-100',
    danger: 'bg-destructive/10 text-destructive dark:bg-destructive/20 dark:text-red-200',
};
const icons = { info: Info, warning: TriangleAlert, success: CircleCheck, danger: CircleX };
const glyph = computed(() => props.icon ?? icons[props.tone]);
const role = computed(() => (props.tone === 'warning' || props.tone === 'danger' ? 'alert' : 'status'));
</script>

<template>
    <div class="flex items-start gap-2 rounded-lg p-3 text-xs" :class="tones[tone]" :role="role">
        <component :is="glyph" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
        <div class="min-w-0 flex-1 space-y-0.5">
            <p v-if="title" class="font-semibold">{{ title }}</p>
            <div><slot /></div>
        </div>
        <div v-if="$slots.actions" class="flex shrink-0 items-center gap-2"><slot name="actions" /></div>
    </div>
</template>
