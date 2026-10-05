<script setup lang="ts">
/** Three-way revenue summary (spec 1.3): Shopify store totals · CRM ad-attributed real orders · platform-reported purchases. */
import { useI18n } from '@/composables/useI18n';
import { formatAdsMoney, formatPct, formatQty, formatRoas } from '@/lib/ads';
import { formatCount } from '@/lib/format';
import type { AdsRevenueSummary } from '@/types/ads';
import { computed } from 'vue';

const props = defineProps<{ summary: AdsRevenueSummary | null }>();

const { t, locale } = useI18n();
const money = (v: number | null) => formatAdsMoney(v, locale.value, props.summary?.currency ?? 'EGP');
// Store and CRM order revenue is always EGP; the platform figures and the spend are in the account currency.
const moneyEgp = (v: number | null) => formatAdsMoney(v, locale.value, 'EGP');
const signed = (v: number | null, fmt: (x: number | null) => string = money) => (v === null ? '—' : `${v > 0 ? '+' : ''}${fmt(v)}`);
const signedPct = (v: number | null) => (v === null ? '—' : `${v > 0 ? '+' : ''}${formatPct(v, locale.value, 1)}`);

const columns = computed(() => {
    const s = props.summary;
    if (!s) return [];
    return [
        {
            key: 'store',
            title: t('ads.summary.store'),
            tip: t('ads.summary.store_tip'),
            value: s.store ? moneyEgp(s.store.revenue) : t('ads.summary.store_hidden'),
            sub: s.store ? t('ads.summary.orders', { n: formatCount(s.store.orders, locale.value) }) : '',
            roas: s.roas.store,
            roasLabel: t('ads.summary.mer'),
            hidden: s.store === null,
        },
        {
            key: 'crm',
            title: t('ads.summary.crm'),
            tip: t('ads.summary.crm_tip'),
            value: moneyEgp(s.crm.revenue),
            sub: t('ads.summary.orders_chat', { n: formatQty(s.crm.orders, locale.value), chat: formatCount(s.crm.chat_orders, locale.value) }),
            roas: s.roas.crm,
            roasLabel: t('ads.kpi.roas'),
            hidden: false,
        },
        {
            key: 'platform',
            title: t('ads.summary.platform'),
            tip: t('ads.summary.platform_tip'),
            value: money(s.platform.revenue),
            sub: t('ads.summary.purchases', { n: formatQty(s.platform.purchases, locale.value) }),
            roas: s.roas.platform,
            roasLabel: t('ads.kpi.roas'),
            hidden: false,
        },
    ];
});
</script>

<template>
    <section v-if="summary" class="space-y-3 rounded-lg bg-card p-4 shadow-card" aria-labelledby="revenue-summary-title">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <div>
                <h2 id="revenue-summary-title" class="text-sm font-semibold">{{ t('ads.summary.title') }}</h2>
                <p class="text-2xs text-muted-foreground">{{ t('ads.summary.hint') }}</p>
            </div>
            <p class="text-2xs tabular-nums text-muted-foreground" :title="t('ads.summary.spend_tip')">
                {{ t('ads.summary.spend', { pre: money(summary.spend), tax: money(summary.spend_tax) }) }}
            </p>
        </div>

        <p v-if="summary.note === 'foreign_currency'" class="text-2xs text-muted-foreground">{{ t('ads.summary.foreign_currency_note') }}</p>

        <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
            <div v-for="c in columns" :key="c.key" class="min-w-0 rounded-md border border-border px-3 py-2" :title="c.tip">
                <p class="text-2xs font-medium text-muted-foreground">{{ c.title }}</p>
                <p class="mt-0.5 text-lg font-bold tabular-nums" :class="{ '!text-sm !font-medium text-muted-foreground': c.hidden }">{{ c.value }}</p>
                <p v-if="c.sub" class="text-2xs text-muted-foreground">{{ c.sub }}</p>
                <p v-if="!c.hidden" class="mt-1 text-xs tabular-nums">
                    <span class="text-muted-foreground">{{ c.roasLabel }}</span> <span class="font-semibold">{{ formatRoas(c.roas, locale) }}</span>
                </p>
            </div>
        </div>

        <dl class="grid grid-cols-1 gap-2 text-xs sm:grid-cols-2">
            <div class="flex items-center justify-between gap-2 rounded-md bg-muted/50 px-3 py-1.5" :title="t('ads.summary.gap_platform_crm_tip')">
                <dt class="text-muted-foreground">{{ t('ads.summary.gap_platform_crm') }}</dt>
                <dd class="font-semibold tabular-nums">{{ signed(summary.gaps.platform_vs_crm) }} · {{ signedPct(summary.gaps.platform_vs_crm_pct) }}</dd>
            </div>
            <div
                v-if="summary.store"
                class="flex items-center justify-between gap-2 rounded-md bg-muted/50 px-3 py-1.5"
                :title="t('ads.summary.gap_crm_store_tip')"
            >
                <dt class="text-muted-foreground">{{ t('ads.summary.gap_crm_store') }}</dt>
                <dd class="font-semibold tabular-nums">{{ signed(summary.gaps.crm_vs_store, moneyEgp) }} · {{ signedPct(summary.gaps.crm_vs_store_pct) }}</dd>
            </div>
        </dl>
    </section>
</template>
