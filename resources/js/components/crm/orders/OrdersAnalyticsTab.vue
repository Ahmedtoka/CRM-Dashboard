<script setup lang="ts">
import BarChart, { type BarDatum } from '@/components/crm/BarChart.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import StatCard from '@/components/crm/StatCard.vue';
import { useI18n } from '@/composables/useI18n';
import { formatCount, formatMoney } from '@/lib/format';
import { formatTodayDate } from '@/lib/today';
import type { OrdersAnalytics, OrdersGroupRow } from '@/types/orders';
import { Link } from '@inertiajs/vue3';
import { ChartColumn } from 'lucide-vue-next';
import { computed } from 'vue';

/** The «تحليلات» tab of /orders (fresh-orders F5); the numbers come grouped from the server (OrdersAnalytics). */
const props = defineProps<{ data: OrdersAnalytics }>();
const { t, locale } = useI18n();

const n = (v: number) => formatCount(v, locale.value);
const money = (v: number) => formatMoney(v, locale.value);

const groupLabel = (r: OrdersGroupRow) =>
    r.key === '_rest' ? t('ordersHub.analytics.rest', { n: n(r.count ?? 0) }) : (r.label ?? t('ordersHub.analytics.unknown'));
const bars = (rows: OrdersGroupRow[]): BarDatum[] => rows.map((r) => ({ key: r.key ?? '_none', label: groupLabel(r), value: r.orders }));

const governorates = computed(() => bars(props.data.governorates));
const districts = computed(() => bars(props.data.districts));
const statuses = computed<BarDatum[]>(() =>
    props.data.statuses.map((s) => ({ key: s.key, label: t(`ordersHub.analytics.status.${s.key}`), value: s.orders })),
);
const days = computed<BarDatum[]>(() => props.data.days.map((d) => ({ key: d.date, label: formatTodayDate(d.date, locale.value), value: d.orders })));
const labelEvery = computed(() => Math.max(1, Math.ceil(props.data.days.length / 10)));
const totals = computed(() => props.data.totals);
const frequency = computed(() => [
    { key: 'one', label: t('ordersHub.analytics.one'), value: props.data.frequency.one },
    { key: 'two', label: t('ordersHub.analytics.two'), value: props.data.frequency.two },
    { key: 'three_plus', label: t('ordersHub.analytics.three_plus'), value: props.data.frequency.three_plus },
]);
</script>

<template>
    <EmptyState v-if="totals.orders === 0" :icon="ChartColumn" :title="t('ordersHub.analytics.empty')" />
    <div v-else class="min-w-0 space-y-4" data-orders-analytics>
        <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
            <StatCard :label="t('ordersHub.analytics.orders')" :value="totals.orders" />
            <StatCard :label="t('ordersHub.analytics.revenue')" :value="money(totals.revenue)" :hint="t('ordersHub.analytics.real_hint')" />
            <StatCard :label="t('ordersHub.analytics.aov')" :value="money(totals.aov)" />
            <StatCard :label="t('ordersHub.analytics.customers')" :value="totals.customers" />
            <StatCard :label="t('ordersHub.analytics.new_customers')" :value="totals.new_customers ?? 0" />
            <StatCard :label="t('ordersHub.analytics.repeat_customers')" :value="totals.repeat_customers ?? 0" />
        </div>

        <section class="min-w-0 rounded-lg bg-card p-3 shadow-card">
            <BarChart :title="t('ordersHub.analytics.by_day')" :items="days" orientation="vertical" :label-every="labelEvery" :format="n" />
        </section>

        <div class="grid min-w-0 gap-4 lg:grid-cols-2">
            <section class="min-w-0 rounded-lg bg-card p-3 shadow-card">
                <BarChart :title="t('ordersHub.analytics.by_governorate')" :items="governorates" :format="n" />
            </section>
            <section class="min-w-0 rounded-lg bg-card p-3 shadow-card">
                <BarChart :title="t('ordersHub.analytics.by_district')" :items="districts" :format="n" />
            </section>
            <section class="min-w-0 rounded-lg bg-card p-3 shadow-card">
                <BarChart :title="t('ordersHub.analytics.frequency')" :items="frequency" :format="n" />
                <template v-if="data.frequency.customers.length">
                    <h3 class="mt-3 text-xs font-medium">{{ t('ordersHub.analytics.repeat_list') }}</h3>
                    <ul class="mt-1 divide-y divide-border text-xs">
                        <li v-for="c in data.frequency.customers" :key="c.id" class="flex min-h-9 items-center gap-2">
                            <Link :href="`/customers/${c.id}`" class="min-w-0 flex-1 truncate font-medium hover:underline" dir="auto">{{
                                c.name ?? '—'
                            }}</Link>
                            <span v-if="c.phone" class="text-2xs text-muted-foreground" dir="ltr">{{ c.phone }}</span>
                            <span class="shrink-0 tabular-nums">{{ t('ordersHub.analytics.orders_n', { n: n(c.orders) }) }}</span>
                        </li>
                    </ul>
                </template>
            </section>
            <section class="min-w-0 rounded-lg bg-card p-3 shadow-card">
                <BarChart :title="t('ordersHub.analytics.by_status')" :items="statuses" :format="n" />
            </section>
        </div>

        <section class="min-w-0 rounded-lg bg-card p-3 shadow-card">
            <h3 class="mb-2 text-xs font-medium">{{ t('ordersHub.analytics.top_products') }}</h3>
            <ul class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3" data-top-products>
                <li v-for="p in data.products" :key="p.title" class="flex min-w-0 items-center gap-2 rounded-md bg-muted/40 p-2">
                    <img v-if="p.image_url" :src="p.image_url" :alt="p.title" class="size-12 shrink-0 rounded object-cover" loading="lazy" />
                    <span v-else class="size-12 shrink-0 rounded bg-muted" aria-hidden="true" />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-xs font-medium" dir="auto">{{ p.title }}</span>
                        <span class="block text-2xs text-muted-foreground"
                            >{{ t('ordersHub.analytics.units', { n: n(p.units) }) }} · {{ money(p.revenue) }}</span
                        >
                    </span>
                </li>
            </ul>
        </section>
    </div>
</template>
