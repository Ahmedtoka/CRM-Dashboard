<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { formatStat } from '@/lib/format';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{ label: string; value: string | number | null; hint?: string; tone?: 'default' | 'positive' | 'warning' | 'negative' }>(),
    { tone: 'default', hint: undefined },
);

const { locale } = useI18n();

// A caller that has already formatted its value (money, a duration) passes a string; a bare number
// is formatted here in the page's digits, and `null` (no data) reads «—», never a lone «٠».
const shown = computed(() => (typeof props.value === 'string' ? props.value : formatStat(props.value, locale.value)));
</script>

<template>
    <div
        class="min-w-0 rounded-lg bg-card px-4 py-3 shadow-card"
        :class="{ 'border-s-4 border-success': tone === 'positive', 'border-s-4 border-warning': tone === 'warning' }"
    >
        <p class="text-2xs font-medium text-muted-foreground">{{ label }}</p>
        <p
            class="mt-0.5 text-lg font-bold tabular-nums [overflow-wrap:anywhere] text-foreground sm:text-xl"
            :class="{ 'text-destructive': tone === 'negative', 'text-muted-foreground': shown === '—' }"
        >
            {{ shown }}
        </p>
        <p v-if="hint" class="text-2xs text-muted-foreground">{{ hint }}</p>
        <slot />
    </div>
</template>
