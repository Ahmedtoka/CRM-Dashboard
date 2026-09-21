<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { formatCount, formatMoney } from '@/lib/format';
import type { OrderRow } from '@/types/admin';

defineProps<{ order: OrderRow }>();

const { t, locale } = useI18n();
</script>

<template>
    <section class="overflow-hidden rounded-lg bg-card shadow-card">
        <h2 class="border-b border-border px-3 py-2 text-xs font-medium">{{ t('orders.items') }}</h2>
        <div class="scrollbar-thin overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-muted/50 text-2xs text-muted-foreground">
                    <tr>
                        <th scope="col" class="px-3 py-1.5 text-start font-medium">{{ t('orders.product') }}</th>
                        <th scope="col" class="px-3 py-1.5 text-end font-medium">{{ t('orders.qty') }}</th>
                        <th scope="col" class="px-3 py-1.5 text-end font-medium">{{ t('orders.price') }}</th>
                        <th scope="col" class="px-3 py-1.5 text-end font-medium">{{ t('orders.line_total') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="item in order.items ?? []" :key="item.id" class="border-t border-border">
                        <td class="px-3 py-2">
                            <span class="block" dir="auto">{{ item.title }}</span>
                            <span v-if="item.sku" class="text-2xs text-muted-foreground" dir="ltr">{{ item.sku }}</span>
                        </td>
                        <td class="px-3 py-2 text-end tabular-nums">{{ formatCount(item.qty, locale) }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-end tabular-nums">{{ formatMoney(item.price, locale) }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-end tabular-nums">{{ formatMoney(item.price * item.qty, locale) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <dl class="space-y-1 border-t border-border px-3 py-2 text-xs">
            <div class="flex justify-between"><dt class="text-muted-foreground">{{ t('order.subtotal') }}</dt><dd class="tabular-nums">{{ formatMoney(order.subtotal, locale) }}</dd></div>
            <div class="flex justify-between"><dt class="text-muted-foreground">{{ t('order.shipping_fee') }}</dt><dd class="tabular-nums">{{ formatMoney(order.shipping_fee, locale) }}</dd></div>
            <div v-if="order.discount" class="flex justify-between">
                <dt class="text-muted-foreground">{{ t('order.discount') }}</dt><dd class="tabular-nums">−{{ formatMoney(order.discount, locale) }}</dd>
            </div>
            <div class="flex justify-between border-t border-border pt-1 text-sm font-bold"><dt>{{ t('order.total') }}</dt><dd class="tabular-nums">{{ formatMoney(order.total, locale) }}</dd></div>
        </dl>
    </section>
</template>
