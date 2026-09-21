<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { formatCount } from '@/lib/format';
import { computed } from 'vue';

const props = withDefaults(defineProps<{ label: string; value: string | number; hint?: string; tone?: 'default' | 'positive' | 'warning' | 'negative' }>(), {
    tone: 'default',
});

const { locale } = useI18n();

// A caller that has already formatted its value (money, a duration) passes a string;
// a bare number is formatted here so no card shows Latin digits in Arabic.
const shown = computed(() => (typeof props.value === 'number' ? formatCount(props.value, locale.value) : props.value));
</script>

<template>
    <div
        class="rounded-lg bg-card px-4 py-3 shadow-card"
        :class="{ 'border-s-4 border-success': tone === 'positive', 'border-s-4 border-warning': tone === 'warning' }"
    >
        <p class="text-2xs font-medium text-muted-foreground">{{ label }}</p>
        <p class="mt-0.5 text-xl font-bold tabular-nums text-foreground" :class="{ 'text-destructive': tone === 'negative' }">
            {{ shown }}
        </p>
        <p v-if="hint" class="text-2xs text-muted-foreground">{{ hint }}</p>
        <slot />
    </div>
</template>
