<script setup lang="ts">
/** Ads Hub — الحملات: campaign → ad sets → ads with roll-up metrics, collapsible rows and the naming-convention badge (spec §1.5). */
import AdsRangeBar from '@/components/ads/AdsRangeBar.vue';
import MoneyCell from '@/components/ads/MoneyCell.vue';
import PlatformChip from '@/components/ads/PlatformChip.vue';
import StatusToggle from '@/components/ads/StatusToggle.vue';
import TrendArrow from '@/components/ads/TrendArrow.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import DataHealthBanner from '@/components/ads/DataHealthBanner.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { type AdsQueryValue, formatPct, formatQty, formatRoas, roasTone, visitAds } from '@/lib/ads';
import { formatCount } from '@/lib/format';
import type { AdsAccess, AdsCampaignsProps, CampaignNode, CampaignSort } from '@/types/ads';
import { Head, usePage } from '@inertiajs/vue3';
import { ChevronDown, ChevronRight, Layers } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<AdsCampaignsProps>();
const canWrite = computed(() => ((usePage().props.ads ?? null) as AdsAccess | null)?.canWrite === true);

const { t, locale } = useI18n();
const n = (v: number) => formatCount(v, locale.value);

const SORTS: CampaignSort[] = ['spend', 'roas'];

const chip = (on: boolean) =>
    on ? 'border-primary bg-primary text-primary-foreground' : 'border-border bg-background text-muted-foreground hover:text-foreground';

/** Account chips: toggling one adds or removes it from accounts[] (kept in the URL by visitAds). */
function toggleAccount(id: number | null): void {
    const cur = props.filters.accounts;
    const next = id === null ? [] : cur.includes(id) ? cur.filter((x) => x !== id) : [...cur, id];
    const f = props.filters;
    visitAds({ from: f.from, to: f.to, platform: f.platform, buyer: f.buyer, ...keep.value, accounts: next.map(String) });
}

const keep = computed<Record<string, AdsQueryValue>>(() => ({ sort: props.filters.sort === 'spend' ? null : props.filters.sort }));

function go(changes: Record<string, AdsQueryValue>): void {
    const f = props.filters;
    visitAds({ from: f.from, to: f.to, platform: f.platform, buyer: f.buyer, ...keep.value, ...changes });
}

interface Row {
    key: string;
    node: CampaignNode;
    depth: number;
    expandable: boolean;
}

/** Rows currently open; the key is the node's path so the id-0 placeholders of different parents stay apart. */
const open = ref<Set<string>>(new Set());

function toggle(key: string): void {
    const next = new Set(open.value);
    if (next.has(key)) next.delete(key);
    else next.add(key);
    open.value = next;
}

function walk(nodes: CampaignNode[], depth: number, parent: string, into: Row[], all: string[]): void {
    for (const node of nodes) {
        const key = `${parent}/${node.level}:${node.id}`;
        const expandable = node.children.length > 0;
        into.push({ key, node, depth, expandable });
        if (expandable) {
            all.push(key);
            if (open.value.has(key)) walk(node.children, depth + 1, key, into, all);
            else collect(node.children, key, all);
        }
    }
}

/** Keys of the closed subtree, so «expand all» knows every expandable row. */
function collect(nodes: CampaignNode[], parent: string, all: string[]): void {
    for (const node of nodes) {
        const key = `${parent}/${node.level}:${node.id}`;
        if (node.children.length) {
            all.push(key);
            collect(node.children, key, all);
        }
    }
}

const view = computed(() => {
    const rows: Row[] = [];
    const all: string[] = [];
    walk(props.tree, 0, '', rows, all);

    return { rows, all };
});
const rows = computed(() => view.value.rows);
const allOpen = computed(() => view.value.all.length > 0 && view.value.all.every((k) => open.value.has(k)));
function toggleAll(): void {
    open.value = allOpen.value ? new Set() : new Set(view.value.all);
}

