<script setup lang="ts">
/** Spend: the main figure includes tax (what the card is charged), the small line is pre-tax. */
import { useI18n } from '@/composables/useI18n';
import { formatAdsMoney } from '@/lib/ads';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        amount: number | null | undefined;
        withTax: number | null | undefined;
        currency?: string;
        size?: 'sm' | 'lg';
        align?: 'start' | 'end';
    }>(),
    { currency: 'EGP', size: 'sm', align: 'end' },
);

const { t, locale } = useI18n();
const main = computed(() => formatAdsMoney(props.withTax, locale.value, props.currency));
const pre = computed(() => formatAdsMoney(props.amount, locale.value, props.currency));
</script>

<template>
    <span class="inline-flex flex-col leading-tight" :class="align === 'end' ? 'items-end text-end' : 'items-start text-start'">
        <span class="whitespace-nowrap font-semibold tabular-nums text-foreground" :class="size === 'lg' ? 'text-xl font-bold' : 'text-xs'">{{
            main
        }}</span>
        <span class="whitespace-nowrap text-2xs tabular-nums text-muted-foreground">{{ t('ads.money.pre_tax', { amount: pre }) }}</span>
    </span>
</template>
