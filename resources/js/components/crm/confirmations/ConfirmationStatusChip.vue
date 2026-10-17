<script setup lang="ts">
import StatusChip from '@/components/crm/StatusChip.vue';
import { useI18n } from '@/composables/useI18n';
import { CONFIRMATION_TONE, confirmationDetail, confirmationStatusLabel } from '@/lib/orderConfirmations';
import type { ConfirmationStatus } from '@/types/orderConfirmations';
import { computed } from 'vue';

/** The WhatsApp confirmation state of an order; a skipped or failed one carries its reason on hover and for readers. */
const props = withDefaults(defineProps<{ status: ConfirmationStatus; skipReason?: string | null; lastError?: string | null }>(), {
    skipReason: null,
    lastError: null,
});
const { t } = useI18n();

const detail = computed(() => confirmationDetail(t, { status: props.status, skip_reason: props.skipReason, last_error: props.lastError }));
</script>

<template>
    <span class="inline-flex" :title="detail || undefined" data-confirmation-chip :data-status="status">
        <StatusChip :label="confirmationStatusLabel(t, status)" :tone="CONFIRMATION_TONE[status] ?? 'neutral'" dot />
        <span v-if="detail" class="sr-only">{{ detail }}</span>
    </span>
</template>
