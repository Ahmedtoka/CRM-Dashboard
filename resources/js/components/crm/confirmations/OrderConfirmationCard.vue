<script setup lang="ts">
import CallOutcomeActions from '@/components/crm/confirmations/CallOutcomeActions.vue';
import ConfirmationStatusChip from '@/components/crm/confirmations/ConfirmationStatusChip.vue';
import ConfirmationTimeline from '@/components/crm/confirmations/ConfirmationTimeline.vue';
import { useI18n } from '@/composables/useI18n';
import type { OrderConfirmationBlock } from '@/types/orderConfirmations';
import { Link, router } from '@inertiajs/vue3';
import { ClipboardList, ListChecks } from 'lucide-vue-next';

/** The order page's WhatsApp confirmation (spec 2026-10-16): status, timeline, the requested change and its case. */
defineProps<{ confirmation: OrderConfirmationBlock }>();
const { t } = useI18n();
</script>

<template>
    <section
        class="space-y-2.5 rounded-lg bg-card p-3 text-xs shadow-card"
        :aria-label="t('order_confirmations.block.title')"
        data-order-confirmation
    >
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="flex items-center gap-1.5 font-medium">
                <ListChecks class="size-3.5 text-primary" aria-hidden="true" />{{ t('order_confirmations.block.title') }}
            </h2>
            <ConfirmationStatusChip :status="confirmation.status" :skip-reason="confirmation.skip_reason" :last-error="confirmation.last_error" />
        </div>

        <ConfirmationTimeline :steps="confirmation.timeline" />

        <div v-if="confirmation.edit_note" class="rounded-md bg-warning/10 p-2">
            <p class="mb-0.5 text-2xs font-medium text-muted-foreground">{{ t('order_confirmations.block.edit_note') }}</p>
            <p class="whitespace-pre-line [overflow-wrap:anywhere]" dir="auto">{{ confirmation.edit_note }}</p>
        </div>

        <div v-if="confirmation.needs_call" class="flex items-center justify-between gap-2 border-t pt-2">
            <span class="text-muted-foreground">{{ t('order_confirmations.call.group') }}</span>
            <CallOutcomeActions :confirmation-id="confirmation.id" @recorded="router.reload({ only: ['confirmation'] })" />
        </div>

        <div class="flex flex-wrap items-center gap-3 border-t pt-2">
            <Link
                v-if="confirmation.case_id"
                :href="`/cases?open=${confirmation.case_id}`"
                class="inline-flex items-center gap-1 text-primary hover:underline"
            >
                <ClipboardList class="size-3.5" aria-hidden="true" />{{ t('order_confirmations.open_case') }}
            </Link>
            <Link href="/orders/confirmations" class="text-primary hover:underline">{{ t('order_confirmations.block.open_page') }}</Link>
        </div>
    </section>
</template>
