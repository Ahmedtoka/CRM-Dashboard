<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import type { ShopifyIntegrationRow } from '@/types/admin';
import { ref, watch } from 'vue';

const props = defineProps<{ settings: ShopifyIntegrationRow['settings']; busy: boolean }>();
const emit = defineEmits<{ submit: [payload: ShopifyIntegrationRow['settings']] }>();

const { t } = useI18n();

const defaultShippingFee = ref(props.settings.default_shipping_fee);
const stuckOrderDays = ref(props.settings.stuck_order_days);
const mismatchAlerts = ref(props.settings.mismatch_alerts);
const orderCreationEnabled = ref(props.settings.order_creation_enabled);

watch(
    () => props.settings,
    (s) => {
        defaultShippingFee.value = s.default_shipping_fee;
        stuckOrderDays.value = s.stuck_order_days;
        mismatchAlerts.value = s.mismatch_alerts;
        orderCreationEnabled.value = s.order_creation_enabled;
    },
);

function submit(): void {
    emit('submit', {
        default_shipping_fee: Number(defaultShippingFee.value),
        stuck_order_days: Number(stuckOrderDays.value),
        mismatch_alerts: mismatchAlerts.value,
        order_creation_enabled: orderCreationEnabled.value,
    });
}
</script>

<template>
    <form class="grid gap-3 rounded-lg border border-line bg-card p-4 text-13" @submit.prevent="submit">
        <h2 class="text-base font-bold">{{ t('settings.shopify.settings_form.title') }}</h2>

        <label class="grid gap-1">
            <span class="text-sm font-semibold">{{ t('settings.shopify.settings_form.default_shipping_fee') }}</span>
            <input
                v-model.number="defaultShippingFee"
                type="number"
                min="0"
                max="10000"
                step="0.01"
                dir="ltr"
                class="h-9 w-40 rounded-md border border-line-strong bg-card px-3"
            />
        </label>

        <label class="grid gap-1">
            <span class="text-sm font-semibold">{{ t('settings.shopify.settings_form.stuck_order_days') }}</span>
            <input
                v-model.number="stuckOrderDays"
                type="number"
                min="1"
                max="60"
                step="1"
                dir="ltr"
                class="h-9 w-40 rounded-md border border-line-strong bg-card px-3"
            />
        </label>

        <label class="flex items-center gap-2">
            <input v-model="mismatchAlerts" type="checkbox" class="size-4 rounded border-line-strong accent-brand" />
            <span>{{ t('settings.shopify.settings_form.mismatch_alerts') }}</span>
        </label>

        <label class="grid gap-1">
            <span class="flex items-center gap-2">
                <input v-model="orderCreationEnabled" type="checkbox" class="size-4 rounded border-line-strong accent-brand" />
                <span>{{ t('settings.shopify.settings_form.order_creation_enabled') }}</span>
            </span>
            <span class="text-fg-2">{{ t('settings.shopify.settings_form.order_creation_enabled_help') }}</span>
        </label>

        <Button class="w-fit" type="submit" :loading="busy">
            {{ t('settings.shopify.settings_form.save') }}
        </Button>
    </form>
</template>
