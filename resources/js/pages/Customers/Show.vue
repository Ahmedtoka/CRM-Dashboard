<script setup lang="ts">
import MergeSuggestions from '@/components/crm/MergeSuggestions.vue';
import OrderCard from '@/components/crm/OrderCard.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import PlatformBadge from '@/components/crm/PlatformBadge.vue';
import StatCard from '@/components/crm/StatCard.vue';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCount, formatListStamp, formatMoney } from '@/lib/format';
import type { Conversation, Customer } from '@/types/crm';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{ customer: Customer; conversations: Conversation[]; canMerge: boolean }>();

const { t, locale } = useI18n();
const now = Date.now();
const title = computed(() => props.customer.name ?? `#${props.customer.id}`);

const contact = computed(() =>
    [
        { label: t('customer.phone'), value: props.customer.phone, ltr: true },
        { label: t('customer.email'), value: props.customer.email, ltr: true },
        { label: t('customer.city'), value: props.customer.city, ltr: false },
        { label: t('customer.address'), value: props.customer.address, ltr: false },
    ].filter((row) => row.value),
);

const breadcrumbs = computed(() => [
    { title: t('customers.title'), href: '/customers' },
    { title: title.value, href: `/customers/${props.customer.id}` },
]);
</script>

<template>
    <Head :title="title" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-4 p-4">
            <PageHeader :title="title" />

            <div class="grid gap-4 lg:grid-cols-[320px_minmax(0,1fr)]">
                <aside class="space-y-4">
                    <div class="grid grid-cols-2 gap-2">
                        <StatCard :label="t('customer.orders_count')" :value="formatCount(customer.orders_count, locale)" />
                        <StatCard :label="t('customer.total_spent')" :value="formatMoney(customer.total_spent, locale)" />
                    </div>

                    <section class="rounded-lg border bg-card p-3 text-xs">
                        <dl class="space-y-1.5">
                            <div v-for="row in contact" :key="row.label" class="flex gap-2">
                                <dt class="w-20 shrink-0 text-muted-foreground">{{ row.label }}</dt>
                                <dd class="min-w-0 break-words" :dir="row.ltr ? 'ltr' : 'auto'">{{ row.value }}</dd>
                            </div>
                        </dl>
                        <h2 class="mb-1 mt-3 font-medium">{{ t('customer.identities') }}</h2>
                        <ul class="space-y-1">
                            <li v-for="identity in customer.identities ?? []" :key="identity.id" class="flex items-center gap-2">
                                <PlatformBadge :platform="identity.platform" />
                                <span class="truncate">{{ identity.display_name ?? identity.username ?? identity.external_id }}</span>
                            </li>
                        </ul>
                        <h2 class="mb-1 mt-3 font-medium">{{ t('customers.notes') }}</h2>
                        <p class="whitespace-pre-line text-muted-foreground" dir="auto">{{ customer.notes || t('customer.no_notes') }}</p>
                    </section>

                    <MergeSuggestions :customer-id="customer.id" :can-merge="canMerge" />
                </aside>

                <div class="grid content-start gap-4 xl:grid-cols-2">
                    <section class="rounded-lg border bg-card">
                        <h2 class="border-b px-3 py-2 text-xs font-medium">{{ t('customers.conversations') }}</h2>
                        <p v-if="!conversations.length" class="px-3 py-6 text-center text-xs text-muted-foreground">{{ t('customers.no_conversations') }}</p>
                        <ul class="divide-y">
                            <li v-for="c in conversations" :key="c.id">
                                <Link :href="`/inbox?c=${c.id}`" class="flex items-start gap-2 px-3 py-2 text-xs hover:bg-muted/50">
                                    <PlatformBadge :platform="c.platform" />
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate" dir="auto">{{ c.last_message_preview ?? '—' }}</span>
                                        <span class="text-2xs text-muted-foreground">{{ t(`inbox.status.${c.status}`) }}</span>
                                    </span>
                                    <span class="shrink-0 text-2xs tabular-nums text-muted-foreground">{{ formatListStamp(c.last_message_at, locale, now) }}</span>
                                </Link>
                            </li>
                        </ul>
                    </section>

                    <section class="space-y-2">
                        <h2 class="text-xs font-medium">{{ t('customer.orders') }}</h2>
                        <p v-if="!(customer.orders ?? []).length" class="rounded-lg border bg-card px-3 py-6 text-center text-xs text-muted-foreground">{{ t('customer.no_orders') }}</p>
                        <Link v-for="order in customer.orders ?? []" :key="order.id" :href="`/orders/${order.id}`" class="block rounded-lg hover:ring-2 hover:ring-primary/30">
                            <OrderCard :order="order" />
                        </Link>
                    </section>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
