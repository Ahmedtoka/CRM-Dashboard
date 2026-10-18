<script setup lang="ts">
import AdSourceChip from '@/components/crm/AdSourceChip.vue';
import CustomerAvatar from '@/components/crm/customers/CustomerAvatar.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import IconAction from '@/components/crm/IconAction.vue';
import MergeSuggestions from '@/components/crm/MergeSuggestions.vue';
import OrderCard from '@/components/crm/OrderCard.vue';
import DetailPanel from '@/components/crm/orders/DetailPanel.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import PlatformBadge from '@/components/crm/PlatformBadge.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import Ledger, { type LedgerFigure } from '@/components/today/Ledger.vue';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCount, formatListStamp } from '@/lib/format';
import type { Conversation, Customer } from '@/types/crm';
import { Head, Link } from '@inertiajs/vue3';
import { Copy, MapPin, MessagesSquare, Package, Phone, StickyNote } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{ customer: Customer; conversations: Conversation[]; canMerge: boolean }>();

/** Control room S3: the ads she came from (her chats' ad referrals), each once. */
const adSources = computed(() => {
    const seen = new Map<string, { key: string; name: string | null; thumbnail_url: string | null; campaign: string | null }>();
    for (const c of props.conversations) {
        const ad = c.ad;
        if (!ad) continue;
        const key = ad.id ?? ad.ref ?? `c${c.id}`;
        if (!seen.has(key)) seen.set(key, { key, name: ad.title || ad.name, thumbnail_url: ad.photo_url, campaign: ad.campaign });
    }

    return [...seen.values()];
});

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

const badgeTone = { new: 'info', repeat: 'positive', has_return: 'negative' } as const;

/** Her chats come newest first: the header's «افتح المحادثة» opens the latest one. */
const latestConversation = computed(() => props.conversations[0] ?? null);

/** What she bought, in one ledger: through the CRM, and everything Shopify knows (store orders too). */
const currency = computed(() => t('common.currency'));
const figures = computed<LedgerFigure[]>(() => [
    { key: 'orders', label: t('customer.orders_count'), value: formatCount(props.customer.orders_count, locale.value) },
    {
        key: 'spent',
        label: t('customer.total_spent'),
        value: formatCount(Math.round(props.customer.total_spent), locale.value),
        unit: currency.value,
    },
    { key: 'shopify_orders', label: t('customer.shopify_orders'), value: formatCount(props.customer.shopify_orders_count ?? 0, locale.value) },
    {
        key: 'shopify_spent',
        label: t('customer.shopify_total_spent'),
        value: formatCount(Math.round(props.customer.shopify_total_spent ?? 0), locale.value),
        unit: currency.value,
    },
]);

const toast = useToast();
async function copyPhone(): Promise<void> {
    if (!props.customer.phone) return;
    try {
        await navigator.clipboard.writeText(props.customer.phone);
        toast.push(t('customer.phone_copied'));
    } catch {
        // Clipboard access can be blocked; the number is on screen to copy by hand.
    }
}

const breadcrumbs = computed(() => [
    { title: t('customers.title'), href: '/customers' },
    { title: title.value, href: `/customers/${props.customer.id}` },
]);
const crumbs = computed(() => breadcrumbs.value.map((b) => ({ label: b.title, href: b.href })));
</script>