function title(node: CampaignNode): string {
    if (node.name) return node.name;

    return node.level === 'campaign' ? t('ads.campaigns.no_campaign') : t('ads.campaigns.no_adset');
}

const ACTIVE = ['ACTIVE', 'ENABLE', 'STATUS_ENABLE', 'STATUS_DELIVERY_OK'];
const PAUSED = ['PAUSED', 'DISABLE', 'STATUS_DISABLE', 'CAMPAIGN_PAUSED', 'ADSET_PAUSED'];
function statusLabel(s: string | null): string {
    if (!s) return '—';
    if (ACTIVE.includes(s)) return t('ads.status.active');
    if (PAUSED.includes(s)) return t('ads.campaigns.status_paused');

    return s;
}
const statusTone = (s: string | null) => (s && ACTIVE.includes(s) ? 'positive' : 'neutral');

const totals = computed(() => {
    const sum = { spend: 0, spend_tax: 0, purchase_value: 0, purchases: 0, real_orders: 0 };
    for (const c of props.tree) {
        sum.spend += c.metrics.spend;
        sum.spend_tax += c.metrics.spend_tax;
        sum.purchase_value += c.metrics.purchase_value;
        sum.purchases += c.metrics.purchases;
        sum.real_orders += c.metrics.real_orders;
    }

    return { ...sum, roas: sum.spend > 0 ? Math.round((sum.purchase_value / sum.spend) * 100) / 100 : null };
});

const selectClass = 'h-9 rounded-md border border-input bg-background px-2 text-xs';
const breadcrumbs = computed(() => [
    { title: t('nav.ads'), href: '/ads' },
    { title: t('nav.ads_campaigns'), href: '/ads/campaigns' },
]);
</script>

