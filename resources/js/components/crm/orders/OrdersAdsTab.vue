<script setup lang="ts">
import EmptyState from '@/components/crm/EmptyState.vue';
import IconAction from '@/components/crm/IconAction.vue';
import { useI18n } from '@/composables/useI18n';
import { adsManagerUrl } from '@/lib/ads';
import { formatCount, formatMoney } from '@/lib/format';
import type { OrdersByAdRow } from '@/types/orders';
import { Link } from '@inertiajs/vue3';
import { ExternalLink, Megaphone } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * The «الإعلانات» tab of /orders (fresh-orders F5): platform → campaign → ad set → ad, each ad with its orders,
 * revenue and units. The ad opens the AdDrawer (ads roles only); its orders count opens /orders/ads/{ad}.
 */
const props = withDefaults(defineProps<{ rows: OrdersByAdRow[]; canOpenAds?: boolean; query?: string }>(), { canOpenAds: false, query: '' });
const emit = defineEmits<{ 'open-ad': [id: number] }>();
const { t, locale } = useI18n();

const n = (v: number) => formatCount(v, locale.value);
const money = (v: number) => formatMoney(v, locale.value);

interface Totals {
    orders: number;
    revenue: number;
    units: number;
}
interface Group<T> extends Totals {
    key: string;
    label: string;
    children: T[];
}

function group<T extends Totals>(key: string, label: string, children: T[]): Group<T> {
    return {
        key,
        label,
        orders: children.reduce((a, c) => a + c.orders, 0),
        revenue: children.reduce((a, c) => a + c.revenue, 0),
        units: children.reduce((a, c) => a + c.units, 0),
        children,
    };
}

function groupBy<T>(items: T[], key: (i: T) => string): [string, T[]][] {
    const out = new Map<string, T[]>();
    for (const i of items) out.set(key(i), [...(out.get(key(i)) ?? []), i]);
    return [...out];
}

const PLATFORM_LABELS: Record<string, string> = { meta: 'Meta', tiktok: 'TikTok', google: 'Google' };

const tree = computed(() =>
    groupBy(
        props.rows.filter((r) => r.ad_id !== null),
        (r) => r.platform ?? '—',
    ).map(([platform, rows]) =>
        group(
            platform,
            PLATFORM_LABELS[platform] ?? platform,
            groupBy(rows, (r) => String(r.campaign_id ?? 'none')).map(([cid, cRows]) =>
                group(
                    `c-${cid}`,
                    cRows[0].campaign ?? t('ordersHub.ads.no_campaign'),
                    groupBy(cRows, (r) => String(r.ad_set_id ?? 'none')).map(([sid, sRows]) =>
                        group(`s-${sid}`, sRows[0].ad_set ?? t('ordersHub.ads.no_ad_set'), sRows),
                    ),
                ),
            ),
        ),
    ),
);
const direct = computed(() => props.rows.find((r) => r.ad_id === null) ?? null);
const adHref = (id: number) => `/orders/ads/${id}${props.query ? `?${props.query}` : ''}`;
const metaUrl = (r: OrdersByAdRow) =>
    r.platform && r.external_id
        ? adsManagerUrl({ platform: r.platform, external_id: r.external_id, account_external_id: r.account_external_id })
        : null;
// Phones: the three figures drop under the name, full width, each cell truncating (Arabic money is long); sm+: a fixed end column.
const figures = 'grid w-full grid-cols-3 gap-2 text-xs tabular-nums sm:w-64 sm:shrink-0 sm:text-end [&>*]:min-w-0 [&>*]:truncate';
</script>

