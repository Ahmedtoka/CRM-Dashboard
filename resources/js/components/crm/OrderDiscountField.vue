<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';

export interface DiscountFields {
    type: 'fixed' | 'percent';
    value: number;
    reason: string;
}

const model = defineModel<DiscountFields>({ required: true });

const { t } = useI18n();

const field = 'h-8 w-full rounded-md border border-input bg-background px-2 text-sm';
const label = 'mb-1 block text-xs font-medium text-muted-foreground';
</script>

<template>
    <div class="space-y-1.5 rounded-md border border-dashed p-2.5">
        <div class="flex gap-2">
            <label class="w-24 shrink-0">
                <span :class="label">{{ t('order.discount') }}</span>
                <select :value="model.type" :class="field" @change="model = { ...model, type: ($event.target as HTMLSelectElement).value as DiscountFields['type'] }">
                    <option value="fixed">{{ t('order.discount_type.fixed') }}</option>
                    <option value="percent">{{ t('order.discount_type.percent') }}</option>
                </select>
            </label>
            <label class="flex-1">
                <span :class="label">&nbsp;</span>
                <input
                    :value="model.value"
                    type="number"
                    min="0"
                    :max="model.type === 'percent' ? 100 : undefined"
                    step="1"
                    :class="field"
                    @input="model = { ...model, value: Number(($event.target as HTMLInputElement).value) || 0 }"
                />
            </label>
        </div>
        <label class="block" v-if="model.value > 0">
            <span :class="label">{{ t('order.discount_reason') }}</span>
            <input
                :value="model.reason"
                dir="auto"
                :placeholder="t('order.discount_reason_placeholder')"
                :class="field"
                @input="model = { ...model, reason: ($event.target as HTMLInputElement).value }"
            />
        </label>
    </div>
</template>
