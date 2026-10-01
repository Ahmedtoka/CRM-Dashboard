<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { useNow } from '@/composables/useNow';
import { formatDateTime, formatListStamp, formatSince } from '@/lib/format';
import { computed } from 'vue';

const props = withDefaults(defineProps<{ iso: string | null | undefined; mode?: 'since' | 'stamp' | 'datetime'; class?: string }>(), { mode: 'since', class: undefined });
const { locale } = useI18n();
const now = useNow();
const text = computed(() => {
    if (!props.iso) return '—';
    if (props.mode === 'datetime') return formatDateTime(props.iso, locale.value);

    return props.mode === 'stamp' ? formatListStamp(props.iso, locale.value, now.value) : formatSince(props.iso, locale.value, now.value);
});
</script>

<template>
    <time v-if="iso" :datetime="iso" :title="formatDateTime(iso, locale)" class="tabular-nums" :class="props.class">{{ text }}</time>
    <span v-else class="text-muted-foreground" :class="props.class">—</span>
</template>