<template>
    <EmptyState v-if="!rows.length" :icon="Megaphone" :title="t('ordersHub.ads.empty')" />
    <div v-else class="min-w-0 space-y-3" data-orders-ads>
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 px-3 text-2xs font-medium text-muted-foreground">
            <span class="min-w-0 flex-1">{{ t('ordersHub.ads.ad') }}</span>
            <span :class="figures">
                <span>{{ t('ordersHub.ads.orders') }}</span>
                <span>{{ t('ordersHub.ads.revenue') }}</span>
                <span>{{ t('ordersHub.ads.units') }}</span>
            </span>
        </div>
        <section v-for="p in tree" :key="p.key" class="min-w-0 rounded-lg bg-card shadow-card" :data-platform="p.key">
            <h3 class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-border px-3 py-2 text-sm font-semibold">
                <span class="min-w-0 flex-1 truncate">{{ p.label }}</span>
                <span :class="figures">
                    <span>{{ n(p.orders) }}</span>
                    <span>{{ money(p.revenue) }}</span>
                    <span>{{ n(p.units) }}</span>
                </span>
            </h3>
            <div v-for="c in p.children" :key="c.key" class="border-b border-border last:border-b-0">
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1 bg-muted/40 px-3 py-1.5 text-xs font-medium">
                    <span class="min-w-0 flex-1 truncate" dir="auto">{{ c.label }}</span>
                    <span :class="figures">
                        <span>{{ n(c.orders) }}</span>
                        <span>{{ money(c.revenue) }}</span>
                        <span>{{ n(c.units) }}</span>
                    </span>
                </div>
                <div v-for="s in c.children" :key="s.key">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 px-3 py-1 ps-6 text-2xs text-muted-foreground">
                        <span class="min-w-0 flex-1 truncate" dir="auto">{{ s.label }}</span>
                        <span :class="figures">
                            <span>{{ n(s.orders) }}</span>
                            <span>{{ money(s.revenue) }}</span>
                            <span>{{ n(s.units) }}</span>
                        </span>
                    </div>
                    <ul>
                        <li
                            v-for="ad in s.children"
                            :key="ad.ad_id ?? 0"
                            class="flex min-h-11 flex-wrap items-center gap-x-2 gap-y-1 px-3 py-1 ps-9"
                            data-ad-row
                        >
                            <img v-if="ad.thumbnail_url" :src="ad.thumbnail_url" alt="" class="size-9 shrink-0 rounded object-cover" loading="lazy" />
                            <Megaphone v-else class="size-4 shrink-0 text-amber-600 dark:text-amber-300" aria-hidden="true" />
                            <span class="flex min-w-0 flex-1 flex-col">
                                <button
                                    v-if="canOpenAds"
                                    type="button"
                                    class="truncate text-start text-xs font-medium hover:underline"
                                    dir="auto"
                                    data-open-ad
                                    @click="emit('open-ad', ad.ad_id as number)"
                                >
                                    {{ ad.ad ?? '—' }}
                                </button>
                                <span v-else class="truncate text-xs font-medium" dir="auto">{{ ad.ad ?? '—' }}</span>
                            </span>
                            <IconAction
                                v-if="canOpenAds && metaUrl(ad)"
                                :href="metaUrl(ad) ?? undefined"
                                external
                                :icon="ExternalLink"
                                :label="t('ordersHub.open_meta')"
                                variant="primary"
                                size="sm"
                                data-open-meta
                            />
                            <span :class="figures">
                                <Link
                                    :href="adHref(ad.ad_id as number)"
                                    class="font-semibold text-primary underline"
                                    :aria-label="t('ordersHub.ads.open_orders', { n: n(ad.orders) })"
                                    data-ad-orders
                                    >{{ n(ad.orders) }}</Link
                                >
                                <span>{{ money(ad.revenue) }}</span>
                                <span>{{ n(ad.units) }}</span>
                            </span>
                        </li>
                    </ul>
                </div>
            </div>
        </section>
        <section v-if="direct" class="flex min-h-11 flex-wrap items-center gap-x-2 gap-y-1 rounded-lg bg-card px-3 py-2 shadow-card" data-direct>
            <span class="min-w-0 flex-1 text-sm font-medium">{{ t('ordersHub.ads.direct') }}</span>
            <span :class="figures">
                <span>{{ n(direct.orders) }}</span>
                <span>{{ money(direct.revenue) }}</span>
                <span>{{ n(direct.units) }}</span>
            </span>
        </section>
    </div>
</template>
