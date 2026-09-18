<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import type { ShopifyIntegrationRow } from '@/types/admin';
import { LoaderCircle } from 'lucide-vue-next';
import { ref, watch } from 'vue';

const props = defineProps<{ settings: ShopifyIntegrationRow['settings']; busy: boolean }>();
const emit = defineEmits<{ submit: [payload: ShopifyIntegrationRow['settings']] }>();

const { t } = useI18n();

const defaultShippingFee = ref(props.settings.default_shipping_fee);
const autoCreateShipment = ref(props.settings.auto_create_shipment);
const stuckOrderDays = ref(props.settings.stuck_order_days);
const mismatchAlerts = ref(props.settings.mismatch_alerts);
const orderCreationEnabled = ref(props.settings.order_creation_enabled);

watch(
    () => props.settings,
    (s) => {
        defaultShippingFee.value = s.default_shipping_fee;
        autoCreateShipment.value = s.auto_create_shipment;
        stuckOrderDays.value = s.stuck_order_days;
        mismatchAlerts.value = s.mismatch_alerts;
        orderCreationEnabled.value = s.order_creation_enabled;
    },
);

function submit(): void {
    emit('submit', {
        default_shipping_fee: Number(defaultShippingFee.value),
        auto_create_shipment: autoCreateShipment.value,
        stuck_order_days: Number(stuckOrderDays.value),
        mismatch_alerts: mismatchAlerts.value,
        order_creation_enabled: orderCreationEnabled.value,
    });
}
</script>

<template>
    <form class="grid gap-3 rounded-lg bg-card p-4 text-xs shadow-card" @submit.prevent="submit">
        <h2 class="text-sm font-semibold">{{ t('settings.shopify.settings_form.title') }}</h2>

        <label class="grid gap-1">
            <span class="text-sm font-semibold">{{ t('settings.shopify.settings_form.default_shipping_fee') }}</span>
            <input v-model.number="defaultShippingFee" type="number" min="0" max="10000" step="0.01" dir="ltr" class="h-9 w-40 rounded-md border border-input bg-background px-3" />
        </label>

        <label class="grid gap-1">
            <span class="text-sm font-semibold">{{ t('settings.shopify.settings_form.stuck_order_days') }}</span>
            <input v-model.number="stuckOrderDays" type="number" min="1" max="60" step="1" dir="ltr" class="h-9 w-40 rounded-md border border-input bg-background px-3" />
        </label>

        <label class="flex items-center gap-2">
            <input v-model="autoCreateShipment" type="checkbox" class="size-4 rounded border-input" />
            <span>{{ t('settings.shopify.settings_form.auto_create_shipment') }}</span>
        </label>

        <label class="flex items-center gap-2">
            <input v-model="mismatchAlerts" type="checkbox" class="size-4 rounded border-input" />
            <span>{{ t('settings.shopify.settings_form.mismatch_alerts') }}</span>
        </label>

        <label class="grid gap-1">
            <span class="flex items-center gap-2">
                <input v-model="orderCreationEnabled" type="checkbox" class="size-4 rounded border-input" />
                <span>{{ t('settings.shopify.settings_form.order_creation_enabled') }}</span>
            </span>
            <span class="text-muted-foreground">{{ t('settings.shopify.settings_form.order_creation_enabled_help') }}</span>
        </label>

        <button type="submit" class="inline-flex h-9 w-fit items-center gap-1.5 rounded-md bg-primary px-3 font-medium text-primary-foreground disabled:opacity-50" :disabled="busy">
            <LoaderCircle v-if="busy" class="size-3.5 animate-spin" aria-hidden="true" />
            {{ t('settings.shopify.settings_form.save') }}
        </button>
    </form>
</template>
