<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { cairoDayKey, cairoToday, formatClock, formatDateTime } from '@/lib/format';
import { Clock } from 'lucide-vue-next';
import { computed } from 'vue';

/** The one freshness indicator per page: «البيانات لحد 10:40» (Cairo). Banners are only for problems. */
const props = defineProps<{ at: string | null }>();

const { t, locale } = useI18n();
const label = computed(() => {
    if (!props.at) return null;
    const time = cairoDayKey(props.at) === cairoToday() ? formatClock(props.at, locale.value) : formatDateTime(props.at, locale.value);

    return t('ui.freshness', { time });
});
</script>

<template>
    <span v-if="label" class="inline-flex h-6 shrink-0 items-center gap-1 rounded-full bg-elevated px-2 text-2xs font-medium text-muted-foreground" :title="at ?? undefined">
        <Clock class="size-3" aria-hidden="true" />{{ label }}
    </span>
</template>
