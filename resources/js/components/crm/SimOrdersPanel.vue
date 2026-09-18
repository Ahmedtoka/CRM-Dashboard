<script setup lang="ts">
import StatusChip from '@/components/crm/StatusChip.vue';
import { useI18n } from '@/composables/useI18n';
import { useSimulator } from '@/composables/useSimulator';
import { formatMoney } from '@/lib/format';
import { shipmentTone } from '@/lib/orderStatus';
import type { OrderRow, SimShipment } from '@/types/admin';
import { Link } from '@inertiajs/vue3';
import { CreditCard, LoaderCircle, Truck } from 'lucide-vue-next';

defineProps<{ orders: OrderRow[]; shipments: SimShipment[] }>();

const { t, locale } = useI18n();
const sim = useSimulator();
const RELOAD = ['awaitingPayment', 'shipments'];

async function pay(order: OrderRow): Promise<void> {
    const number = order.order_number ?? `#${order.id}`;
    await sim.post(`pay-${order.id}`, `/simulator/orders/${order.id}/pay`, {}, t('simulator.orders.paid', { number }), { href: `/orders/${order.id}`, label: number }, RELOAD);
}

// The advance endpoint answers with the order, so the toast names the new shipment status.
async function advance(shipment: SimShipment): Promise<void> {
    const number = shipment.order_number ?? `#${shipment.order_id}`;
    await sim.post<{ data: OrderRow }>(
        `advance-${shipment.id}`,
        `/simulator/shipments/${shipment.id}/advance`,
        {},
        (res) => {
            const status = res.data?.shipment?.status;
            return t('simulator.shipments.advanced', { number, status: status ? t(`shipment.status.${status}`) : '' });
        },
        { href: `/orders/${shipment.order_id}`, label: number },
        RELOAD,
    );
}
</script>

<template>
    <div class="grid gap-4 lg:grid-cols-2">
        <section class="rounded-lg bg-card text-xs shadow-card">
            <h2 class="flex items-center gap-1.5 border-b border-border px-3 py-2 text-sm font-medium"><CreditCard class="size-4" aria-hidden="true" />{{ t('simulator.orders.title') }}</h2>
            <p v-if="!orders.length" class="px-3 py-6 text-center text-muted-foreground">{{ t('simulator.orders.empty') }}</p>
            <ul class="divide-y divide-border">
                <li v-for="order in orders" :key="order.id" class="flex items-center gap-2 px-3 py-2">
                    <div class="min-w-0 flex-1">
                        <Link :href="`/orders/${order.id}`" class="font-medium hover:underline" dir="ltr">{{ order.order_number ?? `#${order.id}` }}</Link>
                        <span class="block truncate text-2xs text-muted-foreground">{{ order.customer?.name }} · {{ formatMoney(order.total, locale) }}</span>
                    </div>
                    <button type="button" class="inline-flex h-7 items-center gap-1 rounded-md border border-border px-2.5 font-medium hover:bg-muted disabled:opacity-50" :disabled="sim.busy.value !== null" @click="pay(order)">
                        <LoaderCircle v-if="sim.busy.value === `pay-${order.id}`" class="size-3 animate-spin" aria-hidden="true" />{{ t('simulator.orders.pay') }}
                    </button>
                </li>
            </ul>
        </section>

        <section class="rounded-lg bg-card text-xs shadow-card">
            <h2 class="flex items-center gap-1.5 border-b border-border px-3 py-2 text-sm font-medium"><Truck class="size-4" aria-hidden="true" />{{ t('simulator.shipments.title') }}</h2>
            <p v-if="!shipments.length" class="px-3 py-6 text-center text-muted-foreground">{{ t('simulator.shipments.empty') }}</p>
            <ul class="divide-y divide-border">
                <li v-for="shipment in shipments" :key="shipment.id" class="flex items-center gap-2 px-3 py-2">
                    <div class="min-w-0 flex-1">
                        <Link :href="`/orders/${shipment.order_id}`" class="font-medium hover:underline" dir="ltr">{{ shipment.order_number ?? `#${shipment.order_id}` }}</Link>
                        <span class="flex items-center gap-1.5 text-2xs text-muted-foreground">
                            <StatusChip :label="shipment.status ? t(`shipment.status.${shipment.status}`) : '—'" :tone="shipmentTone(shipment.status)" />
                            <span dir="ltr">{{ shipment.tracking_number }}</span>
                        </span>
                    </div>
                    <button type="button" class="inline-flex h-7 items-center gap-1 rounded-md border border-border px-2.5 font-medium hover:bg-muted disabled:opacity-50" :disabled="sim.busy.value !== null" @click="advance(shipment)">
                        <LoaderCircle v-if="sim.busy.value === `advance-${shipment.id}`" class="size-3 animate-spin" aria-hidden="true" />{{ t('simulator.shipments.advance') }}
                    </button>
                </li>
            </ul>
        </section>
    </div>
</template>
