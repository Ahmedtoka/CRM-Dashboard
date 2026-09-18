<script setup lang="ts">
import { useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { formatMoney } from '@/lib/format';
import type { ShippingOption, ShippingProvince } from '@/types/crm';
import { LoaderCircle } from 'lucide-vue-next';
import { onMounted, ref, watch } from 'vue';

export interface ShippingFields {
    province_code: string | null;
    rate_id: number | null;
}

const props = defineProps<{ subtotal: number }>();
const model = defineModel<ShippingFields>({ required: true });
const emit = defineEmits<{ fee: [amount: number] }>();

const api = useApi();
const { t, locale } = useI18n();

const provinces = ref<ShippingProvince[]>([]);
const loadingProvinces = ref(true);
const options = ref<ShippingOption[]>([]);
const loadingOptions = ref(false);
// Bumped on every request so a slower, older response (province/subtotal changed again
// before it lands) can't overwrite the result of a request started after it.
let quoteSequence = 0;

onMounted(async () => {
    try {
        const { data } = await api.get<{ data: ShippingProvince[] }>('/shipping/provinces');
        provinces.value = data.data;
    } catch {
        provinces.value = [];
    } finally {
        loadingProvinces.value = false;
    }
});

async function loadOptions(): Promise<void> {
    const sequence = ++quoteSequence;
    loadingOptions.value = true;
    try {
        const { data } = await api.get<{ data: ShippingOption[] }>('/shipping/quote', {
            params: { province_code: model.value.province_code || undefined, subtotal: props.subtotal },
        });
        if (sequence !== quoteSequence) return; // A newer request already landed or is in flight.

        options.value = data.data;
        // Keep the current choice if it's still offered, otherwise default to the first option.
        const stillOffered = options.value.some((o) => o.rate_id === model.value.rate_id);
        const chosen = stillOffered ? model.value.rate_id : (options.value[0]?.rate_id ?? null);
        select(chosen);
    } catch {
        if (sequence !== quoteSequence) return;
        options.value = [];
    } finally {
        if (sequence === quoteSequence) loadingOptions.value = false;
    }
}

watch([() => model.value.province_code, () => props.subtotal], () => void loadOptions(), { immediate: true });

function select(rateId: number | null): void {
    model.value = { ...model.value, rate_id: rateId };
    const option = options.value.find((o) => o.rate_id === rateId);
    emit('fee', option ? Number(option.price) : 0);
}

const field = 'h-8 w-full rounded-md border border-input bg-background px-2 text-sm';
const label = 'mb-1 block text-xs font-medium text-muted-foreground';
</script>

<template>
    <div class="space-y-2">
        <label class="block">
            <span :class="label">{{ t('order.province') }}</span>
            <select
                :value="model.province_code ?? ''"
                :disabled="loadingProvinces"
                :class="field"
                @change="model = { ...model, province_code: ($event.target as HTMLSelectElement).value || null }"
            >
                <option value="">{{ t('order.choose_province') }}</option>
                <option v-for="p in provinces" :key="p.code" :value="p.code">{{ p.name }}</option>
            </select>
        </label>

        <div>
            <p :class="label">{{ t('order.shipping_option') }}</p>
            <p v-if="loadingOptions" class="flex items-center gap-1.5 text-xs text-muted-foreground">
                <LoaderCircle class="size-3.5 animate-spin" aria-hidden="true" />{{ t('order.loading_shipping_options') }}
            </p>
            <p v-else-if="!options.length" class="text-xs text-muted-foreground">{{ t('order.no_shipping_options') }}</p>
            <div v-else class="space-y-1" role="radiogroup" :aria-label="t('order.shipping_option')">
                <label
                    v-for="o in options"
                    :key="o.rate_id ?? 'default'"
                    class="flex cursor-pointer items-center justify-between rounded-md border px-2.5 py-1.5 text-xs"
                    :class="model.rate_id === o.rate_id ? 'border-primary bg-primary/5' : 'border-input'"
                >
                    <span class="flex items-center gap-2">
                        <input type="radio" :checked="model.rate_id === o.rate_id" :name="'shipping-rate'" @change="select(o.rate_id)" />
                        {{ o.title }}
                    </span>
                    <span class="tabular-nums">{{ formatMoney(o.price, locale) }}</span>
                </label>
            </div>
        </div>
    </div>
</template>
