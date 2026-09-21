<script setup lang="ts">
import StatusChip from '@/components/crm/StatusChip.vue';
import { useI18n } from '@/composables/useI18n';
import { formatDateTime } from '@/lib/format';
import type { ShopifyIntegrationRow } from '@/types/admin';
import { LoaderCircle } from 'lucide-vue-next';

defineProps<{ integration: ShopifyIntegrationRow; disconnecting: boolean }>();
const emit = defineEmits<{ disconnect: [] }>();

const { t, locale } = useI18n();

const statusTone = { connected: 'positive', error: 'negative', disconnected: 'neutral' } as const;
</script>

<template>
    <section class="grid gap-3 rounded-lg bg-card p-4 text-xs shadow-card">
        <header class="flex flex-wrap items-center gap-2">
            <h2 class="text-sm font-semibold" dir="ltr">{{ integration.shop_name || integration.shop_domain }}</h2>
            <StatusChip :label="t(`settings.shopify.status.${integration.status}`)" :tone="statusTone[integration.status]" />
            <button
                type="button"
                class="ms-auto inline-flex h-8 items-center gap-1.5 rounded-md border border-border px-2.5 font-medium text-destructive hover:bg-destructive/10 disabled:opacity-50"
                :disabled="disconnecting"
                @click="emit('disconnect')"
            >
                <LoaderCircle v-if="disconnecting" class="size-3.5 animate-spin" aria-hidden="true" />
                {{ t('settings.shopify.card.disconnect') }}
            </button>
        </header>

        <dl class="grid gap-1.5 sm:grid-cols-2">
            <div class="flex justify-between gap-2 sm:block">
                <dt class="text-muted-foreground">{{ t('settings.shopify.card.store') }}</dt>
                <dd dir="ltr">{{ integration.shop_domain }}</dd>
            </div>
            <div class="flex justify-between gap-2 sm:block">
                <dt class="text-muted-foreground">{{ t('settings.shopify.card.currency') }}</dt>
                <dd>{{ integration.currency ?? '—' }}</dd>
            </div>
            <div class="flex justify-between gap-2 sm:block">
                <dt class="text-muted-foreground">{{ t('settings.shopify.card.connected_at') }}</dt>
                <dd class="tabular-nums">{{ formatDateTime(integration.connected_at, locale) || '—' }}</dd>
            </div>
        </dl>

        <p v-if="integration.last_error" class="rounded bg-destructive/10 px-2 py-1.5 text-destructive" dir="ltr">
            <span class="font-medium">{{ t('settings.shopify.card.last_error') }}: </span>{{ integration.last_error }}
        </p>
    </section>
</template>
