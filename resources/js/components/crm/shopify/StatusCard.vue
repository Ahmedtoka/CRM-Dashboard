<script setup lang="ts">
import SettingsPanel from '@/components/crm/settings/SettingsPanel.vue';
import SettingsRow from '@/components/crm/settings/SettingsRow.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { useNow } from '@/composables/useNow';
import { formatDateTime, formatSince } from '@/lib/format';
import type { ShopifyIntegrationRow, ShopifyWebhookRow } from '@/types/admin';
import { RefreshCw, Unplug } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * Settings › شوبيفاي › الربط والحالة (mockup): the store and its connection, when Shopify last reached us (with
 * «متأخرة» once it has been quiet too long), and the webhooks with «سجّليهم تاني».
 */
const props = defineProps<{
    integration: ShopifyIntegrationRow;
    disconnecting: boolean;
    webhooks: ShopifyWebhookRow[];
    /** The newest webhook Shopify delivered and we accepted. */
    lastWebhookAt: string | null;
    reregistering: boolean;
}>();
const emit = defineEmits<{ disconnect: []; reregister: [] }>();

const { t, locale } = useI18n();
const now = useNow();

const statusTone = { connected: 'ok', error: 'danger', disconnected: 'neutral' } as const;

/** Orders arrive all day: three quiet hours (or nothing ever) is worth a look. */
const LATE_AFTER_MS = 3 * 60 * 60 * 1000;
const late = computed(() => !props.lastWebhookAt || now.value - new Date(props.lastWebhookAt).getTime() > LATE_AFTER_MS);
const topics = computed(() => props.webhooks.map((w) => w.topic).join(' · '));
</script>

<template>
    <SettingsPanel :title="t('settings.shopify.state_panel.title')" data-testid="shopify-status">
        <SettingsRow
            :title="integration.shop_domain"
            :description="
                [
                    integration.shop_name,
                    integration.connected_at
                        ? t('settings.shopify.state_panel.since', { at: formatDateTime(integration.connected_at, locale) })
                        : null,
                    integration.currency,
                ]
                    .filter(Boolean)
                    .join(' · ')
            "
        >
            <StatusChip :label="t(`settings.shopify.status.${integration.status}`)" :tone="statusTone[integration.status]" />
            <Button
                variant="ghost"
                size="sm"
                class="text-danger hover:bg-danger-soft hover:text-danger max-sm:h-10"
                type="button"
                :loading="disconnecting"
                @click="emit('disconnect')"
            >
                <Unplug aria-hidden="true" />
                {{ t('settings.shopify.card.disconnect') }}
            </Button>
            <template v-if="integration.last_error" #below>
                <p class="mt-2 break-words rounded-md bg-danger-soft px-2.5 py-1.5 text-13 text-danger" dir="auto">
                    <span class="font-semibold">{{ t('settings.shopify.card.last_error') }}: </span><bdi>{{ integration.last_error }}</bdi>
                </p>
            </template>
        </SettingsRow>

        <SettingsRow
            :title="t('settings.shopify.state_panel.last_update')"
            :description="
                (lastWebhookAt ? formatSince(lastWebhookAt, locale, now) : t('ui.never')) + ' · ' + t('settings.shopify.state_panel.last_update_hint')
            "
        >
            <StatusChip
                :label="late ? t('settings.shopify.state_panel.late') : t('settings.shopify.state_panel.on_time')"
                :tone="late ? 'warn' : 'ok'"
                data-testid="shopify-freshness"
            />
        </SettingsRow>

        <SettingsRow :title="t('settings.shopify.webhooks.title')">
            <template #below>
                <p class="mt-0.5 break-words text-13 text-fg-2">
                    <bdi v-if="topics">{{ topics }}</bdi>
                    <template v-else>{{ t('settings.shopify.webhooks.empty') }}</template>
                </p>
            </template>
            <Button variant="secondary" size="sm" class="max-sm:h-10" type="button" :loading="reregistering" @click="emit('reregister')">
                <RefreshCw aria-hidden="true" />
                {{ t('settings.shopify.webhooks.reregister') }}
            </Button>
        </SettingsRow>
    </SettingsPanel>
</template>