<template>
    <Head :title="title" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full min-w-0 max-w-7xl space-y-4 p-3 md:p-6">
            <PageHeader :title="title" :breadcrumbs="crumbs">
                <template v-if="customer.badges?.length" #extra>
                    <span class="flex flex-wrap items-center gap-1.5" data-customer-badges>
                        <StatusChip v-for="badge in customer.badges" :key="badge" :label="t(`customer.badges.${badge}`)" :tone="badgeTone[badge]" />
                    </span>
                </template>
                <Button v-if="latestConversation" as-child>
                    <Link :href="`/inbox?c=${latestConversation.id}`" data-open-latest>
                        <MessagesSquare aria-hidden="true" />{{ t('ui.open_conversation') }}
                    </Link>
                </Button>
            </PageHeader>

            <!-- Who she is at a glance (the old header had the name only): picture, phone, city, the channels she uses. -->
            <section class="flex flex-wrap items-center gap-x-4 gap-y-2" :aria-label="t('customer.profile')" data-customer-profile>
                <CustomerAvatar :name="customer.name" :src="customer.avatar_url" :platform="customer.identities?.[0]?.platform ?? null" size="lg" />
                <div class="flex min-w-0 flex-1 flex-wrap items-center gap-x-4 gap-y-1.5 text-13 text-fg-2">
                    <span v-if="customer.phone" class="inline-flex items-center gap-1">
                        <Phone class="size-4 text-fg-3" aria-hidden="true" />
                        <bdi dir="ltr" class="font-semibold tabular-nums text-foreground">{{ customer.phone }}</bdi>
                        <IconAction :icon="Copy" size="sm" variant="ghost" :label="t('customer.copy_phone')" data-copy-phone @click="copyPhone" />
                    </span>
                    <span v-if="customer.city" class="inline-flex items-center gap-1.5" dir="auto">
                        <MapPin class="size-4 text-fg-3" aria-hidden="true" />{{ customer.city }}
                    </span>
                    <span v-if="customer.identities?.length" class="flex flex-wrap items-center gap-1.5">
                        <PlatformBadge v-for="identity in customer.identities" :key="identity.id" :platform="identity.platform" show-label />
                    </span>
                </div>
            </section>

            <Ledger :figures="figures" :label="t('customer.totals')" />

            <div class="grid gap-4 lg:grid-cols-[340px_minmax(0,1fr)]">
                <aside class="min-w-0 space-y-4 text-13">
                    <DetailPanel :title="t('customer.contact')">
                        <dl class="space-y-1.5">
                            <div v-for="row in contact" :key="row.label" class="flex gap-3">
                                <dt class="w-20 shrink-0 text-fg-2">{{ row.label }}</dt>
                                <dd class="min-w-0 break-words" :dir="row.ltr ? 'ltr' : 'auto'" :class="row.ltr ? 'text-start tabular-nums' : ''">
                                    {{ row.value }}
                                </dd>
                            </div>
                        </dl>
                        <p v-if="!contact.length" class="text-fg-3">—</p>
                    </DetailPanel>

                    <DetailPanel :title="t('customer.identities')">
                        <ul class="space-y-1.5">
                            <li v-for="identity in customer.identities ?? []" :key="identity.id" class="flex min-w-0 items-center gap-2">
                                <PlatformBadge :platform="identity.platform" />
                                <span class="truncate" dir="auto">{{ identity.display_name ?? identity.username ?? identity.external_id }}</span>
                            </li>
                        </ul>
                    </DetailPanel>

                    <DetailPanel :title="t('customer.tags')">
                        <div v-if="customer.tags?.length" class="flex flex-wrap gap-1.5">
                            <StatusChip v-for="tag in customer.tags" :key="tag" :label="tag" tone="neutral" size="sm" />
                        </div>
                        <p v-else class="text-fg-3">{{ t('customer.no_tags') }}</p>
                    </DetailPanel>

                    <DetailPanel v-if="adSources.length" :title="t('customer.ads')">
                        <ul class="space-y-1.5">
                            <li v-for="ad in adSources" :key="ad.key"><AdSourceChip :source="ad" /></li>
                        </ul>
                    </DetailPanel>

                    <DetailPanel :title="t('customer.addresses')">
                        <ul v-if="customer.addresses?.length" class="space-y-2">
                            <li v-for="address in customer.addresses" :key="address.id" class="rounded-lg border border-line px-3 py-2">
                                <p dir="auto">
                                    {{ [address.name, address.address1, address.city, address.province].filter(Boolean).join('، ') }}
                                </p>
                                <p class="mt-0.5 flex items-center justify-between gap-2">
                                    <span v-if="address.phone" dir="ltr" class="tabular-nums text-fg-2">{{ address.phone }}</span>
                                    <StatusChip v-if="address.is_default" :label="t('customer.default_address')" tone="brand" size="sm" />
                                </p>
                            </li>
                        </ul>
                        <p v-else class="text-fg-3">{{ t('customer.no_addresses') }}</p>
                    </DetailPanel>

                    <DetailPanel :title="t('customers.notes')" :icon="StickyNote" :tone="customer.notes ? 'note' : 'default'">
                        <p class="whitespace-pre-line" :class="customer.notes ? 'text-foreground' : 'text-fg-3'" dir="auto">
                            {{ customer.notes || t('customer.no_notes') }}
                        </p>
                    </DetailPanel>

                    <MergeSuggestions :customer-id="customer.id" :can-merge="canMerge" />
                </aside>

                <div class="grid min-w-0 content-start gap-4 xl:grid-cols-2">
                    <section class="min-w-0 space-y-2" :aria-label="t('customer.orders')">
                        <h2 class="text-base font-bold">{{ t('customer.orders') }}</h2>
                        <div v-if="!(customer.orders ?? []).length" class="rounded-lg border border-line bg-card">
                            <EmptyState :icon="Package" :title="t('customer.no_orders')" />
                        </div>
                        <Link
                            v-for="order in customer.orders ?? []"
                            :key="order.id"
                            :href="`/orders/${order.id}`"
                            class="block rounded-lg hover:ring-1 hover:ring-line-strong focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            <OrderCard :order="order" />
                        </Link>
                    </section>

                    <DetailPanel :title="t('customers.conversations')" flush>
                        <EmptyState v-if="!conversations.length" :icon="MessagesSquare" :title="t('customers.no_conversations')" />
                        <ul class="divide-y divide-line">
                            <li v-for="c in conversations" :key="c.id">
                                <Link
                                    :href="`/inbox?c=${c.id}`"
                                    class="flex items-start gap-2.5 px-4 py-2.5 text-13 hover:bg-surface-2 focus-visible:bg-surface-2"
                                >
                                    <PlatformBadge :platform="c.platform" />
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate" dir="auto">{{ c.last_message_preview ?? '—' }}</span>
                                        <span class="text-xs text-fg-2">{{ t(`inbox.status.${c.status}`) }}</span>
                                    </span>
                                    <span class="shrink-0 text-xs tabular-nums text-fg-3">{{ formatListStamp(c.last_message_at, locale, now) }}</span>
                                </Link>
                            </li>
                        </ul>
                    </DetailPanel>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
