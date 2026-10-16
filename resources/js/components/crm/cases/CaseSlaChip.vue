<script setup lang="ts">
import StatusChip from '@/components/crm/StatusChip.vue';
import { useI18n } from '@/composables/useI18n';
import { formatAge, formatUntil } from '@/lib/format';
import type { Tone } from '@/lib/orderStatus';
import type { CaseSla } from '@/types/crm';
import { computed } from 'vue';

/**
 * The SLA of a case opened from a queue window (queue Part 2): time left, overdue for how long,
 * or whether it was resolved in time. Nothing for a case without an SLA.
 */
const props = defineProps<{ sla: CaseSla | null | undefined }>();
const { t, locale } = useI18n();

const chip = computed<{ label: string; tone: Tone } | null>(() => {
    const sla = props.sla;
    if (!sla || sla.state === null || sla.due_at === null) return null;
    const now = Date.now();

    switch (sla.state) {
        case 'open':
            return { label: t('cases.sla.left', { time: formatUntil(sla.due_at, locale.value, now) ?? '' }), tone: 'info' };
        case 'overdue':
            return { label: t('cases.sla.overdue', { time: formatAge((now - new Date(sla.due_at).getTime()) / 1000, locale.value) }), tone: 'negative' };
        case 'in_time':
            return { label: t('cases.sla.in_time'), tone: 'positive' };
        case 'late':
            return { label: t('cases.sla.late'), tone: 'warning' };
    }

    return null;
});
</script>

<template>
    <StatusChip v-if="chip" :label="chip.label" :tone="chip.tone" />
</template>
