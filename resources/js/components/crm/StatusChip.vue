<script setup lang="ts">
import type { Component } from 'vue';

/**
 * One pill per status (DESIGN §3 StatusChip): one tone per meaning across the whole system, the word always with the
 * colour. ok · warn · danger · info · brand · note · neutral · outline; the older names (positive, warning, negative,
 * overdue) map onto them so every existing caller keeps working.
 */
type Tone = 'ok' | 'warn' | 'danger' | 'info' | 'brand' | 'note' | 'neutral' | 'outline' | 'positive' | 'warning' | 'negative' | 'overdue';

withDefaults(defineProps<{ label: string; tone?: Tone; icon?: Component; dot?: boolean; size?: 'md' | 'sm' }>(), {
    tone: 'neutral',
    icon: undefined,
    dot: false,
    size: 'md',
});

// Text on its soft tint: 4.9–5.5:1 light, 6.8–9.2:1 dark (DESIGN §2.1).
const tones: Record<Tone, string> = {
    ok: 'bg-ok-soft text-ok',
    positive: 'bg-ok-soft text-ok',
    warn: 'bg-warn-soft text-warn',
    warning: 'bg-warn-soft text-warn',
    danger: 'bg-danger-soft text-danger',
    negative: 'bg-danger-soft text-danger',
    overdue: 'bg-danger-soft text-danger',
    info: 'bg-info-soft text-info',
    brand: 'bg-brand-soft text-brand-ink',
    note: 'bg-note-soft text-note-ink',
    neutral: 'bg-sunken text-fg-2',
    outline: 'border border-line-strong bg-transparent text-fg-2',
};
</script>

<template>
    <span
        class="inline-flex shrink-0 items-center gap-1 whitespace-nowrap rounded-full font-semibold"
        :class="[tones[tone], size === 'sm' ? 'h-5 px-[7px] text-[11px]' : 'h-6 px-[9px] text-xs']"
        :data-tone="tone"
    >
        <span v-if="dot" class="size-[7px] rounded-full bg-current" aria-hidden="true" />
        <component :is="icon" v-else-if="icon" class="size-3.5" aria-hidden="true" />
        {{ label }}
    </span>
</template>
