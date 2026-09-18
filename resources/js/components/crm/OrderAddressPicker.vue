<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import type { Customer } from '@/types/crm';
import { computed } from 'vue';

export interface AddressFields {
    name: string;
    phone: string;
    address: string;
    address_id: number | null;
}

const props = defineProps<{ customer: Customer | null }>();
const model = defineModel<AddressFields>({ required: true });

const { t } = useI18n();

const addresses = computed(() => props.customer?.addresses ?? []);

function pick(id: string): void {
    if (id === '') {
        model.value = { ...model.value, address_id: null };
        return;
    }

    const address = addresses.value.find((a) => a.id === Number(id));
    if (!address) return;

    model.value = {
        name: address.name || model.value.name,
        phone: address.phone || model.value.phone,
        address: [address.address1, address.address2, address.city].filter(Boolean).join('، '),
        address_id: address.id,
    };
}

const field = 'h-8 w-full rounded-md border border-input bg-background px-2 text-sm';
const label = 'mb-1 block text-xs font-medium text-muted-foreground';
</script>

<template>
    <div class="space-y-3">
        <label v-if="addresses.length" class="block">
            <span :class="label">{{ t('order.saved_address') }}</span>
            <select :value="model.address_id ?? ''" :class="field" @change="pick(($event.target as HTMLSelectElement).value)">
                <option value="">{{ t('order.new_address') }}</option>
                <option v-for="a in addresses" :key="a.id" :value="a.id">
                    {{ [a.name, a.address1, a.city].filter(Boolean).join(' — ') }}<template v-if="a.is_default"> ({{ t('customer.default_address') }})</template>
                </option>
            </select>
        </label>

        <div class="grid grid-cols-2 gap-3">
            <label>
                <span :class="label">{{ t('order.name') }}</span>
                <input :value="model.name" :class="field" @input="model = { ...model, name: ($event.target as HTMLInputElement).value }" />
            </label>
            <label>
                <span :class="label">{{ t('order.phone') }}</span>
                <input
                    :value="model.phone"
                    dir="ltr"
                    inputmode="tel"
                    :class="[field, 'text-start']"
                    @input="model = { ...model, phone: ($event.target as HTMLInputElement).value }"
                />
            </label>
            <label class="col-span-2">
                <span :class="label">{{ t('order.address') }}</span>
                <textarea
                    :value="model.address"
                    rows="2"
                    dir="auto"
                    :class="[field, 'h-auto py-1.5']"
                    @input="model = { ...model, address: ($event.target as HTMLTextAreaElement).value }"
                />
            </label>
        </div>
    </div>
</template>
