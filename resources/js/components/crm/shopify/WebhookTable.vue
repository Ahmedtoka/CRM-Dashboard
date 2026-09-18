<script setup lang="ts">
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
import { useI18n } from '@/composables/useI18n';
import { formatDateTime } from '@/lib/format';
import type { ShopifyWebhookRow } from '@/types/admin';
import { LoaderCircle, RotateCw } from 'lucide-vue-next';
import { computed } from 'vue';

defineProps<{ webhooks: ShopifyWebhookRow[]; reregistering: boolean }>();
const emit = defineEmits<{ reregister: [] }>();

const { t, locale } = useI18n();

const columns = computed<Column[]>(() => [
    { key: 'topic', label: t('settings.shopify.webhooks.topic') },
    { key: 'last_received_at', label: t('settings.shopify.webhooks.last_received') },
    { key: 'registered_at', label: t('settings.shopify.webhooks.registered_at') },
]);
</script>

<template>
    <section class="grid gap-2">
        <header class="flex items-center gap-2">
            <h2 class="text-sm font-semibold">{{ t('settings.shopify.webhooks.title') }}</h2>
            <button
                type="button"
                class="ms-auto inline-flex h-7 items-center gap-1 rounded-md border border-border px-2 text-xs hover:bg-muted disabled:opacity-50"
                :disabled="reregistering"
                @click="emit('reregister')"
            >
                <LoaderCircle v-if="reregistering" class="size-3 animate-spin" aria-hidden="true" />
                <RotateCw v-else class="size-3" aria-hidden="true" />
                {{ t('settings.shopify.webhooks.reregister') }}
            </button>
        </header>

        <DataTable :columns="columns" :rows="webhooks.map((w) => ({ id: w.topic, ...w }))" :empty="t('settings.shopify.webhooks.empty')">
            <template #cell-topic="{ row }"><code class="text-2xs" dir="ltr">{{ row.topic }}</code></template>
            <template #cell-last_received_at="{ row }">
                <span class="whitespace-nowrap tabular-nums text-muted-foreground">{{ formatDateTime(row.last_received_at, locale) || t('settings.shopify.webhooks.never') }}</span>
            </template>
            <template #cell-registered_at="{ row }">
                <span class="whitespace-nowrap tabular-nums text-muted-foreground">{{ formatDateTime(row.registered_at, locale) || '—' }}</span>
            </template>
        </DataTable>
    </section>
</template>
