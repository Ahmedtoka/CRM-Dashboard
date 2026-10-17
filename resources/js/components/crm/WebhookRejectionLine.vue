<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { formatDateTime } from '@/lib/format';
import type { WebhookRejectionSummary } from '@/types/admin';
import { ShieldAlert } from 'lucide-vue-next';

/**
 * Webhooks refused for a bad signature in the last day (2026-10-17): a wrong App Secret (Meta)
 * or API secret (Shopify) used to drop every delivery with nobody seeing it.
 */
defineProps<{
    summary: WebhookRejectionSummary | null | undefined;
    /** What to check: the Meta App Secret or the Shopify API secret. */
    secret: 'app_secret' | 'api_secret';
}>();

const { t, locale } = useI18n();
</script>

<template>
    <p
        v-if="summary && summary.count > 0"
        role="alert"
        class="flex items-start gap-1.5 rounded bg-destructive/10 px-2 py-1.5 text-2xs font-medium text-destructive"
        data-testid="webhook-rejections"
    >
        <ShieldAlert class="mt-px size-3.5 shrink-0" aria-hidden="true" />
        <span class="min-w-0">
            {{ t('settings.webhook_rejections.line', { hours: summary.hours, n: summary.count, secret: t(`settings.webhook_rejections.${secret}`) }) }}
            <span v-if="summary.last_at" class="block font-normal opacity-90">
                {{ t('settings.webhook_rejections.last', { at: formatDateTime(summary.last_at, locale) }) }}
                <template v-if="summary.last_reason"> · {{ t(`settings.webhook_rejections.reasons.${summary.last_reason}`) }}</template>
            </span>
        </span>
    </p>
</template>
