<script setup lang="ts">
/** One health badge (U 3.3): text + tone, never colour only. */
import StatusChip from '@/components/crm/StatusChip.vue';
import { useI18n } from '@/composables/useI18n';
import type { AdHealthKey } from '@/types/ads';

defineProps<{ kind: AdHealthKey }>();
const { t } = useI18n();
const TONES: Record<AdHealthKey, 'negative' | 'warning' | 'neutral' | 'positive'> = {
    out_of_stock: 'negative',
    losing: 'negative',
    tired: 'warning',
    too_early: 'neutral',
    parent_paused: 'neutral',
    winning: 'positive',
};
</script>

<template>
    <StatusChip :label="t(`ads.control.badge.${kind}`)" :tone="TONES[kind]" />
</template>
