<script setup lang="ts">
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
import { useI18n } from '@/composables/useI18n';
import { formatDateTime } from '@/lib/format';
import type { ShopifyWebhookRow } from '@/types/admin';
import { computed } from 'vue';

/** Each webhook topic with its last delivery; «سجّليهم تاني» lives on the status panel above. */
defineProps<{ webhooks: ShopifyWebhookRow[] }>();

const { t, locale } = useI18n();

const columns = computed<Column[]>(() => [
    { key: 'topic', label: t('settings.shopify.webhooks.topic') },
    { key: 'last_received_at', label: t('settings.shopify.webhooks.last_received') },
    { key: 'registered_at', label: t('settings.shopify.webhooks.registered_at') },
]);
</script>

<template>
    <section class="grid gap-2">
        <h2 class="text-base font-bold">{{ t('settings.shopify.webhooks.title') }}</h2>
        <DataTable
            table-id="shopify-webhooks"
            :columns="columns"
            :rows="webhooks.map((w) => ({ id: w.topic, ...w }))"
            :empty="t('settings.shopify.webhooks.empty')"
        >
            <template #cell-topic="{ row }"
                ><code class="text-xs" dir="ltr">{{ row.topic }}</code></template
            >
            <template #cell-last_received_at="{ row }">
                <span class="whitespace-nowrap tabular-nums text-fg-2">{{
                    formatDateTime(row.last_received_at, locale) || t('settings.shopify.webhooks.never')
                }}</span>
            </template>
            <template #cell-registered_at="{ row }">
                <span class="whitespace-nowrap tabular-nums text-fg-2">{{ formatDateTime(row.registered_at, locale) || '—' }}</span>
            </template>
        </DataTable>
    </section>
</template>
