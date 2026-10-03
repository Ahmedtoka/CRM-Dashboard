<script setup lang="ts">
/** Material status: activated green, not started amber, done blue. */
import StatusChip from '@/components/crm/StatusChip.vue';
import { useI18n } from '@/composables/useI18n';
import type { MaterialStatus } from '@/types/ads';
import { computed } from 'vue';

const props = defineProps<{ status: MaterialStatus }>();

const { t } = useI18n();
const tone = computed(() => ({ activated: 'positive', not_started: 'warning', done: 'info' }) as const);
</script>

<template>
    <StatusChip :label="t(`ads.materials.status.${status}`)" :tone="tone[props.status] ?? 'neutral'" dot />
</template>