<template>
    <Head :title="t('ads.campaigns.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-[1400px] space-y-4 p-3 md:p-6">
            <DataHealthBanner :data-health="data_health" :numbers-under-review="numbers_under_review" :clamped-to-history="clamped_to_history" />
            <PageHeader :title="t('ads.campaigns.title')" :description="t('ads.campaigns.hint')">
                <AdsRangeBar :filters="filters" :platforms="platforms" :buyers="buyers" :keep="keep" />
            </PageHeader>

            <p class="text-2xs text-muted-foreground" :title="t('ads.scope_note_tip')">{{ t('ads.scope_note') }}</p>

            <div class="flex flex-wrap items-center gap-2 rounded-lg bg-card p-3 shadow-card">
                <button
                    type="button"
                    class="inline-flex h-9 items-center rounded-md border border-border bg-background px-3 text-xs font-medium hover:bg-muted disabled:opacity-50"
                    :disabled="!view.all.length"
                    @click="toggleAll"
                >
                    {{ allOpen ? t('ads.campaigns.collapse_all') : t('ads.campaigns.expand_all') }}
                </button>
                <label class="sr-only" for="campaign-sort">{{ t('ads.filters.sort') }}</label>
                <select id="campaign-sort" :value="filters.sort" :class="[selectClass, 'ms-auto']" @change="go({ sort: ($event.target as HTMLSelectElement).value })">
                    <option v-for="s in SORTS" :key="s" :value="s">{{ t('ads.filters.sort') }}: {{ t(`ads.sort.${s}`) }}</option>
                </select>
            </div>

            <div v-if="account_options.length" class="flex flex-wrap items-center gap-1 rounded-lg bg-card p-3 shadow-card" role="group" :aria-label="t('ads.filters.account')">
                <button
                    type="button"
                    :aria-pressed="filters.accounts.length === 0"
                    class="inline-flex h-7 items-center rounded-full border px-2.5 text-2xs font-medium transition-colors"
                    :class="chip(filters.accounts.length === 0)"
                    @click="toggleAccount(null)"
                >
                    {{ t('ads.filters.all_accounts') }}
                </button>
                <button
                    v-for="a in account_options"
                    :key="a.id"
                    type="button"
                    :aria-pressed="filters.accounts.includes(a.id)"
                    class="inline-flex h-7 max-w-64 items-center rounded-full border px-2.5 text-2xs font-medium transition-colors"
                    :class="chip(filters.accounts.includes(a.id))"
                    @click="toggleAccount(a.id)"
                >
                    <span class="truncate" dir="auto">{{ a.name }}</span>
                </button>
            </div>

            <div class="scrollbar-thin relative table-scroll-box rounded-lg bg-card shadow-card [contain:inline-size]">
                <EmptyState v-if="!tree.length" :icon="Layers" :title="t('ads.campaigns.empty')" :body="t('ads.empty.body')" />
                <table v-else class="w-full min-w-[1100px] text-xs">
                    <caption class="sr-only">
                        {{
                            t('ads.campaigns.tree')
                        }}
                    </caption>
                    <thead class="crm-sticky-head border-b border-border/60 text-2xs font-semibold text-muted-foreground">
                        <tr>
                            <th scope="col" class="px-3 py-2 text-start">{{ t('ads.campaigns.name') }}</th>
                            <th scope="col" class="px-2 py-2 text-start">{{ t('ads.table.platform') }}</th>
                            <th scope="col" class="px-2 py-2 text-center">{{ t('ads.table.status') }}</th>
                            <th scope="col" class="px-2 py-2 text-end">{{ t('ads.kpi.spend_tax') }}</th>
                            <th scope="col" class="px-2 py-2 text-end">{{ t('ads.kpi.purchase_value') }}</th>
                            <th scope="col" class="px-2 py-2 text-end">{{ t('ads.kpi.roas') }}</th>
                            <th scope="col" class="px-2 py-2 text-end">{{ t('ads.table.conv') }}</th>
                            <th scope="col" class="px-2 py-2 text-end">{{ t('ads.kpi.real_orders') }}</th>
                            <th scope="col" class="px-2 py-2 text-end">{{ t('ads.kpi.ctr') }}</th>
                            <th v-if="canWrite" scope="col" class="px-2 py-2">
                                <span class="sr-only">{{ t('ads.actions.title') }}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="r in rows"
                            :key="r.key"
                            class="border-t border-border/60 first:border-t-0"
                            :class="r.node.level === 'campaign' ? 'bg-muted/30 font-semibold' : r.node.level === 'adset' ? 'bg-muted/10' : ''"
                        >
                            <th scope="row" class="px-3 py-2 text-start font-[inherit]">
                                <div class="flex items-center gap-1.5" :style="{ paddingInlineStart: `${r.depth * 1.5}rem` }">
                                    <button
                                        v-if="r.expandable"
                                        type="button"
                                        class="inline-flex size-7 shrink-0 items-center justify-center rounded-md text-muted-foreground hover:bg-background hover:text-foreground focus-visible:outline focus-visible:outline-2 focus-visible:outline-ring"
                                        :aria-expanded="open.has(r.key)"
                                        :aria-label="t(open.has(r.key) ? 'ads.campaigns.collapse' : 'ads.campaigns.expand', { name: title(r.node) })"
                                        @click="toggle(r.key)"
                                    >
                                        <ChevronDown v-if="open.has(r.key)" class="size-4" aria-hidden="true" />
                                        <ChevronRight v-else class="rtl-flip size-4" aria-hidden="true" />
                                    </button>
                                    <span v-else class="inline-block size-7 shrink-0" aria-hidden="true" />
                                    <div class="min-w-0 max-w-96">
                                        <p class="truncate" dir="auto" :title="title(r.node)">{{ title(r.node) }}</p>
                                        <p class="flex flex-wrap items-center gap-1 text-2xs font-normal text-muted-foreground">
                                            <span>{{ t(`ads.campaigns.level_${r.node.level}`) }}</span>
                                            <span v-if="r.depth === 0" class="truncate" dir="auto">· {{ r.node.account }}</span>
                                            <StatusChip
                                                v-if="r.node.level !== 'ad' && !r.node.placeholder"
                                                :label="r.node.naming_ok ? t('ads.campaigns.naming_ok') : t('ads.campaigns.naming_bad')"
                                                :tone="r.node.naming_ok ? 'positive' : 'warning'"
                                            />
                                            <span v-if="r.node.level !== 'ad' && !r.node.placeholder && !r.node.naming_ok" class="sr-only">
                                                {{ t(`ads.campaigns.naming_${r.node.level}_hint`) }}
                                            </span>
                                        </p>
                                        <p
                                            v-if="r.node.level !== 'ad' && !r.node.placeholder && !r.node.naming_ok"
                                            class="text-2xs font-normal text-muted-foreground"
                                            aria-hidden="true"
                                        >
                                            {{ t(`ads.campaigns.naming_${r.node.level}_hint`) }}
                                        </p>
                                    </div>
                                </div>
                            </th>
                            <td class="px-2 py-2"><PlatformChip :platform="r.node.platform" size="xs" /></td>
                            <td class="px-2 py-2 text-center">
                                <StatusChip v-if="!r.node.placeholder" :label="statusLabel(r.node.status)" :tone="statusTone(r.node.status)" dot />
                            </td>
                            <td class="px-2 py-2 text-end">
                                <MoneyCell :amount="r.node.metrics.spend" :with-tax="r.node.metrics.spend_tax" :currency="currency" />
                            </td>
                            <td class="px-2 py-2 text-end tabular-nums">{{ n(Math.round(r.node.metrics.purchase_value)) }}</td>
                            <td class="px-2 py-2 text-end">
                                <div class="flex flex-col items-end gap-0.5">
                                    <StatusChip :label="formatRoas(r.node.metrics.roas, locale)" :tone="roasTone(r.node.metrics.roas)" />
                                    <TrendArrow v-if="r.node.trend" :trend="r.node.trend" />
                                </div>
                            </td>
                            <td class="px-2 py-2 text-end tabular-nums">{{ formatQty(r.node.metrics.purchases, locale) }}</td>
                            <td class="px-2 py-2 text-end tabular-nums">{{ n(r.node.metrics.real_orders) }}</td>
                            <td class="px-2 py-2 text-end tabular-nums">{{ formatPct(r.node.metrics.ctr, locale) }}</td>
                            <td v-if="canWrite" class="px-2 py-2 text-center">
                                <StatusToggle
                                    v-if="!r.node.placeholder"
                                    :account-id="r.node.account_id"
                                    :account="r.node.account"
                                    :platform="r.node.platform"
                                    :level="r.node.level"
                                    :external-id="r.node.external_id"
                                    :name="title(r.node)"
                                    :status="r.node.status"
                                    :parent-paused="r.node.parent_paused"
                                    :disabled="r.node.can_write !== true"
                                />
                                <p v-if="r.node.parent_paused && r.node.can_write && r.node.platform !== 'google'" class="mt-0.5 text-2xs text-muted-foreground">{{ t('ads.actions.parent_paused') }}</p>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t-2 border-border bg-muted/40 font-semibold">
                        <tr>
                            <th scope="row" class="px-3 py-2 text-start">{{ t('ads.campaigns.totals_n', { n: tree.length }) }}</th>
                            <td colspan="2" />
                            <td class="px-2 py-2 text-end">
                                <MoneyCell :amount="totals.spend" :with-tax="totals.spend_tax" :currency="currency" />
                            </td>
                            <td class="px-2 py-2 text-end tabular-nums">{{ n(Math.round(totals.purchase_value)) }}</td>
                            <td class="px-2 py-2 text-end"><StatusChip :label="formatRoas(totals.roas, locale)" :tone="roasTone(totals.roas)" /></td>
                            <td class="px-2 py-2 text-end tabular-nums">{{ formatQty(totals.purchases, locale) }}</td>
                            <td class="px-2 py-2 text-end tabular-nums">{{ n(totals.real_orders) }}</td>
                            <td :colspan="canWrite ? 2 : 1" />
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
