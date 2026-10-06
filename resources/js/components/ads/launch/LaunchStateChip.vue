<script setup lang="ts">
import StatusChip from '@/components/crm/StatusChip.vue';
import { useI18n } from '@/composables/useI18n';
import { LAUNCH_TONES } from '@/lib/launch';
import type { LaunchState } from '@/types/ads';

const props = defineProps<{ state: LaunchState }>();
const { t } = useI18n();
</script>

<template>
    <StatusChip :label="t(`ads.launch.state.${props.state}`)" :tone="LAUNCH_TONES[props.state]" dot />
</template>
