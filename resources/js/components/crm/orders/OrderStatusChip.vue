<script setup lang="ts">
import StatusChip from '@/components/crm/StatusChip.vue';
import { useI18n } from '@/composables/useI18n';
import { combinedStatus } from '@/lib/orderStatus';
import type { Order } from '@/types/crm';
import { computed } from 'vue';

const props = defineProps<{ order: Order }>();
const { t } = useI18n();
const status = computed(() => combinedStatus(props.order, t));
</script>

<template>
    <!-- The three families stay one hover away; screen readers get them as text. -->
    <span class="inline-flex" :title="status.detail || undefined">
        <StatusChip :label="status.label" :tone="status.tone" dot />
        <span v-if="status.detail" class="sr-only">{{ status.detail }}</span>
    </span>
</template>
