<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { formatDateTime } from '@/lib/format';
import { timelineLabel } from '@/lib/orderConfirmations';
import type { ConfirmationTimelineStep } from '@/types/orderConfirmations';

/** Steps the confirmation reached, oldest first: scheduled → sent → reminded → answered / no answer → called. */
defineProps<{ steps: ConfirmationTimelineStep[] }>();
const { t, locale } = useI18n();

const BAD = ['no_response', 'failed', 'skipped'];
</script>

<template>
    <ol class="space-y-2" data-confirmation-timeline>
        <li v-for="(step, i) in steps" :key="`${step.key}-${i}`" class="flex items-start gap-2 text-xs" :data-step="step.key">
            <span
                class="mt-1 size-2 shrink-0 rounded-full"
                :class="BAD.includes(step.key) ? 'bg-overdue' : i === steps.length - 1 ? 'bg-primary' : 'bg-muted-foreground/50'"
                aria-hidden="true"
            />
            <span class="min-w-0 flex-1">
                <span class="block [overflow-wrap:anywhere]" dir="auto">{{ timelineLabel(t, step) }}</span>
                <span v-if="step.at" class="block text-2xs tabular-nums text-muted-foreground">{{ formatDateTime(step.at, locale) }}</span>
            </span>
        </li>
    </ol>
</template>
