<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { useSimulator } from '@/composables/useSimulator';
import { formatMoney } from '@/lib/format';
import type { OrderRow } from '@/types/admin';
import { Link } from '@inertiajs/vue3';
import { CreditCard } from 'lucide-vue-next';

defineProps<{ orders: OrderRow[] }>();

const { t, locale } = useI18n();
const sim = useSimulator();
const RELOAD = ['awaitingPayment'];

async function pay(order: OrderRow): Promise<void> {
    const number = order.order_number ?? `#${order.id}`;
    await sim.post(`pay-${order.id}`, `/simulator/orders/${order.id}/pay`, {}, t('simulator.orders.paid', { number }), { href: `/orders/${order.id}`, label: number }, RELOAD);
}
</script>

<template>
    <section class="rounded-lg bg-card text-xs shadow-card">
        <h2 class="flex items-center gap-1.5 border-b border-border px-3 py-2 text-sm font-medium"><CreditCard class="size-4" aria-hidden="true" />{{ t('simulator.orders.title') }}</h2>
        <p v-if="!orders.length" class="px-3 py-6 text-center text-muted-foreground">{{ t('simulator.orders.empty') }}</p>
        <ul class="divide-y divide-border">
            <li v-for="order in orders" :key="order.id" class="flex items-center gap-2 px-3 py-2">
                <div class="min-w-0 flex-1">
                    <Link :href="`/orders/${order.id}`" class="font-medium hover:underline" dir="ltr">{{ order.order_number ?? `#${order.id}` }}</Link>
                    <span class="block truncate text-2xs text-muted-foreground">{{ order.customer?.name }} · {{ formatMoney(order.total, locale) }}</span>
                </div>
                <Button variant="outline" class="inline-flex h-7 items-center gap-1 rounded-md border border-border px-2.5 font-medium hover:bg-muted disabled:opacity-50 text-[length:inherit]" type="button" :disabled="sim.busy.value !== null" @click="pay(order)" :loading="sim.busy.value === `pay-${order.id}`">
                    {{ t('simulator.orders.pay') }}
                </Button>
            </li>
        </ul>
    </section>
</template>
