<script setup lang="ts">
/** Derived material status: live green, in review amber, paused neutral, new info, retired neutral. */
import StatusChip from '@/components/crm/StatusChip.vue';
import { useI18n } from '@/composables/useI18n';
import type { MaterialStatus } from '@/types/ads';

const props = defineProps<{ status: MaterialStatus }>();

const { t } = useI18n();
const tone = { live: 'positive', in_review: 'warning', paused: 'neutral', new: 'info', retired: 'neutral' } as const;
</script>

<template>
    <StatusChip :label="t(`ads.materials.status.${props.status}`)" :tone="tone[props.status] ?? 'neutral'" dot />
</template>
