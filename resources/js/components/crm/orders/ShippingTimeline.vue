<script setup lang="ts">
import StatusChip from '@/components/crm/StatusChip.vue';
import { useI18n } from '@/composables/useI18n';
import { formatDateTime } from '@/lib/format';
import type { ShippingSummary } from '@/types/returnsShipping';
import { ExternalLink, TriangleAlert } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * The shipping timeline from Shopify only (round 2026-10-24): created → picked up → in transit → out for delivery →
 * delivered / failed attempt / returned. `compact` is the one-line version for the thread's order card. When Shopify
 * never reported a carrier step the card says so instead of pretending: Le Voile's courier may not push tracking.
 */
const props = withDefaults(defineProps<{ shipping: ShippingSummary | undefined; compact?: boolean }>(), { compact: false });

const { t, locale } = useI18n();

const tone = (step: string | null) =>
    step === 'delivered' ? 'ok' : step === 'failed_attempt' || step === 'returned' ? 'danger' : step === 'cancelled' || !step ? 'neutral' : 'info';

const entries = computed(() => [...(props.shipping?.timeline ?? [])].reverse());
const hasAny = computed(() => (props.shipping?.timeline.length ?? 0) > 0);
</script>

<template>
    <div v-if="shipping && hasAny" :class="compact ? 'space-y-1' : 'space-y-3'" data-shipping-timeline>
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
            <StatusChip
                :label="shipping.step ? t(`shipment.status.${shipping.step}`) : '—'"
                :tone="tone(shipping.step)"
                :size="compact ? 'sm' : 'md'"
                data-shipping-step
            />
            <span v-if="shipping.failed_attempt" class="inline-flex items-center gap-1 text-xs font-semibold text-danger" data-shipping-failed>
                <TriangleAlert class="size-3.5" aria-hidden="true" />{{ t('shipping_timeline.failed') }}
            </span>
            <span v-if="shipping.step_at" class="text-2xs tabular-nums text-fg-3">{{ formatDateTime(shipping.step_at, locale) }}</span>
            <a
                v-if="shipping.tracking?.url"
                :href="shipping.tracking.url"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex items-center gap-1 text-xs font-semibold text-brand-ink hover:underline"
            >
                <ExternalLink class="size-3.5" aria-hidden="true" />{{ t('shipping_timeline.track') }}
            </a>
        </div>

        <p v-if="!shipping.carrier_updates && !compact" class="text-xs text-fg-2" data-shipping-no-updates>{{ t('shipping_timeline.no_updates') }}</p>

        <ol v-if="!compact" class="ms-1.5 space-y-2 border-s border-line ps-4 text-13">
            <li v-for="(e, i) in entries" :key="`${e.fulfillment_id}-${e.step}-${e.at}`" class="relative">
                <span
                    class="absolute -start-[1.3rem] top-1.5 size-2 rounded-full"
                    :class="i === 0 ? (e.step === 'failed_attempt' ? 'bg-danger' : 'bg-brand') : 'bg-line-strong'"
                    aria-hidden="true"
                />
                <p :class="i === 0 ? 'font-semibold' : 'text-fg-2'">{{ t(`shipment.status.${e.step}`) }}</p>
                <p v-if="e.step === 'created' && (e.tracking_company || e.tracking_number)" class="text-xs text-fg-2" dir="auto">
                    {{ e.tracking_company }} <bdi v-if="e.tracking_number" class="tabular-nums" dir="ltr">{{ e.tracking_number }}</bdi>
                </p>
                <p v-if="e.message" class="text-xs text-fg-2" dir="auto">{{ e.message }}</p>
                <p class="text-2xs tabular-nums text-fg-3">{{ formatDateTime(e.at, locale) }}</p>
            </li>
        </ol>
        <p v-if="!compact" class="text-2xs text-fg-3">{{ t('shipping_timeline.note') }}</p>
    </div>
</template>
